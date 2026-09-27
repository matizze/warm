import { v4 as uuidv4 } from "uuid";

export interface Group {
  id: string;
  providerGroupId: string;
  name: string;
  description: string;
  observations: string;
  createdAt: Date;
  updatedAt: Date;
}

export function createGroup(data: Omit<Group, "id" | "createdAt" | "updatedAt">): Group {
  return {
    ...data,
    id: uuidv4(),
    createdAt: new Date(),
    updatedAt: new Date(),
  };
}
