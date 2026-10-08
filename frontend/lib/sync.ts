import { db, type SyncOperation, type SyncStatus } from "./db";
import { api, type ApiError } from "./api";

type SyncEnvelopeResponse = {
  status: "accepted" | "rejected" | "conflict";
  operation_uuid: string;
  sync_operation_id: string;
  result_reference?: string | null;
  result?: unknown;
  rejection_code?: string | null;
  errors?: Record<string, unknown>;
  authoritative: boolean;
};

type QueuePayload = {
  path: string;
  body: Record<string, unknown> | null;
};

const DEVICE_KEY = "nexora_device_uuid";

function getDeviceUuid(): string {
  if (typeof window === "undefined") {
    throw new Error("Device UUID is only available in the browser.");
  }

  const existing = localStorage.getItem(DEVICE_KEY);
  if (existing) return existing;

  const uuid = crypto.randomUUID();
  localStorage.setItem(DEVICE_KEY, uuid);
  return uuid;
}

function isConflict(error: ApiError): boolean {
  const data = error.data as { rejection_code?: string; errors?: Record<string, unknown> } | undefined;
  return error.status === 409 || data?.rejection_code === "conflict";
}

function syncStatus(response: SyncEnvelopeResponse): SyncStatus {
  if (response.status === "accepted") return "completed";
  if (response.status === "conflict") return "conflict";
  return "rejected";
}

export async function queueOfflineOperation(
  operationType: string,
  path: string,
  body: unknown,
  idempotencyKey?: string,
) {
  const operation: SyncOperation = {
    id: crypto.randomUUID(),
    operationUuid: crypto.randomUUID(),
    operationType,
    idempotencyKey: idempotencyKey || crypto.randomUUID(),
    payload: { path, body },
    status: "pending",
    createdAt: Date.now(),
  };

  await db.syncOperations.add(operation);
  return operation;
}

async function sendSyncEnvelope(op: SyncOperation, payload: QueuePayload): Promise<SyncEnvelopeResponse> {
  const response = await api<SyncEnvelopeResponse>("/sync/operations", {
    method: "POST",
    headers: {
      "X-Device-UUID": getDeviceUuid(),
      "Idempotency-Key": op.idempotencyKey,
    },
    body: JSON.stringify({
      operation_uuid: op.operationUuid,
      operation_type: op.operationType,
      schema_version: 1,
      idempotency_key: op.idempotencyKey,
      client_created_at: new Date(op.createdAt).toISOString(),
      payload,
    }),
  });

  if (!response.authoritative) {
    throw new Error("Sync response was not authoritative.");
  }

  return response;
}

function errorMessage(error: unknown): string {
  const apiError = error as ApiError;
  const data = apiError?.data as { errors?: Record<string, unknown>; message?: string } | undefined;
  if (data?.message) return data.message;
  if (data?.errors) return JSON.stringify(data.errors);
  return error instanceof Error ? error.message : "Sync failed";
}

export async function flushOfflineQueue(
  _apiFn: typeof api = api,
): Promise<void> {
  const pending = await db.syncOperations
    .where("status")
    .anyOf("pending", "syncing", "failed")
    .sortBy("createdAt");

  for (const op of pending) {
    const payload = op.payload as QueuePayload;
    await db.syncOperations.update(op.id, { status: "syncing", lastError: undefined });

    try {
      const response = await sendSyncEnvelope(op, payload);
      await db.syncOperations.update(op.id, {
        status: syncStatus(response),
        resultReference: response.result_reference ?? null,
        lastError: response.status === "accepted" ? undefined : errorMessage(response),
      });

      if (response.status === "accepted") continue;
      if (response.status === "conflict" || response.status === "rejected") continue;
    } catch (error) {
      const apiError = error as ApiError;

      if (apiError?.status === 422 || isConflict(apiError)) {
        const data = apiError.data as SyncEnvelopeResponse | undefined;
        await db.syncOperations.update(op.id, {
          status: isConflict(apiError) ? "conflict" : "rejected",
          lastError: errorMessage(error),
          resultReference: data?.result_reference ?? null,
        });
        continue;
      }

      await db.syncOperations.update(op.id, {
        status: "failed",
        lastError: errorMessage(error),
      });
    }
  }
}
