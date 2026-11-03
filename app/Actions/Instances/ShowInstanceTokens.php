<?php

namespace App\Actions\Instances;

use App\Models\Instance;
use Illuminate\Console\Command;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Prints an instance's endpoints and tokens, so an operator can configure the
 * Evolution webhook and the MCP clients.
 */
final class ShowInstanceTokens
{
    use AsAction;

    public string $commandSignature = 'warm:instance:tokens
        {instance_id : Instance whose endpoints and tokens are shown}';

    public string $commandDescription = 'Show the webhook/MCP endpoints and tokens of an instance';

    public function print(Command $command, Instance $instance): void
    {
        $command->table(['', 'URL', 'Token'], [
            ['Webhook', route('instance.webhook', $instance->instance_id), (string) $instance->webhook_token],
            ['MCP', route('instance.mcp', $instance->instance_id), (string) $instance->mcp_token],
        ]);
    }

    public function asCommand(Command $command): int
    {
        $instance = Instance::query()->find((string) $command->argument('instance_id'));

        if ($instance === null) {
            $command->error("Instance [{$command->argument('instance_id')}] not found.");

            return Command::FAILURE;
        }

        $this->print($command, $instance);

        return Command::SUCCESS;
    }
}
