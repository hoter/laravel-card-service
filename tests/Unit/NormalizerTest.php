<?php

namespace Tests\Unit;

use App\Services\Documents\Normalizer;
use PHPUnit\Framework\TestCase;

class NormalizerTest extends TestCase
{
    public function test_hyphenation_and_spaces(): void
    {
        $normalizer = new Normalizer;
        $this->assertSame('мощность', $normalizer->clean("мощ-\nность"));
        $this->assertSame('слева справа', $normalizer->clean('слева  справа'));
    }

    public function test_repeated_footer_removed_on_two_pages(): void
    {
        $blocks = [
            ['content' => "ООО «ТехноДом» — стр. 1\nПервый абзац", 'kind' => 'text', 'page' => 1],
            ['content' => "ООО «ТехноДом» — стр. 2\nВторой абзац", 'kind' => 'text', 'page' => 2],
        ];

        $result = (new Normalizer)->normalize($blocks);
        $text = implode("\n", array_column($result, 'content'));

        $this->assertStringNotContainsString('ООО «ТехноДом»', $text);
        $this->assertStringContainsString('Первый абзац', $text);
    }
}
