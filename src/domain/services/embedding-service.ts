export interface EmbeddingService {
  readonly enabled: boolean;
  embed(text: string): Promise<number[] | null>;
}
