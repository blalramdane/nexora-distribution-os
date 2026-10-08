const API_URL = process.env.NEXT_PUBLIC_API_URL || "http://127.0.0.1:8000/api/v1";

export async function api<T>(path: string, options: RequestInit = {}): Promise<T> {
  const token = typeof window !== "undefined" ? localStorage.getItem("nexora_token") : null;
  const headers = new Headers(options.headers);
  headers.set("Accept", "application/json");
  headers.set("Content-Type", "application/json");
  if (token) headers.set("Authorization", `Bearer ${token}`);

  const response = await fetch(`${API_URL}${path}`, { ...options, headers });
  const payload = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(payload?.message || "حدث خطأ أثناء الاتصال بالنظام.");
  return payload as T;
}

export async function apiWithOfflineQueue<T>(path:string,options:RequestInit={}):Promise<T>{
  try {
    return await api<T>(path,options);
  } catch(error) {
    if(typeof window==="undefined"||navigator.onLine) throw error;
    const {queueOfflineOperation}=await import("./sync");
    const body=options.body?JSON.parse(String(options.body)):null;
    const key=body?.idempotency_key || crypto.randomUUID();
    if(body && !body.idempotency_key) body.idempotency_key=key;
    await queueOfflineOperation(options.method||"POST",path,body,key);
    return {queued:true,offline:true} as T;
  }
}
export { API_URL };
