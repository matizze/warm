<?php

namespace App\Actions\Contacts;

use App\Models\Contact;
use App\Support\PhoneNumber;
use Illuminate\Database\Eloquent\Builder;
use Lorisleiva\Actions\Concerns\AsAction;

final class SearchContacts
{
    use AsAction;

    /**
     * @param  array<string, mixed>  $filters
     * @return list<array<string, mixed>>
     */
    public function handle(array $filters): array
    {
        return Contact::query()
            ->when($filters['name'] ?? null, fn (Builder $q, $v) => $q->where('name', 'ilike', "%{$v}%"))
            ->when($filters['phoneNumber'] ?? null, fn (Builder $q, $v) => $this->applyPhoneFilter($q, (string) $v))
            ->when($filters['search'] ?? null, function (Builder $q, $v) {
                $q->where(function (Builder $inner) use ($v) {
                    $inner->where('name', 'ilike', "%{$v}%")
                        ->orWhere('phone_number', 'ilike', "%{$v}%");
                });
            })
            ->orderBy('name')
            ->limit((int) ($filters['limit'] ?? 50))
            ->offset((int) ($filters['offset'] ?? 0))
            ->get()
            ->map->toArray()
            ->all();
    }

    /**
     * Matches the same number regardless of the stored key format (canonical
     * provider JID, bare digits, "+" prefix or legacy "@c.us").
     *
     * @param  Builder<Contact>  $query
     */
    private function applyPhoneFilter(Builder $query, string $value): void
    {
        $digits = PhoneNumber::normalize($value);

        if ($digits === null) {
            $query->where('phone_number', $value);

            return;
        }

        $query->whereIn('phone_number', [
            PhoneNumber::contactJid($digits),
            $digits,
            PhoneNumber::e164($digits),
            PhoneNumber::jid($digits),
        ]);
    }
}
