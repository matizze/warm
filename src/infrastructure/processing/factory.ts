import { MediaProcessor } from "../../domain/services/media-processor";
import { PdfProcessor } from "../../infrastructure/processing/pdf-processor";
import { AudioProcessor } from "../../infrastructure/processing/audio-processor";
import { ImageProcessor } from "../../infrastructure/processing/image-processor";
import { ProcessingService } from "../../application/media/processing-service";

export function createProcessingService(): ProcessingService {
  const processors: MediaProcessor[] = [new PdfProcessor(), new AudioProcessor(), new ImageProcessor()];
  return new ProcessingService(processors);
}
