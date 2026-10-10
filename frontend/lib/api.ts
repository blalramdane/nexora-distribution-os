const API_URL = process.env.NEXT_PUBLIC_API_URL || "http://localhost:8000/api/v1";

export class ApiError extends Error {
  status: number;
  data: unknown;

  constructor(status: number, data: unknown, message = "حدث خطأ أثناء الاتصال بالنظام.") {
    super(message);
    this.name = "ApiError";
    this.status = status;
    this.data = data;
  }
}

export async function api<T>(path: string, options: RequestInit = {}): Promise<T> {
  const token = typeof window !== "undefined" ? localStorage.getItem("nexora_token") : null;
  const headers = new Headers(options.headers);
  headers.set("Accept", "application/json");
  headers.set("Content-Type", "application/json");
  if (token) headers.set("Authorization", `Bearer ${token}`);

  const response = await fetch(`${API_URL}${path}`, { ...options, headers });
  const payload = await response.json().catch(() => ({}));
  if (!response.ok) {
    if (response.status === 401 && typeof window !== "undefined" && path !== "/auth/login") {
      if (token) localStorage.removeItem("nexora_token");
      // Protected pages must never remain open with empty data when no session exists.
      if (window.location.pathname !== "/login") {
        const reason = token ? "session-expired" : "login-required";
        window.location.assign(`/login?reason=${reason}`);
      }
    }
    const validation = payload?.errors && typeof payload.errors === "object"
      ? Object.values(payload.errors).flat().join(" ")
      : "";
    const message = response.status === 401
      ? "انتهت جلسة الدخول. سجّل الدخول مرة أخرى."
      : response.status === 403
        ? "حسابك لا يملك صلاحية تنفيذ العملية دي. راجع صلاحيات المستخدم."
        : response.status === 422
          ? validation || payload?.message || "راجع البيانات المدخلة."
          : payload?.message || `تعذر تنفيذ العملية (HTTP ${response.status}).`;
    throw new ApiError(response.status, payload, message);
  }
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
    const method = (options.method || "POST").toUpperCase();
    const operationType = path === "/field/visits" ? "field.visit" : `${method} ${path}`;
    await queueOfflineOperation(operationType,path,body,key);
    return {queued:true,offline:true} as T;
  }
}
export { API_URL };
