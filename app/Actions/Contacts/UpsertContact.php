<?php

namespace App\Actions\Contacts;

use App\Actions\Conversations\FindOrCreateConversation;
use App\Models\Contact;
use App\Models\Instance;
use App\Support\ContactLookup;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Idempotent write of a contact into the WARM contact base.
 *
 * The contact table is global (shared by the numbers), so isolation happens at
 * the conversation layer: a contact already used by other instances is not
 * mutated from a different number, and the created conversation always belongs
 * to the calling instance. The stored key is the provider JID
 * ("<digits>@s.whatsapp.net"), the same one inbound ingestion uses.
 */
final class UpsertContact
{
    use AsAction;

    /**
     * @return array{
     *     status: string,
     *     created: bool,
     *     updated: bool,
     *     contact: array<string, mixed>
     * }
     */
    public function handle(
        string $phoneNumber,
        ?string $name = null,
        ?string $observations = null,
        ?Instance $instance = null,
    ): array {
        $digits = PhoneNumber::normalize($phoneNumber);

        if ($digits === null) {
            throw new InvalidArgumentException('Telefone inválido: informe um número com DDI/DDD (E.164).');
        }

        if ($name !== null && trim($name) === '') {
            throw new InvalidArgumentException('Nome inválido: informe um nome não vazio.');
        }

        $canonical = PhoneNumber::contactJid($digits);

        return DB::transaction(function () use ($digits, $canonical, $name, $observations, $instance) {
            $contact = ContactLookup::byPhone($digits);
            $created = $contact === null;

            if ($created) {
                $contact = Contact::firstOrCreate(['phone_number' => $canonical]);
                $created = $contact->wasRecentlyCreated;
            }

            if (! $created) {
                ContactLookup::assertWritable($contact, $instance);

                // Rows from before the canonical key (bare digits, "+" or
                // "@c.us") are rewritten so the stored key matches ingestion.
                if ($contact->phone_number !== $canonical) {
                    $contact->phone_number = $canonical;
                }
            }

            if (is_string($name) && trim($name) !== '') {
                $contact->name = trim($name);
            }

            if (is_string($observations) && trim($observations) !== '') {
                $contact->observations = trim($observations);
            }

            $contact->save();

            $conversation = $instance === null
                ? null
                : FindOrCreateConversation::run($contact->id, $instance);

            return [
                'status' => 'ok',
                'created' => $created,
                'updated' => ! $created,
                'contact' => [
                    'id' => $contact->id,
                    'name' => $contact->name,
                    'phoneNumber' => $digits,
                    'e164' => PhoneNumber::e164($digits),
                    'jid' => PhoneNumber::jid($digits),
                    'observations' => $contact->observations,
                    'instanceId' => $instance?->instance_id,
                    'conversationId' => $conversation?->id,
                ],
            ];
        });
    }
}
