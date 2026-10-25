<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

class JudgeAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'TXT'
Ты — судья качества карточки. Тебе даны фрагменты документов и карточка со
ссылками sources. Проверь, подтверждается ли каждое утверждение карточки
источником, на который оно ссылается. Не оценивай стиль и полноту.

supported=true только если ВСЕ утверждения подтверждены. В unsupported_claims
перечисли конкретные утверждения без подтверждения.
TXT;
    }

    public function maxTokens(): int
    {
        return 500;
    }

    public function temperature(): float
    {
        return 0.0;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'supported' => $schema->boolean()->required(),
            'unsupported_claims' => $schema->array()->items($schema->string())->required(),
        ];
    }
}
