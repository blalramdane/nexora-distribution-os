import { db, type SyncOperation } from "./db";

export async function queueOfflineOperation(operationType:string,path:string,body:unknown){
 const operation:SyncOperation={id:crypto.randomUUID(),operationUuid:crypto.randomUUID(),operationType,idempotencyKey:crypto.randomUUID(),payload:{path,body},status:"pending",createdAt:Date.now()};
 await db.syncOperations.add(operation); return operation;
}
export async function flushOfflineQueue(apiFn:(path:string,options:RequestInit)=>Promise<unknown>){
 const pending=await db.syncOperations.where("status").equals("pending").sortBy("createdAt");
 for(const op of pending){
  const payload=op.payload as {path:string;body:unknown};
  await db.syncOperations.update(op.id,{status:"syncing"});
  try{
   await apiFn(payload.path,{method:"POST",headers:{"Idempotency-Key":op.idempotencyKey},body:JSON.stringify(payload.body)});
   await db.syncOperations.update(op.id,{status:"completed"});
  }catch(error){
   await db.syncOperations.update(op.id,{status:"failed",lastError:error instanceof Error?error.message:"Sync failed"});
  }
 }
}
