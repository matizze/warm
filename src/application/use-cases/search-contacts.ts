import { QueryService } from "../../domain/services/query-service";
import { Contact } from "../../domain/entities/contact";

export async function searchContacts(queryService: QueryService, filters: SearchContactsFilters): Promise<Contact[]> {
  return queryService.searchContacts(filters);
}

export async function getContact(queryService: QueryService, contactId: string): Promise<Contact | null> {
  return queryService.getContact(contactId);
}

export interface SearchContactsFilters { name?: string; phoneNumber?: string; search?: string; limit?: number; offset?: number; }
