import { request, type ApiEnvelope } from "../utils/request";

export type CaptchaPayload = { captcha_id: string; image: string };
export type LoginPayload = { username: string; password: string; captcha: string; captcha_id: string };
export type LoginData = { token: string };

export const getCaptcha = () => request.get<ApiEnvelope<CaptchaPayload>>("/agent/auth/captcha");
export const login = (payload: LoginPayload) => request.post<ApiEnvelope<LoginData>>("/agent/auth/login", payload);
export const verifyCaptcha = (payload: { captcha_id: string; answer: string }) => request.post<ApiEnvelope<{ verified: boolean }>>("/agent/auth/captcha/verify", payload);
