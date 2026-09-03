import http from "./http";
import type { LoginResponse, MenuItem } from "../types";

interface Envelope<T> {
  code: number;
  message: string;
  data: T;
  request_id: string;
}
export const login = (payload: { username: string; password: string }) =>
  http.post<never, Envelope<LoginResponse>>("/admin/auth/login", payload);
export const logout = () =>
  http.post<never, Envelope<null>>("/admin/auth/logout");
export const heartbeat = () =>
  http.post<never, Envelope<{ online: boolean; server_time: string }>>(
    "/admin/auth/heartbeat",
  );
export type Branding = {
  platform_name: string;
  site_id: number | null;
  site_name: string;
};
export const getBranding = () =>
  http.get<never, Envelope<Branding>>("/branding");
export const getBrandingSettings = () =>
  http.get<never, Envelope<{ platform_name: string }>>(
    "/admin/system-settings/branding",
  );
export const saveBrandingSettings = (payload: { platform_name: string }) =>
  http.put<never, Envelope<{ platform_name: string }>>(
    "/admin/system-settings/branding",
    payload,
  );
export const getMenus = () =>
  http.get<never, Envelope<MenuItem[]>>("/admin/auth/menus");
export const listResource = (
  resource: string,
  params?: Record<string, unknown>,
) =>
  http.get<never, Envelope<{ list: Record<string, unknown>[]; total: number }>>(
    `/admin/${endpoint(resource)}`,
    { params },
  );
export const getAuditLog = (id: number) =>
  http.get<never, Envelope<Record<string, unknown>>>(`/admin/audit-logs/${id}`);
export const clearAuditLogs = () =>
  http.delete<never, Envelope<{ cleared_count: number }>>("/admin/audit-logs");
const endpoint = (resource: string) =>
  ({ "sub-agents": "sub_agents", "audit-logs": "audit-logs" })[resource] ||
  resource;
export const createResource = (
  resource: string,
  payload: Record<string, unknown>,
) =>
  http.post<never, Envelope<Record<string, unknown>>>(
    `/admin/${endpoint(resource)}`,
    payload,
  );
export const updateResource = (
  resource: string,
  id: number,
  payload: Record<string, unknown>,
) =>
  http.put<never, Envelope<Record<string, unknown>>>(
    `/admin/${endpoint(resource)}/${id}`,
    payload,
  );
export const deleteResource = (
  resource: string,
  id: number,
  params?: Record<string, unknown>,
) =>
  http.delete<never, Envelope<null>>(`/admin/${endpoint(resource)}/${id}`, {
    params,
  });
export type BetDetail = {
  id: number;
  row_key?: string;
  number_index?: number;
  lottery: string;
  number_text?: string;
  number_count?: number;
  hundreds: string;
  tens: string;
  units: string;
  amount: string;
  odds?: string;
  category?: string;
  play_type?: string;
  source_text?: string;
  win_amount?: string;
  result_status?: "pending" | "won" | "partial" | "unwon" | string;
  draw_numbers?: string;
};
export const getBetDetails = (
  id: number,
  params?: { page?: number; page_size?: number },
) =>
  http.get<
    never,
    Envelope<{
      record: Record<string, any>;
      list: BetDetail[];
      total: number;
      page: number;
      page_size: number;
    }>
  >(`/admin/bet-record-details/${id}`, { params });
export const updateBetDetail = (
  id: number,
  payload: {
    number_index?: number;
    number_text: string;
    amount: string;
    play_type?: string;
    source_text?: string;
  },
) => http.put<never, Envelope<null>>(`/admin/bet-details/${id}`, payload);
export type BatchBetNumber = {
  key: string;
  record_id: number;
  detail_id: number;
  number_index: number;
  value: string;
  amount: string;
  source_text: string;
};
export type BatchBetUser = {
  key: string;
  user_id: number;
  site_id: number;
  username: string;
  display_name: string;
  site_name: string;
  number_count?: number;
  numbers: BatchBetNumber[];
};
export type BatchBetLottery = {
  id: number;
  name: string;
  code: string;
  sort: number;
};
export type BatchBetOptions = {
  lotteries: BatchBetLottery[];
  lottery: BatchBetLottery | null;
  issue_no: string;
  issues: string[];
  selected_record_ids?: number[];
  selected_user_ids?: number[];
  users: BatchBetUser[];
};
export const getBatchBetOptions = (params?: { lottery_id?: number; lottery?: string; issue_no?: string; user_ids?: number[]; record_ids?: number[] }) =>
  http.get<never, Envelope<BatchBetOptions>>(
    "/admin/bet-details/batch-options",
    { params: { ...params, user_ids: params?.user_ids?.join(','), record_ids: params?.record_ids?.join(',') } },
  );
export const replaceBatchBetNumbers = (payload: {
  lottery_id: number;
  issue_no: string;
  selections: Array<{ detail_id: number; number_index: number }>;
  replacements: { hundreds: string; tens: string; units: string };
}) =>
  http.put<never, Envelope<{ changed: number }>>(
    "/admin/bet-details/batch-replace",
    payload,
  );
export type MasterBetDetail = {
  detail_id: number;
  lottery: string;
  number_text: string;
  play_type: string;
  amount: string;
};
export type MasterBetRecord = {
  record_id: number;
  id: number;
  site_id: number;
  user_id: number;
  username: string;
  site_name: string;
  issue_no: string;
  submission_id?: number;
  amount: string;
  bet_count: number;
  source_text?: string;
  formatted_text?: string;
  details: MasterBetDetail[];
};
export type MasterBatchPreview = {
  record_ids: number[];
  changed_count: number;
  skipped_count: number;
  changes: Array<{
    record_id: number;
    detail_id: number;
    issue_no: string;
    old_number: string;
    new_number: string;
    old_play: string;
    new_play: string;
    old_amount: string;
    new_amount: string;
  }>;
  skipped: Array<{ record_id: number; detail_id: number; reason: string }>;
};
export const getMasterBetOptions = () =>
  http.get<never, Envelope<{ records: MasterBetRecord[] }>>(
    "/admin/bet-records/batch-options",
  );
export const previewMasterBetBatch = (payload: {
  record_ids: number[];
  operation: "replace_number" | "replace_play" | "set_amount";
  payload: Record<string, unknown>;
}) =>
  http.post<never, Envelope<MasterBatchPreview>>(
    "/admin/bet-records/batch-preview",
    payload,
  );
export const applyMasterBetBatch = (payload: {
  record_ids: number[];
  operation: "replace_number" | "replace_play" | "set_amount";
  payload: Record<string, unknown>;
}) =>
  http.post<never, Envelope<{ changed: number }>>(
    "/admin/bet-records/batch-apply",
    payload,
  );
export const enterSite = (id: number) =>
  http.get<never, Envelope<{ url: string; token: string; name?: string }>>(
    `/admin/agent-center/${id}/enter`,
  );
export type OrganizationLevel =
  | "director"
  | "shareholder"
  | "small_shareholder"
  | "general_agent"
  | "agent";
export type OrganizationNode = {
  id: number;
  site_id: number;
  parent_id: number;
  level: OrganizationLevel;
  level_label: string;
  next_level: OrganizationLevel | null;
  depth: number;
  path: string;
  name: string;
  code: string;
  credit_limit: string;
  balance?: string;
  share_rate?: string;
  max_share_rate?: string;
  child_count?: number;
  children?: OrganizationNode[];
  boards?: LotteryBoard[];
  board_codes?: string[];
  account_id?: number | null;
  username?: string | null;
  display_name?: string | null;
  phone?: string | null;
  online?: number;
  last_login_at?: string | null;
  last_login_ip?: string | null;
  last_login_location?: string | null;
  last_login_device?: string | null;
  permissions: string[];
  settings: Record<string, unknown>;
  status: number;
};
export type OrganizationAccount = {
  id: number;
  organization_id: number;
  username: string;
  display_name: string;
  phone?: string;
  permissions: string[];
  status: number;
  online?: number;
  last_seen_at?: string;
  last_login_at?: string;
  last_login_ip?: string;
  last_login_location?: string;
  last_login_device?: string;
};
export type OrganizationCatalog = {
  levels: Array<{ value: OrganizationLevel; label: string }>;
  permissions: Array<{ code: string; label: string }>;
};
export type OrganizationResponse = {
  site: {
    id: number;
    name: string;
    credit_limit?: string;
    available_balance?: string;
    director_allocated_score?: string;
    director_count?: number;
  };
  nodes: OrganizationNode[];
  members?: unknown[];
  accounts: OrganizationAccount[];
  boards?: LotteryBoard[];
  catalog: OrganizationCatalog;
  site_max_share_rate?: string;
};
export type InitialCredential = {
  id: number;
  username: string;
  initial_password: string;
  must_change_password: number;
};
export const getSiteOrganizations = (siteId: number) =>
  http.get<never, Envelope<OrganizationResponse>>(
    `/admin/sites/${siteId}/organizations`,
  );
export const createOrganization = (
  siteId: number,
  payload: Record<string, unknown>,
) =>
  http.post<never, Envelope<{ id: number }>>(
    `/admin/sites/${siteId}/organizations`,
    payload,
  );
export const updateOrganization = (
  id: number,
  payload: Record<string, unknown>,
) => http.put<never, Envelope<null>>(`/admin/organizations/${id}`, payload);
export const setDirectorCredit = (id: number, credit_limit: number) =>
  http.put<never, Envelope<Record<string, unknown>>>(
    `/admin/organizations/${id}/credit`,
    { credit_limit },
  );
export const setDirectorCreditShare = (
  id: number,
  payload: { credit_limit: number; max_share_rate: number },
) =>
  http.put<never, Envelope<Record<string, unknown>>>(
    `/admin/organizations/${id}/credit-share`,
    payload,
  );
export const deleteOrganization = (id: number) =>
  http.delete<never, Envelope<null>>(`/admin/organizations/${id}`);
export const createOrganizationAccount = (
  organizationId: number,
  payload: Record<string, unknown>,
) =>
  http.post<never, Envelope<InitialCredential>>(
    `/admin/organizations/${organizationId}/accounts`,
    payload,
  );
export const updateOrganizationAccount = (
  id: number,
  payload: Record<string, unknown>,
) =>
  http.put<never, Envelope<null>>(
    `/admin/organization-accounts/${id}`,
    payload,
  );
export const deleteOrganizationAccount = (id: number) =>
  http.delete<never, Envelope<null>>(`/admin/organization-accounts/${id}`);
export const saveOrganizationProfitShare = (
  siteId: number,
  childId: number,
  payload: { share_rate: number; max_share_rate: number },
) =>
  http.put<never, Envelope<Record<string, unknown>>>(
    `/admin/sites/${siteId}/profit-shares/${childId}`,
    payload,
  );
export type AgentPermissionNode = {
  code: string;
  label: string;
  type: "route" | "page" | "button";
  children?: AgentPermissionNode[];
};
export type AgentPermissionLevel = { value: OrganizationLevel; label: string };
export type SiteAgentPermissions = {
  site: { id: number; name: string };
  levels: AgentPermissionLevel[];
  tree: AgentPermissionNode[];
  allowed_codes_by_level: Record<OrganizationLevel, string[]>;
  permissions_by_level: Record<OrganizationLevel, string[]>;
};
export const getSiteAgentPermissions = (siteId: number) =>
  http.get<never, Envelope<SiteAgentPermissions>>(
    `/admin/sites/${siteId}/agent-permissions`,
  );
export const saveSiteAgentPermissions = (
  siteId: number,
  permissionsByLevel: Record<OrganizationLevel, string[]>,
) =>
  http.put<
    never,
    Envelope<{ permissions_by_level: Record<OrganizationLevel, string[]> }>
  >(`/admin/sites/${siteId}/agent-permissions`, {
    permissions_by_level: permissionsByLevel,
  });
export type ScoreOverview = {
  total_score: string;
  available_score: string;
  allocated_score: string;
  site_available?: string;
  organization_available: string;
  user_available: string;
  user_locked: string;
};
export type DashboardScoreData = {
  overview: ScoreOverview & {
    accounted_score: string;
    difference_score: string;
  };
  today: { total: number; total_in: string; total_out: string; net: string };
  trend: Array<{
    day: string;
    total: number;
    total_in: string;
    total_out: string;
    net: string;
  }>;
  sites: Array<{
    site_id: number;
    site_name: string;
    status: number;
    allocated_score: string;
    site_available?: string;
    director_allocated_score?: string;
    organization_available: string;
    user_available: string;
    user_locked: string;
    circulating_score: string;
    organization_count: number;
    user_count: number;
  }>;
  levels: Array<{
    level: OrganizationLevel;
    label: string;
    account_count: number;
    credit_limit: string;
    available_score: string;
  }>;
  categories: Array<{
    category: string;
    total: number;
    total_in: string;
    total_out: string;
  }>;
  recent: ScoreLedgerRow[];
  counts: { sites: number; organizations: number; users: number };
  generated_at: string;
};
export type ScoreLedgerRow = {
  id: number;
  transaction_no: string;
  site_id: number;
  site_name?: string;
  organization_id: number;
  account_type: string;
  account_id: number;
  account_name?: string;
  direction: string;
  amount: string;
  balance_before: string;
  balance_after: string;
  source_type: string;
  category: string;
  reason?: string;
  issue_no?: string;
  operator_name?: string;
  counterparty_name?: string;
  metadata?: Record<string, unknown>;
  created_at: string;
  [key: string]: unknown;
};
export const getScoreOverview = () =>
  http.get<never, Envelope<ScoreOverview>>("/admin/score-ledger/overview");
export const getDashboardScore = () =>
  http.get<never, Envelope<DashboardScoreData>>("/admin/dashboard/score");
export const updatePlatformTotal = (payload: {
  total_score: number;
  note: string;
}) =>
  http.put<never, Envelope<Record<string, unknown>>>(
    "/admin/score-ledger/total",
    payload,
  );
export const getScoreLedger = (params?: Record<string, unknown>) =>
  http.get<
    never,
    Envelope<{
      list: ScoreLedgerRow[];
      total: number;
      page: number;
      page_size: number;
      summary: {
        total: number;
        total_in: string;
        total_out: string;
        net: string;
      };
    }>
  >("/admin/score-ledger", { params });
export const getScoreLedgerDetail = (id: number) =>
  http.get<never, Envelope<ScoreLedgerRow>>(`/admin/score-ledger/${id}`);
export const getAgreement = (siteId: number) =>
  http.get<
    never,
    Envelope<{ site_id: number; title: string; content: string }>
  >("/admin/site-settings/agreement", { params: { site_id: siteId } });
export const saveAgreement = (payload: {
  site_id: number;
  title: string;
  content: string;
}) =>
  http.put<never, Envelope<{ title: string; content: string }>>(
    "/admin/site-settings/agreement",
    payload,
  );
export const getAnnouncement = (siteId: number) =>
  http.get<
    never,
    Envelope<{ site_id: number; title: string; content: string }>
  >("/admin/site-settings/announcement", { params: { site_id: siteId } });
export const saveAnnouncement = (payload: {
  site_id: number;
  title: string;
  content: string;
}) =>
  http.put<never, Envelope<{ title: string; content: string }>>(
    "/admin/site-settings/announcement",
    payload,
  );
export const getAgentAgreement = (siteId: number) =>
  http.get<
    never,
    Envelope<{ site_id: number; title: string; content: string }>
  >("/admin/site-settings/agent-agreement", { params: { site_id: siteId } });
export const saveAgentAgreement = (payload: {
  site_id: number;
  title: string;
  content: string;
}) =>
  http.put<never, Envelope<{ title: string; content: string }>>(
    "/admin/site-settings/agent-agreement",
    payload,
  );
export const getAgentAnnouncement = (siteId: number) =>
  http.get<
    never,
    Envelope<{ site_id: number; title: string; content: string }>
  >("/admin/site-settings/agent-announcement", { params: { site_id: siteId } });
export const saveAgentAnnouncement = (payload: {
  site_id: number;
  title: string;
  content: string;
}) =>
  http.put<never, Envelope<{ title: string; content: string }>>(
    "/admin/site-settings/agent-announcement",
    payload,
  );
export type RuleSettings = {
  site_id?: number;
  title: string;
  basic: string;
  special: string;
  amount: string;
  text: string;
};
export const getRules = (siteId: number) =>
  http.get<never, Envelope<RuleSettings>>("/admin/site-settings/rules", {
    params: { site_id: siteId },
  });
export const saveRules = (payload: RuleSettings & { site_id: number }) =>
  http.put<never, Envelope<RuleSettings>>(
    "/admin/site-settings/rules",
    payload,
  );
export type SiteTimingRule = {
  start_time: string;
  end_time: string;
  allow_bet: number;
  mask_enabled: number;
  show_next_issue: number;
  header_show_next_issue?: number;
  display_text: string;
};
export type SiteBettingControl = {
  cutoff_enabled: number;
  cutoff_time: string | null;
  mask_enabled: number;
  refund_enabled: number;
  timing_rules?: SiteTimingRule[];
};
export const getSiteBettingControls = (siteId: number) =>
  http.get<
    never,
    Envelope<{
      site_id: number;
      controls: Record<string, SiteBettingControl>;
      draw_history_limit: number;
    }>
  >("/admin/site-settings/betting-controls", { params: { site_id: siteId } });
export const saveSiteBettingControls = (payload: {
  site_id: number;
  controls: Record<string, SiteBettingControl>;
  draw_history_limit: number;
}) =>
  http.put<
    never,
    Envelope<{
      site_id: number;
      controls: Record<string, SiteBettingControl>;
      draw_history_limit: number;
    }>
  >("/admin/site-settings/betting-controls", payload);
export interface Lottery {
  id: number;
  name: string;
  code: string;
  unit_stake: string;
  source_type: "official" | "system";
  system_interval_seconds: number;
  system_issue_mode: "auto" | "manual";
  system_initial_issue: string | null;
  odds_source_lottery_id?: number | null;
  status: number;
  sort: number;
  site_ids: number[];
  cutoff_enabled: number;
  cutoff_time: string | null;
  mask_enabled: number;
  refund_enabled: number;
}
export const listLotteries = (params?: Record<string, unknown>) =>
  http.get<never, Envelope<{ list: Lottery[]; total: number }>>(
    "/admin/lotteries",
    { params },
  );
export type LotteryBettingControls = {
  cutoff_enabled: number;
  cutoff_time: string | null;
  mask_enabled: number;
  refund_enabled: number;
};
export type LotterySourceSettings = {
  source_type: "official" | "system";
  system_interval_seconds: number;
  system_issue_mode: "auto" | "manual";
  system_initial_issue: string | null;
  odds_source_lottery_id?: number | null;
};
export const createLottery = (
  payload: {
    name: string;
    code: string;
    unit_stake: number;
    status: number;
    sort: number;
  } & Partial<LotteryBettingControls> &
    Partial<LotterySourceSettings>,
) => http.post<never, Envelope<{ id: number }>>("/admin/lotteries", payload);
export const updateLottery = (
  id: number,
  payload: Partial<
    {
      name: string;
      code: string;
      unit_stake: number;
      status: number;
      sort: number;
    } & LotteryBettingControls &
      LotterySourceSettings
  >,
) => http.put<never, Envelope<null>>(`/admin/lotteries/${id}`, payload);
export const deleteLottery = (id: number) =>
  http.delete<never, Envelope<null>>(`/admin/lotteries/${id}`);
export type LotteryRules = {
  id: number;
  name: string;
  code: string;
  content: string;
};
export const getLotteryRules = (id: number) =>
  http.get<never, Envelope<LotteryRules>>(`/admin/lotteries/${id}/rules`);
export const saveLotteryRules = (id: number, content: string) =>
  http.put<never, Envelope<LotteryRules>>(`/admin/lotteries/${id}/rules`, {
    content,
  });
export const assignLotterySites = (id: number, site_ids: number[]) =>
  http.put<never, Envelope<{ site_ids: number[] }>>(
    `/admin/lotteries/${id}/sites`,
    { site_ids },
  );
export const getLotteryConfig = () =>
  http.get<never, Envelope<{ base_url: string }>>("/admin/lottery-config");
export const saveLotteryConfig = (payload: { base_url: string }) =>
  http.put<never, Envelope<{ base_url: string }>>(
    "/admin/lottery-config",
    payload,
  );
export type ThirdPartyQuickEntryAccount = {
  id: string; username: string; password: string;
  rate_window_seconds?: number | null; rate_limit_calls?: number | null; freeze_seconds?: number | null;
  is_current?: boolean; call_count?: number; success_count?: number; failure_count?: number;
  login_count?: number; login_failure_count?: number; window_call_count?: number;
  health_check_count?: number; last_health_at?: string; last_health_status?: string; last_health_error?: string;
  ak?: string; ak_expires_at?: string; last_used_at?: string; last_duration_ms?: number;
  last_status?: string; last_error?: string; frozen_until?: string;
};
export type ThirdPartyQuickEntryConfig = {
  enabled: boolean; strict: boolean; base_url: string; captcha_endpoint: string;
  login_endpoint: string; recognize_endpoint: string; captcha_ocr_endpoint: string;
  captcha_ocr_command: string; captcha_ocr_language: string; request_timeout: number; token_ttl_seconds: number; rate_window_seconds: number;
  freeze_after_calls: number; freeze_seconds: number; accounts: ThirdPartyQuickEntryAccount[];
  current_account?: ThirdPartyQuickEntryAccount | null;
};
export const getThirdPartyQuickEntryConfig = (siteId?: number) =>
  http.get<never, Envelope<ThirdPartyQuickEntryConfig>>('/admin/system-settings/third-party-quick-entry', { params: siteId ? { site_id: siteId } : undefined });
export const saveThirdPartyQuickEntryConfig = (payload: Partial<ThirdPartyQuickEntryConfig> & { site_id?: number }) =>
  http.put<never, Envelope<ThirdPartyQuickEntryConfig>>('/admin/system-settings/third-party-quick-entry', payload);
export const testThirdPartyQuickEntryConfig = (payload?: { site_id?: number; text?: string; lottery?: string }) =>
  http.post<never, Envelope<{ code: number; total_amount: number | string | null; total_count: number | string | null; account?: { id: string; username: string; ak: string } | null; result: Record<string, unknown> }>>('/admin/system-settings/third-party-quick-entry/test', payload, { timeout: 45000 });
export const loginThirdPartyQuickEntryAccount = (accountId: string, siteId?: number) =>
  http.post<never, Envelope<{ id: string; username: string; ak: string; ak_expires_at: string; duration_ms: number; attempts: number }>>(`/admin/system-settings/third-party-quick-entry/accounts/${encodeURIComponent(accountId)}/login`, siteId ? { site_id: siteId } : {}, { timeout: 45000 });
export type LotteryConfigTest = {
  base_url: string;
  url: string;
  http_status: number;
  response_time_ms: number;
  available: boolean;
  api_code?: number | null;
};
export const testLotteryConfig = () =>
  http.post<never, Envelope<LotteryConfigTest>>("/admin/lottery-config/test");
export type SecurityPolicy = {
  weak_passwords: string[];
  minimum_length?: number;
  requires_letter?: boolean;
  requires_number?: boolean;
};
export const getSecurityPolicy = () =>
  http.get<never, Envelope<SecurityPolicy>>("/admin/system-settings/security");
export const saveSecurityPolicy = (payload: { weak_passwords: string[] }) =>
  http.put<never, Envelope<{ weak_passwords: string[] }>>(
    "/admin/system-settings/security",
    payload,
  );
export type LotteryHistory = {
  id: number;
  code: string;
  draw_day: string;
  one: number;
  two: number;
  three: number;
  numbers: string;
  open_time: string;
  next_code?: string;
  is_opened: number;
};
export const getLotteryHistory = (
  id: number,
  params?: Record<string, unknown>,
) =>
  http.get<
    never,
    Envelope<{
      list: LotteryHistory[];
      total: number;
      page: number;
      page_size: number;
    }>
  >(`/admin/lottery-history/${id}`, { params });
export const updateLotteryHistory = (
  id: number,
  payload: { numbers: string },
) =>
  http.put<never, Envelope<{ id: number; numbers: string }>>(
    `/admin/lottery-history/${id}`,
    payload,
  );
export type LotteryBoard = {
  id?: number;
  code: string;
  name: string;
  status?: number;
  sort?: number;
};
export const listLotteryBoards = () =>
  http.get<never, Envelope<{ list: LotteryBoard[] }>>("/admin/lottery-boards");
export const createLotteryBoard = (payload: {
  code: string;
  name: string;
  status?: number;
  sort?: number;
}) =>
  http.post<never, Envelope<LotteryBoard>>("/admin/lottery-boards", payload);
export const updateLotteryBoard = (
  id: number,
  payload: Partial<LotteryBoard>,
) => http.put<never, Envelope<null>>(`/admin/lottery-boards/${id}`, payload);
export const copyLotteryOdds = (
  id: number,
  source_lottery_id: number,
  replace = false,
  board_code = "A",
) =>
  http.post<never, Envelope<null>>(`/admin/lotteries/${id}/copy-odds`, {
    source_lottery_id,
    replace: replace ? 1 : 0,
    board_code,
  });
export type OptionalOddsValue = string | null;
export type LotteryOdds = {
  id: number;
  lottery_id: number;
  category_id: number;
  category: string;
  name: string;
  min_bet: OptionalOddsValue;
  odds_limit: OptionalOddsValue;
  single_bet_limit: OptionalOddsValue;
  single_item_limit: OptionalOddsValue;
  odds: OptionalOddsValue;
  offline_rebate: OptionalOddsValue;
  status: number;
  sort: number;
};
export type LotteryOddsCategory = {
  id: number;
  lottery_id: number;
  name: string;
  is_playable: number;
  min_bet: OptionalOddsValue;
  odds_limit: OptionalOddsValue;
  single_bet_limit: OptionalOddsValue;
  single_item_limit: OptionalOddsValue;
  odds: OptionalOddsValue;
  offline_rebate: OptionalOddsValue;
  status: number;
  sort: number;
  children: LotteryOdds[];
};
export const listLotteryOdds = (
  id: number,
  params?: { page?: number; page_size?: number; board_code?: string },
) =>
  http.get<
    never,
    Envelope<{
      categories: LotteryOddsCategory[];
      total: number;
      category_total: number;
      page: number;
      page_size: number;
      board_code: string;
      boards: LotteryBoard[];
    }>
  >(`/admin/lottery-odds/${id}`, { params });
export const createLotteryOddsCategory = (
  id: number,
  payload: { name: string; status: number; sort: number },
) =>
  http.post<never, Envelope<{ id: number }>>(
    `/admin/lottery-odds/${id}/categories`,
    payload,
  );
export const updateLotteryOddsCategory = (
  id: number,
  categoryId: number,
  payload: Partial<{ name: string; status: number; sort: number }>,
) =>
  http.put<never, Envelope<null>>(
    `/admin/lottery-odds/${id}/categories/${categoryId}`,
    payload,
  );
export const deleteLotteryOddsCategory = (id: number, categoryId: number) =>
  http.delete<never, Envelope<null>>(
    `/admin/lottery-odds/${id}/categories/${categoryId}`,
  );
export const createLotteryOdds = (
  id: number,
  payload: Omit<LotteryOdds, "id" | "lottery_id" | "category">,
) =>
  http.post<never, Envelope<{ id: number }>>(
    `/admin/lottery-odds/${id}`,
    payload,
  );
export const updateLotteryOdds = (
  id: number,
  oddsId: number,
  payload: Partial<LotteryOdds>,
) =>
  http.put<never, Envelope<null>>(
    `/admin/lottery-odds/${id}/${oddsId}`,
    payload,
  );
export const deleteLotteryOdds = (id: number, oddsId: number) =>
  http.delete<never, Envelope<null>>(`/admin/lottery-odds/${id}/${oddsId}`);

export type RobotSite = { id: number; name: string };
export type RobotNode = {
  id: number;
  parent_id: number;
  level: string;
  name: string;
  code: string;
  depth: number;
  path: string;
};
export type RobotLottery = {
  id: number;
  name: string;
  code: string;
  sort: number;
};
export type RobotOptions = {
  sites: RobotSite[];
  site_id: number;
  nodes: RobotNode[];
  lotteries: RobotLottery[];
};
export type RobotConfig = { lottery_id: number; enabled: number };
export type RobotMonthlyRule = {
  month: string;
  weeks?: RobotWeeklyRule[];
  win_weight?: string | number;
  max_amount?: string | number;
};
export type RobotWeeklyRule = {
  week: number;
  win_weight: string | number;
  max_amount: string | number;
};
export type RobotHourlyWeight = {
  start: string;
  end: string;
  weight: string | number;
};
export type Robot = {
  id: number;
  tenant_id: number;
  site_id: number;
  organization_id: number;
  user_id: number;
  name: string;
  username: string;
  plain_password: string;
  organization_name: string;
  site_name: string;
  balance: string;
  credit_balance: string;
  used_balance: string;
  available_score: string;
  min_amount: string;
  max_amount: string;
  amount_precision: number;
  start_at: string;
  next_run_at?: string | null;
  last_bet_at?: string | null;
  last_run_at?: string | null;
  last_run_status?: "success" | "skipped" | "failed" | string | null;
  last_run_message?: string | null;
  interval_min: number;
  interval_max: number;
  weight_fu: string;
  weight_ti: string;
  weight_futi: string;
  win_weight: string;
  loss_weight: string;
  monthly_rules?: RobotMonthlyRule[];
  hourly_weights?: RobotHourlyWeight[];
  lottery_configs: RobotConfig[];
  skip_windows?: Array<{ start: string; end: string }>;
  status: "running" | "stopped" | "converted";
};
export type RobotHistory = {
  id: number;
  issue_no: string;
  bet_count: number;
  amount: string;
  status: string;
  win_amount: string;
  placed_at: string;
  formatted_text?: string;
  source_text?: string;
};
export type RobotRunLog = {
  id: number;
  robot_id: number;
  level: string;
  status: string | null;
  message: string;
  context: Record<string, unknown> & {
    execution_at?: string;
    scheduled_at?: string;
    lottery?: string;
    text?: string;
    next_run_at?: string;
    weight_miss?: boolean;
  };
  created_at: string;
};
export const getRobotOptions = (siteId?: number) =>
  http.get<never, Envelope<RobotOptions>>("/admin/robots/options", {
    params: { site_id: siteId },
  });
export const listRobots = (siteId?: number) =>
  http.get<never, Envelope<{ list: Robot[]; total: number }>>("/admin/robots", {
    params: { site_id: siteId },
  });
export const createRobot = (payload: Record<string, unknown>) =>
  http.post<never, Envelope<{ id: number }>>("/admin/robots", payload);
export const updateRobot = (id: number, payload: Record<string, unknown>) =>
  http.put<never, Envelope<null>>(`/admin/robots/${id}`, payload);
export const setRobotStatus = (id: number, status: "running" | "stopped") =>
  http.post<never, Envelope<null>>(`/admin/robots/${id}/status`, { status });
export const convertRobot = (id: number) =>
  http.post<never, Envelope<null>>(`/admin/robots/${id}/convert`);
export const getRobotHistory = (
  id: number,
  params?: { lottery?: string; page?: number; page_size?: number },
) =>
  http.get<
    never,
    Envelope<{
      robot_id: number;
      list: RobotHistory[];
      total: number;
      page: number;
      page_size: number;
    }>
  >(`/admin/robots/${id}/history`, { params });
export const clearRobotLatest = (id: number) =>
  http.post<never, Envelope<{ deleted: number; cutoff: string | null }>>(
    `/admin/robots/${id}/clear-latest`,
  );
export const getRobotLogs = (id: number, params?: { after_id?: number; limit?: number }) =>
  http.get<never, Envelope<{ robot_id: number; list: RobotRunLog[]; last_id: number; limit: number }>>(
    `/admin/robots/${id}/logs`, { params },
  );
