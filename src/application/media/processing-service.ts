import {
  MediaProcessor,
  ProcessedContent,
  ProcessingContext,
  ProcessingServicePort,
} from "../../domain/services/media-processor";

export class ProcessingService implements ProcessingServicePort {
  constructor(private processors: MediaProcessor[]) {}

  async process(buffer: Buffer, context: ProcessingContext): Promise<ProcessedContent[]> {
    const results: ProcessedContent[] = [];
    for (const processor of this.processors) {
      if (!processor.supports(context)) {
        continue;
      }
      const result = await processor.process(buffer, context);
      if (result) {
        results.push(result);
      }
    }
    return results;
  }
}
