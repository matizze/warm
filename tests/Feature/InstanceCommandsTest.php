<?php

namespace Tests\Feature;

use App\Models\Instance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InstanceCommandsTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_registers_an_instance_with_generated_encrypted_tokens(): void
    {
        $this->artisan('warm:instance:create', [
            'instance_id' => 'support',
            '--url' => 'https://evolution.test/',
            '--api-key' => 'evo-key',
        ])->assertSuccessful();

        $instance = Instance::query()->findOrFail('support');

        $this->assertSame('https://evolution.test', $instance->evolution_url);
        $this->assertSame('evo-key', $instance->api_key);
        $this->assertSame(48, strlen($instance->webhook_token));
        $this->assertSame(48, strlen($instance->mcp_token));
        $this->assertFalse($instance->isDefault());

        $stored = DB::table('instances')->where('instance_id', 'support')->first();
        $this->assertNotSame('evo-key', $stored->api_key);
        $this->assertNotSame($instance->webhook_token, $stored->webhook_token);
        $this->assertNotSame($instance->mcp_token, $stored->mcp_token);
    }

    public function test_create_with_default_replaces_the_current_default_instance(): void
    {
        $this->artisan('warm:instance:create', [
            'instance_id' => 'support',
            '--url' => 'https://evolution.test',
            '--api-key' => 'evo-key',
            '--default' => true,
        ])->assertSuccessful();

        $this->assertSame('support', Instance::defaultInstanceId());
        $this->assertSame(1, Instance::query()->where('is_default', true)->count());
    }

    public function test_create_refuses_an_existing_instance(): void
    {
        $this->artisan('warm:instance:create', [
            'instance_id' => 'instance1',
            '--url' => 'https://evolution.test',
            '--api-key' => 'evo-key',
        ])->assertFailed();
    }

    public function test_rotate_replaces_the_token_and_the_old_one_stops_working(): void
    {
        $this->artisan('warm:instance:rotate-token', ['instance_id' => 'instance1', '--mcp' => true])
            ->assertSuccessful();

        $default = Instance::default();
        $this->assertSame('test-token', $default->webhook_token);
        $this->assertNotSame('test-mcp-token', $default->mcp_token);

        $ping = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'];
        $this->postJson('/mcp', $ping, ['Authorization' => 'Bearer test-mcp-token'])->assertStatus(401);
        $this->postJson('/mcp', $ping, ['Authorization' => "Bearer {$default->mcp_token}"])->assertOk();
    }

    public function test_rotate_fails_for_an_unknown_instance(): void
    {
        $this->artisan('warm:instance:rotate-token', ['instance_id' => 'missing'])->assertFailed();
    }

    public function test_list_does_not_reveal_credentials(): void
    {
        $this->artisan('warm:instance:list')
            ->expectsOutputToContain('instance1')
            ->doesntExpectOutputToContain('test-mcp-token')
            ->doesntExpectOutputToContain('test-token')
            ->assertSuccessful();
    }
}
