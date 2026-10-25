<?php

namespace App\Data;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final readonly class CardData
{
    public function __construct(
        public string $title,
        public string $description,
        public array $characteristics,
        public array $benefits,
        public array $missingFields,
        public float $confidence,
        public array $seo,
        public array $sources,
    ) {}

    public static function fromArray(array $data): self
    {
        $data['title'] = trim((string) ($data['title'] ?? ''));

        $validator = Validator::make($data, [
            'title' => ['required', 'string', 'max:60'],
            'description' => ['required', 'string'],
            'characteristics' => ['required', 'array'],
            'benefits' => ['array', 'min:1'],
            'benefits.*' => ['string'],
            'missing_fields' => ['array'],
            'missing_fields.*' => ['string'],
            'confidence' => ['required', 'numeric', 'between:0,1'],
            'seo' => ['sometimes', 'array'],
            'seo.title' => ['nullable', 'string', 'max:60'],
            'seo.description' => ['nullable', 'string', 'max:160'],
            'seo.keywords' => ['nullable', 'array'],
            'sources' => ['sometimes', 'array'],
            'sources.*.field' => ['required', 'string'],
            'sources.*.chunk_id' => ['required', 'string'],
            'sources.*.page' => ['nullable', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        if (! array_is_list($data['characteristics']) && ($data['sources'] ?? []) === []) {
            throw ValidationException::withMessages([
                'characteristics' => 'characteristics должен быть массивом объектов '
                    .'{name, value, chunk_id, page}, а не словарём',
            ]);
        }

        [$characteristics, $builtSources] = self::readCharacteristics($data['characteristics']);
        $sources = [...$builtSources, ...($data['sources'] ?? [])];

        if ($sources === []) {
            throw ValidationException::withMessages([
                'sources' => 'В карточке нет ни одной ссылки на источник',
            ]);
        }

        $sources = array_map(static fn (array $source): array => [
            'field' => (string) $source['field'],
            'chunk_id' => (string) $source['chunk_id'],
            'doc_id' => (string) ($source['doc_id'] ?? ''),
            'page' => isset($source['page']) ? (int) $source['page'] : null,
        ], $sources);

        $missing = array_values(array_unique(array_map('strval', $data['missing_fields'] ?? [])));
        $overlap = array_intersect(array_keys($characteristics), $missing);

        if ($overlap !== []) {
            throw ValidationException::withMessages([
                'characteristics' => 'Поля не могут быть одновременно в characteristics и missing_fields: '
                    .implode(', ', $overlap),
            ]);
        }

        $seo = $data['seo'] ?? [];

        return new self(
            title: $data['title'],
            description: trim((string) $data['description']),
            characteristics: $characteristics,
            benefits: array_values(array_map('strval', $data['benefits'] ?? [])),
            missingFields: $missing,
            confidence: (float) $data['confidence'],
            seo: [
                'title' => (string) ($seo['title'] ?? ''),
                'description' => (string) ($seo['description'] ?? ''),
                'keywords' => array_values(array_map('strval', $seo['keywords'] ?? [])),
            ],
            sources: $sources,
        );
    }

    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'description' => $this->description,
            'characteristics' => $this->characteristics,
            'benefits' => $this->benefits,
            'missing_fields' => $this->missingFields,
            'confidence' => $this->confidence,
            'seo' => $this->seo,
            'sources' => $this->sources,
        ];
    }

    /**
     * Ответ генератора: список объектов с цитатой у каждой характеристики.
     * Сохранённая карточка: словарь name => value и отдельный список sources.
     *
     * @return array{0: array<string, string>, 1: array<int, array<string, mixed>>}
     */
    private static function readCharacteristics(array $raw): array
    {
        if (! array_is_list($raw)) {
            return [self::normalizePairs($raw), []];
        }

        $characteristics = [];
        $sources = [];

        foreach ($raw as $index => $item) {
            if (! is_array($item) || ! isset($item['name'], $item['value'])) {
                throw ValidationException::withMessages([
                    "characteristics.{$index}" => 'Ожидается объект {name, value, chunk_id, page}',
                ]);
            }

            $name = trim((string) $item['name']);
            $value = trim((string) $item['value']);
            $chunkId = trim((string) ($item['chunk_id'] ?? ''));

            if ($name === '' || $value === '') {
                continue;
            }
            if ($chunkId === '') {
                throw ValidationException::withMessages([
                    "characteristics.{$index}" => "У характеристики «{$name}» нет chunk_id",
                ]);
            }

            $characteristics[$name] = $value;
            $sources[] = [
                'field' => 'characteristics.'.$name,
                'chunk_id' => $chunkId,
                'page' => isset($item['page']) ? (int) $item['page'] : null,
            ];
        }

        return [$characteristics, $sources];
    }

    private static function normalizePairs(array $raw): array
    {
        $result = [];

        foreach ($raw as $key => $item) {
            if (is_array($item)) {
                $name = trim((string) ($item['name'] ?? (is_string($key) ? $key : '')));
                $value = trim((string) ($item['value'] ?? ''));
            } elseif (is_string($item)) {
                $name = is_string($key) ? $key : '';
                $value = trim($item);
            } else {
                continue;
            }

            if ($name === '' || $value === '') {
                continue;
            }

            $result[$name] = $value;
        }

        return $result;
    }
}
