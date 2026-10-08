import { db, type SyncOperation } from "./db";
import { api } from "./api";

export async function queueOfflineOperation(operationType:string,path:string,body:unknown,idempotencyKey?:string){
 const operation:SyncOperation={
   id:crypto.randomUUID(),operationUuid:crypto.randomUUID(),operationType,
   idempotencyKey:idempotencyKey || crypto.randomUUID(),
   payload:{path,body},status:"pending",createdAt:Date.now()
 };
 await db.syncOperations.add(operation); return operation;
}

export async function flushOfflineQueue(apiFn:(path:string,options:RequestInit)=>Promise<unknown>=api){
 const pending=await db.syncOperations.where("status").anyOf("pending","syncing").sortBy("createdAt");
 for(const op of pending){
  const payload=op.payload as {path:string;body:Record<string,unknown>|null};
  await db.syncOperations.update(op.id,{status:"syncing"});
  try{
   const body=payload.body ? {...payload.body,idempotency_key:payload.body.idempotency_key || op.idempotencyKey} : payload.body;
   await apiFn(payload.path,{method:op.operationType,headers:{"Idempotency-Key":op.idempotencyKey},body:body?JSON.stringify(body):undefined});
   await db.syncOperations.update(op.id,{status:"completed",lastError:undefined});
  }catch(error){
   await db.syncOperations.update(op.id,{status:"failed",lastError:error instanceof Error?error.message:"Sync failed"});
  }
 }
}
