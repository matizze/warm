<?php

namespace Tests\Unit;

use App\Contracts\WhatsAppProvider;
use App\Data\OutgoingMessage;
use App\Data\ProviderMedia;
use App\Data\SendResult;
use App\Exceptions\ProviderException;
use App\Mcp\McpServer;
use App\Mcp\ToolRegistry;
use App\Models\Contact;
use App\Models\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class FailingSendProvider implements WhatsAppProvider
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

class McpServerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function callTool(array $params): array
    {
        $method = (new ReflectionMethod(McpServer::class, 'callTool'))->getClosure(new McpServer(new ToolRegistry));

        return $method($params);
    }

    public function test_message_send_maps_provider_failure_to_status_error_contract(): void
    {
        $contact = Contact::create(['phone_number' => '5511888888888', 'name' => 'Ana']);
        $conversation = Conversation::create(['type' => 'contact', 'contact_id' => $contact->id]);

        app()->instance(WhatsAppProvider::class, new FailingSendProvider);

        $result = $this->callTool([
            'name' => 'message.send',
            'arguments' => ['conversationId' => $conversation->id, 'content' => 'oi'],
        ]);

        $this->assertTrue($result['isError']);
        $payload = json_decode($result['content'][0]['text'], true);
        $this->assertSame('error', $payload['status']);
        $this->assertSame('provedor fora do ar', $payload['error']);
    }

    public function test_unexpected_tool_failure_stays_a_generic_error(): void
    {
        $result = $this->callTool([
            'name' => 'media.download',
            'arguments' => ['messageId' => '00000000-0000-0000-0000-000000000000'],
        ]);

        $this->assertTrue($result['isError']);
        $this->assertArrayHasKey('error', json_decode($result['content'][0]['text'], true));
    }

    public function test_success_result_with_status_error_is_marked_as_error(): void
    {
        $result = $this->callTool([
            'name' => 'message.send',
            'arguments' => ['conversationId' => '00000000-0000-0000-0000-000000000000', 'content' => 'oi'],
        ]);

        $this->assertTrue($result['isError']);
        $payload = json_decode($result['content'][0]['text'], true);
        $this->assertSame('error', $payload['status']);
        $this->assertStringContainsString('conversation not found', $payload['error']);
    }
}
