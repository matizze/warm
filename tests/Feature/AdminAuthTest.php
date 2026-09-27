<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_endpoint_is_disabled_without_configured_token(): void
    {
        config(['warm.admin_token' => '']);

        $this->postJson('/admin/reindex')->assertStatus(404);
    }

    public function test_admin_endpoint_rejects_wrong_token(): void
    {
        config(['warm.admin_token' => 'admin-secret']);

        $this->postJson('/admin/reindex', [], ['x-admin-token' => 'wrong'])
            ->assertStatus(401);
    }

    public function test_admin_endpoint_accepts_correct_token(): void
    {
        config(['warm.admin_token' => 'admin-secret']);

        $this->postJson('/admin/reindex', [], ['x-admin-token' => 'admin-secret'])
            ->assertOk()
            ->assertJsonPath('scanned', 0);
    }
}
