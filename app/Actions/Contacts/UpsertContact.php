<?php

namespace App\Actions\Contacts;

use App\Actions\Conversations\FindOrCreateConversation;
use App\Models\Contact;
use App\Models\Instance;
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

        $canonical = PhoneNumber::contactJid($digits);

        return DB::transaction(function () use ($digits, $canonical, $name, $observations, $instance) {
            $contact = $this->findContact($digits, $canonical);
            $created = $contact === null;

            if ($created) {
                $contact = Contact::firstOrCreate(['phone_number' => $canonical]);
                $created = $contact->wasRecentlyCreated;
            }

            if (! $created) {
                $this->assertBelongsToInstance($contact, $instance);

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

    /**
     * Looks the contact up by the canonical key first (indexed, one row) and
     * then by the legacy formats. Ties are broken deterministically so a
     * duplicated legacy row never makes the upsert pick an arbitrary contact.
     */
    private function findContact(string $digits, string $canonical): ?Contact
    {
        $contact = Contact::query()
            ->where('phone_number', $canonical)
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();

        if ($contact !== null) {
            return $contact;
        }

        $legacy = [$digits, '+'.$digits, PhoneNumber::jid($digits)];

        return Contact::query()
            ->whereIn('phone_number', $legacy)
            ->orderByRaw(
                'CASE phone_number WHEN ? THEN 0 WHEN ? THEN 1 WHEN ? THEN 2 ELSE 3 END',
                $legacy,
            )
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();
    }

    /**
     * A contact already used by other numbers is not writable from this one.
     * A contact without conversations can be adopted by the calling instance.
     */
    private function assertBelongsToInstance(Contact $contact, ?Instance $instance): void
    {
        if ($instance === null) {
            return;
        }

        $conversations = $contact->conversations();

        if ($conversations->clone()->exists()
            && ! $conversations->clone()->where('instance_id', $instance->instance_id)->exists()) {
            throw new InvalidArgumentException("contact {$contact->id} belongs to another instance");
        }
    }
}
