import Dexie, { type EntityTable } from "dexie";

export type SyncStatus = "pending" | "syncing" | "failed" | "completed" | "rejected" | "conflict";

export type SyncOperation = {
  id: string;
  operationUuid: string;
  operationType: string;
  idempotencyKey: string;
  payload: unknown;
  status: SyncStatus;
  createdAt: number;
  lastError?: string;
  resultReference?: string | null;
};

export const db = new Dexie("nexora-distribution") as Dexie & {
  syncOperations: EntityTable<SyncOperation, "id">;
};

db.version(1).stores({
  syncOperations: "id, operationUuid, operationType, idempotencyKey, status, createdAt",
});
