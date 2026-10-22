// Same-origin by default: the Next.js server proxies /api/* to the Laravel API
// (next.config.ts rewrites) so production never depends on a hard-coded port.
const API_URL = process.env.NEXT_PUBLIC_API_URL || "/api/v1";

export class ApiError extends Error {
  status: number;
  data: unknown;
  retryAfterSeconds?: number;

  constructor(status: number, data: unknown, message = "حدث خطأ أثناء الاتصال بالنظام.", retryAfterSeconds?: number) {
    super(message);
    this.name = "ApiError";
    this.status = status;
    this.data = data;
    this.retryAfterSeconds = retryAfterSeconds;
  }
}
export async function api<T>(path: string, options: RequestInit = {}): Promise<T> {
  const token = typeof window !== "undefined" ? localStorage.getItem("nexora_token") : null;
  const headers = new Headers(options.headers);
  headers.set("Accept", "application/json");
  const isFormData = typeof FormData !== "undefined" && options.body instanceof FormData;
  if (isFormData) headers.delete("Content-Type");
  else if (options.body !== undefined && options.body !== null) headers.set("Content-Type", "application/json");
  if (token) headers.set("Authorization", `Bearer ${token}`);

  const response = await fetch(`${API_URL}${path}`, { ...options, headers });
  const payload = await response.json().catch(() => ({}));
  if (!response.ok) {
    if (response.status === 401 && typeof window !== "undefined" && path !== "/auth/login") {
      if (token) localStorage.removeItem("nexora_token");
      // Protected pages must never remain open with empty data when no session exists.
      if (window.location.pathname !== "/login") {
        const reason = token ? "session-expired" : "login-required";
        const organizationId = localStorage.getItem("nexora_organization_id");
        const tenant = organizationId ? `&organization_id=${encodeURIComponent(organizationId)}` : "";
        window.location.assign(`/login?reason=${reason}${tenant}`);
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
    const retryAfterHeader = Number(response.headers.get("Retry-After"));
    const retryAfterSeconds = response.status === 429 && Number.isFinite(retryAfterHeader) && retryAfterHeader > 0
      ? Math.ceil(retryAfterHeader)
      : undefined;
    throw new ApiError(response.status, payload, message, retryAfterSeconds);
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
