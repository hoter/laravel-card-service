<?php

namespace Tests\Unit;

use App\Services\Llm\CostCalculator;
use Tests\TestCase;

class CostCalculatorTest extends TestCase
{
    public function test_cost_is_integer_micros(): void
    {
        config([
            'card.models.main' => 'main',
            'card.models.aux' => 'aux',
            'card.models.embedding' => 'embed',
            'card.pricing' => [
                'main' => ['input' => 150_000, 'output' => 600_000],
                'aux' => ['input' => 50_000, 'output' => 200_000],
                'embedding' => ['input' => 0, 'output' => 0],
            ],
        ]);

        $cost = (new CostCalculator)->forModel('main', 1000, 500);

        $this->assertSame(450, $cost);
        $this->assertIsInt($cost);
    }

    public function test_aux_model_has_its_own_price(): void
    {
        config([
            'card.models.main' => 'main',
            'card.models.aux' => 'aux',
            'card.pricing' => [
                'main' => ['input' => 150_000, 'output' => 600_000],
                'aux' => ['input' => 50_000, 'output' => 200_000],
                'embedding' => ['input' => 0, 'output' => 0],
            ],
        ]);

        $this->assertSame(50_000, (new CostCalculator)->forModel('aux', 1_000_000, 0));
    }
}
