<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Document extends Model
{
    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'filename', 'content_hash', 'kind', 'status', 'size_bytes',
        'content_type', 'stored_path', 'error', 'chunks_count',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'chunks_count' => 'integer',
    ];

    public function chunks(): HasMany
    {
        return $this->hasMany(Chunk::class);
    }
}
