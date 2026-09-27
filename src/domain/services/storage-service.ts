export interface StorageObject {
  key: string;
  contentType?: string;
}

export interface StorageService {
  put(key: string, body: Buffer, contentType?: string): Promise<void>;
  get(key: string): Promise<Buffer>;
  getSignedUrl(key: string, expiresInSeconds?: number): Promise<string>;
  delete(key: string): Promise<void>;
  ensureBucket(): Promise<void>;
}
