import { QueryService } from "../../domain/services/query-service";
import { Group } from "../../domain/entities/group";

export async function getGroup(queryService: QueryService, groupId: string): Promise<Group | null> {
  return queryService.getGroup(groupId);
}

export async function searchGroups(queryService: QueryService, filters: SearchGroupsFilters): Promise<Group[]> {
  return queryService.searchGroups(filters);
}

export interface SearchGroupsFilters { name?: string; search?: string; limit?: number; offset?: number; }
