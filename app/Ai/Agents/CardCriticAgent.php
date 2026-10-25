<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

class CardCriticAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'TXT'
Ты — строгий редактор карточки маркетплейса. Тебе даны фрагменты документов
с метками [chunk_id | файл | страница | секция] и черновик карточки со ссылками.

Проверь:
1. Каждое утверждение подтверждается ИМЕННО тем фрагментом, на который ссылается
   sources. Нет подтверждения — проблема.
2. Нет утверждений, которых нет в контексте (выдуманные характеристики, цены,
   гарантии, контакты).
3. У каждой характеристики есть ссылка.
4. title не длиннее 60 символов, description и benefits не пустые, нет поля
   одновременно в characteristics и missing_fields.
Верни verdict approve или regenerate и конкретные issues.
TXT;
    }

    public function maxTokens(): int
    {
        return 300;
    }

    public function temperature(): float
    {
        return 0.0;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'verdict' => $schema->string()->enum(['approve', 'regenerate'])->required(),
            'issues' => $schema->array()->items($schema->string())->required(),
        ];
    }
}
