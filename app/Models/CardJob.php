<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CardJob extends Model
{
    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'status', 'hint', 'document_ids', 'context', 'attempts', 'idempotency_key',
        'result', 'guard_report', 'error', 'request_id',
    ];

    protected $casts = [
        'document_ids' => 'array',
        'result' => 'array',
        'guard_report' => 'array',
        'attempts' => 'integer',
    ];
}
