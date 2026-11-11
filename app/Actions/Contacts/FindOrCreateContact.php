<?php

namespace App\Actions\Contacts;

use App\Models\Contact;
use Lorisleiva\Actions\Concerns\AsAction;

final class FindOrCreateContact
{
    use AsAction;

    public function handle(string $phoneNumber, ?string $name = null): Contact
    {
        $contact = Contact::firstOrNew(['phone_number' => $phoneNumber]);

        if (! $contact->exists) {
            $contact->observations = '';
        }

        if (is_string($name) && $name !== '') {
            $contact->push_name = $name;

            // A name edited by the operator (rename/upsert) wins over the
            // provider pushName; an unnamed contact keeps following it.
            if (! $contact->name_is_custom || $contact->name === null || $contact->name === '') {
                $contact->name = $name;
            }
        }

        $contact->save();

        return $contact;
    }
}
