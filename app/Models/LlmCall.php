<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LlmCall extends Model
{
    protected $fillable = [
        'card_job_id', 'request_id', 'model', 'role', 'input_tokens',
        'output_tokens', 'cost_micros', 'duration_ms',
    ];

    protected $casts = [
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'cost_micros' => 'integer',
        'duration_ms' => 'integer',
    ];
}
