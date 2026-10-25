<?php

namespace Tests\Unit;

use App\Services\Guardrails\Pii;
use PHPUnit\Framework\TestCase;

class PiiTest extends TestCase
{
    public function test_phone_and_email_are_masked(): void
    {
        $pii = new Pii;
        [$cleaned, $entities] = $pii->mask('Менеджер +7 926 555-14-08, a@b.example');

        $this->assertStringNotContainsString('926', $cleaned);
        $this->assertStringContainsString('[PHONE_1]', $cleaned);
        $this->assertStringContainsString('[EMAIL_1]', $cleaned);
        $this->assertSame(['phone', 'email'], array_column($entities, 'kind'));
    }

    public function test_inn_checksum(): void
    {
        $pii = new Pii;
        $this->assertTrue($pii->innValid('7712345671'));
        $this->assertFalse($pii->innValid('7712345679'));

        [$cleaned] = $pii->mask('заказ 1234567890 оформлен');
        $this->assertStringContainsString('1234567890', $cleaned);
    }

    public function test_card_luhn(): void
    {
        $pii = new Pii;
        $this->assertTrue($pii->luhnValid('4111 1111 1111 1111'));
        $this->assertFalse($pii->luhnValid('4111 1111 1111 1112'));
    }

    public function test_products_are_not_touched(): void
    {
        $text = 'Артикул KTL-1700, мощность 800 Вт, объём 1,7 л, цена 2 990 ₽';
        $this->assertSame($text, (new Pii)->mask($text)[0]);
    }
}
