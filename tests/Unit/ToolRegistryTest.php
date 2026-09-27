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

        app(ToolRegistry::class)->call('media.download', ['messageId' => 'nope']);
    }

    public function test_rejects_an_unknown_tool(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(ToolRegistry::class)->call('nope', []);
    }

    public function test_rejects_an_out_of_range_limit(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(ToolRegistry::class)->call('contact.search', ['limit' => 0]);
    }

    public function test_rejects_an_unknown_search_mode(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(ToolRegistry::class)->call('message.search', ['mode' => 'turbo']);
    }
}
