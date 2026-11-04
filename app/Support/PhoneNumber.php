<?php

namespace App\Support;

/**
 * Canonicalizes WhatsApp phone numbers for contact writes. The database keeps
 * the provider JID (E.164 digits + "@s.whatsapp.net"), the same key ingestion
 * uses for inbound messages; the user-facing E.164 form and the "@c.us" JID are
 * derived from it for responses.
 */
final class PhoneNumber
{
    private const MIN_DIGITS = 8;

    private const MAX_DIGITS = 15;

    /**
     * Returns the E.164 digits or null when the input is empty/invalid.
     * Accepts a bare number, a "+"-prefixed number, a JID or a formatted
     * number (spaces, parentheses, dots and dashes).
     */
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        // Drop a WhatsApp JID suffix (e.g. "@c.us", "@s.whatsapp.net").
        $value = preg_replace('/@.*$/u', '', $value) ?? '';

        if (preg_match('/[^0-9\s().+\-]/u', $value) === 1) {
            return null;
        }

        // A single leading "+" is the only allowed use of the sign.
        $unsigned = str_starts_with($value, '+') ? substr($value, 1) : $value;
        if (str_contains($unsigned, '+')) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if (strlen($digits) < self::MIN_DIGITS || strlen($digits) > self::MAX_DIGITS) {
            return null;
        }

        // E.164 never starts with zero: that would be a local number without DDI.
        if (str_starts_with($digits, '0')) {
            return null;
        }

        return $digits;
    }

    public static function e164(string $digits): string
    {
        return '+'.$digits;
    }

    public static function jid(string $digits): string
    {
        return $digits.'@c.us';
    }

    /**
     * Storage key shared with inbound ingestion (FindOrCreateContact).
     */
    public static function contactJid(string $digits): string
    {
        return $digits.'@s.whatsapp.net';
    }
}
