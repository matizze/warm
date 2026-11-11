<?php

namespace App\Support;

use App\Models\Contact;
use App\Models\Instance;
use InvalidArgumentException;

/**
 * Contact resolution shared by the write tools. The stored key is the provider
 * JID ("<digits>@s.whatsapp.net"), the same one inbound ingestion uses, but
 * legacy rows (bare digits, "+" prefix or "@c.us") are still found.
 */
final class ContactLookup
{
    /**
     * Looks the contact up by the canonical key first (indexed, one row) and
     * then by the legacy formats. Ties are broken deterministically so a
     * duplicated legacy row never makes the write pick an arbitrary contact.
     */
    public static function byPhone(string $digits): ?Contact
    {
        $canonical = PhoneNumber::contactJid($digits);

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
    public static function assertWritable(Contact $contact, ?Instance $instance): void
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
