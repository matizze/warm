<?php

namespace Tests\Feature;

use App\Actions\Messages\SendMessage;
use App\Contracts\WhatsAppProvider;
use App\Data\OutgoingMessage;
use App\Data\ProviderMedia;
use App\Data\SendResult;
use App\Exceptions\ProviderException;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Group;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FakeWhatsAppProvider implements WhatsAppProvider
{
    public ?string $recipient = null;

    public function sendMessage(OutgoingMessage $message): SendResult
    {
        $this->recipient = $message->recipient;

        return new SendResult('PENDING', 'prov-1');
    }

    public function getMedia(string $providerMessageId, string $remoteJid, bool $fromMe): ProviderMedia
    {
        return new ProviderMedia('');
    }
}

class ThrowingWhatsAppProvider implements WhatsAppProvider
{
    public function sendMessage(OutgoingMessage $message): SendResult
    {
        throw new ProviderException('provedor fora do ar');
    }

    public function getMedia(string $providerMessageId, string $remoteJid, bool $fromMe): ProviderMedia
    {
        return new ProviderMedia('');
    }
}

class ConversationIdentityTest extends TestCase
{
    use RefreshDatabase;

    private function headers(): array
    {
        return ['x-webhook-token' => 'test-token'];
    }

    /**
     * @return array<string, mixed>
     */
    private function upsert(string $id, string $remoteJid, ?string $participant = null): array
    {
        $data = [
            'key' => ['remoteJid' => $remoteJid, 'fromMe' => false, 'id' => $id],
            'pushName' => 'Henrique',
            'message' => ['conversation' => 'oi'],
            'messageType' => 'conversation',
            'messageTimestamp' => 1758000000,
        ];

        if ($participant !== null) {
            $data['participant'] = $participant;
        }

        return ['event' => 'messages.upsert', 'data' => $data];
    }

    public function test_group_message_creates_a_group_conversation(): void
    {
        Queue::fake();

        $this->withHeaders($this->headers())
            ->postJson('/webhook', $this->upsert('G1', '111@g.us', '5527999999999@s.whatsapp.net'))
            ->assertOk();

        $conversation = Conversation::first();
        $this->assertNotNull($conversation->group_id);
        $this->assertNull($conversation->contact_id);
        $this->assertSame('111@g.us', $conversation->group->provider_group_id);
    }

    public function test_same_participant_in_two_groups_stays_in_two_conversations(): void
    {
        Queue::fake();

        $participant = '5527999999999@s.whatsapp.net';

        $this->withHeaders($this->headers())
            ->postJson('/webhook', $this->upsert('G1', '111@g.us', $participant))->assertOk();
        $this->withHeaders($this->headers())
            ->postJson('/webhook', $this->upsert('G2', '222@g.us', $participant))->assertOk();

        $this->assertSame(1, Contact::count());
        $this->assertSame(2, Group::count());
        $this->assertSame(2, Conversation::count());
        $this->assertSame(2, Message::count());
    }

    public function test_private_message_creates_a_contact_conversation(): void
    {
        Queue::fake();

        $this->withHeaders($this->headers())
            ->postJson('/webhook', $this->upsert('P1', '5527999999999@s.whatsapp.net'))
            ->assertOk();

        $conversation = Conversation::first();
        $this->assertNotNull($conversation->contact_id);
        $this->assertNull($conversation->group_id);
    }

    public function test_send_resolves_the_recipient_from_the_contact(): void
    {
        $contact = Contact::create(['phone_number' => '5511888888888', 'name' => 'Ana']);
        $conversation = Conversation::create(['type' => 'contact', 'contact_id' => $contact->id]);

        $fake = new FakeWhatsAppProvider;
        app()->instance(WhatsAppProvider::class, $fake);

        $result = SendMessage::run(['conversationId' => $conversation->id, 'content' => 'oi']);

        $this->assertSame('PENDING', $result['status']);
        $this->assertSame('5511888888888', $fake->recipient);
    }

    public function test_send_resolves_the_recipient_from_the_group(): void
    {
        $group = Group::create(['provider_group_id' => '999@g.us']);
        $conversation = Conversation::create(['type' => 'group', 'group_id' => $group->id]);

        $fake = new FakeWhatsAppProvider;
        app()->instance(WhatsAppProvider::class, $fake);

        $result = SendMessage::run(['conversationId' => $conversation->id, 'content' => 'oi']);

        $this->assertSame('PENDING', $result['status']);
        $this->assertSame('999@g.us', $fake->recipient);
    }

    public function test_send_fails_when_the_conversation_does_not_exist(): void
    {
        $result = SendMessage::run(['conversationId' => 'nope', 'content' => 'oi']);

        $this->assertSame('error', $result['status']);
    }

    public function test_send_rethrows_the_provider_failure(): void
    {
        $contact = Contact::create(['phone_number' => '5511888888888', 'name' => 'Ana']);
        $conversation = Conversation::create(['type' => 'contact', 'contact_id' => $contact->id]);

        app()->instance(WhatsAppProvider::class, new ThrowingWhatsAppProvider);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('provedor fora do ar');

        SendMessage::run(['conversationId' => $conversation->id, 'content' => 'oi']);
    }
}
