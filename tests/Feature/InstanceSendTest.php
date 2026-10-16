<?php

namespace Tests\Feature;

use App\Actions\Messages\SendMessage;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Instance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InstanceSendTest extends TestCase
{
    use RefreshDatabase;

    private function makeInstance(string $id, string $url, string $apiKey): Instance
    {
        return Instance::factory()->create([
            'instance_id' => $id,
            'evolution_url' => $url,
            'api_key' => $apiKey,
        ]);
    }

    private function conversationFor(string $instanceId, string $phone): Conversation
    {
        $contact = Contact::create(['phone_number' => $phone, 'name' => 'Ana']);

        return Conversation::create([
            'type' => 'contact',
            'contact_id' => $contact->id,
            'instance_id' => $instanceId,
        ]);
    }

    public function test_each_conversation_replies_through_its_own_instance(): void
    {
        Http::preventStrayRequests();

        Http::fake([
            'https://evolution-support.test/*' => Http::response(['status' => 'PENDING', 'key' => ['id' => 'srv-1']]),
            'https://evolution-personal.test/*' => Http::response(['status' => 'PENDING', 'key' => ['id' => 'srv-2']]),
        ]);

        $this->makeInstance('support', 'https://evolution-support.test', 'key-support');
        $this->makeInstance('personal', 'https://evolution-personal.test', 'key-personal');

        $supportConversation = $this->conversationFor('support', '5511999999999');
        $personalConversation = $this->conversationFor('personal', '5521888888888');

        $supportResult = SendMessage::run(['conversationId' => $supportConversation->id, 'content' => 'oi do suporte']);
        $personalResult = SendMessage::run(['conversationId' => $personalConversation->id, 'content' => 'oi do pessoal']);

        $this->assertSame('srv-1', $supportResult['providerMessageId']);
        $this->assertSame('srv-2', $personalResult['providerMessageId']);

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/message/sendText/support')
            && $request['number'] === '5511999999999'
            && $request->header('apikey')[0] === 'key-support');

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/message/sendText/personal')
            && $request['number'] === '5521888888888'
            && $request->header('apikey')[0] === 'key-personal');
    }
}
