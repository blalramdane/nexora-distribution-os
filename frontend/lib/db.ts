import Dexie, { type EntityTable } from "dexie";

export type SyncOperation = {
  id: string;
  operationUuid: string;
  operationType: string;
  idempotencyKey: string;
  payload: unknown;
  status: "pending" | "syncing" | "failed" | "completed";
  createdAt: number;
  lastError?: string;
};

export const db = new Dexie("nexora-distribution") as Dexie & {
  syncOperations: EntityTable<SyncOperation, "id">;
};

db.version(1).stores({
  syncOperations: "id, operationUuid, operationType, idempotencyKey, status, createdAt",
});