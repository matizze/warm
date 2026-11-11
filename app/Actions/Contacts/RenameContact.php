<?php

namespace App\Actions\Contacts;

use App\Models\Contact;
use App\Models\Instance;
use App\Support\ContactLookup;
use App\Support\PhoneNumber;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Renames an existing contact, touching only its name.
 *
 * Unlike the upsert it never creates a contact, conversation or message, and
 * it is idempotent: renaming to the name the contact already has writes
 * nothing and reports `updated: false`.
 */
final class RenameContact
{
    use AsAction;

    /**
     * @return array{
     *     status: string,
     *     updated: bool,
     *     contact: array<string, mixed>
     * }
     */
    public function handle(
        ?string $contactId,
        ?string $phoneNumber,
        string $name,
        ?Instance $instance = null,
    ): array {
        $name = trim($name);

        if ($name === '') {
            throw new InvalidArgumentException('Nome inválido: informe um nome não vazio.');
        }

        $contact = $this->resolve($contactId, $phoneNumber);
        ContactLookup::assertWritable($contact, $instance);

        $updated = $contact->name !== $name;

        if ($updated) {
            $contact->name = $name;
            $contact->name_is_custom = true;
            $contact->save();
        }

        $digits = PhoneNumber::normalize($contact->phone_number);

        return [
            'status' => 'ok',
            'updated' => $updated,
            'contact' => [
                'id' => $contact->id,
                'name' => $contact->name,
                'phoneNumber' => $digits ?? $contact->phone_number,
                'e164' => $digits === null ? null : PhoneNumber::e164($digits),
                'jid' => $digits === null ? null : PhoneNumber::jid($digits),
            ],
        ];
    }

    /**
     * Resolves the target contact from either the tool identifier. Reading by
     * `contactId` wins when both are informed.
     */
    private function resolve(?string $contactId, ?string $phoneNumber): Contact
    {
        if (is_string($contactId) && $contactId !== '') {
            $contact = Contact::find($contactId);

            if ($contact === null) {
                throw new InvalidArgumentException("contact {$contactId} not found");
            }

            return $contact;
        }

        $digits = PhoneNumber::normalize($phoneNumber);

        if ($digits === null) {
            throw new InvalidArgumentException('Telefone inválido: informe um número com DDI/DDD (E.164).');
        }

        return ContactLookup::byPhone($digits)
            ?? throw new InvalidArgumentException('Contato não encontrado para o telefone informado.');
    }
}
