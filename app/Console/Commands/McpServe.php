<?php

namespace App\Console\Commands;

use App\Mcp\McpServer;
use App\Mcp\ToolRegistry;
use App\Models\Instance;
use Illuminate\Console\Command;

class McpServe extends Command
{
    protected $signature = 'mcp:serve';

    protected $description = 'Run the WARM MCP server over stdio';

    public function handle(): int
    {
        (new McpServer(new ToolRegistry(Instance::default())))->run();

        return self::SUCCESS;
    }
}
