<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A WhatsApp number WARM operates: an Evolution instance plus the credentials
 * and per-instance tokens used to ingest and reply through it.
 *
 * @mixin IdeHelperInstance
 */
class Instance extends Model
{
    use HasFactory;

    protected $table = 'instances';

    protected $primaryKey = 'instance_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'instance_id',
        'evolution_url',
        'api_key',
        'webhook_token',
        'mcp_token',
        'is_default',
        'active',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'api_key',
        'webhook_token',
        'mcp_token',
    ];

    /**
     * Credentials are encrypted at rest with the APP_KEY.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'webhook_token' => 'encrypted',
            'mcp_token' => 'encrypted',
            'is_default' => 'boolean',
            'active' => 'boolean',
        ];
    }

    /**
     * A random token suitable for the webhook and MCP credentials.
     */
    public static function generateToken(): string
    {
        return Str::random(48);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'instance_id', 'instance_id');
    }

    public function isDefault(): bool
    {
        return $this->is_default === true;
    }

    /**
     * Provider credentials shaped for the Evolution adapter.
     *
     * @return array{url: string, api_key: string, instance: string}
     */
    public function providerConfig(): array
    {
        return [
            'url' => $this->evolution_url,
            'api_key' => $this->api_key,
            'instance' => $this->instance_id,
        ];
    }

    public static function default(): ?self
    {
        return static::query()->where('is_default', true)->first();
    }

    public static function defaultInstanceId(): ?string
    {
        return static::default()?->instance_id;
    }
}
