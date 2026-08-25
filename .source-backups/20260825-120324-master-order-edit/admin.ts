import http from './http'
import type { LoginResponse, MenuItem } from '../types'

interface Envelope<T> { code: number; message: string; data: T; request_id: string }
export const login = (payload: { username: string; password: string }) => http.post<never, Envelope<LoginResponse>>('/admin/auth/login', payload)
export const logout = () => http.post<never, Envelope<null>>('/admin/auth/logout')
export const heartbeat = () => http.post<never, Envelope<{ online: boolean; server_time: string }>>('/admin/auth/heartbeat')
export type Branding = { platform_name: string; site_id: number | null; site_name: string }
export const getBranding = () => http.get<never, Envelope<Branding>>('/branding')
export const getBrandingSettings = () => http.get<never, Envelope<{ platform_name: string }>>('/admin/system-settings/branding')
export const saveBrandingSettings = (payload: { platform_name: string }) => http.put<never, Envelope<{ platform_name: string }>>('/admin/system-settings/branding', payload)
export const getMenus = () => http.get<never, Envelope<MenuItem[]>>('/admin/auth/menus')
export const listResource = (resource: string, params?: Record<string, unknown>) => http.get<never, Envelope<{ list: Record<string, unknown>[]; total: number }>>(`/admin/${endpoint(resource)}`, { params })
export const getAuditLog = (id: number) => http.get<never, Envelope<Record<string, unknown>>>(`/admin/audit-logs/${id}`)
export const clearAuditLogs = () => http.delete<never, Envelope<{ cleared_count: number }>>('/admin/audit-logs')
const endpoint = (resource: string) => ({ 'sub-agents': 'sub_agents', 'audit-logs': 'audit-logs' }[resource] || resource)
export const createResource = (resource: string, payload: Record<string, unknown>) => http.post<never, Envelope<Record<string, unknown>>>(`/admin/${endpoint(resource)}`, payload)
export const updateResource = (resource: string, id: number, payload: Record<string, unknown>) => http.put<never, Envelope<Record<string, unknown>>>(`/admin/${endpoint(resource)}/${id}`, payload)
export const deleteResource = (resource: string, id: number, params?: Record<string, unknown>) => http.delete<never, Envelope<null>>(`/admin/${endpoint(resource)}/${id}`, { params })
export type BetDetail = { id: number; row_key?: string; number_index?: number; lottery: string; number_text?: string; number_count?: number; hundreds: string; tens: string; units: string; amount: string; odds?: string; category?: string; play_type?: string; source_text?: string; win_amount?: string; result_status?: string; draw_numbers?: string }
export const getBetDetails = (id: number, params?: { page?: number; page_size?: number }) => http.get<never, Envelope<{ record: Record<string, any>; list: BetDetail[]; total: number; page: number; page_size: number }>>(`/admin/bet-record-details/${id}`, { params })
export const updateBetDetail = (id: number, payload: { number_index: number; number_text: string; amount: string }) => http.put<never, Envelope<null>>(`/admin/bet-details/${id}`, payload)
export type BatchBetNumber = { key: string; detail_id: number; number_index: number; value: string; amount: string; source_text: string }
export type BatchBetUser = { key: string; user_id: number; site_id: number; username: string; display_name: string; site_name: string; numbers: BatchBetNumber[] }
export type BatchBetLottery = { id: number; name: string; code: string; sort: number }
export type BatchBetOptions = { lotteries: BatchBetLottery[]; lottery: BatchBetLottery | null; issue_no: string; users: BatchBetUser[] }
export const getBatchBetOptions = (lotteryId?: number) => http.get<never, Envelope<BatchBetOptions>>('/admin/bet-details/batch-options', { params: { lottery_id: lotteryId } })
export const replaceBatchBetNumbers = (payload: { lottery_id: number; issue_no: string; selections: Array<{ detail_id: number; number_index: number }>; replacements: { hundreds: string; tens: string; units: string } }) => http.put<never, Envelope<{ changed: number }>>('/admin/bet-details/batch-replace', payload)
export const enterSite = (id: number) => http.get<never, Envelope<{ url: string; token: string; name?: string }>>(`/admin/agent-center/${id}/enter`)
export type OrganizationLevel = 'director' | 'shareholder' | 'small_shareholder' | 'general_agent' | 'agent'
export type OrganizationNode = { id: number; site_id: number; parent_id: number; level: OrganizationLevel; level_label: string; next_level: OrganizationLevel | null; depth: number; path: string; name: string; code: string; credit_limit: string; balance?: string; share_rate?: string; max_share_rate?: string; child_count?: number; children?: OrganizationNode[]; account_id?: number | null; username?: string | null; display_name?: string | null; phone?: string | null; online?: number; last_login_at?: string | null; last_login_ip?: string | null; last_login_location?: string | null; last_login_device?: string | null; permissions: string[]; settings: Record<string, unknown>; status: number }
export type OrganizationAccount = { id: number; organization_id: number; username: string; display_name: string; phone?: string; permissions: string[]; status: number; online?: number; last_seen_at?: string; last_login_at?: string; last_login_ip?: string; last_login_location?: string; last_login_device?: string }
export type OrganizationCatalog = { levels: Array<{ value: OrganizationLevel; label: string }>; permissions: Array<{ code: string; label: string }> }
export type OrganizationResponse = { site: { id: number; name: string; credit_limit?: string; available_balance?: string; director_allocated_score?: string; director_count?: number }; nodes: OrganizationNode[]; accounts: OrganizationAccount[]; catalog: OrganizationCatalog; site_max_share_rate?: string }
export type InitialCredential = { id: number; username: string; initial_password: string; must_change_password: number }
export const getSiteOrganizations = (siteId: number) => http.get<never, Envelope<OrganizationResponse>>(`/admin/sites/${siteId}/organizations`)
export const createOrganization = (siteId: number, payload: Record<string, unknown>) => http.post<never, Envelope<{ id: number }>>(`/admin/sites/${siteId}/organizations`, payload)
export const updateOrganization = (id: number, payload: Record<string, unknown>) => http.put<never, Envelope<null>>(`/admin/organizations/${id}`, payload)
export const setDirectorCredit = (id: number, credit_limit: number) => http.put<never, Envelope<Record<string, unknown>>>(`/admin/organizations/${id}/credit`, { credit_limit })
export const setDirectorCreditShare = (id: number, payload: { credit_limit: number; max_share_rate: number }) => http.put<never, Envelope<Record<string, unknown>>>(`/admin/organizations/${id}/credit-share`, payload)
export const deleteOrganization = (id: number) => http.delete<never, Envelope<null>>(`/admin/organizations/${id}`)
export const createOrganizationAccount = (organizationId: number, payload: Record<string, unknown>) => http.post<never, Envelope<InitialCredential>>(`/admin/organizations/${organizationId}/accounts`, payload)
export const updateOrganizationAccount = (id: number, payload: Record<string, unknown>) => http.put<never, Envelope<null>>(`/admin/organization-accounts/${id}`, payload)
export const deleteOrganizationAccount = (id: number) => http.delete<never, Envelope<null>>(`/admin/organization-accounts/${id}`)
export const saveOrganizationProfitShare = (siteId: number, childId: number, payload: { share_rate: number; max_share_rate: number }) => http.put<never, Envelope<Record<string, unknown>>>(`/admin/sites/${siteId}/profit-shares/${childId}`, payload)
export type AgentPermissionNode = { code: string; label: string; type: 'route' | 'page' | 'button'; children?: AgentPermissionNode[] }
export type AgentPermissionLevel = { value: OrganizationLevel; label: string }
export type SiteAgentPermissions = { site: { id: number; name: string }; levels: AgentPermissionLevel[]; tree: AgentPermissionNode[]; allowed_codes_by_level: Record<OrganizationLevel, string[]>; permissions_by_level: Record<OrganizationLevel, string[]> }
export const getSiteAgentPermissions = (siteId: number) => http.get<never, Envelope<SiteAgentPermissions>>(`/admin/sites/${siteId}/agent-permissions`)
export const saveSiteAgentPermissions = (siteId: number, permissionsByLevel: Record<OrganizationLevel, string[]>) => http.put<never, Envelope<{ permissions_by_level: Record<OrganizationLevel, string[]> }>>(`/admin/sites/${siteId}/agent-permissions`, { permissions_by_level: permissionsByLevel })
export type ScoreOverview = { total_score: string; available_score: string; allocated_score: string; site_available?: string; organization_available: string; user_available: string; user_locked: string }
export type DashboardScoreData = {
  overview: ScoreOverview & { accounted_score: string; difference_score: string }
  today: { total: number; total_in: string; total_out: string; net: string }
  trend: Array<{ day: string; total: number; total_in: string; total_out: string; net: string }>
  sites: Array<{ site_id: number; site_name: string; status: number; allocated_score: string; site_available?: string; director_allocated_score?: string; organization_available: string; user_available: string; user_locked: string; circulating_score: string; organization_count: number; user_count: number }>
  levels: Array<{ level: OrganizationLevel; label: string; account_count: number; credit_limit: string; available_score: string }>
  categories: Array<{ category: string; total: number; total_in: string; total_out: string }>
  recent: ScoreLedgerRow[]
  counts: { sites: number; organizations: number; users: number }
  generated_at: string
}
export type ScoreLedgerRow = { id: number; transaction_no: string; site_id: number; site_name?: string; organization_id: number; account_type: string; account_id: number; account_name?: string; direction: string; amount: string; balance_before: string; balance_after: string; source_type: string; category: string; reason?: string; issue_no?: string; operator_name?: string; counterparty_name?: string; metadata?: Record<string, unknown>; created_at: string; [key: string]: unknown }
export const getScoreOverview = () => http.get<never, Envelope<ScoreOverview>>('/admin/score-ledger/overview')
export const getDashboardScore = () => http.get<never, Envelope<DashboardScoreData>>('/admin/dashboard/score')
export const updatePlatformTotal = (payload: { total_score: number; note: string }) => http.put<never, Envelope<Record<string, unknown>>>('/admin/score-ledger/total', payload)
export const getScoreLedger = (params?: Record<string, unknown>) => http.get<never, Envelope<{ list: ScoreLedgerRow[]; total: number; page: number; page_size: number; summary: { total: number; total_in: string; total_out: string; net: string } }>>('/admin/score-ledger', { params })
export const getScoreLedgerDetail = (id: number) => http.get<never, Envelope<ScoreLedgerRow>>(`/admin/score-ledger/${id}`)
export const getAgreement = (siteId: number) => http.get<never, Envelope<{ site_id: number; title: string; content: string }>>('/admin/site-settings/agreement', { params: { site_id: siteId } })
export const saveAgreement = (payload: { site_id: number; title: string; content: string }) => http.put<never, Envelope<{ title: string; content: string }>>('/admin/site-settings/agreement', payload)
export const getAnnouncement = (siteId: number) => http.get<never, Envelope<{ site_id: number; title: string; content: string }>>('/admin/site-settings/announcement', { params: { site_id: siteId } })
export const saveAnnouncement = (payload: { site_id: number; title: string; content: string }) => http.put<never, Envelope<{ title: string; content: string }>>('/admin/site-settings/announcement', payload)
export const getAgentAgreement = (siteId: number) => http.get<never, Envelope<{ site_id: number; title: string; content: string }>>('/admin/site-settings/agent-agreement', { params: { site_id: siteId } })
export const saveAgentAgreement = (payload: { site_id: number; title: string; content: string }) => http.put<never, Envelope<{ title: string; content: string }>>('/admin/site-settings/agent-agreement', payload)
export const getAgentAnnouncement = (siteId: number) => http.get<never, Envelope<{ site_id: number; title: string; content: string }>>('/admin/site-settings/agent-announcement', { params: { site_id: siteId } })
export const saveAgentAnnouncement = (payload: { site_id: number; title: string; content: string }) => http.put<never, Envelope<{ title: string; content: string }>>('/admin/site-settings/agent-announcement', payload)
export type RuleSettings = { site_id?: number; title: string; basic: string; special: string; amount: string; text: string }
export const getRules = (siteId: number) => http.get<never, Envelope<RuleSettings>>('/admin/site-settings/rules', { params: { site_id: siteId } })
export const saveRules = (payload: RuleSettings & { site_id: number }) => http.put<never, Envelope<RuleSettings>>('/admin/site-settings/rules', payload)
export type SiteBettingControl = { cutoff_enabled: number; cutoff_time: string | null; mask_enabled: number; refund_enabled: number }
export const getSiteBettingControls = (siteId: number) => http.get<never, Envelope<{ site_id: number; controls: Record<string, SiteBettingControl>; draw_history_limit: number }>>('/admin/site-settings/betting-controls', { params: { site_id: siteId } })
export const saveSiteBettingControls = (payload: { site_id: number; controls: Record<string, SiteBettingControl>; draw_history_limit: number }) => http.put<never, Envelope<{ site_id: number; controls: Record<string, SiteBettingControl>; draw_history_limit: number }>>('/admin/site-settings/betting-controls', payload)
export interface Lottery { id: number; name: string; code: string; unit_stake: string; status: number; sort: number; site_ids: number[]; cutoff_enabled: number; cutoff_time: string | null; mask_enabled: number; refund_enabled: number }
export const listLotteries = (params?: Record<string, unknown>) => http.get<never, Envelope<{ list: Lottery[]; total: number }>>('/admin/lotteries', { params })
export type LotteryBettingControls = { cutoff_enabled: number; cutoff_time: string | null; mask_enabled: number; refund_enabled: number }
export const createLottery = (payload: { name: string; code: string; unit_stake: number; status: number; sort: number } & Partial<LotteryBettingControls>) => http.post<never, Envelope<{ id: number }>>('/admin/lotteries', payload)
export const updateLottery = (id: number, payload: Partial<{ name: string; code: string; unit_stake: number; status: number; sort: number } & LotteryBettingControls>) => http.put<never, Envelope<null>>(`/admin/lotteries/${id}`, payload)
export const deleteLottery = (id: number) => http.delete<never, Envelope<null>>(`/admin/lotteries/${id}`)
export type LotteryRules = { id: number; name: string; code: string; content: string }
export const getLotteryRules = (id: number) => http.get<never, Envelope<LotteryRules>>(`/admin/lotteries/${id}/rules`)
export const saveLotteryRules = (id: number, content: string) => http.put<never, Envelope<LotteryRules>>(`/admin/lotteries/${id}/rules`, { content })
export const assignLotterySites = (id: number, site_ids: number[]) => http.put<never, Envelope<{ site_ids: number[] }>>(`/admin/lotteries/${id}/sites`, { site_ids })
export const getLotteryConfig = () => http.get<never, Envelope<{ base_url: string }>>('/admin/lottery-config')
export const saveLotteryConfig = (payload: { base_url: string }) => http.put<never, Envelope<{ base_url: string }>>('/admin/lottery-config', payload)
export type LotteryConfigTest = { base_url: string; url: string; http_status: number; response_time_ms: number; available: boolean; api_code?: number | null }
export const testLotteryConfig = () => http.post<never, Envelope<LotteryConfigTest>>('/admin/lottery-config/test')
export type SecurityPolicy = { weak_passwords: string[]; minimum_length?: number; requires_letter?: boolean; requires_number?: boolean }
export const getSecurityPolicy = () => http.get<never, Envelope<SecurityPolicy>>('/admin/system-settings/security')
export const saveSecurityPolicy = (payload: { weak_passwords: string[] }) => http.put<never, Envelope<{ weak_passwords: string[] }>>('/admin/system-settings/security', payload)
export type LotteryHistory = { id: number; code: string; draw_day: string; one: number; two: number; three: number; numbers: string; open_time: string; next_code?: string }
export const getLotteryHistory = (id: number, params?: Record<string, unknown>) => http.get<never, Envelope<{ list: LotteryHistory[]; total: number; page: number; page_size: number }>>(`/admin/lottery-history/${id}`, { params })
export type OptionalOddsValue = string | null
export type LotteryOdds = { id: number; lottery_id: number; category_id: number; category: string; name: string; min_bet: OptionalOddsValue; odds_limit: OptionalOddsValue; single_bet_limit: OptionalOddsValue; single_item_limit: OptionalOddsValue; odds: OptionalOddsValue; offline_rebate: OptionalOddsValue; status: number; sort: number }
export type LotteryOddsCategory = { id: number; lottery_id: number; name: string; is_playable: number; min_bet: OptionalOddsValue; odds_limit: OptionalOddsValue; single_bet_limit: OptionalOddsValue; single_item_limit: OptionalOddsValue; odds: OptionalOddsValue; offline_rebate: OptionalOddsValue; status: number; sort: number; children: LotteryOdds[] }
export const listLotteryOdds = (id: number, params?: { page?: number; page_size?: number }) => http.get<never, Envelope<{ categories: LotteryOddsCategory[]; total: number; category_total: number; page: number; page_size: number }>>(`/admin/lottery-odds/${id}`, { params })
export const createLotteryOddsCategory = (id: number, payload: { name: string; status: number; sort: number }) => http.post<never, Envelope<{ id: number }>>(`/admin/lottery-odds/${id}/categories`, payload)
export const updateLotteryOddsCategory = (id: number, categoryId: number, payload: Partial<{ name: string; status: number; sort: number }>) => http.put<never, Envelope<null>>(`/admin/lottery-odds/${id}/categories/${categoryId}`, payload)
export const deleteLotteryOddsCategory = (id: number, categoryId: number) => http.delete<never, Envelope<null>>(`/admin/lottery-odds/${id}/categories/${categoryId}`)
export const createLotteryOdds = (id: number, payload: Omit<LotteryOdds, 'id' | 'lottery_id' | 'category'>) => http.post<never, Envelope<{ id: number }>>(`/admin/lottery-odds/${id}`, payload)
export const updateLotteryOdds = (id: number, oddsId: number, payload: Partial<LotteryOdds>) => http.put<never, Envelope<null>>(`/admin/lottery-odds/${id}/${oddsId}`, payload)
export const deleteLotteryOdds = (id: number, oddsId: number) => http.delete<never, Envelope<null>>(`/admin/lottery-odds/${id}/${oddsId}`)
