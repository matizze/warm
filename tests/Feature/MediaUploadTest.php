<?php

namespace Tests\Feature;

use App\Actions\Media\UploadMedia;
use App\Actions\Messages\SendMessage;
use App\Mcp\ToolRegistry;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Instance;
use App\Models\Media;
use App\Support\Media\HostResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

class MediaUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'warm.storage.disk' => 'local',
            'warm.processing.enabled' => false,
        ]);

        Storage::disk('local')->deleteDirectory('media');
    }

    protected function tearDown(): void
    {
        Storage::disk('local')->deleteDirectory('media');

        parent::tearDown();
    }

    /**
     * Pins the DNS answers so the SSRF guard is exercised without the network.
     *
     * @param  array<string, list<string>>  $overrides
     */
    private function resolveHosts(array $overrides = []): void
    {
        $this->app->instance(HostResolver::class, new class($overrides) extends HostResolver
        {
            /**
             * @param  array<string, list<string>>  $overrides
             */
            public function __construct(private readonly array $overrides) {}

            public function resolve(string $host): array
            {
                if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
                    return [$host];
                }

                return $this->overrides[$host] ?? ['93.184.216.34'];
            }
        });
    }

    private function pdf(): string
    {
        return "%PDF-1.4\n1 0 obj\n<</Type /Catalog /Pages 2 0 R>>\nendobj\n"
            ."2 0 obj\n<</Type /Pages /Kids [3 0 R] /Count 1>>\nendobj\n"
            ."3 0 obj\n<</Type /Page /Parent 2 0 R /MediaBox [0 0 200 200]>>\nendobj\n"
            ."xref\ntrailer\n<</Root 1 0 R>>\n%%EOF\n";
    }

    private function png(): string
    {
        return (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+ip1sAAAAASUVORK5CYII=',
            true,
        );
    }

    public function test_uploads_a_media_downloaded_from_a_url(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://files.example.com/contrato.pdf' => Http::response($this->pdf(), 200, [
                'Content-Type' => 'application/pdf',
            ]),
        ]);
        $this->resolveHosts();

        $result = UploadMedia::run([
            'type' => 'document',
            'url' => 'https://files.example.com/contrato.pdf',
        ]);

        $this->assertSame('contrato.pdf', $result['filename']);
        $this->assertSame('application/pdf', $result['mimeType']);

        $media = Media::findOrFail($result['mediaId']);
        $this->assertSame('document', $media->type);
        $this->assertSame(strlen($this->pdf()), $media->size_bytes);
        Storage::disk('local')->assertExists($media->storage_key);
    }

    public function test_loopback_ip_is_refused(): void
    {
        Http::preventStrayRequests();
        $this->resolveHosts();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('rede privada');

        UploadMedia::run(['type' => 'document', 'url' => 'https://127.0.0.1/arquivo.pdf']);
    }

    public function test_host_that_resolves_to_a_private_ip_is_refused(): void
    {
        Http::preventStrayRequests();
        $this->resolveHosts(['interna.example' => ['10.1.2.3']]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('rede privada');

        UploadMedia::run(['type' => 'document', 'url' => 'https://interna.example/arquivo.pdf']);
    }

    public function test_cloud_metadata_redirect_is_refused(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://files.example.com/redirect' => Http::response('', 302, [
                'Location' => 'https://169.254.169.254/latest/meta-data/',
            ]),
        ]);
        $this->resolveHosts();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('rede privada');

        UploadMedia::run(['type' => 'document', 'url' => 'https://files.example.com/redirect']);
    }

    public function test_http_url_is_refused_by_the_tool(): void
    {
        $this->resolveHosts();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('https');

        (new ToolRegistry)->call('media.upload', [
            'type' => 'document',
            'url' => 'http://files.example.com/arquivo.pdf',
            'filename' => 'arquivo.pdf',
        ]);
    }

    public function test_url_and_base64_together_are_refused(): void
    {
        $this->resolveHosts();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('nunca os dois');

        (new ToolRegistry)->call('media.upload', [
            'type' => 'document',
            'url' => 'https://files.example.com/arquivo.pdf',
            'base64' => base64_encode('documento'),
            'filename' => 'arquivo.pdf',
        ]);
    }

    public function test_content_length_above_the_type_limit_is_refused_before_downloading(): void
    {
        config(['warm.media.max_bytes.document' => 100]);

        Http::preventStrayRequests();
        Http::fake([
            'https://files.example.com/grande.pdf' => Http::response($this->pdf(), 200, [
                'Content-Length' => '101',
                'Content-Type' => 'application/pdf',
            ]),
        ]);
        $this->resolveHosts();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('acima do limite');

        UploadMedia::run(['type' => 'document', 'url' => 'https://files.example.com/grande.pdf']);
    }

    public function test_stream_is_aborted_when_it_crosses_the_type_limit(): void
    {
        config(['warm.media.max_bytes.document' => 100]);

        Http::preventStrayRequests();
        Http::fake([
            'https://files.example.com/grande.pdf' => Http::response($this->pdf(), 200, [
                'Content-Type' => 'application/pdf',
            ]),
        ]);
        $this->resolveHosts();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('acima do limite');

        UploadMedia::run(['type' => 'document', 'url' => 'https://files.example.com/grande.pdf']);
    }

    public function test_mime_type_divergent_from_the_declared_type_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Mimetype divergente');

        UploadMedia::run([
            'type' => 'image',
            'base64' => base64_encode($this->pdf()),
            'filename' => 'foto.png',
        ]);
    }

    public function test_image_content_can_be_sent_as_a_document(): void
    {
        $result = UploadMedia::run([
            'type' => 'document',
            'base64' => base64_encode($this->png()),
            'filename' => 'foto.png',
        ]);

        $this->assertSame('document', Media::findOrFail($result['mediaId'])->type);
    }

    public function test_multipart_upload_returns_a_media_id_usable_in_message_send(): void
    {
        $instance = Instance::factory()->create([
            'instance_id' => 'support',
            'evolution_url' => 'https://evolution.test',
            'mcp_token' => 'mcp-support',
        ]);

        $response = $this->post('/instance/support/media', [
            'file' => UploadedFile::fake()->createWithContent('contrato.pdf', $this->pdf()),
        ], ['Authorization' => 'Bearer mcp-support'])->assertStatus(201);

        $mediaId = (string) $response->json('mediaId');
        $this->assertNotSame('', $mediaId);

        $media = Media::findOrFail($mediaId);
        $this->assertSame('document', $media->type);
        $this->assertSame('application/pdf', $media->mime_type);

        Http::preventStrayRequests();
        Http::fake([
            'https://evolution.test/*' => Http::response(['status' => 'PENDING', 'key' => ['id' => 'EV-1']]),
        ]);

        $contact = Contact::create(['phone_number' => '5511999999999', 'name' => 'Ana']);
        $conversation = Conversation::create([
            'type' => 'contact',
            'contact_id' => $contact->id,
            'instance_id' => $instance->instance_id,
        ]);

        $sent = SendMessage::run([
            'conversationId' => $conversation->id,
            'type' => 'document',
            'mediaId' => $mediaId,
            'content' => 'Contrato em anexo',
        ]);

        $this->assertSame('PENDING', $sent['status']);
        $this->assertSame('EV-1', $sent['providerMessageId']);

        Http::assertSent(fn (ClientRequest $request): bool => str_ends_with($request->url(), '/message/sendMedia/support')
            && $request['media'] === base64_encode($this->pdf())
            && $request['mediatype'] === 'document');
    }

    public function test_multipart_upload_without_token_is_unauthorized(): void
    {
        Instance::factory()->create([
            'instance_id' => 'support',
            'mcp_token' => 'mcp-support',
        ]);

        $this->post('/instance/support/media', [
            'file' => UploadedFile::fake()->createWithContent('contrato.pdf', $this->pdf()),
        ])->assertStatus(401)
            ->assertJsonMissingPath('mediaId');
    }

    public function test_multipart_upload_above_the_type_limit_is_refused(): void
    {
        config(['warm.media.max_bytes.document' => 10]);

        Instance::factory()->create([
            'instance_id' => 'support',
            'mcp_token' => 'mcp-support',
        ]);

        $this->post('/instance/support/media', [
            'file' => UploadedFile::fake()->createWithContent('contrato.pdf', $this->pdf()),
        ], ['Authorization' => 'Bearer mcp-support'])
            ->assertStatus(422)
            ->assertJsonPath('error', fn (string $error): bool => str_contains($error, 'acima do limite'));
    }

    public function test_legacy_media_route_targets_the_default_instance(): void
    {
        $default = Instance::default();
        $this->assertNotNull($default);
        $default->forceFill(['mcp_token' => 'mcp-default'])->save();

        $this->post('/media', [
            'file' => UploadedFile::fake()->createWithContent('contrato.pdf', $this->pdf()),
        ], ['Authorization' => 'Bearer mcp-default'])
            ->assertStatus(201)
            ->assertJsonStructure(['mediaId']);
    }

    public function test_png_upload_infers_the_image_type_from_the_content(): void
    {
        Instance::factory()->create([
            'instance_id' => 'support',
            'mcp_token' => 'mcp-support',
        ]);

        $response = $this->post('/instance/support/media', [
            'file' => UploadedFile::fake()->createWithContent('foto.png', $this->png()),
        ], ['Authorization' => 'Bearer mcp-support'])->assertStatus(201);

        $media = Media::findOrFail((string) $response->json('mediaId'));
        $this->assertSame('image', $media->type);
        $this->assertSame('image/png', $media->mime_type);
    }
}
