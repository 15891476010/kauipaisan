import { useEffect, useRef, useState } from "react";
import { App as AntdApp, Empty, Modal } from "antd";
import { HashRouter, Navigate, NavLink, Route, Routes } from "react-router-dom";
import {
  AccountBookOutlined, AlertOutlined, FileDoneOutlined, FileTextOutlined,
  FolderOutlined, LogoutOutlined,
  SettingOutlined, ShareAltOutlined, SwapOutlined, TeamOutlined, TrophyOutlined,
  TransactionOutlined,
} from "@ant-design/icons";
import "./App.css";
import { Login } from "./features/auth/Login";
import { Agreement, defaultAgentAgreement, type AgreementData } from "./features/agreement/Agreement";
import { getAgentLineOptions, getAgentOrganizationProfile, getAgreement, getAnnouncement, getBranding, getLotteries, type AgentOrganizationProfile, type Announcement, type Lottery } from "./api/user";
import { apiErrorMessage } from "./utils/request";
import { RulesPage } from "./features/rules/RulesPage";
import { SubordinatesPage } from "./features/subordinates/SubordinatesPage";
import { SubordinateFormPage } from "./features/subordinates/SubordinateFormPage";
import { SubordinateEditPage } from "./features/subordinates/SubordinateEditPage";
import { LogsPage } from "./features/logs/LogsPage";
import { LedgerPage } from "./features/ledger/LedgerPage";
import { ResultsPage } from "./features/results/ResultsPage";
import { SettingsPage } from "./features/settings/SettingsPage";
import { ReportsPage } from "./features/reports/ReportsPage";
import { InterceptionsPage } from "./features/interceptions/InterceptionsPage";
import { SubaccountsPage } from "./features/subaccounts/SubaccountsPage";
import { heartbeat, logout as logoutSession } from "./api/auth";
import { ForcedPasswordPage } from "./features/auth/ForcedPasswordPage";
import { firstAllowedRoute, hasAgentPermission, isRouteAllowed } from "./routePermissions";
import fishLogo from "./assets/login-logo.svg";

const menus = [
  { path: "overview", title: "总货概览", icon: FileDoneOutlined },
  { path: "ledger", title: "分类账", icon: FileTextOutlined },
  { path: "reports", title: "报表", icon: AccountBookOutlined },
  { path: "results", title: "开奖号码", icon: TrophyOutlined },
  { path: "subordinates", title: "下级管理", icon: TeamOutlined },
  { path: "intercept", title: "拦货", icon: TransactionOutlined },
  { path: "logs", title: "日志", icon: FolderOutlined },
  { path: "rules", title: "规则说明", icon: AlertOutlined },
  { path: "settings", title: "设置", icon: SettingOutlined },
  { path: "subaccounts", title: "子账号", icon: ShareAltOutlined },
];

const levelSystemNames: Record<string, string> = {
  shareholder: "股东系统",
  director: "总监系统",
  general_agent: "总代理系统",
  agent: "代理系统",
};

function clearAgentAuthQuery() {
  const url = new URL(window.location.href);
  url.searchParams.delete("auto_token");
  url.searchParams.delete("agent_name");
  url.searchParams.delete("line_switch");
  window.history.replaceState({}, "", `${url.pathname}${url.search}${url.hash || "#/overview"}`);
}

function PermissionState({ failed = false }: { failed?: boolean }) {
  return <section className="agent-workspace"><h2>{failed ? "权限加载失败" : "暂无访问权限"}</h2><div className="agent-empty"><Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description={failed ? "无法获取实时权限，请刷新页面重试" : "当前账号没有可访问的功能，请联系上级分配权限"} /></div></section>;
}

function lotteryTiming(openTime: string | null, now: number) {
  if (!openTime) return { status: "时间待定", countdown: "-- : -- : --" };
  const target = new Date(openTime.replace(" ", "T")).getTime();
  if (!Number.isFinite(target)) return { status: "时间待定", countdown: "-- : -- : --" };
  const openingDay = new Date(target);
  openingDay.setHours(0, 0, 0, 0);
  const status = now >= target ? "已封盘" : now >= openingDay.getTime() ? "开盘中" : "未开盘";
  const seconds = Math.max(0, Math.floor((target - now) / 1000));
  const hours = String(Math.floor(seconds / 3600)).padStart(2, "0");
  const minutes = String(Math.floor((seconds % 3600) / 60)).padStart(2, "0");
  const remaining = String(seconds % 60).padStart(2, "0");
  return { status, countdown: `${hours} : ${minutes} : ${remaining}` };
}

const overviewTabs = [
  { label: "总货概览", permission: "overview" },
  { label: "总货明细", permission: "order_details" },
  { label: "中奖明细", permission: "winning_details" },
  { label: "投注明细", permission: "bet_details" },
  { label: "查看退码", permission: "refunds" },
];
const dateOptions = ["8-19(2026221)", "8-18(2026220)", "8-17(2026219)"];

function OverviewPage() {
  const visibleTabs = overviewTabs.filter((tab) => hasAgentPermission(tab.permission));
  const [activeTab, setActiveTab] = useState(() => visibleTabs[0]?.label || "总货概览");
  const [account, setAccount] = useState("");
  const [source, setSource] = useState("全部");
  const [startDate, setStartDate] = useState(dateOptions[0]);
  const [endDate, setEndDate] = useState(dateOptions[0]);

  return (
    <section className="overview-page">
      <div className="overview-location">
        <div className="overview-breadcrumb"><b>位置</b><span>»</span><u>{activeTab}</u></div>
        <div className="overview-tabs" role="tablist" aria-label="总货查询分类">
          {visibleTabs.map((tab) => (
            <button key={tab.permission} type="button" role="tab" aria-selected={activeTab === tab.label} className={activeTab === tab.label ? "active" : ""} onClick={() => setActiveTab(tab.label)}>{tab.label}</button>
          ))}
        </div>
      </div>

      <form className="overview-filters" onSubmit={(event) => event.preventDefault()}>
        <fieldset>
          <legend>查账号：</legend>
          <input value={account} onChange={(event) => setAccount(event.target.value)} placeholder="查账号" aria-label="查账号" />
        </fieldset>
        <fieldset className="source-filter">
          <legend>来源：</legend>
          <select value={source} onChange={(event) => setSource(event.target.value)} aria-label="来源">
            <option>全部</option>
            <option>代理</option>
            <option>会员</option>
          </select>
        </fieldset>
        <div className="overview-submit-wrap"><button className="overview-submit" type="submit">提交</button></div>
      </form>

      <section className="overview-table-panel">
        <div className="overview-table-title">
          <strong>{activeTab}</strong>
          <select value={startDate} onChange={(event) => setStartDate(event.target.value)} aria-label="开始日期">
            {dateOptions.map((item) => <option key={item}>{item}</option>)}
          </select>
          <span>至</span>
          <select value={endDate} onChange={(event) => setEndDate(event.target.value)} aria-label="结束日期">
            {dateOptions.map((item) => <option key={item}>{item}</option>)}
          </select>
        </div>
        <div className="overview-table-scroll">
          <table>
            <thead><tr><th>编号</th><th>注单编号</th><th>会员名</th><th>期号</th><th>下单时间</th><th>来源</th><th>注单数量</th><th>注单总额</th><th>操作</th></tr></thead>
          </table>
          <div className="overview-no-data"><Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="暂无数据" /></div>
        </div>
      </section>
    </section>
  );
}

function AgentMain({ name, onLogout, announcement, siteName }: { name: string; onLogout: () => void; announcement: Announcement; siteName: string }) {
  const { modal } = AntdApp.useApp();
  const [lotteries, setLotteries] = useState<Lottery[]>([]);
  const [selectedLotteryId, setSelectedLotteryId] = useState<number | null>(() => {
    const stored = Number(sessionStorage.getItem("agent_selected_lottery_id"));
    return Number.isInteger(stored) && stored > 0 ? stored : null;
  });
  const [lotteriesLoading, setLotteriesLoading] = useState(true);
  const [now, setNow] = useState(Date.now());
  const [lineOpen, setLineOpen] = useState(false);
  const [lineLoading, setLineLoading] = useState(false);
  const [lineResults, setLineResults] = useState<Array<{ line: number; delay: number | null; fastest?: boolean }>>([]);
  const [lineCountdown, setLineCountdown] = useState<number | null>(null);
  const [organizationProfile, setOrganizationProfile] = useState<AgentOrganizationProfile | null>(null);
  const [permissions, setPermissions] = useState<string[]>(() => { try { const value=JSON.parse(localStorage.getItem("agent_permissions")||"[]");return Array.isArray(value)?value.map(String):[]; } catch { return []; } });
  const [permissionsReady, setPermissionsReady] = useState(false);
  const [permissionsFailed, setPermissionsFailed] = useState(false);
  const lineRedirectTimer = useRef<number | null>(null);
  const lineCountdownTimer = useRef<number | null>(null);
  const lineRun = useRef(0);
  const isSubaccount = localStorage.getItem("agent_is_subaccount") === "1";
  const currentLevel = organizationProfile?.organization?.level || localStorage.getItem("agent_organization_level") || "agent";
  const systemName = levelSystemNames[currentLevel] || "业务系统";
  const resolvedSiteName = organizationProfile?.site.name || siteName || "站点管理系统";
  const accessContext = { permissions, level: currentLevel, isSubaccount };
  const visibleMenus = permissionsReady && !permissionsFailed ? menus.filter((item) => isRouteAllowed(item.path, accessContext)) : [];
  const firstRoute = firstAllowedRoute(menus.map((item) => item.path), accessContext);
  const routeAllowed = (path: string) => permissionsReady && !permissionsFailed && isRouteAllowed(path, accessContext);
  useEffect(() => {
    document.title = `${resolvedSiteName} - ${systemName}`;
  }, [resolvedSiteName, systemName]);
  useEffect(() => {
    const send = () => { void heartbeat().catch(() => undefined); };
    send();
    const timer = window.setInterval(send, 20_000);
    return () => window.clearInterval(timer);
  }, []);
  useEffect(() => {
    void getAgentOrganizationProfile().then((response) => {
      const profile=response.data.data; setOrganizationProfile(profile);
      if(profile.organization){localStorage.setItem("agent_organization_level",profile.organization.level);localStorage.setItem("agent_level_label",profile.organization.level_label);}
      const nextPermissions=Array.isArray(profile.permissions)?profile.permissions.map(String):[];
      setPermissions(nextPermissions);localStorage.setItem("agent_permissions",JSON.stringify(nextPermissions));setPermissionsFailed(false);
    }).catch(() => {setPermissions([]);setPermissionsFailed(true);}).finally(() => setPermissionsReady(true));
  }, []);
  useEffect(() => {
    let active = true;
    const loadLotteries = (silent = false) => {
      if (!silent) setLotteriesLoading(true);
      void getLotteries()
        .then((response) => {
          if (!active) return;
          const next = response.data?.data?.list || [];
          setLotteries(next);
          setSelectedLotteryId((current) => {
            const valid = current !== null && next.some((item) => item.id === current);
            const value = valid ? current : (next[0]?.id ?? null);
            if (value === null) sessionStorage.removeItem("agent_selected_lottery_id");
            else sessionStorage.setItem("agent_selected_lottery_id", String(value));
            return value;
          });
        })
        .catch(() => { if (active && !silent) setLotteries([]); })
        .finally(() => { if (active && !silent) setLotteriesLoading(false); });
    };
    const refresh = () => loadLotteries(true);
    loadLotteries();
    const refreshTimer = window.setInterval(refresh, 60_000);
    window.addEventListener("focus", refresh);
    return () => { active = false; window.clearInterval(refreshTimer); window.removeEventListener("focus", refresh); };
  }, []);
  useEffect(() => {
    const timer = window.setInterval(() => setNow(Date.now()), 1_000);
    return () => window.clearInterval(timer);
  }, []);
  useEffect(() => () => {
    lineRun.current += 1;
    if (lineRedirectTimer.current !== null) window.clearTimeout(lineRedirectTimer.current);
    if (lineCountdownTimer.current !== null) window.clearInterval(lineCountdownTimer.current);
  }, []);
  async function checkLines() {
    const run = ++lineRun.current;
    if (lineRedirectTimer.current !== null) window.clearTimeout(lineRedirectTimer.current);
    if (lineCountdownTimer.current !== null) window.clearInterval(lineCountdownTimer.current);
    lineRedirectTimer.current=null;
    lineCountdownTimer.current=null;
    setLineOpen(true);
    setLineLoading(true);
    setLineResults([]);
    setLineCountdown(null);
    try {
      const response = await getAgentLineOptions();
      const options = response.data?.data?.list || [];
      const results = await Promise.all(options.map(async (option) => {
        const started = performance.now();
        const controller = new AbortController();
        const timeout = window.setTimeout(() => controller.abort(), 6_000);
        try {
          const response = await fetch(`${option.url}/prod_api/v1/health?line=${option.line}&t=${Date.now()}`, { mode: "cors", cache: "no-store", signal: controller.signal });
          if (!response.ok) throw new Error(`HTTP ${response.status}`);
          return { line: option.line, delay: Math.max(1, Math.round(performance.now() - started)) };
        } catch {
          return { line: option.line, delay: null };
        } finally { window.clearTimeout(timeout); }
      }));
      if (run !== lineRun.current) return;
      const available = results.filter((item) => item.delay !== null) as Array<{ line: number; delay: number }>;
      const fastest = [...available].sort((a, b) => a.delay - b.delay)[0]?.line;
      setLineResults(results.map((item) => ({ ...item, fastest: item.line === fastest })));
      const target = options.find((option) => option.line === fastest)?.url;
      if (target) {
        setLineCountdown(3);
        lineCountdownTimer.current = window.setInterval(() => setLineCountdown((value) => value !== null && value > 1 ? value - 1 : 1), 1_000);
        lineRedirectTimer.current = window.setTimeout(() => {
          if (run !== lineRun.current) return;
          if (lineCountdownTimer.current !== null) window.clearInterval(lineCountdownTimer.current);
          const destination = new URL(`${window.location.pathname}${window.location.search}`, `${target}/`);
          const token = localStorage.getItem("agent_token");
          if (token) destination.searchParams.set("auto_token", token);
          destination.searchParams.set("agent_name", localStorage.getItem("agent_name") || name);
          destination.searchParams.set("line_switch", "1");
          destination.hash = window.location.hash;
          window.location.assign(destination.toString());
        }, 3_000);
      }
    } catch (error) {
      if (run === lineRun.current) modal.error({ title: "线路检测失败", content: apiErrorMessage(error, "暂时无法获取线路") });
    } finally {
      if (run === lineRun.current) setLineLoading(false);
    }
  }
  function closeLineModal() {
    lineRun.current += 1;
    if (lineRedirectTimer.current !== null) window.clearTimeout(lineRedirectTimer.current);
    if (lineCountdownTimer.current !== null) window.clearInterval(lineCountdownTimer.current);
    lineRedirectTimer.current=null;
    lineCountdownTimer.current=null;
    setLineCountdown(null);
    setLineOpen(false);
  }
  const delayClass = (delay: number | null) => {
    if (delay === null) return "agent-line-delay-failed";
    if (delay <= 100) return "agent-line-delay-fast";
    if (delay <= 300) return "agent-line-delay-medium";
    return "agent-line-delay-slow";
  };
  return (
    <div className="app agent-app">
      <button className="notice" type="button" onClick={() => modal.info({ title: announcement.title, content: <div className="announcement-modal-content">{announcement.content}</div>, okText: "关闭" })}>
        <span className="notice-track">{announcement.content || "暂无公告"}</span>
        <span className="notice-track" aria-hidden="true">{announcement.content || "暂无公告"}</span>
      </button>
      <header className="site-header">
        <img className="fish-logo" src={fishLogo} alt="快排" />
        <div className="account">
          <label className="account-field"><span>账号</span><input value={name} readOnly /></label>
          <label className="account-field account-credit"><span>信用</span><input value={organizationProfile?.organization?.credit.total_credit || "0"} readOnly /></label>
          <label className="account-field account-used"><span>已用</span><input value={organizationProfile?.organization?.credit.allocated_credit || "0"} readOnly /></label>
          <label className="account-field account-available"><span>可用</span><input value={organizationProfile?.organization?.credit.available_credit || "0"} readOnly /></label>
        </div>
        <ul className="lottery">
          {lotteriesLoading ? <li>正在加载彩票...</li> : lotteries.length === 0 ? <li>当前站点暂未分配彩票</li> : lotteries.map((item) => {
            const timing = lotteryTiming(item.next_open_time, now);
            return <li key={item.id} className={selectedLotteryId === item.id ? "selected" : ""} role="button" tabIndex={0} onClick={() => { setSelectedLotteryId(item.id); sessionStorage.setItem("agent_selected_lottery_id", String(item.id)); }} onKeyDown={(event) => { if (event.key === "Enter" || event.key === " ") { setSelectedLotteryId(item.id); sessionStorage.setItem("agent_selected_lottery_id", String(item.id)); } }}><div className="lottery-row"><div className="lottery-name"><span>{item.name}</span><b>{timing.status}</b></div><div className="lottery-meta"><label>{item.next_code || item.latest_code || "--"}</label><strong>{timing.countdown}</strong></div></div></li>;
          })}
        </ul>
        <nav className="site-navigation">
          {visibleMenus.map(({ path, title, icon: Icon }) => <NavLink key={path} to={`/${path}`} title={title} className={({ isActive }) => isActive ? "selected" : ""}><span className="nav-icon-shell"><Icon className="nav-icon" /></span>{path === "ledger" ? "贡献度" : title}</NavLink>)}
          <button className="line" type="button" onClick={() => void checkLines()}><span className="nav-icon-shell"><SwapOutlined className="nav-icon" /></span><em>更换线路</em></button>
          <button className="exit" type="button" onClick={onLogout}><span className="nav-icon-shell"><LogoutOutlined className="nav-icon" /></span><em>退出</em></button>
        </nav>
      </header>
      <Modal title="切换线路" open={lineOpen} onCancel={closeLineModal} footer={null} width={460} destroyOnHidden>
        <div className="agent-line-tip">测速完成后将 <b>自动跳转</b> 至 <b>速度最快</b> 的线路</div>
        <div className="agent-line-tip">数字越 <b>小</b>，速度越 <b>快</b></div>
        {lineLoading && <div className="agent-line-modal-state">正在检测线路...</div>}
        {!lineLoading && lineResults.length === 0 && <div className="agent-line-modal-state">当前站点暂无可用线路</div>}
        {lineResults.length > 0 && <div className="agent-line-table"><div className="agent-line-table-row agent-line-table-head"><span>线路</span><span>延时</span></div>{lineResults.map((item) => <div className="agent-line-table-row" key={item.line}><span className="agent-line-name">线路{item.line}</span><strong className={delayClass(item.delay)}>{item.delay === null ? "检测失败" : <>{item.delay}ms{item.fastest && <em className="agent-line-fastest">最快</em>}</>}</strong></div>)}</div>}
        {lineCountdown !== null && <div className="agent-line-countdown"><b>{lineCountdown}</b> 秒后自动跳转至最快线路</div>}
      </Modal>
      <div className="body agent-body management-workspace">
        <main>{!permissionsReady ? <section className="agent-workspace"><div className="agent-empty">正在加载实时权限...</div></section> : permissionsFailed ? <PermissionState failed /> : firstRoute === null ? <PermissionState /> : <Routes><Route path="/" element={<Navigate to={`/${firstRoute}`} replace />} />{routeAllowed("overview") && <Route path="/overview" element={<OverviewPage />} />}{routeAllowed("rules") && <Route path="/rules" element={<RulesPage lottery={lotteries.find((item) => item.id === selectedLotteryId)?.name || ""} />} />}{routeAllowed("settings") && <Route path="/settings" element={<SettingsPage lottery={lotteries.find((item) => item.id === selectedLotteryId)?.name || ""} />} />}{routeAllowed("reports") && <Route path="/reports" element={<ReportsPage lottery={lotteries.find((item) => item.id === selectedLotteryId)?.name || ""} />} />}{routeAllowed("subordinates") && <Route path="/subordinates" element={<SubordinatesPage agentName={name} />} />}{routeAllowed("subordinates") && hasAgentPermission("member.create", permissions) && <Route path="/subordinates/new" element={<SubordinateFormPage />} />}{routeAllowed("subordinates") && hasAgentPermission("member.update", permissions) && <Route path="/subordinates/:id/edit" element={<SubordinateEditPage agentName={name} />} />}{routeAllowed("logs") && <Route path="/logs" element={<LogsPage />} />}{routeAllowed("ledger") && <Route path="/ledger" element={<LedgerPage lottery={lotteries.find((item) => item.id === selectedLotteryId)?.name || ""} />} />}{routeAllowed("results") && <Route path="/results" element={<ResultsPage lottery={lotteries.find((item) => item.id === selectedLotteryId)?.name || ""} />} />}{routeAllowed("intercept") && <Route path="/intercept" element={<InterceptionsPage lottery={lotteries.find((item) => item.id === selectedLotteryId)?.name || ""} />} />}{routeAllowed("subaccounts") && <Route path="/subaccounts" element={<SubaccountsPage/>}/>}<Route path="*" element={<Navigate to={`/${firstRoute}`} replace />} /></Routes>}</main>
      </div>
    </div>
  );
}

export default function App() {
  const [siteName, setSiteName] = useState("站点管理系统");
  const [name, setName] = useState(() => localStorage.getItem("agent_token") ? localStorage.getItem("agent_name") || "" : "");
  const [mustChangePassword, setMustChangePassword] = useState(() => localStorage.getItem("agent_must_change_password") === "1");
  const [agreementVisible, setAgreementVisible] = useState(() => {
    const token = localStorage.getItem("agent_token");
    return Boolean(token && localStorage.getItem("agent_name") && sessionStorage.getItem("agent_agreement_accepted_token") !== token);
  });
  const [agreement, setAgreement] = useState<AgreementData>(defaultAgentAgreement);
  const [announcement, setAnnouncement] = useState<Announcement>({ title: "代理端公告", content: "暂无公告" });
  useEffect(() => {
    void getBranding().then((response) => {
      const value = String(response.data.data?.site_name || "").trim();
      if (value) setSiteName(value);
    }).catch(() => undefined);
  }, []);
  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const autoToken = params.get("auto_token");
    const lineSwitch = params.get("line_switch") === "1";
    if (!autoToken && !lineSwitch) return;
    const token = autoToken || localStorage.getItem("agent_token");
    if (!token) return;
    if (autoToken) localStorage.setItem("agent_token", autoToken);
    const agentName = params.get("agent_name") || localStorage.getItem("agent_name") || "站点管理员";
    localStorage.setItem("agent_name", agentName);
    if (lineSwitch) sessionStorage.setItem("agent_agreement_accepted_token", token);
    else sessionStorage.removeItem("agent_agreement_accepted_token");
    clearAgentAuthQuery();
    setName(agentName);
    setAgreementVisible(!lineSwitch);
  }, []);
  useEffect(() => {
    if (!name || !agreementVisible) return;
    void getAgreement().then((response) => { if (response.data.data) setAgreement(response.data.data); }).catch(() => setAgreement(defaultAgentAgreement));
  }, [name, agreementVisible]);
  useEffect(() => {
    if (!name || agreementVisible) return;
    void getAnnouncement().then((response) => { if (response.data.data) setAnnouncement(response.data.data); }).catch(() => setAnnouncement({ title: "代理端公告", content: "暂无公告" }));
  }, [name, agreementVisible]);
  useEffect(() => {
    const logout = () => { setMustChangePassword(false); setName(""); };
    window.addEventListener("agent:unauthorized", logout);
    return () => window.removeEventListener("agent:unauthorized", logout);
  }, []);
  const clearSession = () => { clearAgentAuthQuery(); localStorage.removeItem("agent_token"); localStorage.removeItem("agent_name"); localStorage.removeItem("agent_permissions"); localStorage.removeItem("agent_is_subaccount"); localStorage.removeItem("agent_organization_level"); localStorage.removeItem("agent_level_label"); localStorage.removeItem("agent_must_change_password"); sessionStorage.removeItem("agent_agreement_accepted_token"); setAgreementVisible(false); setMustChangePassword(false); setName(""); };
  const logout = () => { void logoutSession().catch(() => undefined).finally(clearSession); };
  return <HashRouter>{name ? agreementVisible ? <div className="agent-agreement-theme"><Agreement agreement={agreement} onReject={logout} onAccept={() => { const token=localStorage.getItem("agent_token"); if (token) sessionStorage.setItem("agent_agreement_accepted_token",token); setAgreementVisible(false); }} /></div> : mustChangePassword ? <ForcedPasswordPage username={name} onSuccess={() => setMustChangePassword(false)} /> : <AgentMain name={name} onLogout={logout} announcement={announcement} siteName={siteName} /> : <Login siteName={siteName} onLogin={(value) => { localStorage.setItem("agent_name", value); sessionStorage.removeItem("agent_agreement_accepted_token"); setMustChangePassword(localStorage.getItem("agent_must_change_password") === "1"); setName(value); setAgreementVisible(true); }} />}</HashRouter>;
}
