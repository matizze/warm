export type ProcessedKind = "extracted_text" | "transcription" | "description" | "ocr";

export interface ProcessedContent {
  kind: ProcessedKind;
  text: string;
  metadata?: Record<string, unknown>;
}

export interface ProcessingContext {
  type: string;
  mimeType: string;
  filename: string;
}

export interface MediaProcessor {
  name: string;
  supports(context: ProcessingContext): boolean;
  process(buffer: Buffer, context: ProcessingContext): Promise<ProcessedContent | null>;
}

export interface ProcessingServicePort {
  process(buffer: Buffer, context: ProcessingContext): Promise<ProcessedContent[]>;
}
