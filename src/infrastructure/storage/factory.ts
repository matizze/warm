import config from "../../config/index";
import { StorageService } from "../../domain/services/storage-service";
import { S3Storage } from "./s3-storage";
import { LocalStorage } from "./local-storage";

export function createStorage(): StorageService {
  switch (config.storageDriver) {
    case "local":
      return new LocalStorage();
    case "s3":
      return new S3Storage();
    default:
      throw new Error(`Unknown STORAGE_DRIVER: ${config.storageDriver}`);
  }
}
