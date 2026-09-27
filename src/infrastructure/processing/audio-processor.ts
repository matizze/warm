import { MediaProcessor, ProcessedContent, ProcessingContext } from "../../domain/services/media-processor";
import { transcribe } from "./transcription";

export class AudioProcessor implements MediaProcessor {
  name = "audio";

  supports(context: ProcessingContext): boolean {
    return context.type === "audio" || context.mimeType.startsWith("audio/");
  }

  async process(buffer: Buffer, context: ProcessingContext): Promise<ProcessedContent | null> {
    const text = await transcribe(buffer, {
      mimeType: context.mimeType || "audio/ogg",
      filename: context.filename,
    });
    return {
      kind: "transcription",
      text,
      metadata: { processor: this.name },
    };
  }
}
