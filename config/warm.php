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
    | Attendance (customer question -> grounded reply)
    |--------------------------------------------------------------------------
    |
    | `enabled` is the kill switch: false turns the whole flow off (no reply,
    | no case). `dry_run` keeps it safe: the intended reply is recorded and
    | logged but never sent. `instances` / `conversations` are the scope — an
    | empty scope means nothing is answered. `tone` is configurable because the
    | final wording is still open (Henrique validates it); nothing is fixed in
    | code.
    |
    */

    'attendance' => [
        'enabled' => filter_var(env('ATTENDANCE_ENABLED', true), FILTER_VALIDATE_BOOL),
        'dry_run' => filter_var(env('ATTENDANCE_DRY_RUN', true), FILTER_VALIDATE_BOOL),
        'instances' => array_values(array_filter(array_map('trim', explode(',', (string) env('ATTENDANCE_INSTANCES', ''))))),
        'conversations' => array_values(array_filter(array_map('trim', explode(',', (string) env('ATTENDANCE_CONVERSATIONS', ''))))),
        'min_confidence' => (float) env('ATTENDANCE_MIN_CONFIDENCE', 0.6),
        'knowledge_limit' => (int) env('ATTENDANCE_KNOWLEDGE_LIMIT', 5),
        'answer_model' => env('ATTENDANCE_ANSWER_MODEL', 'gpt-4o-mini'),
        'tone' => env('ATTENDANCE_TONE', 'Próximo e educado, linguagem simples do dia a dia; não prometa prazos que o time não confirmou.'),
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
