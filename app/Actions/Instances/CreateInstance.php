<?php

namespace App\Actions\Instances;

use App\Models\Instance;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Registers a WhatsApp (Evolution) instance with freshly generated webhook and
 * MCP tokens. The tokens are only shown once, when the command runs.
 */
final class CreateInstance
{
    use AsAction;

    public string $commandSignature = 'warm:instance:create
        {instance_id : Exact name of the instance in Evolution}
        {--url= : Evolution base URL}
        {--api-key= : Evolution API key (prompted when omitted)}
        {--default : Make it the default instance (legacy /webhook and /mcp routes)}';

    public string $commandDescription = 'Register a WhatsApp instance and generate its webhook/MCP tokens';

    public function handle(string $instanceId, string $evolutionUrl, string $apiKey, bool $isDefault = false): Instance
    {
        if ($instanceId === '' || $evolutionUrl === '' || $apiKey === '') {
            throw new InvalidArgumentException('Instance id, Evolution URL and API key are required.');
        }

        if (Instance::query()->whereKey($instanceId)->exists()) {
            throw new InvalidArgumentException("Instance [{$instanceId}] already exists.");
        }

        return DB::transaction(function () use ($instanceId, $evolutionUrl, $apiKey, $isDefault): Instance {
            if ($isDefault) {
                Instance::query()->where('is_default', true)->update(['is_default' => false]);
            }

            return Instance::create([
                'instance_id' => $instanceId,
                'evolution_url' => rtrim($evolutionUrl, '/'),
                'api_key' => $apiKey,
                'webhook_token' => Instance::generateToken(),
                'mcp_token' => Instance::generateToken(),
                'is_default' => $isDefault,
                'active' => true,
            ]);
        });
    }

    public function asCommand(Command $command): int
    {
        $apiKey = (string) ($command->option('api-key') ?: $command->secret('Evolution API key'));

        try {
            $instance = $this->handle(
                (string) $command->argument('instance_id'),
                (string) $command->option('url'),
                $apiKey,
                (bool) $command->option('default'),
            );
        } catch (InvalidArgumentException $e) {
            $command->error($e->getMessage());

            return Command::FAILURE;
        }

        $command->info("Instance [{$instance->instance_id}] created".($instance->isDefault() ? ' as default.' : '.'));
        ShowInstanceTokens::make()->print($command, $instance);

        return Command::SUCCESS;
    }
}
