import { PDFParse } from "pdf-parse";
import { MediaProcessor, ProcessedContent, ProcessingContext } from "../../domain/services/media-processor";

export class PdfProcessor implements MediaProcessor {
  name = "pdf";

  supports(context: ProcessingContext): boolean {
    return context.mimeType === "application/pdf" || context.filename.toLowerCase().endsWith(".pdf");
  }

  async process(buffer: Buffer, _context: ProcessingContext): Promise<ProcessedContent | null> {
    const parser = new PDFParse({ data: buffer });
    try {
      const result = await parser.getText();
      const text = (result.text || "").trim();
      return {
        kind: "extracted_text",
        text,
        metadata: { pages: result.total, processor: this.name },
      };
    } finally {
      await parser.destroy();
    }
  }
}
