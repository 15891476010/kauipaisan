import { request, type ApiEnvelope } from "../utils/request";

export type CaptchaPayload = { captcha_id: string; image: string };
export type LoginPayload = { username: string; password: string; captcha: string; captcha_id: string };
export type LoginData = { token: string; user?: { username: string; display_name: string; is_subaccount?: boolean; organization_id?: number; organization_level?: string; level_label?: string; must_change_password?: boolean }; permissions?: string[]; lottery_permissions?: Array<number|string> };

export const getCaptcha = () => request.get<ApiEnvelope<CaptchaPayload>>("/agent/auth/captcha");
export const login = (payload: LoginPayload) => request.post<ApiEnvelope<LoginData>>("/agent/auth/login", payload);
export const heartbeat = () => request.post<ApiEnvelope<{ online: boolean; server_time: string }>>("/agent/auth/heartbeat");
export const logout = () => request.post<ApiEnvelope<null>>("/agent/auth/logout");
export const changeAgentPassword = (payload: { old_password: string; password: string; confirm_password: string }) => request.post<ApiEnvelope<null>>("/agent/auth/password", payload);
export type SecurityPolicy = { weak_passwords: string[]; minimum_length: number; requires_letter: boolean; requires_number: boolean };
export const getSecurityPolicy = () => request.get<ApiEnvelope<SecurityPolicy>>("/security-policy", { headers: { "X-Skip-Global-Loading": "1" } });
export const verifyCaptcha = (payload: { captcha_id: string; answer: string }) => request.post<ApiEnvelope<{ verified: boolean }>>("/agent/auth/captcha/verify", payload);
