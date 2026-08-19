import axios, { type AxiosError, type AxiosInstance, type AxiosResponse, type InternalAxiosRequestConfig } from "axios";

export type ApiEnvelope<T> = {
  code: number;
  message?: string;
  data: T;
  request_id?: string;
};

export const request: AxiosInstance = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL || "/api/v1",
  timeout: 12000,
});

let unauthorizedDispatched = false;

function handleUnauthorized() {
  localStorage.removeItem("user_token");
  localStorage.removeItem("user_name");
  sessionStorage.removeItem("agreement_accepted_token");
  if (unauthorizedDispatched) return;
  unauthorizedDispatched = true;
  window.dispatchEvent(new CustomEvent("user:unauthorized"));
  window.setTimeout(() => { unauthorizedDispatched = false; }, 0);
}

request.interceptors.request.use((config: InternalAxiosRequestConfig) => {
  const token = localStorage.getItem("user_token");
  config.headers.set("X-User-Domain", window.location.host);
  if (token && !config.headers?.Authorization) {
    config.headers.set("Authorization", `Bearer ${token}`);
  }
  return config;
});

request.interceptors.response.use(
  (response: AxiosResponse<ApiEnvelope<unknown>>) => {
    const payload = response.data;
    if (payload && typeof payload.code === "number" && payload.code !== 0) {
      if (payload.code === 401) handleUnauthorized();
      const error = new Error(payload.message || "请求失败") as AxiosError;
      error.response = response as AxiosResponse;
      return Promise.reject(error);
    }
    return response;
  },
  (error: AxiosError<ApiEnvelope<unknown>>) => {
    if (error.response?.status === 401 || error.response?.data?.code === 401) handleUnauthorized();
    return Promise.reject(error);
  },
);

export function apiErrorMessage(error: unknown, fallback: string): string {
  if (axios.isAxiosError(error)) {
    const response = error.response?.data as ApiEnvelope<unknown> | undefined;
    return String(response?.message || error.message || fallback);
  }
  return error instanceof Error ? error.message : fallback;
}
