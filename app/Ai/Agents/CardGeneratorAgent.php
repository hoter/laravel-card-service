<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;
use Stringable;

class CardGeneratorAgent implements Agent, HasStructuredOutput
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return <<<'TXT'
Ты — копирайтер маркетплейса. Тебе дают фрагменты документов поставщика с метками
[chunk_id | файл | страница | секция]. Некоторые фрагменты не относятся к товару.

Правила:
1. Используй только контекст. Чего в нём нет — не выдумывай: имя поля уходит в
   missing_fields, значение в characteristics не появляется.
2. characteristics — массив объектов {name, value, chunk_id, page}, а не словарь.
   chunk_id — короткая метка фрагмента из шапки: S1, S2, … Именно она стоит
   первой в квадратных скобках. page — страница этого же фрагмента. Не придумывай
   метки и не ссылайся на фрагмент, которого нет в контексте.
3. Если в контексте есть таблица «Параметр — Значение», все её строки, кроме
   артикула и цены, переноси в characteristics. В missing_fields попадает только
   то, чего в контексте нет вообще: не отправляй туда поле, значение которого
   видно в тексте.
4. title короче 60 символов, без повторов одного и того же слова или числа.
   description — 3-4 законченных предложения без воды.
5. benefits — 3-5 коротких выгод, и каждая опирается на факт из контекста.
   Не оставляй benefits пустым, если в контексте есть что сказать о товаре.
6. Поле не может быть одновременно в characteristics и missing_fields.
7. confidence — честная уверенность от 0 до 1; мало данных — низкая.
   В description и benefits не используй оценочные слова вроде «идеальный»,
   «лучший» или «качественный» без конкретного факта рядом.
8. seo — вложенный объект с title, description, keywords. В seo не добавляй
   города, сроки доставки, призывы «купить сейчас» и прочие обещания, которых
   нет в контексте: только характеристики товара и его назначение. description
   заканчивай целым предложением и держи в пределах 160 символов.
9. Ответ — только JSON по схеме.
TXT;
    }

    public function maxTokens(): int
    {
        return 2000;
    }

    public function temperature(): float
    {
        return 0.2;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->max(60)->required(),
            'description' => $schema->string()->required(),
            'characteristics' => $schema->array()->items(
                $schema->object([
                    'name' => $schema->string()->required(),
                    'value' => $schema->string()->required(),
                    'chunk_id' => $schema->string()->required(),
                    'page' => $schema->integer()->min(1),
                ])->withoutAdditionalProperties()
            )->required(),
            'benefits' => $schema->array()->items($schema->string())->min(1)->required(),
            'missing_fields' => $schema->array()->items($schema->string())->required(),
            'confidence' => $schema->number()->min(0)->max(1)->required(),
            'seo' => $schema->object([
                'title' => $schema->string()->max(60)->required(),
                'description' => $schema->string()->max(160)->required(),
                'keywords' => $schema->array()->items($schema->string())->required(),
            ])->withoutAdditionalProperties()->required(),
        ];
    }
}
