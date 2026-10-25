<?php

namespace App\Services\Evals;

use App\Ai\Agents\JudgeAgent;
use App\Data\CardData;
use App\Models\Document;
use App\Services\Llm\StructuredRunner;
use App\Services\Rag\CitationValidator;
use App\Services\Rag\RagPipeline;
use Illuminate\Validation\ValidationException;
use Throwable;

class EvalRunner
{
    public function __construct(
        private readonly RagPipeline $pipeline,
        private readonly CitationValidator $citations,
        private readonly StructuredRunner $runner,
        private readonly Metrics $metrics,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(bool $all, ?string $document = null): array
    {
        $golden = json_decode((string) file_get_contents(base_path('data/golden_cards.json')), true);
        $selected = $document !== null
            ? [$document]
            : ($all ? array_keys($golden['documents']) : $golden['eval_defaults']);

        $rows = [];
        foreach ($selected as $filename) {
            $expected = $golden['documents'][$filename];
            $document = Document::where('filename', $filename)->where('status', 'indexed')->first();

            if (! $document) {
                $rows[] = ['document' => $filename, 'skipped' => 'не найден или не проиндексирован'];

                continue;
            }

            [$context, $guard] = $this->pipeline->retrieve((string) ($expected['product'] ?? 'товар'), [$document->id]);
            if ($context === '' || $guard->needsReview()) {
                $rows[] = ['document' => $filename, 'skipped' => 'защита исключила контекст'];

                continue;
            }

            $card = null;
            $feedback = [];
            $attempts = 0;
            $critiqueVerdict = null;

            try {
                for ($attempts = 1; $attempts <= 3; $attempts++) {
                    $card = $this->pipeline->generate($context, $expected['product'] ?? null, $feedback);
                    $errors = $this->pipeline->verify($card, $context);
                    if ($errors !== []) {
                        $feedback = $errors;

                        continue;
                    }

                    // Критик совещательный: в eval он не должен запускать
                    // второй круг генерации, иначе слабая 1b-модель крутит
                    // цикл до лимита попыток.
                    $critiqueVerdict = $this->pipeline->critique($context, $card)['verdict'];
                    break;
                }
            } catch (Throwable $exception) {
                $rows[] = [
                    'document' => $filename,
                    'skipped' => 'ошибка генерации: '.mb_substr($exception->getMessage(), 0, 200),
                ];

                continue;
            }

            if (! $card instanceof CardData) {
                $rows[] = ['document' => $filename, 'skipped' => 'не удалось собрать карточку'];

                continue;
            }

            $sources = $this->citations->enrich($card, $context);
            $chars = $this->metrics->characteristicMatch($expected['characteristics'] ?? [], $card->characteristics);
            $citations = $this->metrics->citationSupport($expected['source_probes'] ?? [], $sources);
            $judge = $this->judge($context, $card);

            $rows[] = [
                'document' => $filename,
                'characteristic_match' => round($chars['score'], 3),
                'missing_characteristics' => $chars['missed'],
                'citation_support' => round($citations['score'], 3),
                'citation_failed' => $citations['failed'],
                'judge_supported' => $judge['supported'],
                'judge_unsupported_claims' => $judge['unsupported_claims'],
                'attempts' => $attempts,
                'critique_verdict' => $critiqueVerdict,
                'confidence' => $card->confidence,
                'missing_fields' => $card->missingFields,
            ];
        }

        $scored = array_values(array_filter($rows, static fn (array $row) => ! isset($row['skipped'])));
        $averages = [];
        if ($scored !== []) {
            $averages = [
                'characteristic_match' => round(array_sum(array_column($scored, 'characteristic_match')) / count($scored), 3),
                'citation_support' => round(array_sum(array_column($scored, 'citation_support')) / count($scored), 3),
                'judge_supported' => round(count(array_filter(array_column($scored, 'judge_supported'))) / count($scored), 3),
            ];
        }

        return [
            'generated_at' => now()->toIso8601String(),
            'subset' => ! $all,
            'documents' => $rows,
            'averages' => $averages,
        ];
    }

    /**
     * @return array{supported: bool, unsupported_claims: array<int, string>}
     */
    private function judge(string $context, CardData $card): array
    {
        $prompt = $context."\n\nКарточка для проверки:\n"
            .json_encode($card->toArray(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            ."\n\nПроверь каждое утверждение карточки по фрагменту, на который оно ссылается. "
            .'supported=false, если хотя бы одно утверждение не подтверждено.';

        return $this->runner->run(
            new JudgeAgent,
            $prompt,
            'Judge',
            static function (array $data): array {
                $validator = validator($data, [
                    'supported' => ['required', 'boolean'],
                    'unsupported_claims' => ['array'],
                    'unsupported_claims.*' => ['string'],
                ]);
                if ($validator->fails()) {
                    throw new ValidationException($validator);
                }

                return [
                    'supported' => (bool) $data['supported'],
                    'unsupported_claims' => array_values(array_map('strval', $data['unsupported_claims'] ?? [])),
                ];
            },
            aux: true,
        );
    }
}
