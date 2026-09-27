<?php

namespace App\Actions\Groups;

use App\Models\Group;
use Illuminate\Database\Eloquent\Builder;
use Lorisleiva\Actions\Concerns\AsAction;

final class SearchGroups
{
    use AsAction;

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function handle(array $filters): array
    {
        return Group::query()
            ->when($filters['name'] ?? null, fn (Builder $q, $v) => $q->where('name', 'ilike', "%{$v}%"))
            ->when($filters['search'] ?? null, function (Builder $q, $v) {
                $q->where(function (Builder $inner) use ($v) {
                    $inner->where('name', 'ilike', "%{$v}%")
                        ->orWhere('description', 'ilike', "%{$v}%");
                });
            })
            ->orderBy('name')
            ->limit((int) ($filters['limit'] ?? 50))
            ->offset((int) ($filters['offset'] ?? 0))
            ->get()
            ->map->toArray()
            ->all();
    }
}
