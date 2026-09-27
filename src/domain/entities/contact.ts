import { v4 as uuidv4 } from "uuid";

export interface Contact {
  id: string;
  phoneNumber: string;
  name?: string;
  pushName?: string;
  observations: string;
  createdAt: Date;
  updatedAt: Date;
}

export function createContact(data: Omit<Contact, "id" | "createdAt" | "updatedAt">): Contact {
  return {
    ...data,
    id: uuidv4(),
    createdAt: new Date(),
    updatedAt: new Date(),
  };
}
