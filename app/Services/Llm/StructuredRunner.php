<?php

namespace App\Services\Llm;

use Illuminate\Validation\ValidationException;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Throwable;

class StructuredRunner
{
    public function __construct(private readonly LlmGateway $gateway) {}

    /**
     * Попросить модель вернуть структуру и провалидировать её; ошибка уходит обратно модели.
     *
     * @param  callable(array<string, mixed>): mixed  $validate
     */
    public function run(
        Agent&HasStructuredOutput $agent,
        string $prompt,
        string $role,
        callable $validate,
        bool $aux = false,
        int $attempts = 3,
    ): mixed {
        $current = $prompt;
        $lastError = 'модель не ответила';

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $data = $this->gateway->structured($agent, $current, $role, $aux);

                return $validate($data);
            } catch (ValidationException $exception) {
                $lastError = collect($exception->errors())->flatten()->implode('; ');
            } catch (Throwable $exception) {
                $lastError = $exception->getMessage();
            }

            if ($attempt < $attempts) {
                $current = $prompt
                    ."\n\nПредыдущий ответ не прошёл проверку: ".$lastError
                    ."\nИсправь ошибку и верни ТОЛЬКО валидный JSON по схеме.";
            }
        }

        throw new \RuntimeException(
            "Не удалось получить валидный ответ за {$attempts} попытки: {$lastError}"
        );
    }
}
