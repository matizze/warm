<?php

namespace App\Actions\Instances;

use App\Models\Instance;
use Illuminate\Console\Command;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Replaces an instance's webhook and/or MCP token. The old token stops working
 * immediately, so Evolution and the MCP clients must be updated right after.
 */
final class RotateInstanceTokens
{
    use AsAction;

    public string $commandSignature = 'warm:instance:rotate-token
        {instance_id : Instance whose tokens are replaced}
        {--webhook : Rotate only the webhook token}
        {--mcp : Rotate only the MCP token}';

    public string $commandDescription = 'Generate new webhook and/or MCP tokens for an instance';

    public function handle(Instance $instance, bool $webhook = true, bool $mcp = true): Instance
    {
        if ($webhook) {
            $instance->webhook_token = Instance::generateToken();
        }

        if ($mcp) {
            $instance->mcp_token = Instance::generateToken();
        }

        $instance->save();

        return $instance;
    }

    public function asCommand(Command $command): int
    {
        $instance = Instance::query()->find((string) $command->argument('instance_id'));

        if ($instance === null) {
            $command->error("Instance [{$command->argument('instance_id')}] not found.");

            return Command::FAILURE;
        }

        $onlyWebhook = (bool) $command->option('webhook');
        $onlyMcp = (bool) $command->option('mcp');
        $rotateBoth = ! $onlyWebhook && ! $onlyMcp;

        $this->handle($instance, $rotateBoth || $onlyWebhook, $rotateBoth || $onlyMcp);

        $command->info("Tokens rotated for [{$instance->instance_id}]. The previous ones no longer work.");
        ShowInstanceTokens::make()->print($command, $instance);

        return Command::SUCCESS;
    }
}
