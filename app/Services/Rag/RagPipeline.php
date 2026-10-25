<?php

namespace App\Services\Rag;

use App\Ai\Agents\CardCriticAgent;
use App\Ai\Agents\CardGeneratorAgent;
use App\Data\CardData;
use App\Data\GuardReport;
use App\Services\Guardrails\Guard;
use App\Services\Llm\StructuredRunner;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class RagPipeline
{
    public function __construct(
        private readonly ChunkRepository $chunks,
        private readonly ContextBuilder $contextBuilder,
        private readonly Guard $guard,
        private readonly StructuredRunner $runner,
        private readonly CitationValidator $citations,
    ) {}

    /**
     * @return array{0: string, 1: GuardReport}
     */
    public function retrieve(string $query, array $documentIds): array
    {
        $rows = $this->chunks->hybrid(
            $query,
            (int) config('card.rag.search_limit'),
            null,
            $documentIds
        );

        $chunks = array_map(static fn (array $row) => [
            'chunk_id' => $row['chunk_id'],
            'text' => $row['text'],
            'metadata' => $row['metadata'] ?? [],
            'filename' => $row['filename'] ?? null,
        ], $rows);

        [$safe, $report] = $this->guard->context($chunks);

        return [$this->contextBuilder->build($safe), $report];
    }

    /**
     * @param  array<int, string>  $feedback
     */
    public function generate(string $context, ?string $hint, array $feedback = []): CardData
    {
        $prompt = $context;
        if ($hint !== null && $hint !== '') {
            $prompt .= "\n\nПодсказка о товаре: ".$hint;
        }
        if ($feedback !== []) {
            $prompt .= "\n\nПредыдущая попытка отклонена, исправь:\n- ".implode("\n- ", $feedback);
        }
        $prompt .= "\n\nСобери карточку по контексту. characteristics — массив объектов "
            .'{name, value, chunk_id, page}: в chunk_id ставь метку S1, S2, … из шапки '
            .'фрагмента, где нашлось значение. Чего нет в контексте — в missing_fields.';

        return $this->runner->run(
            new CardGeneratorAgent,
            $prompt,
            'Generator',
            static fn (array $data) => CardData::fromArray($data)
        );
    }

    /**
     * @return array{verdict: string, issues: array<int, string>}
     */
    public function critique(string $context, CardData $card): array
    {
        $prompt = $context."\n\nЧерновик карточки:\n"
            .json_encode($card->toArray(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            ."\n\nСверь каждое утверждение с фрагментом, на который оно ссылается. "
            .'Если подтверждения нет — verdict regenerate и конкретные issues.';

        try {
            return $this->runner->run(
                new CardCriticAgent,
                $prompt,
                'Critic',
                static function (array $data): array {
                    $validator = validator($data, [
                        'verdict' => ['required', 'in:approve,regenerate'],
                        'issues' => ['array'],
                        'issues.*' => ['string'],
                    ]);

                    if ($validator->fails()) {
                        throw new ValidationException($validator);
                    }

                    return [
                        'verdict' => (string) $data['verdict'],
                        'issues' => array_values(array_map('strval', $data['issues'] ?? [])),
                    ];
                },
                aux: true,
            );
        } catch (Throwable $exception) {
            Log::warning('critic_unavailable', ['error' => $exception->getMessage()]);

            return ['verdict' => 'unavailable', 'issues' => []];
        }
    }

    /**
     * @return array<int, string>
     */
    public function verify(CardData $card, string $context): array
    {
        return $this->citations->validate($card, $context);
    }
}
