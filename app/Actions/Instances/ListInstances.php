<?php

namespace App\Actions\Instances;

use App\Models\Instance;
use Illuminate\Console\Command;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Lists the registered instances without revealing their credentials.
 */
final class ListInstances
{
    use AsAction;

    public string $commandSignature = 'warm:instance:list';

    public string $commandDescription = 'List registered WhatsApp instances (credentials are not shown)';

    public function asCommand(Command $command): int
    {
        $rows = Instance::query()->orderByDesc('is_default')->orderBy('instance_id')->get()
            ->map(fn (Instance $instance): array => [
                $instance->instance_id,
                $instance->isDefault() ? 'yes' : '',
                $instance->active ? 'yes' : 'no',
                $instance->evolution_url,
                filled($instance->webhook_token) ? 'set' : 'MISSING',
                filled($instance->mcp_token) ? 'set' : 'MISSING',
            ]);

        $command->table(['Instance', 'Default', 'Active', 'Evolution URL', 'Webhook token', 'MCP token'], $rows);

        return Command::SUCCESS;
    }
}
