import { promises as fs } from "fs";
import path from "path";
import config from "../../config/index";
import { StorageService } from "../../domain/services/storage-service";

export class LocalStorage implements StorageService {
  private basePath: string;

  constructor() {
    this.basePath = path.resolve(config.storageLocalPath);
  }

  private resolve(key: string): string {
    const target = path.resolve(this.basePath, key);
    if (!target.startsWith(this.basePath)) {
      throw new Error(`Invalid storage key: ${key}`);
    }
    return target;
  }

  async ensureBucket(): Promise<void> {
    await fs.mkdir(this.basePath, { recursive: true });
  }

  async put(key: string, body: Buffer): Promise<void> {
    const target = this.resolve(key);
    await fs.mkdir(path.dirname(target), { recursive: true });
    await fs.writeFile(target, body);
  }

  async get(key: string): Promise<Buffer> {
    return fs.readFile(this.resolve(key));
  }

  async getSignedUrl(key: string): Promise<string> {
    return `file://${this.resolve(key)}`;
  }

  async delete(key: string): Promise<void> {
    await fs.rm(this.resolve(key), { force: true });
  }
}
