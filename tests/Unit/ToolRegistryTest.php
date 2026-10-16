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

    public function test_rejects_an_unknown_search_mode(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ToolRegistry()->call('message.search', ['mode' => 'turbo']);
    }
}
