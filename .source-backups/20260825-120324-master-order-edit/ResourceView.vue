<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import { useAuthStore } from "../stores/auth";
import { ElMessage, ElMessageBox } from "element-plus";
import { Delete, Plus } from "@element-plus/icons-vue";
import {
  createResource,
  clearAuditLogs,
  deleteResource,
  enterSite,
  listLotteries,
  listResource,
  getAuditLog,
  updateResource,
  getBetDetails,
  updateBetDetail,
  type BetDetail,
} from "../api/admin";

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();
const loading = ref(false);
const rows = ref<Record<string, unknown>[]>([]);
const total = ref(0);
const drawer = ref(false);
const editingId = ref<number | null>(null);
const siteOptions = ref<Record<string, unknown>[]>([]);
const lotteryOptions = ref<{ id: number; name: string; code: string }[]>([]);
const selectedLotteryIds = ref<number[]>([]);
const detailDrawer = ref(false);
const detailLoading = ref(false);
const detailRows = ref<BetDetail[]>([]);
const detailPage = ref(1);
const detailPageSize = ref(30);
const detailTotal = ref(0);
const detailSaveTimers = new Map<string, ReturnType<typeof setTimeout>>();
const detailSaving = new Set<string>();
const detailRecord = ref<Record<string, unknown> | null>(null);
const textDrawerVisible = ref(false);
const textDrawerTitle = ref("");
const textDrawerValue = ref("");
const auditDetail = ref<Record<string, unknown> | null>(null);
const auditDetailVisible = ref(false);
const auditDetailLoading = ref(false);
const clearingAuditLogs = ref(false);
let refreshTimer: ReturnType<typeof setInterval> | null = null;
let realtimeRefreshing = false;
const isPlatform = computed(() => auth.user?.role !== "site");
const query = reactive({ keyword: "", site_id: "", lottery: "", page: 1, page_size: 20 });
type DomainItem = { domain: string; is_primary: number; status: number };
const createDomainItem = (isPrimary = false): DomainItem => ({
  domain: "",
  is_primary: isPrimary ? 1 : 0,
  status: 1,
});
const agentDomains = ref<DomainItem[]>([createDomainItem(true)]);
const userDomains = ref<DomainItem[]>([createDomainItem(true)]);
const form = reactive({
  name: "",
  code: "",
  status: 1,
  domain: "",
  agent_domain: "",
  user_domain: "",
  domain_type: "agent",
  parent_id: "",
  manager_username: "",
  manager_password: "",
  manager_phone: "",
  site_id: "",
  username: "",
  display_name: "",
  phone: "",
  password: "",
  balance: "0.00",
  credit_balance: "0.00",
  used_balance: "0.00",
  credit_limit: 0,
  max_profit_share_rate: 100,
  account_table: "admins",
});
const resource = computed(() => String(route.meta.resource || ""));
const title = computed(() => String(route.meta.title || "资源管理"));
const fields: Record<string, string[]> = {
  "agent-center": [
    "id",
    "name",
    "admin_count",
    "agent_domain",
    "user_domain",
    "credit_limit",
    "max_profit_share_rate",
    "status",
    "created_at",
  ],
  "site-users": [
    "id",
    "site_name",
    "username",
    "display_name",
    "organization_name",
    "balance",
    "credit_balance",
    "used_balance",
    "available_balance",
    "online",
    "last_seen_at",
    "last_login_at",
    "last_login_device",
    "last_login_location",
    "last_login_ip",
    "status",
    "created_at",
  ],
  "bet-records": ["id", "site_name", "username", "lottery", "issue_no", "bet_count", "amount", "win_amount", "source_text", "formatted_text", "status", "sealed", "placed_at"],
  admins: ["id", "site_name", "scope_label", "username", "display_name", "online", "last_seen_at", "last_login_at", "last_login_device", "last_login_location", "last_login_ip", "status"],
  roles: ["id", "name", "code", "status", "created_at"],
  menus: ["id", "title", "path", "component", "sort", "status"],
  "audit-logs": ["id", "username", "site_name", "agent_name", "action", "resource", "ip", "created_at"],
  settings: ["id", "key", "value", "updated_at"],
};
const labels: Record<string, string> = {
  id: "ID",
  name: "站点名称",
  code: "站点编码",
  site_id: "站点ID",
  level: "级别",
  status: "状态",
  created_at: "创建时间",
  parent_name: "上级代理",
  agent_name: "所属代理",
  manager_username: "管理员账号",
  manager_phone: "管理员手机号",
  admin_count: "管理员数量",
  domain: "域名",
  agent_domain: "代理端域名",
  user_domain: "用户端域名",
  site_name: "所属站点",
  scope_label: "账号类型",
  lottery: "彩种",
  is_primary: "主域名",
  username: "账号",
  display_name: "姓名",
  organization_name: "所属层级",
  phone: "手机号",
  balance: "余额",
  total_balance: "总余额",
  credit_balance: "信用余额",
  used_balance: "已用余额",
  available_balance: "可用余额",
  credit_limit: "站点分数",
  max_profit_share_rate: "每级最高占成",
  last_login_at: "最后登录",
  last_seen_at: "最后活跃",
  last_login_device: "最后登录设备",
  last_login_location: "最后登录地点",
  last_login_ip: "最后登录 IP",
  online: "在线状态",
  issue_no: "期号",
  bet_count: "笔数",
  amount: "下单金额",
  win_amount: "中奖金额",
  source_text: "原始文本",
  formatted_text: "规范文本",
  sealed: "封盘状态",
  placed_at: "下单时间",
  title: "菜单名称",
  path: "路由",
  component: "组件",
  sort: "排序",
  action: "动作",
  resource: "资源",
  ip: "IP",
  key: "配置项",
  value: "配置值",
  updated_at: "更新时间",
};
const columns = computed(
  () => fields[resource.value] || ["id", "name", "status", "created_at"],
);
const pageSubtitle = computed(() =>
  resource.value === "site-users"
    ? "平台统一管理所有站点用户，按站点隔离账号和数据"
    : resource.value === "bet-records"
      ? "查看用户端提交的下单记录，数据按站点和用户隔离"
    : resource.value === "admins"
      ? "统一管理平台管理员和各站点后台管理员"
    : "每个站点拥有独立管理员与反代域名，站点之间账号和数据隔离",
);
async function load(silent = false) {
  if (silent && realtimeRefreshing) return;
  if (silent) realtimeRefreshing = true;
  if (!silent) loading.value = true;
  try {
    const res = await listResource(resource.value, query);
    rows.value = res.data.list;
    total.value = res.data.total;
  } catch (e) {
    if (!silent) ElMessage.error(e instanceof Error ? e.message : "加载失败");
  } finally {
    if (!silent) loading.value = false;
    if (silent) realtimeRefreshing = false;
  }
}
const betLotteryOptions = computed(() => lotteryOptions.value.length
  ? lotteryOptions.value.map((item) => item.name)
  : Array.from(new Set(rows.value.map((row) => String(row.lottery || '')).filter(Boolean))));
function detailLotteryNames() {
  const items = detailRecord.value?.lotteries;
  return Array.isArray(items) ? items.map((item) => String((item as Record<string, unknown>).name || '')).filter(Boolean).join('、') : '';
}
function openRecordText(label: string, value: unknown) {
  textDrawerTitle.value = label;
  textDrawerValue.value = String(value ?? "-");
  textDrawerVisible.value = true;
}
function betStatusLabel(row: Record<string, unknown>): string {
  const status = String(row.status ?? "").toLowerCase();
  if (status === "refunded" || status === "refund") return "已退码";
  if (status === "won" || status === "unwon" || status === "sealed" || status === "closed" || Number(row.sealed) === 1) return "已封盘";
  return "未开奖";
}
function betStatusTagType(row: Record<string, unknown>): "success" | "warning" | "info" {
  const status = String(row.status ?? "").toLowerCase();
  if (status === "refunded" || status === "refund") return "info";
  return betStatusLabel(row) === "未开奖" ? "warning" : "success";
}
function isDetailDrawn(record: Record<string, unknown> | null): boolean {
  if (!record) return false;
  const opened = record.opened;
  if (opened === true || opened === 1 || opened === "1" || opened === "true") return true;
  const status = String(record.status ?? "").toLowerCase();
  if (status === "won" || status === "unwon") return true;
  const numbers = String(record.draw_numbers ?? "").replace(/[^0-9]/g, "");
  return numbers.length >= 3;
}
function detailStatusLabel(record: Record<string, unknown> | null): string {
  if (!record) return "未开奖";
  const status = String(record.status ?? "").toLowerCase();
  if (status === "refunded" || status === "refund") return "已退码";
  if (isDetailDrawn(record)) return "已开奖";
  if (status === "sealed" || status === "closed" || Number(record.sealed) === 1) return "已封盘";
  return "未开奖";
}
async function loadSites() {
  try {
    const res = await listResource("agent-center", { page: 1, page_size: 100 });
    siteOptions.value = res.data.list;
  } catch {
    siteOptions.value = [];
  }
}
async function loadLotteryOptions() {
  try {
    const res = await listLotteries({ page: 1, page_size: 100 });
    lotteryOptions.value = res.data.list
      .filter((item) => Number(item.status) === 1)
      .map((item) => ({ id: Number(item.id), name: item.name, code: item.code }));
  } catch {
    lotteryOptions.value = [];
  }
}
function rowDomains(
  row: Record<string, unknown>,
  field: "agent_domains" | "user_domains",
): DomainItem[] {
  const value = row[field];
  if (!Array.isArray(value)) return [];
  return value
    .filter((item): item is Record<string, unknown> => Boolean(item && typeof item === "object"))
    .map((item) => ({
      domain: String(item.domain || ""),
      is_primary: Number(item.is_primary) === 1 ? 1 : 0,
      status: Number(item.status) === 0 ? 0 : 1,
    }));
}
function hydrateDomains(
  row: Record<string, unknown>,
  field: "agent_domains" | "user_domains",
  fallback: unknown,
): DomainItem[] {
  const items = rowDomains(row, field);
  if (items.length) return items;
  const domain = String(fallback || "").trim();
  return domain ? [{ domain, is_primary: 1, status: 1 }] : [createDomainItem(true)];
}
function addDomain(items: DomainItem[]) {
  items.push(createDomainItem(items.length === 0));
}
function removeDomain(items: DomainItem[], index: number) {
  const wasPrimary = items[index]?.is_primary === 1;
  items.splice(index, 1);
  if (wasPrimary && items.length) {
    items.forEach((item, itemIndex) => (item.is_primary = itemIndex === 0 ? 1 : 0));
  }
}
function setPrimary(items: DomainItem[], index: number) {
  items.forEach((item, itemIndex) => (item.is_primary = itemIndex === index ? 1 : 0));
}
function normalizeDomains(items: DomainItem[], used: Set<string>): DomainItem[] {
  const result: DomainItem[] = [];
  for (const item of items) {
    const domain = item.domain.trim().replace(/\/$/, "").toLowerCase();
    if (!domain) continue;
    if (used.has(domain)) throw new Error(`域名“${domain}”不能重复添加`);
    used.add(domain);
    result.push({ domain, is_primary: item.is_primary === 1 ? 1 : 0, status: 1 });
  }
  const primaryIndex = result.findIndex((item) => item.is_primary === 1);
  result.forEach((item, index) => (item.is_primary = index === (primaryIndex >= 0 ? primaryIndex : 0) ? 1 : 0));
  return result;
}
function domainCount(row: Record<string, unknown>, column: string) {
  return rowDomains(row, column === "agent_domain" ? "agent_domains" : "user_domains").length;
}
function domainTooltip(row: Record<string, unknown>, column: string) {
  return rowDomains(row, column === "agent_domain" ? "agent_domains" : "user_domains")
    .map((item) => item.domain)
    .join("、");
}
function resetForm() {
  Object.assign(form, {
    name: "",
    code: "",
    status: 1,
    domain: "",
    agent_domain: "",
    user_domain: "",
    domain_type: "agent",
    parent_id: "",
    manager_username: "",
    manager_password: "",
    manager_phone: "",
    site_id: "",
    account_table: "admins",
    username: "",
    display_name: "",
    phone: "",
    password: "",
    balance: 0,
    credit_balance: 0,
    used_balance: 0,
    credit_limit: 0,
    max_profit_share_rate: 100,
  });
  agentDomains.value = [createDomainItem(true)];
  userDomains.value = [createDomainItem(true)];
  selectedLotteryIds.value = [];
}
function openCreate() {
  resetForm();
  editingId.value = null;
  drawer.value = true;
}
function openEdit(row: Record<string, unknown>) {
  Object.assign(form, {
    name: String(row.name || ""),
    code: String(row.code || ""),
    status: Number(row.status ?? 1),
    domain: String(row.domain || "").split(",")[0],
    agent_domain: String(row.agent_domain || row.domain || "").split(",")[0],
    user_domain: String(row.user_domain || ""),
    domain_type: String(row.domain_type || "agent"),
    manager_username: String(row.manager_username || ""),
    manager_password: "",
    manager_phone: String(row.manager_phone || ""),
    site_id: String(row.site_id || ""),
    account_table: String(row.account_table || "admins"),
    username: String(row.username || ""),
    display_name: String(row.display_name || ""),
    phone: String(row.phone || ""),
    password: "",
    balance: Number(row.balance ?? 0),
    credit_balance: Number(row.credit_balance ?? 0),
    used_balance: Number(row.used_balance ?? 0),
    credit_limit: Number(row.credit_limit ?? 0),
    max_profit_share_rate: Number(row.max_profit_share_rate ?? 100),
  });
  agentDomains.value = hydrateDomains(
    row,
    "agent_domains",
    row.agent_domain || row.domain,
  );
  userDomains.value = hydrateDomains(row, "user_domains", row.user_domain);
  selectedLotteryIds.value = Array.isArray(row.lottery_ids)
    ? row.lottery_ids.map(Number).filter((id) => Number.isInteger(id) && id > 0)
    : [];
  editingId.value = Number(row.id);
  drawer.value = true;
}
function payload() {
  if (resource.value === "site-users")
    return {
      site_id: Number(form.site_id),
      username: form.username,
      display_name: form.display_name,
      phone: form.phone,
      password: form.password,
      balance: form.balance,
      credit_balance: form.credit_balance,
      used_balance: form.used_balance,
      status: form.status,
    };
  if (resource.value === "admins") {
    const base = { account_table: form.account_table, username: form.username, display_name: form.display_name, phone: form.phone, password: form.password, status: form.status };
    return form.account_table === "site_admins" ? { ...base, site_id: Number(form.site_id) } : base;
  }
  if (resource.value === "agent-center")
    {
      const used = new Set<string>();
      const normalizedAgentDomains = normalizeDomains(agentDomains.value, used);
      const normalizedUserDomains = normalizeDomains(userDomains.value, used);
      return {
      name: form.name,
      agent_domains: normalizedAgentDomains,
      user_domains: normalizedUserDomains,
      agent_domain: normalizedAgentDomains[0]?.domain || "",
      user_domain: normalizedUserDomains[0]?.domain || "",
      lottery_ids: [...selectedLotteryIds.value],
      credit_limit: form.credit_limit,
      max_profit_share_rate: form.max_profit_share_rate,
      status: form.status,
      };
    }
  return { ...form };
}
async function save() {
  try {
    if (editingId.value)
      await updateResource(resource.value, editingId.value, payload());
    else await createResource(resource.value, payload());
    drawer.value = false;
    ElMessage.success(
      resource.value === "site-users"
        ? editingId.value
          ? "用户已更新"
          : "用户创建成功"
        : editingId.value
          ? "站点已更新"
          : "站点创建成功",
    );
    await load();
  } catch (e) {
    ElMessage.error(e instanceof Error ? e.message : "保存失败");
  }
}
async function remove(id: number, accountTable?: unknown) {
  await ElMessageBox.confirm("确定删除这条数据吗？", "操作确认", {
    type: "warning",
  });
  await deleteResource(resource.value, id, resource.value === "admins" ? { account_table: String(accountTable || "admins") } : undefined);
  ElMessage.success("已删除");
  await load();
}
async function clearAllAuditLogs() {
  await ElMessageBox.confirm("将清除全部普通审计日志，操作不可恢复；本次清除记录会保留。", "清除审计日志", {
    type: "warning",
    confirmButtonText: "确认清除",
    cancelButtonText: "取消",
  });
  clearingAuditLogs.value = true;
  try {
    const response = await clearAuditLogs();
    ElMessage.success(`已清除 ${Number(response.data.cleared_count || 0)} 条审计日志`);
    query.page = 1;
    await load();
  } catch (error) {
    ElMessage.error(error instanceof Error ? error.message : "清除失败");
  } finally {
    clearingAuditLogs.value = false;
  }
}
async function openBetDetails(row: Record<string, unknown>) {
  detailLoading.value = true;
  detailDrawer.value = true;
  detailPage.value = 1;
  try {
    const response = await getBetDetails(Number(row.id), { page: detailPage.value, page_size: detailPageSize.value });
    detailRecord.value = response.data.record;
    detailRows.value = response.data.list;
    detailTotal.value = response.data.total;
  } catch (e) {
    detailDrawer.value = false;
    ElMessage.error(e instanceof Error ? e.message : "详情加载失败");
  } finally {
    detailLoading.value = false;
  }
}
async function loadBetDetailPage(silent = false) {
  if (!detailRecord.value?.id) return;
  if (!silent) detailLoading.value = true;
  try {
    const response = await getBetDetails(Number(detailRecord.value.id), { page: detailPage.value, page_size: detailPageSize.value });
    Object.assign(detailRecord.value, response.data.record);
    detailRows.value = response.data.list;
    detailTotal.value = response.data.total;
  } catch (e) {
    if (!silent) ElMessage.error(e instanceof Error ? e.message : "详情加载失败");
  } finally {
    if (!silent) detailLoading.value = false;
  }
}
async function saveExpandedBet(row: BetDetail) {
  const key = row.row_key || String(row.id);
  if (detailSaving.has(key)) {
    scheduleExpandedBetSave(row);
    return;
  }
  const number = `${row.hundreds || ""}${row.tens || ""}${row.units || ""}`;
  if (!/^\d{3}$/.test(number)) return ElMessage.warning("百位、十位、个位都必须填写一位数字");
  if (!Number.isFinite(Number(row.amount)) || Number(row.amount) < 0) return ElMessage.warning("请输入有效的单注金额");
  detailSaving.add(key);
  try {
    await updateBetDetail(row.id, { number_index: Number(row.number_index || 0), number_text: number, amount: String(row.amount) });
    row.number_text = number;
    await load(true);
  } catch (e) {
    ElMessage.error(e instanceof Error ? e.message : "保存失败");
  } finally {
    detailSaving.delete(key);
  }
}
function scheduleExpandedBetSave(row: BetDetail) {
  const key = row.row_key || String(row.id);
  const current = detailSaveTimers.get(key);
  if (current) clearTimeout(current);
  detailSaveTimers.set(key, setTimeout(() => {
    detailSaveTimers.delete(key);
    void saveExpandedBet(row);
  }, 300));
}
async function refreshRealtime() {
  if (resource.value !== "bet-records" && resource.value !== "site-users") return;
  await load(true);
  if (resource.value !== "bet-records") return;
  if (detailDrawer.value && detailRecord.value?.id) {
    const activeTag = document.activeElement?.tagName;
    if (activeTag === "INPUT" || activeTag === "TEXTAREA") return;
    try {
      const response = await getBetDetails(Number(detailRecord.value.id), { page: detailPage.value, page_size: detailPageSize.value });
      Object.assign(detailRecord.value, response.data.record);
      detailRows.value = response.data.list;
      detailTotal.value = response.data.total;
    } catch {
      // Keep the currently visible detail when a transient refresh fails.
    }
  }
}
function openOrganizations(row: Record<string, unknown>) {
  void router.push({ name: 'site-organizations', params: { siteId: Number(row.id) }, query: { name: String(row.name || '') } });
}
function openPermissions(row: Record<string, unknown>) {
  void router.push({ name: 'site-permissions', params: { siteId: Number(row.id) }, query: { name: String(row.name || '') } });
}
async function openSite(row: Record<string, unknown>) {
  try {
    const res = await enterSite(Number(row.id));
    const raw = String(res.data?.url || "").trim();
    if (!raw) {
      ElMessage.warning("请先配置有效的反代域名");
      return;
    }
    const normalized = /^https?:\/\//i.test(raw) ? raw : `https://${raw}`;
    let target: URL;
    try {
      target = new URL(normalized);
    } catch {
      ElMessage.warning("反代域名格式不正确，请填写有效域名");
      return;
    }
    const isLocalHost = target.hostname === "localhost" || target.hostname === "127.0.0.1" || target.hostname === "[::1]";
    if (!target.hostname || (!target.hostname.includes(".") && !isLocalHost)) {
      ElMessage.warning("反代域名格式不正确，请填写有效域名");
      return;
    }
    if (isLocalHost && !target.port) target.port = "5176";
    target.searchParams.set("auto_token", res.data.token);
    if (res.data.name) target.searchParams.set("agent_name", res.data.name);
    window.open(target.toString(), "_blank");
  } catch {
    ElMessage.error("进入站点失败，请检查反代域名和根总监管理员配置");
  }
}
const auditActionNames: Record<string, string> = { login_success: "登录成功", login_failed: "登录失败", logout: "退出登录", create: "新增", update: "修改", delete: "删除", clear: "清除" };
const auditResourceNames: Record<string, string> = { admin: "平台管理员", site_admin: "站点管理员", agent: "代理端", user: "用户端", preview: "投注预览", place: "提交下注", members: "会员管理", settings: "系统配置", lotteries: "彩票管理", rules: "规则配置", agreement: "协议配置", announcement: "公告配置", domains: "域名管理", sites: "站点管理", users: "用户管理", password: "修改密码", refund: "退码", tags: "快捷标签", preferences: "快捷设置", controls: "投注控制", audit_logs: "审计日志" };
function auditAction(value: unknown) { return auditActionNames[String(value || "")] || String(value || "未知操作"); }
function auditResource(value: unknown) { return auditResourceNames[String(value || "")] || String(value || "未知模块"); }
function auditPayload(value: unknown): unknown {
  if (Array.isArray(value)) return value.map(auditPayload);
  if (!value || typeof value !== "object") return value;
  const names: Record<string,string> = { _request: "请求信息", method: "请求方法", path: "接口路径", host: "访问主机", referer: "来源页面", user_agent: "浏览器信息", query: "查询参数", body: "提交参数", started_at: "调用时间", duration_ms: "响应时长(毫秒)", status_code: "状态码", success: "是否成功", response: "返回结果" };
  return Object.fromEntries(Object.entries(value as Record<string, unknown>).map(([key, item]) => [names[key] || key, auditPayload(item)]));
}
const auditPayloadData = computed(() => { const value=auditDetail.value?.payload; if (typeof value === 'string') { try { return JSON.parse(value) as Record<string, unknown>; } catch { return {}; } } return (value && typeof value === 'object' ? value : {}) as Record<string, unknown>; });
const auditRequest = computed(() => (auditPayloadData.value._request || {}) as Record<string, unknown>);
const auditParams = computed(() => ({ query: auditRequest.value.query || {}, body: auditRequest.value.body || {} }));
const auditResponse = computed(() => auditRequest.value.response || {});
async function openAuditDetail(row: Record<string, unknown>) {
  auditDetailLoading.value = true; auditDetailVisible.value = true;
  try {
    const response = await getAuditLog(Number(row.id));
    const raw = response as unknown as Record<string, unknown>;
    const first = raw.data && typeof raw.data === 'object' ? raw.data as Record<string, unknown> : raw;
    const value = first.data && typeof first.data === 'object' ? first.data as Record<string, unknown> : first;
    auditDetail.value = { ...row, ...value };
  }
  catch (e) { ElMessage.error(e instanceof Error ? e.message : "日志详情加载失败"); auditDetailVisible.value = false; }
  finally { auditDetailLoading.value = false; }
}
watch(resource, async () => {
  await load();
  if (resource.value === "site-users" || resource.value === "bet-records" || resource.value === "admins") await loadSites();
  if (resource.value === "agent-center" || resource.value === "bet-records") await loadLotteryOptions();
});
onMounted(async () => {
  await load();
  if (resource.value === "site-users" || resource.value === "bet-records" || resource.value === "admins") await loadSites();
  if (resource.value === "agent-center" || resource.value === "bet-records") await loadLotteryOptions();
  refreshTimer = setInterval(refreshRealtime, 15000);
});
onBeforeUnmount(() => {
  if (refreshTimer) clearInterval(refreshTimer);
  refreshTimer = null;
  detailSaveTimers.forEach((timer) => clearTimeout(timer));
  detailSaveTimers.clear();
});
</script>
<template>
  <div :class="['page-card', { 'audit-page-card': resource === 'audit-logs' }]">
    <h1 class="page-title">{{ title }}</h1>
    <p class="page-subtitle">{{ pageSubtitle }}</p>
    <div class="toolbar">
      <el-input
        v-model="query.keyword"
        clearable
        :placeholder="
          resource === 'site-users'
            ? '搜索账号、姓名或手机号'
            : resource === 'bet-records'
              ? '搜索期号、原始文本或状态'
            : '搜索站点、管理员或域名'
        "
        style="width: 280px"
        @keyup.enter="load"
      /><el-select
        v-if="(resource === 'site-users' || resource === 'bet-records' || resource === 'admins') && isPlatform"
        v-model="query.site_id"
        clearable
        placeholder="全部站点"
        style="width: 180px"
        @change="load"
        ><el-option
          v-for="site in siteOptions"
          :key="site.id"
          :label="String(site.name)"
          :value="String(site.id)"
      /></el-select>
      <el-select
        v-if="resource === 'bet-records'"
        v-model="query.lottery"
        clearable
        filterable
        placeholder="按彩种检索"
        style="width: 170px"
        @change="query.page = 1; load()"
      ><el-option v-for="lottery in betLotteryOptions" :key="lottery" :label="lottery" :value="lottery" /></el-select>
      <div>
        <el-button @click="load">刷新</el-button
        ><el-button
          v-if="resource === 'audit-logs' && isPlatform"
          type="danger"
          :icon="Delete"
          :loading="clearingAuditLogs"
          @click="clearAllAuditLogs"
          >清除日志</el-button
        ><el-button
          v-if="resource === 'bet-records'"
          type="primary"
          @click="router.push('/bet-records/batch-replace')"
          >批量替换</el-button
        ><el-button
          v-if="resource !== 'audit-logs' && resource !== 'bet-records'"
          type="primary"
          @click="openCreate()"
          >{{
            resource === "agent-center"
              ? "新增站点"
              : `新增${title.replace("管理", "")}`
          }}</el-button
        >
      </div>
    </div>
    <el-table
      v-loading="loading"
      :data="rows"
      stripe
      :height="resource === 'audit-logs' ? 'calc(100% - 172px)' : 'calc(100vh - 300px)'"
      ><el-table-column
        v-for="col in columns"
        :key="col"
        :prop="col"
        :label="labels[col] || col"
        :min-width="['last_login_device','last_login_location'].includes(col) ? 180 : col === 'last_login_ip' ? 210 : col === 'online' ? 100 : 120"
        ><template #default="scope"
          ><el-tag v-if="col === 'status'"
            :type="resource === 'bet-records' ? betStatusTagType(scope.row) : (Number(scope.row.status) === 1 ? 'success' : 'info')"
            >{{ resource === 'bet-records' ? betStatusLabel(scope.row) : (Number(scope.row.status) === 1 ? "启用" : "停用") }}</el-tag
          ><el-tag v-else-if="col === 'sealed'" :type="Number(scope.row.sealed) === 1 ? 'danger' : 'info'">{{ Number(scope.row.sealed) === 1 ? '已封盘' : '未封盘' }}</el-tag
          ><el-tag v-else-if="col === 'online'" :type="Number(scope.row.online) === 1 ? 'success' : 'info'" effect="dark">{{ Number(scope.row.online) === 1 ? '在线' : '离线' }}</el-tag
          ><el-tooltip
            v-else-if="col === 'agent_domain' || col === 'user_domain'"
            :content="domainTooltip(scope.row, col) || '暂未配置'"
            placement="top"
            ><div class="domain-cell">
              <span>{{ scope.row[col] || "-" }}</span>
              <el-tag v-if="domainCount(scope.row, col) > 1" size="small" type="info"
                >+{{ domainCount(scope.row, col) - 1 }}</el-tag
              >
            </div></el-tooltip
          ><span v-else-if="resource === 'bet-records' && (col === 'source_text' || col === 'formatted_text')" class="record-text-cell" @click="openRecordText(labels[col] || col, scope.row[col])">{{ scope.row[col] || "-" }}</span
          ><span v-else-if="resource === 'audit-logs' && col === 'action'">{{ auditAction(scope.row[col]) }}</span
          ><span v-else-if="resource === 'audit-logs' && col === 'resource'">{{ auditResource(scope.row[col]) }}</span
          ><span v-else>{{ scope.row[col] ?? "-" }}</span></template
        ></el-table-column
      ><el-table-column
        v-if="resource === 'audit-logs'"
        label="操作"
        fixed="right"
        width="90"
        ><template #default="scope"><el-button link type="primary" @click="openAuditDetail(scope.row)">详情</el-button></template
        ></el-table-column
      ><el-table-column
        v-if="resource === 'bet-records'"
        label="操作"
        fixed="right"
        width="100"
        ><template #default="scope"
          ><el-button link type="primary" @click="openBetDetails(scope.row)">详情</el-button></template
        ></el-table-column
      ><el-table-column
        v-if="resource !== 'audit-logs' && resource !== 'bet-records'"
        label="操作"
        fixed="right"
        :width="resource === 'agent-center' ? 380 : 230"
        ><template #default="scope"
          ><div class="row-actions">
            <el-button
              v-if="resource === 'agent-center'"
              link
              type="primary"
              @click="openEdit(scope.row)"
              >编辑</el-button
            ><el-button
              v-if="resource === 'agent-center'"
              link
              type="primary"
              @click="openOrganizations(scope.row)"
              >组织架构</el-button
            ><el-button
              v-if="resource === 'agent-center'"
              link
              type="primary"
              @click="openPermissions(scope.row)"
              >路由权限</el-button
            ><el-button
              v-if="resource === 'agent-center'"
              link
              type="primary"
              @click="openSite(scope.row)"
              >进入站点</el-button
            ><el-button v-else link type="primary" @click="openEdit(scope.row)"
              >编辑</el-button
            ><el-button link type="danger" @click="remove(Number(scope.row.id), scope.row.account_table)"
              >删除</el-button
            >
          </div></template
        ></el-table-column
      ></el-table
    ><el-pagination
      v-model:current-page="query.page"
      v-model:page-size="query.page_size"
      :total="total"
      layout="total, prev, pager, next"
      style="margin-top: 18px; justify-content: flex-end"
      @change="load"
    /><el-drawer
      v-model="auditDetailVisible"
      title="审计日志详情"
      direction="rtl"
      size="min(760px, 92vw)"
      ><div v-loading="auditDetailLoading" class="audit-detail-panel" v-if="auditDetail">
        <div class="audit-detail-grid">
          <span><b>日志编号</b>{{ auditDetail.id }}</span>
          <span><b>所属站点</b>{{ auditDetail.site_name || '平台' }}</span>
          <span><b>所属代理</b>{{ auditDetail.agent_name || '平台' }}</span>
          <span><b>账号</b>{{ auditDetail.username || '-' }}</span>
          <span><b>操作</b>{{ auditAction(auditDetail.action) }}</span>
          <span><b>模块</b>{{ auditResource(auditDetail.resource) }}</span>
          <span><b>调用者 IP</b>{{ auditDetail.ip || '-' }}</span>
          <span><b>IP 位置</b>{{ auditDetail.ip_location || '-' }}</span>
          <span><b>记录时间</b>{{ auditDetail.created_at || '-' }}</span>
        </div>
        <div class="audit-request-grid"><span><b>请求方法</b>{{ auditRequest.method || '-' }}</span><span><b>接口路径</b>{{ auditRequest.path || '-' }}</span><span><b>调用时间</b>{{ auditRequest.started_at || auditDetail.created_at || '-' }}</span><span><b>响应时长</b>{{ auditRequest.duration_ms != null ? `${auditRequest.duration_ms} 毫秒` : '-' }}</span><span><b>状态码</b>{{ auditRequest.status_code || '-' }}</span><span><b>执行结果</b>{{ auditRequest.success === true ? '成功' : auditRequest.success === false ? '失败' : '-' }}</span></div>
        <div class="audit-json-block"><h3>请求参数</h3><pre>{{ JSON.stringify(auditPayload(auditParams), null, 2) }}</pre><h3>返回结果</h3><pre>{{ JSON.stringify(auditPayload(auditResponse), null, 2) }}</pre></div>
      </div></el-drawer
    /><el-drawer
      v-model="detailDrawer"
      title="下单详情"
      direction="rtl"
      size="min(1080px, 92vw)"
      ><div v-loading="detailLoading" class="bet-detail-panel">
        <div class="bet-detail-summary">
          <span>彩种：<b>{{ detailLotteryNames() || '未知' }}</b></span>
          <span>期号：<b>{{ detailRecord?.issue_no || '待分配' }}</b></span>
          <span>状态：<el-tag :type="detailStatusLabel(detailRecord) === '未开奖' ? 'warning' : detailStatusLabel(detailRecord) === '已退码' ? 'info' : 'success'">{{ detailStatusLabel(detailRecord) }}</el-tag></span>
          <span>中奖金额：<b class="win-total">{{ detailRecord?.win_amount || '0.00' }}</b></span>
          <span v-if="detailRecord?.draw_numbers">开奖号码：<b>{{ detailRecord.draw_numbers }}</b></span>
          <span>共 {{ detailTotal }} 条玩法</span>
        </div>
        <el-table :data="detailRows" :row-key="(row: BetDetail) => row.row_key || row.id" border stripe table-layout="fixed">
          <el-table-column prop="lottery" label="彩种" width="90" />
          <el-table-column label="号码" min-width="300"><template #default="scope"><span class="detail-number-list">{{ scope.row.number_text || '-' }}</span></template></el-table-column>
          <el-table-column prop="number_count" label="笔数" width="76" />
          <el-table-column prop="amount" label="金额" width="110" />
          <el-table-column label="专业玩法" min-width="150"><template #default="scope"><el-tag type="info">{{ scope.row.play_type || scope.row.category || '未识别玩法' }}</el-tag></template></el-table-column>
          <el-table-column prop="odds" label="赔率" width="90" />
          <el-table-column label="中奖" width="180"><template #default="scope"><span v-if="!isDetailDrawn(detailRecord)" class="muted">待开奖</span><span v-else :class="scope.row.result_status === 'won' ? 'win-text' : 'muted'">{{ scope.row.result_status === 'won' ? `已中奖 ¥${scope.row.win_amount || '0.00'}` : '未中奖' }}</span></template></el-table-column>
          <el-table-column prop="source_text" label="原始行" min-width="180" show-overflow-tooltip />
        </el-table>
        <el-pagination v-model:current-page="detailPage" v-model:page-size="detailPageSize" :total="detailTotal" :page-sizes="[20, 30, 50, 100]" layout="total, sizes, prev, pager, next, jumper" class="detail-pagination" @current-change="loadBetDetailPage()" @size-change="detailPage = 1; loadBetDetailPage()" />
      </div>
    </el-drawer><el-drawer
      v-model="textDrawerVisible"
      :title="textDrawerTitle"
      direction="rtl"
      size="min(760px, 92vw)"
      ><div class="record-text-drawer"><pre>{{ textDrawerValue }}</pre></div>
    </el-drawer><el-drawer
      v-model="drawer"
      :title="editingId ? `编辑${title}` : `新增${title}`"
      direction="rtl"
      size="600px"
      ><el-form label-width="96"
        ><el-form-item v-if="resource !== 'site-users' && resource !== 'admins'" label="名称"
          ><el-input v-model="form.name" /></el-form-item
        ><el-form-item
          v-if="resource !== 'agent-center' && resource !== 'site-users' && resource !== 'admins'"
          label="编码"
          ><el-input v-model="form.code" /></el-form-item
        ><el-form-item v-if="resource === 'agent-center'" label="代理端域名" class="domain-form-item"
          ><div class="domain-editor">
            <div class="domain-editor-head"><span>代理管理员访问地址</span><el-button type="primary" link :icon="Plus" @click="addDomain(agentDomains)">新增域名</el-button></div>
            <div v-if="agentDomains.length === 0" class="domain-empty">暂未配置代理端域名</div>
            <div v-for="(item, index) in agentDomains" :key="index" class="domain-row">
              <el-input v-model="item.domain" placeholder="如 agent.example.com" />
              <el-radio :model-value="agentDomains.findIndex((domain) => domain.is_primary === 1)" :value="index" @change="setPrimary(agentDomains, index)">主域名</el-radio>
              <el-tooltip content="删除域名" placement="top"><el-button class="domain-delete" circle :icon="Delete" aria-label="删除域名" @click="removeDomain(agentDomains, index)" /></el-tooltip>
            </div>
          </div></el-form-item
        ><el-form-item v-if="resource === 'agent-center'" label="用户端域名" class="domain-form-item"
          ><div class="domain-editor">
            <div class="domain-editor-head"><span>普通用户访问地址</span><el-button type="primary" link :icon="Plus" @click="addDomain(userDomains)">新增域名</el-button></div>
            <div v-if="userDomains.length === 0" class="domain-empty">暂未配置用户端域名</div>
            <div v-for="(item, index) in userDomains" :key="index" class="domain-row">
              <el-input v-model="item.domain" placeholder="如 www.example.com" />
              <el-radio :model-value="userDomains.findIndex((domain) => domain.is_primary === 1)" :value="index" @change="setPrimary(userDomains, index)">主域名</el-radio>
              <el-tooltip content="删除域名" placement="top"><el-button class="domain-delete" circle :icon="Delete" aria-label="删除域名" @click="removeDomain(userDomains, index)" /></el-tooltip>
            </div>
          </div></el-form-item
        ><el-form-item v-if="resource === 'agent-center'" label="可见彩票"
          ><el-checkbox-group v-model="selectedLotteryIds" class="lottery-checkboxes">
            <el-checkbox v-for="lottery in lotteryOptions" :key="lottery.id" :value="lottery.id">
              {{ lottery.name }}
            </el-checkbox>
          </el-checkbox-group>
          <div class="lottery-tip">只显示勾选的彩票；不勾选则该站点的用户端和代理端都不显示彩票。</div>
        </el-form-item
        ><el-form-item v-if="resource === 'agent-center'" label="站点总额度"
          ><el-input-number v-model="form.credit_limit" :min="0" :precision="2" controls-position="right" style="width: 100%" /><div class="lottery-tip">平台预留给本站点的独立资金池。保存后进入组织架构，点击具体总监单独分配分数；不同总监之间不共享。</div></el-form-item
        ><el-form-item v-if="resource === 'agent-center'" label="每级最高占成"
          ><el-input-number v-model="form.max_profit_share_rate" :min="0" :max="100" :precision="4" controls-position="right" style="width: 100%" /><div class="lottery-tip">动态限制本站点每一级向直属下级分配的占成比例；逐级按上一级实际占成继续计算。</div></el-form-item
        ><el-form-item
          v-if="resource === 'domains'"
          label="域名"
          ><el-input
            v-model="form.domain"
            placeholder="example.com" /></el-form-item
        ><el-form-item v-if="resource === 'domains'" label="域名用途"
          ><el-select v-model="form.domain_type" style="width: 100%"><el-option label="代理端域名" value="agent" /><el-option label="用户端域名" value="user" /></el-select></el-form-item
        ><el-form-item
          v-if="resource === 'site-users' && isPlatform"
          label="所属站点"
          ><el-select
            v-model="form.site_id"
            filterable
            placeholder="请选择站点"
            style="width: 100%"
            ><el-option
              v-for="site in siteOptions"
              :key="site.id"
              :label="String(site.name)"
              :value="String(site.id)" /></el-select></el-form-item
        ><el-form-item v-if="resource === 'site-users'" label="用户账号"
          ><el-input v-model="form.username" /></el-form-item
        ><el-form-item v-if="resource === 'site-users'" label="用户姓名"
          ><el-input v-model="form.display_name" /></el-form-item
        ><el-form-item v-if="resource === 'site-users'" label="手机号"
          ><el-input v-model="form.phone" /></el-form-item
        ><el-form-item v-if="resource === 'site-users'" label="余额"
          ><el-input-number v-model="form.balance" :min="0" :precision="2" controls-position="right" style="width: 100%" /></el-form-item
        ><el-form-item v-if="resource === 'site-users'" label="信用余额"
          ><el-input-number v-model="form.credit_balance" :min="0" :precision="2" controls-position="right" style="width: 100%" /></el-form-item
        ><el-form-item v-if="resource === 'site-users'" label="已用余额"
          ><el-input-number v-model="form.used_balance" :min="0" :precision="2" controls-position="right" style="width: 100%" /></el-form-item
        ><el-form-item v-if="resource === 'site-users'" label="登录密码"
          ><el-input v-model="form.password" type="password" /></el-form-item
        ><el-form-item v-if="resource === 'admins'" label="所属站点"
          ><el-select v-model="form.site_id" clearable filterable placeholder="留空表示平台管理员" style="width:100%"><el-option v-for="site in siteOptions" :key="site.id" :label="String(site.name)" :value="String(site.id)" /></el-select></el-form-item
        ><el-form-item v-if="resource === 'admins'" label="管理员账号"
          ><el-input v-model="form.username" maxlength="80" /></el-form-item
        ><el-form-item v-if="resource === 'admins'" label="姓名"
          ><el-input v-model="form.display_name" maxlength="120" /></el-form-item
        ><el-form-item v-if="resource === 'admins'" label="手机号"
          ><el-input v-model="form.phone" maxlength="30" /></el-form-item
        ><el-form-item v-if="resource === 'admins'" :label="editingId ? '新密码' : '登录密码'"
          ><el-input v-model="form.password" type="password" show-password placeholder="留空则自动生成/不修改" /></el-form-item
        ><el-form-item label="状态"
          ><el-switch
            v-model="form.status"
            :active-value="1"
            :inactive-value="0" /></el-form-item
        ></el-form
      ><template #footer
        ><el-button @click="drawer = false">取消</el-button
        ><el-button type="primary" @click="save">保存</el-button></template
      ></el-drawer
    >
  </div>
</template>

<style scoped>
.domain-cell {
  display: flex;
  min-width: 0;
  align-items: center;
  gap: 6px;
}
.domain-cell span:first-child {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.domain-editor {
  width: 100%;
  padding: 10px 12px;
  border: 1px solid var(--el-border-color-lighter);
  border-radius: 6px;
  background: #fafcff;
}
.domain-editor-head,
.domain-row {
  display: flex;
  align-items: center;
}
.domain-editor-head {
  justify-content: space-between;
  min-height: 28px;
  margin-bottom: 8px;
  color: #64748b;
  font-size: 13px;
}
.domain-row {
  gap: 10px;
  margin-top: 8px;
}
.domain-row :deep(.el-input) {
  min-width: 0;
  flex: 1;
}
.domain-row :deep(.el-radio) {
  flex: 0 0 auto;
  margin-right: 0;
}
.domain-delete {
  flex: 0 0 32px;
}
.domain-empty {
  padding: 10px 0;
  color: #94a3b8;
  font-size: 13px;
  text-align: center;
}
.domain-form-item :deep(.el-form-item__content) {
  min-width: 0;
}
.lottery-checkboxes {
  display: flex;
  width: 100%;
  flex-wrap: wrap;
  gap: 6px 18px;
}
.lottery-checkboxes :deep(.el-checkbox) {
  margin-right: 0;
}
.lottery-tip {
  width: 100%;
  margin-top: 5px;
  color: #8893a7;
  font-size: 12px;
  line-height: 1.5;
}
.bet-detail-panel { width: 100%; overflow-x: hidden; }
.record-text-cell { display: block; width: 100%; max-width: 280px; min-width: 0; overflow: hidden; color: #2563eb; cursor: pointer; text-overflow: ellipsis; white-space: nowrap; }
.record-text-cell:hover { text-decoration: underline; }
.record-text-drawer { min-height: 100%; color: #334155; }
.record-text-drawer pre { margin: 0; padding: 14px; border: 1px solid #e5e7eb; border-radius: 6px; background: #f8fafc; font: 13px/1.7 ui-monospace, SFMono-Regular, Menlo, monospace; white-space: pre-wrap; word-break: break-word; }
.bet-detail-summary { display: flex; align-items: center; flex-wrap: wrap; gap: 12px 24px; padding: 0 0 16px; color: #64748b; font-size: 13px; }
.bet-detail-summary b { color: #1f2937; }
.detail-number-list { display: block; max-height: 58px; overflow: hidden; line-height: 1.55; color: #1f2937; word-break: break-all; }
.win-total, .win-text { color: #e04b35 !important; }
.bet-detail-panel :deep(.el-table) { width: 100%; }
.bet-detail-panel :deep(.el-input__wrapper) { padding-left: 7px; padding-right: 7px; }
.detail-number { color: #1f2937; font-size: 15px; font-variant-numeric: tabular-nums; }
.detail-pagination { justify-content: flex-end; padding-top: 16px; }
.audit-detail-panel { color: #334155; }
.audit-detail-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px 18px; padding-bottom: 18px; border-bottom: 1px solid #e5e7eb; }
.audit-detail-grid span { display: flex; flex-direction: column; gap: 4px; word-break: break-word; }
.audit-detail-grid b { color: #64748b; font-size: 12px; font-weight: 500; }
.audit-json-block h3 { margin: 18px 0 8px; font-size: 14px; }
.audit-json-block pre { max-height: 560px; overflow: auto; margin: 0; padding: 14px; border-radius: 4px; background: #f8fafc; color: #334155; font: 12px/1.65 ui-monospace, SFMono-Regular, Menlo, monospace; white-space: pre-wrap; word-break: break-word; }
.audit-request-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px 18px; margin-top: 18px; padding: 14px; border: 1px solid #e5e7eb; border-radius: 5px; background: #fafafa; }
.audit-request-grid span { display: flex; flex-direction: column; gap: 4px; word-break: break-word; }
.audit-request-grid b { color: #64748b; font-size: 12px; font-weight: 500; }
</style>
