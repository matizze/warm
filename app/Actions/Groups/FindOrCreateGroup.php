<?php

namespace App\Actions\Groups;

use App\Models\Group;
use Lorisleiva\Actions\Concerns\AsAction;

final class FindOrCreateGroup
{
    use AsAction;

    public function handle(string $providerGroupId): Group
    {
        return Group::firstOrCreate(['provider_group_id' => $providerGroupId]);
    }
}
