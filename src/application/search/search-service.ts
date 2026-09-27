import { QueryService } from "../../domain/services/query-service";
import { EmbeddingService } from "../../domain/services/embedding-service";
import { Message } from "../../domain/entities/message";

export type SearchMode = "lexical" | "semantic" | "hybrid";

const DEFAULT_MIN_SCORE = 0.25;

export interface MessageSearchFilters {
  conversationId?: string;
  contactId?: string;
  type?: string;
  direction?: string;
  from?: Date;
  to?: Date;
  search?: string;
  limit?: number;
  offset?: number;
  minScore?: number;
}

export type ScoredMessage = Message & { score?: number };

export class SearchService {
  constructor(
    private query: QueryService,
    private embeddings: EmbeddingService
  ) {}

  async indexMessage(messageId: string): Promise<boolean> {
    const message = await this.query.getMessageById(messageId);
    if (!message) {
      return false;
    }

    const media = await this.query.getMediaByMessageId(messageId);
    const searchText = [message.content, media?.extractedText]
      .filter((part): part is string => Boolean(part && part.trim()))
      .join("\n")
      .trim();

    if (!searchText) {
      return false;
    }

    let embedding: number[] | null = null;
    try {
      embedding = await this.embeddings.embed(searchText);
    } catch (error) {
      console.warn(`Embedding failed for message ${messageId}:`, (error as Error).message);
    }

    await this.query.setMessageSearchData(messageId, searchText, embedding);
    return true;
  }

  async searchMessages(filters: MessageSearchFilters, mode: SearchMode = "hybrid"): Promise<ScoredMessage[]> {
    const hasQuery = Boolean(filters.search && filters.search.trim());
    if (!hasQuery || mode === "lexical") {
      return this.query.searchMessages(filters);
    }

    let semantic: ScoredMessage[] = [];
    if (this.embeddings.enabled) {
      try {
        const vector = await this.embeddings.embed(filters.search!);
        if (vector) {
          const minScore = filters.minScore ?? DEFAULT_MIN_SCORE;
          semantic = await this.query.searchMessagesSemantic(vector, { ...filters, minScore });
        }
      } catch (error) {
        console.warn("Semantic search failed, falling back to lexical:", (error as Error).message);
      }
    }

    if (mode === "semantic") {
      return semantic;
    }

    const lexical = await this.query.searchMessages(filters);
    return fuseResults(lexical, semantic);
  }
}

function fuseResults(lexical: Message[], semantic: ScoredMessage[], k = 60): ScoredMessage[] {
  const merged = new Map<string, ScoredMessage>();

  lexical.forEach((message, index) => {
    merged.set(message.id, { ...message, score: 1 / (k + index + 1) });
  });

  semantic.forEach((message, index) => {
    const existing = merged.get(message.id);
    const contribution = 1 / (k + index + 1);
    if (existing) {
      existing.score = (existing.score || 0) + contribution;
    } else {
      merged.set(message.id, { ...message, score: contribution });
    }
  });

  return [...merged.values()].sort((a, b) => (b.score || 0) - (a.score || 0));
}
