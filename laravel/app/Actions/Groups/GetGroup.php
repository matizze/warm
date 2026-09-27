<?php

namespace App\Actions\Groups;

use App\Models\Group;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;

final class GetGroup
{
    use AsAction;

    /**
     * @return array<string, mixed>|null
     */
    public function handle(string $groupId): ?array
    {
        return Str::isUuid($groupId) ? Group::find($groupId)?->toArray() : null;
    }
}
