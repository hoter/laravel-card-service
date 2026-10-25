<?php

return [
    'models' => [
        'main' => env('OLLAMA_MODEL', 'gemma3:4b'),
        'aux' => env('OLLAMA_AUX_MODEL', 'gemma3:1b'),
        'embedding' => env('OLLAMA_EMBED_MODEL', 'bge-m3'),
    ],

    'embedding_dimensions' => (int) env('OLLAMA_EMBED_DIM', 1024),

    // Цены в микроединицах за 1M токенов. Локальная модель «бесплатна»,
    // тариф нужен, чтобы учёт стоимости работал в статье.
    'pricing' => [
        'main' => [
            'input' => (int) env('PRICE_MAIN_INPUT', 150_000),
            'output' => (int) env('PRICE_MAIN_OUTPUT', 600_000),
        ],
        'aux' => [
            'input' => (int) env('PRICE_AUX_INPUT', 50_000),
            'output' => (int) env('PRICE_AUX_OUTPUT', 200_000),
        ],
        'embedding' => [
            'input' => (int) env('PRICE_EMBED_INPUT', 0),
            'output' => 0,
        ],
    ],

    'ai_timeout' => (int) env('AI_TIMEOUT', 300),
    'ai_max_retries' => (int) env('AI_MAX_RETRIES', 3),

    'confidence_threshold' => (float) env('CONFIDENCE_THRESHOLD', 0.6),
    'search_min_similarity' => (float) env('SEARCH_MIN_SIMILARITY', 0.45),

    'rag' => [
        'search_limit' => (int) env('RAG_SEARCH_LIMIT', 8),
        'context_max_chars' => (int) env('RAG_CONTEXT_MAX_CHARS', 6000),
    ],

    'guardrails' => [
        'suspicious_chunk_limit' => (int) env('SUSPICIOUS_CHUNK_LIMIT', 2),
        'max_upload_bytes' => (int) env('MAX_UPLOAD_BYTES', 10 * 1024 * 1024),
    ],

    'documents_dir' => env('DOCUMENTS_DIR', 'documents'),
];
