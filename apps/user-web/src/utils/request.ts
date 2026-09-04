import axios, { type AxiosError, type AxiosInstance, type AxiosResponse, type InternalAxiosRequestConfig } from "axios";

export type ApiEnvelope<T> = {
  code: number;
  message?: string;
  data: T;
  request_id?: string;
};

export const request: AxiosInstance = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL || "/prod_api/v1",
  timeout: 12000,
});

let unauthorizedDispatched = false;
let refreshPromise: Promise<string | null> | null = null;

function handleUnauthorized() {
  localStorage.removeItem("user_token");
  localStorage.removeItem("user_name");
  localStorage.removeItem("user_must_change_password");
  sessionStorage.removeItem("agreement_accepted_token");
  if (unauthorizedDispatched) return;
  unauthorizedDispatched = true;
  window.dispatchEvent(new CustomEvent("user:unauthorized"));
  window.setTimeout(() => { unauthorizedDispatched = false; }, 0);
}

function isUnauthorized(error: AxiosError<ApiEnvelope<unknown>>): boolean {
  return error.response?.status === 401 || error.response?.data?.code === 401;
}

async function refreshToken(currentToken: string): Promise<string | null> {
  if (!currentToken) return null;
  if (!refreshPromise) {
    const base = String(request.defaults.baseURL || "/prod_api/v1").replace(/\/$/, "");
    refreshPromise = axios.post<ApiEnvelope<{ token?: string }>>(
      `${base}/user/auth/refresh`,
      null,
      { timeout: 8000, headers: { Authorization: `Bearer ${currentToken}`, "X-User-Domain": window.location.host } },
    ).then((response) => {
      const token = String(response.data?.data?.token || "").trim();
      if (token) localStorage.setItem("user_token", token);
      return token || null;
    }).catch(() => null).finally(() => { refreshPromise = null; });
  }
  return refreshPromise;
}

async function retryAfterRefresh(error: AxiosError<ApiEnvelope<unknown>>): Promise<any> {
  const config = error.config as (InternalAxiosRequestConfig & { _tokenRefreshAttempted?: boolean }) | undefined;
  const url = String(config?.url || "");
  if (!isUnauthorized(error) || !config || config._tokenRefreshAttempted || url.includes("/auth/refresh")) {
    if (isUnauthorized(error)) handleUnauthorized();
    return Promise.reject(error);
  }
  config._tokenRefreshAttempted = true;
  const oldToken = String(config.headers?.Authorization || "").replace(/^Bearer\s+/i, "") || localStorage.getItem("user_token") || "";
  const token = await refreshToken(oldToken);
  if (token) {
    config.headers.set("Authorization", `Bearer ${token}`);
    return request(config);
  }
  handleUnauthorized();
  return Promise.reject(error);
}

request.interceptors.request.use((config: InternalAxiosRequestConfig) => {
  const token = localStorage.getItem("user_token");
  config.headers.set("X-User-Domain", window.location.host);
  if (token && !config.headers?.Authorization) config.headers.set("Authorization", `Bearer ${token}`);
  return config;
});

request.interceptors.response.use(
  (response: AxiosResponse<ApiEnvelope<unknown>>) => {
    const payload = response.data;
    if (payload && typeof payload.code === "number" && payload.code !== 0) {
      const error = new Error(payload.message || "请求失败") as AxiosError;
      error.config = response.config;
      error.response = response as AxiosResponse;
      if (payload.code === 401) return retryAfterRefresh(error as AxiosError<ApiEnvelope<unknown>>);
      return Promise.reject(error);
    }
    return response;
  },
  (error: AxiosError<ApiEnvelope<unknown>>) => retryAfterRefresh(error),
);

export function apiErrorMessage(error: unknown, fallback: string): string {
  const extract = (value: unknown): string | undefined => {
    if (!value) return undefined;
    if (typeof value === "string") {
      const trimmed = value.trim();
      if (!trimmed) return undefined;
      try {
        const parsed = JSON.parse(trimmed) as Record<string, unknown>;
        return extract(parsed);
      } catch {
        return trimmed;
      }
    }
    if (typeof value === "object") {
      const record = value as Record<string, unknown>;
      return (
        (typeof record.message === "string" && record.message) ||
        (typeof record.msg === "string" && record.msg) ||
        (typeof record.error === "string" && record.error) ||
        extract(record.data) ||
        extract(record.detail)
      );
    }
    return undefined;
  };
  if (axios.isAxiosError(error)) {
    const response = error.response?.data as ApiEnvelope<unknown> | string | undefined;
    return extract(response) || error.message || fallback;
  }
  return extract(error) || (error instanceof Error ? error.message : fallback);
}
