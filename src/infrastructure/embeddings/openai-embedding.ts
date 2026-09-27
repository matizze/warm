import config from "../../config/index";
import { EmbeddingService } from "../../domain/services/embedding-service";

export class OpenAIEmbedding implements EmbeddingService {
  get enabled(): boolean {
    return config.embeddingEnabled && !!config.openaiApiKey;
  }

  async embed(text: string): Promise<number[] | null> {
    const input = text.trim();
    if (!this.enabled || !input) {
      return null;
    }

    const response = await fetch(`${config.openaiBaseUrl}/embeddings`, {
      method: "POST",
      headers: {
        Authorization: `Bearer ${config.openaiApiKey}`,
        "Content-Type": "application/json",
      },
      body: JSON.stringify({
        model: config.embeddingModel,
        input: input.slice(0, 8000),
        dimensions: config.embeddingDimensions,
      }),
    });

    if (!response.ok) {
      throw new Error(`Embedding failed (${response.status}): ${await response.text()}`);
    }

    const json = (await response.json()) as { data?: { embedding: number[] }[] };
    return json.data?.[0]?.embedding ?? null;
  }
}

export function toVectorLiteral(embedding: number[]): string {
  return `[${embedding.join(",")}]`;
}
