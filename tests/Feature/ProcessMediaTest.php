<?php

namespace Tests\Feature;

use App\Actions\Media\ProcessMedia;
use App\Contracts\MediaProcessor;
use App\Models\Media;
use App\Processing\ProcessedContent;
use App\Processing\ProcessingContext;
use App\Processing\ProcessingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class ProcessMediaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['warm.processing.enabled' => true]);
    }

    private function media(string $type = 'audio'): Media
    {
        return Media::create([
            'type' => $type,
            'filename' => 'sample',
            'mime_type' => 'audio/ogg',
            'storage_key' => 'media/'.Str::uuid().'/original',
            'size_bytes' => 4,
            'metadata' => [],
        ]);
    }

    public function test_marks_skipped_when_no_processor_matches(): void
    {
        $processor = new class implements MediaProcessor
        {
            public function name(): string
            {
                return 'nothing';
            }

            public function supports(ProcessingContext $context): bool
            {
                return false;
            }

            public function process(string $buffer, ProcessingContext $context): ?ProcessedContent
            {
                return null;
            }
        };

        app()->instance(ProcessingService::class, new ProcessingService([$processor]));

        $media = ProcessMedia::run($this->media(), 'data');

        $this->assertSame('skipped', $media->metadata['processing']['status']);
        $this->assertSame('', $media->extracted_text);
    }

    public function test_aggregates_processor_results_and_marks_done(): void
    {
        $audio = new class implements MediaProcessor
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
                return new ProcessedContent(kind: 'transcription', text: 'Ola WARM', metadata: ['model' => 'whisper']);
            }
        };

        $other = new class implements MediaProcessor
        {
            public function name(): string
            {
                return 'other';
            }

            public function supports(ProcessingContext $context): bool
            {
                return $context->type === 'pdf';
            }

            public function process(string $buffer, ProcessingContext $context): ?ProcessedContent
            {
                return new ProcessedContent(kind: 'extracted_text', text: 'nao usado');
            }
        };

        app()->instance(ProcessingService::class, new ProcessingService([$audio, $other]));

        $media = ProcessMedia::run($this->media(), 'data');

        $this->assertSame('done', $media->metadata['processing']['status']);
        $this->assertSame(['transcription'], $media->metadata['processing']['kinds']);
        $this->assertSame('Ola WARM', $media->extracted_text);
    }

    public function test_marks_failed_with_the_cause_and_processor(): void
    {
        $processor = new class implements MediaProcessor
        {
            public function name(): string
            {
                return 'audio';
            }

            public function supports(ProcessingContext $context): bool
            {
                return true;
            }

            public function process(string $buffer, ProcessingContext $context): ?ProcessedContent
            {
                throw new RuntimeException('falha sintetica');
            }
        };

        app()->instance(ProcessingService::class, new ProcessingService([$processor]));

        $media = ProcessMedia::run($this->media(), 'data');

        $this->assertSame('failed', $media->metadata['processing']['status']);
        $this->assertStringContainsString('falha sintetica', $media->metadata['processing']['error']);
        $this->assertStringContainsString('[audio]', $media->metadata['processing']['error']);
    }
}
