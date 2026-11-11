<?php

namespace Tests\Unit;

use App\Mcp\ToolRegistry;
use InvalidArgumentException;
use Tests\TestCase;

class ToolRegistryTest extends TestCase
{
    public function test_rejects_an_invalid_uuid(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ToolRegistry()->call('media.download', ['messageId' => 'nope']);
    }

    public function test_rejects_an_unknown_tool(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ToolRegistry()->call('nope', []);
    }

    public function test_rejects_an_out_of_range_limit(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ToolRegistry()->call('contact.search', ['limit' => 0]);
    }

    public function test_rejects_a_contact_upsert_without_phone(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ToolRegistry()->call('contact.upsert', []);
    }

    public function test_rejects_a_contact_upsert_with_an_invalid_phone(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ToolRegistry()->call('contact.upsert', ['phoneNumber' => 'telefone']);
    }

    public function test_rejects_a_contact_rename_without_a_target_or_a_name(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ToolRegistry()->call('contact.rename', ['phoneNumber' => '5511988887777']);
    }

    public function test_rejects_a_contact_rename_with_an_empty_name(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ToolRegistry()->call('contact.rename', ['contactId' => '01a18eeb-f298-783e-8be1-2245c1e15782', 'name' => '   ']);
    }

    public function test_rejects_a_contact_rename_without_a_contact_identifier(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ToolRegistry()->call('contact.rename', ['name' => 'Ana']);
    }

    public function test_rejects_an_unknown_search_mode(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ToolRegistry()->call('message.search', ['mode' => 'turbo']);
    }
}
