import { QueryService } from "../../domain/services/query-service";
import { Conversation } from "../../domain/entities/conversation";

export async function searchConversations(queryService: QueryService, filters: SearchConversationsFilters): Promise<Conversation[]> {
  return queryService.searchConversations(filters);
}

export interface SearchConversationsFilters {
  name?: string; contactId?: string; groupId?: string; search?: string; limit?: number; offset?: number;
}
