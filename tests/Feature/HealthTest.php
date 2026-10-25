<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_live_is_ok(): void
    {
        $this->getJson('/api/health/live')
            ->assertOk()
            ->assertJson(['status' => 'ok']);
    }

    public function test_ready_reports_vector_version(): void
    {
        $this->getJson('/api/health/ready')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('database', 'ok');
    }
}
