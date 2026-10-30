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
 * to the calling instance.
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

        return DB::transaction(function () use ($digits, $name, $observations, $instance) {
            $contact = $this->findByDigits($digits);
            $created = $contact === null;

            if ($created) {
                $contact = new Contact(['phone_number' => $digits]);
            } else {
                $this->assertBelongsToInstance($contact, $instance);
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
     * Existing rows may hold a formatted number, a "+" prefix or a provider
     * JID (older webhooks stored "<number>@s.whatsapp.net").
     */
    private function findByDigits(string $digits): ?Contact
    {
        return Contact::query()
            ->whereRaw("regexp_replace(phone_number, '[^0-9]', '', 'g') = ?", [$digits])
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
