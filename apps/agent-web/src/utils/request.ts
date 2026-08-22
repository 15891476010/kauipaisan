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
  localStorage.removeItem("agent_token");
  localStorage.removeItem("agent_name");
  localStorage.removeItem("agent_permissions");
  localStorage.removeItem("agent_is_subaccount");
  localStorage.removeItem("agent_organization_level");
  localStorage.removeItem("agent_level_label");
  localStorage.removeItem("agent_must_change_password");
  sessionStorage.removeItem("agent_agreement_accepted_token");
  if (unauthorizedDispatched) return;
  unauthorizedDispatched = true;
  window.dispatchEvent(new CustomEvent("agent:unauthorized"));
  window.setTimeout(() => { unauthorizedDispatched = false; }, 0);
}

request.interceptors.request.use((config: InternalAxiosRequestConfig) => {
  const token = localStorage.getItem("agent_token");
  config.headers.set("X-Agent-Domain", window.location.host);
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
