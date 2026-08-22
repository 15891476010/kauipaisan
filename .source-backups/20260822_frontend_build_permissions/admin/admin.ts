import http from './http'
import type { LoginResponse, MenuItem } from '../types'

interface Envelope<T> { code: number; message: string; data: T; request_id: string }
export const login = (payload: { username: string; password: string }) => http.post<never, Envelope<LoginResponse>>('/admin/auth/login', payload)
export const logout = () => http.post<never, Envelope<null>>('/admin/auth/logout')
export const getMenus = () => http.get<never, Envelope<MenuItem[]>>('/admin/auth/menus')
export const listResource = (resource: string, params?: Record<string, unknown>) => http.get<never, Envelope<{ list: Record<string, unknown>[]; total: number }>>(`/admin/${endpoint(resource)}`, { params })
const endpoint = (resource: string) => ({ 'sub-agents': 'sub_agents', 'audit-logs': 'audit_logs' }[resource] || resource)
export const createResource = (resource: string, payload: Record<string, unknown>) => http.post<never, Envelope<Record<string, unknown>>>(`/admin/${endpoint(resource)}`, payload)
export const updateResource = (resource: string, id: number, payload: Record<string, unknown>) => http.put<never, Envelope<Record<string, unknown>>>(`/admin/${endpoint(resource)}/${id}`, payload)
export const deleteResource = (resource: string, id: number) => http.delete<never, Envelope<null>>(`/admin/${endpoint(resource)}/${id}`)
export type BetDetail = { id: number; row_key?: string; number_index?: number; lottery: string; number_text?: string; number_count?: number; hundreds: string; tens: string; units: string; amount: string; odds?: string; category?: string; play_type?: string; source_text?: string; win_amount?: string; result_status?: string; draw_numbers?: string }
export const getBetDetails = (id: number, params?: { page?: number; page_size?: number }) => http.get<never, Envelope<{ record: Record<string, any>; list: BetDetail[]; total: number; page: number; page_size: number }>>(`/admin/bet-record-details/${id}`, { params })
export const updateBetDetail = (id: number, payload: { number_index: number; number_text: string; amount: string }) => http.put<never, Envelope<null>>(`/admin/bet-details/${id}`, payload)
export const enterSite = (id: number) => http.get<never, Envelope<{ url: string; token: string; name?: string }>>(`/admin/agent-center/${id}/enter`)
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
export interface Lottery { id: number; name: string; code: string; status: number; sort: number; site_ids: number[]; cutoff_enabled: number; cutoff_time: string | null; mask_enabled: number; refund_enabled: number }
export const listLotteries = (params?: Record<string, unknown>) => http.get<never, Envelope<{ list: Lottery[]; total: number }>>('/admin/lotteries', { params })
export type LotteryBettingControls = { cutoff_enabled: number; cutoff_time: string | null; mask_enabled: number; refund_enabled: number }
export const createLottery = (payload: { name: string; code: string; status: number; sort: number } & Partial<LotteryBettingControls>) => http.post<never, Envelope<{ id: number }>>('/admin/lotteries', payload)
export const updateLottery = (id: number, payload: Partial<{ name: string; code: string; status: number; sort: number } & LotteryBettingControls>) => http.put<never, Envelope<null>>(`/admin/lotteries/${id}`, payload)
export const deleteLottery = (id: number) => http.delete<never, Envelope<null>>(`/admin/lotteries/${id}`)
export type LotteryRules = { id: number; name: string; code: string; content: string }
export const getLotteryRules = (id: number) => http.get<never, Envelope<LotteryRules>>(`/admin/lotteries/${id}/rules`)
export const saveLotteryRules = (id: number, content: string) => http.put<never, Envelope<LotteryRules>>(`/admin/lotteries/${id}/rules`, { content })
export const assignLotterySites = (id: number, site_ids: number[]) => http.put<never, Envelope<{ site_ids: number[] }>>(`/admin/lotteries/${id}/sites`, { site_ids })
export const getLotteryConfig = () => http.get<never, Envelope<{ base_url: string }>>('/admin/lottery-config')
export const saveLotteryConfig = (payload: { base_url: string }) => http.put<never, Envelope<{ base_url: string }>>('/admin/lottery-config', payload)
export type LotteryConfigTest = { base_url: string; url: string; http_status: number; response_time_ms: number; available: boolean; api_code?: number | null }
export const testLotteryConfig = () => http.post<never, Envelope<LotteryConfigTest>>('/admin/lottery-config/test')
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
