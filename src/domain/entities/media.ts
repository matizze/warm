import { v4 as uuidv4 } from "uuid";

export interface Media {
  id: string;
  messageId: string | null;
  type: string;
  filename: string;
  mimeType: string;
  sizeBytes: number;
  storageKey: string;
  extractedText?: string | null;
  metadata: Record<string, unknown>;
  createdAt: Date;
}

export function createMedia(data: Omit<Media, "id" | "createdAt">): Media {
  return {
    ...data,
    id: uuidv4(),
    createdAt: new Date(),
  };
}
