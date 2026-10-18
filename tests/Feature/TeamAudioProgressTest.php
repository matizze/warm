<?php

namespace Tests\Feature;

use App\Actions\Progress\RecordProgressFromMessage;
use App\Contracts\MediaProcessor;
use App\Contracts\WhatsAppProvider;
use App\Data\OutgoingMessage;
use App\Data\ProviderMedia;
use App\Data\SendResult;
use App\Enums\ClientChannelRole;
use App\Enums\MessageDirection;
use App\Enums\MessageType;
use App\Enums\ProgressEntryStatus;
use App\Models\Client;
use App\Models\ClientChannel;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Group;
use App\Models\Instance;
use App\Models\Message;
use App\Models\ProgressEntry;
use App\Processing\ProcessedContent;
use App\Processing\ProcessingContext;
use App\Processing\ProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TeamAudioProgressProvider implements WhatsAppProvider
{
    public function sendMessage(OutgoingMessage $message): SendResult
    {
        return new SendResult('PENDING');
    }

    public function getMedia(string $providerMessageId, string $remoteJid, bool $fromMe): ProviderMedia
    {
        return new ProviderMedia(
            base64: base64_encode('fake-audio-bytes'),
            mimetype: 'audio/ogg',
            fileName: 'andamento.ogg',
        );
    }
}

class TeamAudioProgressTest extends TestCase
{
    use RefreshDatabase;

    private const GROUP_JID = '120363000000000000@g.us';

    private const TEAM_PHONE = '5511988887777@s.whatsapp.net';

    public const TRANSCRIPTION = 'Instalei o novo sistema e treinei a equipe do cliente.';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'warm.storage.disk' => 'local',
            'warm.processing.enabled' => true,
        ]);

        app()->instance(WhatsAppProvider::class, new TeamAudioProgressProvider);

        $processor = new class implements MediaProcessor
        {
            public function name(): string
            {
                return 'audio';
            }

            public function supports(ProcessingContext $context): bool
            {
                return $context->type === 'audio';
            }

            public function process(string $buffer, ProcessingContext $context): ?ProcessedContent
            {
                return new ProcessedContent(kind: 'transcription', text: TeamAudioProgressTest::TRANSCRIPTION);
            }
        };

        app()->instance(ProcessingService::class, new ProcessingService([$processor]));
    }

    private function groupConversation(): Conversation
    {
        $group = Group::create(['provider_group_id' => self::GROUP_JID, 'name' => 'Andamento - Cliente X']);

        return Conversation::create([
            'type' => 'group',
            'group_id' => $group->id,
            'instance_id' => Instance::defaultInstanceId(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function teamAudioPayload(string $id = 'AUD-1'): array
    {
        return [
            'event' => 'messages.upsert',
            'instance' => 'instance1',
            'data' => [
                'key' => [
                    'remoteJid' => self::GROUP_JID,
                    'fromMe' => true,
                    'id' => $id,
                    'participant' => self::TEAM_PHONE,
                ],
                'pushName' => 'Ana Equipe',
                'message' => ['audioMessage' => ['url' => 'https://cdn/a.ogg', 'mimetype' => 'audio/ogg']],
                'messageType' => 'audioMessage',
                'messageTimestamp' => 1758000000,
                'participant' => self::TEAM_PHONE,
            ],
        ];
    }

    private function headers(): array
    {
        return ['x-webhook-token' => 'test-token'];
    }

    public function test_team_audio_is_transcribed_and_linked_to_the_client(): void
    {
        $conversation = $this->groupConversation();
        $client = Client::factory()->create(['name' => 'Cliente X']);
        ClientChannel::create([
            'client_id' => $client->id,
            'conversation_id' => $conversation->id,
            'role' => ClientChannelRole::Progress,
            'active' => true,
        ]);

        $this->withHeaders($this->headers())
            ->postJson('/webhook', $this->teamAudioPayload())
            ->assertOk()
            ->assertJson(['status' => 'ok', 'duplicate' => false]);

        $message = Message::where('provider_message_id', 'AUD-1')->firstOrFail();
        $this->assertSame(MessageDirection::Outbound, $message->direction);
        $this->assertSame(MessageType::Audio, $message->type);

        $this->assertDatabaseHas('media', [
            'message_id' => $message->id,
            'extracted_text' => self::TRANSCRIPTION,
        ]);

        $entry = ProgressEntry::firstOrFail();
        $this->assertSame($client->id, $entry->client_id);
        $this->assertSame(ProgressEntryStatus::Recorded, $entry->status);
        $this->assertSame(self::TRANSCRIPTION, $entry->content);
        $this->assertSame($message->id, $entry->source_message_id);
        $this->assertSame($conversation->id, $entry->conversation_id);
        $this->assertSame($conversation->instance_id, $entry->instance_id);
        $this->assertNotNull($entry->occurred_at);
        $this->assertSame(
            Contact::where('phone_number', self::TEAM_PHONE)->value('id'),
            $entry->author_contact_id,
        );

        Storage::disk('local')->deleteDirectory("media/{$message->id}");
    }

    public function test_reprocessing_the_same_audio_does_not_duplicate(): void
    {
        $conversation = $this->groupConversation();
        $client = Client::factory()->create();
        ClientChannel::create([
            'client_id' => $client->id,
            'conversation_id' => $conversation->id,
            'role' => ClientChannelRole::Progress,
            'active' => true,
        ]);

        $payload = $this->teamAudioPayload();
        $this->withHeaders($this->headers())->postJson('/webhook', $payload)->assertOk();
        $this->withHeaders($this->headers())->postJson('/webhook', $payload)->assertOk();

        $message = Message::where('provider_message_id', 'AUD-1')->firstOrFail();

        RecordProgressFromMessage::run($message->id);
        RecordProgressFromMessage::run($message->id);

        $this->assertDatabaseCount('progress_entries', 1);

        Storage::disk('local')->deleteDirectory("media/{$message->id}");
    }

    public function test_audio_without_identifiable_client_is_recorded_as_pending(): void
    {
        $conversation = $this->groupConversation();
        ClientChannel::create([
            'client_id' => null,
            'conversation_id' => $conversation->id,
            'role' => ClientChannelRole::Progress,
            'active' => true,
        ]);

        $this->withHeaders($this->headers())
            ->postJson('/webhook', $this->teamAudioPayload())
            ->assertOk();

        $message = Message::where('provider_message_id', 'AUD-1')->firstOrFail();
        $entry = ProgressEntry::firstOrFail();

        $this->assertNull($entry->client_id);
        $this->assertSame(ProgressEntryStatus::Pending, $entry->status);
        $this->assertSame(self::TRANSCRIPTION, $entry->content);
        $this->assertDatabaseCount('progress_entries', 1);

        Storage::disk('local')->deleteDirectory("media/{$message->id}");
    }

    public function test_audio_outside_a_progress_channel_creates_no_entry(): void
    {
        $this->groupConversation();

        $this->withHeaders($this->headers())
            ->postJson('/webhook', $this->teamAudioPayload())
            ->assertOk();

        $message = Message::where('provider_message_id', 'AUD-1')->firstOrFail();
        $this->assertDatabaseCount('progress_entries', 0);

        Storage::disk('local')->deleteDirectory("media/{$message->id}");
    }

    public function test_queries_by_client_day_and_pending(): void
    {
        $contact = Contact::create(['phone_number' => self::TEAM_PHONE, 'name' => 'Ana']);
        $conversation = $this->groupConversation();
        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();

        $entry = function (Client $client, string $providerId, \DateTimeInterface $at) use ($contact, $conversation): ProgressEntry {
            $message = Message::create([
                'conversation_id' => $conversation->id,
                'provider_message_id' => $providerId,
                'direction' => 'inbound',
                'type' => 'audio',
                'sender_contact_id' => $contact->id,
                'content' => null,
                'metadata' => [],
                'status' => 'sent',
            ]);

            return ProgressEntry::create([
                'client_id' => $client->id,
                'conversation_id' => $message->conversation_id,
                'instance_id' => $message->instance_id,
                'source_message_id' => $message->id,
                'author_contact_id' => $message->sender_contact_id,
                'content' => 'andamento '.$providerId,
                'status' => ProgressEntryStatus::Recorded,
                'occurred_at' => $at,
                'metadata' => [],
            ]);
        };

        $entry($clientA, 'Q-1', now());
        $entry($clientA, 'Q-2', now()->subDay());
        $entry($clientB, 'Q-3', now());

        ProgressEntry::create([
            'client_id' => null,
            'conversation_id' => $conversation->id,
            'instance_id' => $conversation->instance_id,
            'source_message_id' => Message::create([
                'conversation_id' => $conversation->id,
                'provider_message_id' => 'Q-4',
                'direction' => 'inbound',
                'type' => 'audio',
                'sender_contact_id' => $contact->id,
                'content' => null,
                'metadata' => [],
                'status' => 'sent',
            ])->id,
            'author_contact_id' => $contact->id,
            'content' => 'andamento sem cliente',
            'status' => ProgressEntryStatus::Pending,
            'occurred_at' => now(),
            'metadata' => [],
        ]);

        $this->assertSame(1, ProgressEntry::forClient($clientA)->onDay(now())->count());
        $this->assertSame(2, ProgressEntry::forClient($clientA)->count());
        $this->assertSame(0, ProgressEntry::forClient($clientB)->onDay(now()->subDay())->count());
        $this->assertSame(1, ProgressEntry::forClient($clientB)->onDay(now())->count());
        $this->assertSame(4, ProgressEntry::recorded()->count() + ProgressEntry::pending()->count());
        $this->assertSame(3, ProgressEntry::recorded()->count());
        $this->assertSame(1, ProgressEntry::pending()->count());
    }
}
