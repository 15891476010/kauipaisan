import { request, type ApiEnvelope } from "../utils/request";

export type Announcement = { title: string; content: string };
export type Agreement = { title: string; content: string };
export type RuleSettings = {
  title: string;
  basic: string;
  special: string;
  amount: string;
  text: string;
};
export type UserProfile = {
  balance: string;
  total_balance: string;
  credit_balance: string;
  used_balance: string;
  available_balance: string;
};
export type Lottery = {
  id: number;
  name: string;
  code: string;
  sort: number;
  latest_code: string;
  latest_numbers: string;
  next_code: string;
  next_open_time: string | null;
  can_bet?: boolean;
};
export type MemberLotteryPermission = { lottery_id: number; name: string; code: string; can_view: boolean; can_bet: boolean; offline_rebate: string };
export type MemberLotteryOdds = {
  id: number;
  lottery_id: number;
  lottery_name: string;
  lottery_code: string;
  category: string;
  name: string;
  min_bet: string;
  odds_limit: string;
  single_bet_limit: string;
  single_item_limit: string;
  odds: string;
  offline_rebate: string;
  direct_category?: number;
};
export type AgentCreditSummary = { total_credit: string; available_credit: string; allocated_credit: string };
export type AgentMember = {
  id: number;
  username: string;
  display_name: string;
  phone: string | null;
  balance: string;
  credit_balance: string;
  used_balance: string;
  available_balance: string;
  status: number;
  remark?: string | null;
  account_state?: "enabled" | "disabled" | "bet_paused";
  interception_rate?: string;
  type: string;
  last_login_at: string | null;
  created_at: string | null;
  permissions?: MemberLotteryPermission[];
  odds?: MemberLotteryOdds[];
  summary?: AgentCreditSummary;
};
export type AgentMemberList = {
  list: AgentMember[];
  total: number;
  page: number;
  page_size: number;
  total_credit: string;
  available_credit: string;
  allocated_credit: string;
};

export const getAnnouncement = () => request.get<ApiEnvelope<Announcement>>("/agent/announcement");
export const getAgreement = () => request.get<ApiEnvelope<Agreement>>("/agent/agreement");
export const getRules = () => request.get<ApiEnvelope<RuleSettings>>("/agent/rules");
export const getProfile = () => request.get<ApiEnvelope<UserProfile>>("/user/profile");
export const getLotteries = () => request.get<ApiEnvelope<{ list: Lottery[] }>>("/agent/lotteries");
export const getAgentMembers = (params: Record<string, unknown>) => request.get<ApiEnvelope<AgentMemberList>>("/agent/members", { params });
export const getAgentMember = (id: number) => request.get<ApiEnvelope<AgentMember>>(`/agent/members/${id}`);
export const createAgentMember = (payload: Record<string, unknown>) => request.post<ApiEnvelope<{ id: number }>>("/agent/members", payload);
export const updateAgentMember = (id: number, payload: Record<string, unknown>) => request.put<ApiEnvelope<null>>(`/agent/members/${id}`, payload);
