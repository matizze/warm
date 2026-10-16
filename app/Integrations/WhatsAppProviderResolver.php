<?php

namespace App\Integrations;

use App\Contracts\WhatsAppProvider;
use App\Integrations\Evolution\EvolutionProvider;
use App\Models\Instance;

/**
 * Resolves the WhatsApp provider for a given instance.
 *
 * The default instance keeps resolving through the container binding so test
 * doubles and future provider swaps stay a single override; every other
 * instance gets its own Evolution adapter built from its stored credentials.
 */
final class WhatsAppProviderResolver
{
    public function forInstance(?Instance $instance): WhatsAppProvider
    {
        $instance ??= Instance::default();

        if ($instance === null || $instance->isDefault()) {
            return app(WhatsAppProvider::class);
        }

        return new EvolutionProvider($instance->providerConfig());
    }
}
