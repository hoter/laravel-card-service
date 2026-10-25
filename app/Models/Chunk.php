<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Chunk extends Model
{
    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'document_id', 'ordinal', 'text', 'metadata'];

    protected $casts = ['metadata' => 'array'];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
