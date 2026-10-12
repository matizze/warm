<?php

return [

    /*
    |--------------------------------------------------------------------------
    | WhatsApp Provider
    |--------------------------------------------------------------------------
    |
    | Each WARM installation talks to exactly one provider at a time.
    | The provider is an infrastructure concern; the domain never sees it.
    |
    */

    'provider' => env('WHATSAPP_PROVIDER', 'evolution'),

    /*
    |--------------------------------------------------------------------------
    | Inbound / Admin authentication
    |--------------------------------------------------------------------------
    */

    'webhook_token' => env('WEBHOOK_TOKEN', ''),
    'admin_token' => env('ADMIN_TOKEN', ''),
    // Empty disables the HTTP MCP endpoint (POST /mcp returns 404).
    'mcp_token' => env('MCP_TOKEN', ''),

    /*
    |--------------------------------------------------------------------------
    | Evolution provider
    |--------------------------------------------------------------------------
    */

    'evolution' => [
        'url' => rtrim(env('EVOLUTION_URL', 'http://localhost:8080'), '/'),
        'api_key' => env('EVOLUTION_API_KEY', ''),
        'instance' => env('EVOLUTION_INSTANCE_ID', 'instance1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Media storage
    |--------------------------------------------------------------------------
    |
    | STORAGE_DRIVER=s3 uses the "warm" disk (RustFS/MinIO/AWS), "local" writes
    | to STORAGE_LOCAL_PATH. See config/filesystems.php.
    |
    */

    'storage' => [
        'driver' => env('STORAGE_DRIVER', 's3'),
        'disk' => env('STORAGE_DISK', 'warm'),
        'signed_url_ttl' => (int) env('MEDIA_SIGNED_URL_TTL', 3600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Media content processing (PDF / audio / image)
    |--------------------------------------------------------------------------
    */

    'processing' => [
        'enabled' => filter_var(env('PROCESSING_ENABLED', true), FILTER_VALIDATE_BOOL),
        'openai_key' => env('OPENAI_API_KEY', ''),
        'openai_base_url' => rtrim(env('OPENAI_BASE_URL', 'https://api.openai.com/v1'), '/'),
        'transcription_model' => env('TRANSCRIPTION_MODEL', 'whisper-1'),
        'vision_model' => env('VISION_MODEL', 'gpt-4o-mini'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Embeddings / semantic search (pgvector)
    |--------------------------------------------------------------------------
    */

    'embeddings' => [
        'enabled' => filter_var(env('EMBEDDING_ENABLED', true), FILTER_VALIDATE_BOOL),
        'model' => env('EMBEDDING_MODEL', 'text-embedding-3-small'),
        'dimensions' => (int) env('EMBEDDING_DIMENSIONS', 1536),
        'openai_key' => env('OPENAI_API_KEY', ''),
        'openai_base_url' => rtrim(env('OPENAI_BASE_URL', 'https://api.openai.com/v1'), '/'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduled reindex
    |--------------------------------------------------------------------------
    */

    'reindex' => [
        'enabled' => filter_var(env('REINDEX_ENABLED', true), FILTER_VALIDATE_BOOL),
        'cron' => env('REINDEX_CRON', '0 3 * * *'),
        'timezone' => env('REINDEX_TZ', 'America/Sao_Paulo'),
    ],

];
