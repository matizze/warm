<?php

namespace App\Actions\Contacts;

use App\Actions\Conversations\SearchConversations;
use App\Models\Contact;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;

final class GetContact
{
    use AsAction;

    /**
     * @return array{contact: ?array<string, mixed>, conversations: list<array<string, mixed>>}
     */
    public function handle(string $contactId): array
    {
        if (! Str::isUuid($contactId)) {
            return ['contact' => null, 'conversations' => []];
        }

        $contact = Contact::find($contactId);

        return [
            'contact' => $contact?->toArray(),
            'conversations' => $contact === null ? [] : SearchConversations::run(['contactId' => $contactId]),
        ];
    }
}
