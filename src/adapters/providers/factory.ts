import { WhatsAppService } from "../../domain/services/whatsapp-service";
import config from "../../config/index";
import { EvolutionAdapter } from "./evolution-adapter";

export function createWhatsAppService(): WhatsAppService {
  switch (config.provider) {
    case "evolution":
      return new EvolutionAdapter();
    default:
      throw new Error(`Unknown WHATSAPP_PROVIDER: ${config.provider}`);
  }
}
