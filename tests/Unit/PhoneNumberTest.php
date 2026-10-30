<?php

namespace Tests\Unit;

use App\Support\PhoneNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PhoneNumberTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function validNumbers(): array
    {
        return [
            'bare' => ['5511988887777', '5511988887777'],
            'e164' => ['+5511988887777', '5511988887777'],
            'formatted' => ['+55 (11) 98888-7777', '5511988887777'],
            'dots' => ['+55.11.98888.7777', '5511988887777'],
            'jid' => ['5511988887777@c.us', '5511988887777'],
            'legacy jid' => ['5511988887777@s.whatsapp.net', '5511988887777'],
            'minimum' => ['12345678', '12345678'],
            'maximum' => ['123456789012345', '123456789012345'],
        ];
    }

    #[DataProvider('validNumbers')]
    public function test_normalizes_valid_numbers(string $input, string $expected): void
    {
        $this->assertSame($expected, PhoneNumber::normalize($input));
    }

    /**
     * @return array<string, array{?string}>
     */
    public static function invalidNumbers(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'blank' => ['   '],
            'letters' => ['abc'],
            'too short' => ['1234567'],
            'too long' => ['1234567890123456'],
            'local without ddi' => ['011988887777'],
            'double plus' => ['++5511988887777'],
            'plus in the middle' => ['55+11988887777'],
            'invalid characters' => ['5511#988887777'],
        ];
    }

    #[DataProvider('invalidNumbers')]
    public function test_rejects_invalid_numbers(?string $input): void
    {
        $this->assertNull(PhoneNumber::normalize($input));
    }

    public function test_derives_e164_and_jid(): void
    {
        $this->assertSame('+5511988887777', PhoneNumber::e164('5511988887777'));
        $this->assertSame('5511988887777@c.us', PhoneNumber::jid('5511988887777'));
    }
}
