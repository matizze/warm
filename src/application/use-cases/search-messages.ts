import { QueryService } from "../../domain/services/query-service";
import { Message } from "../../domain/entities/message";

export async function searchMessages(queryService: QueryService, filters: SearchMessagesFilters): Promise<Message[]> {
  return queryService.searchMessages(filters);
}

export interface SearchMessagesFilters { conversationId?: string; contactId?: string; type?: string; direction?: string; search?: string; limit?: number; offset?: number; }
