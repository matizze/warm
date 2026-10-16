<?php

namespace App\Actions\Conversations;

use App\Models\Conversation;
use Illuminate\Database\Eloquent\Builder;
use Lorisleiva\Actions\Concerns\AsAction;

final class SearchConversations
{
    use AsAction;

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function handle(array $filters): array
    {
        $term = $filters['name'] ?? $filters['search'] ?? null;

        return Conversation::query()
            ->when($filters['instance_id'] ?? null, fn (Builder $q, $v) => $q->where('instance_id', $v))
            ->when($filters['contactId'] ?? null, fn (Builder $q, $v) => $q->where('contact_id', $v))
            ->when($filters['groupId'] ?? null, fn (Builder $q, $v) => $q->where('group_id', $v))
            ->when($term ?? null, function (Builder $q, $v) {
                $q->where(function (Builder $inner) use ($v) {
                    $inner->whereRaw('id::text ILIKE ?', ["%{$v}%"])
                        ->orWhereHas('contact', fn (Builder $c) => $c->where('name', 'ilike', "%{$v}%"))
                        ->orWhereHas('group', fn (Builder $g) => $g->where('name', 'ilike', "%{$v}%"));
                });
            })
            ->orderByRaw('last_message_at DESC NULLS LAST')
            ->limit((int) ($filters['limit'] ?? 50))
            ->offset((int) ($filters['offset'] ?? 0))
            ->get()
            ->map->toArray()
            ->all();
    }
}
