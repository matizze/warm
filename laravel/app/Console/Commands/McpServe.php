<?php

namespace App\Console\Commands;

use App\Mcp\McpServer;
use App\Mcp\ToolRegistry;
use Illuminate\Console\Command;

class McpServe extends Command
{
    protected $signature = 'mcp:serve';

    protected $description = 'Run the WARM MCP server over stdio';

    public function handle(): int
    {
        (new McpServer(app(ToolRegistry::class)))->run();

        return self::SUCCESS;
    }
}
