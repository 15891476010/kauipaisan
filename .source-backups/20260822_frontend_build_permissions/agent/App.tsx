import { useEffect, useRef, useState } from "react";
import { App as AntdApp, Empty, Modal } from "antd";
import { HashRouter, NavLink, Route, Routes, useLocation, useNavigate } from "react-router-dom";
import {
  AccountBookOutlined, AlertOutlined, FileDoneOutlined, FileTextOutlined,
  FolderOutlined, LogoutOutlined,
  BellOutlined, MenuFoldOutlined, MenuUnfoldOutlined, SettingOutlined, ShareAltOutlined, SwapOutlined, TeamOutlined, TrophyOutlined,
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
import { HierarchyPage } from "./features/organizations/HierarchyPage";
import { heartbeat, logout as logoutSession } from "./api/auth";
import { ForcedPasswordPage } from "./features/auth/ForcedPasswordPage";

const menus = [
  { path: "overview", title: "总货概览", icon: FileDoneOutlined },
  { path: "ledger", title: "分类账", icon: FileTextOutlined },
  { path: "reports", title: "报表", icon: AccountBookOutlined },
  { path: "results", title: "开奖号码", icon: TrophyOutlined },
  { path: "organizations", title: "下级管理", icon: ShareAltOutlined },
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

const levelWorkspaceNames: Record<string, string> = {
  shareholder: "股东工作台",
  director: "总监工作台",
  general_agent: "总代理工作台",
  agent: "代理工作台",
};

function clearAgentAuthQuery() {
  const url = new URL(window.location.href);
  url.searchParams.delete("auto_token");
  url.searchParams.delete("agent_name");
  url.searchParams.delete("line_switch");
  window.history.replaceState({}, "", `${url.pathname}${url.search}${url.hash || "#/overview"}`);
}

function Placeholder({ title }: { title: string }) {
  return <section className="agent-workspace"><h2>{title}</h2><div className="agent-empty"><Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="暂无数据" /></div></section>;
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

const overviewTabs = ["总货概览", "总货明细", "中奖明细", "投注明细", "查看退码"];
const dateOptions = ["8-19(2026221)", "8-18(2026220)", "8-17(2026219)"];

function OverviewPage() {
  const [activeTab, setActiveTab] = useState(overviewTabs[0]);
  const [account, setAccount] = useState("");
  const [source, setSource] = useState("全部");
  const [startDate, setStartDate] = useState(dateOptions[0]);
  const [endDate, setEndDate] = useState(dateOptions[0]);

  return (
    <section className="overview-page">
      <div className="overview-location">
        <div className="overview-breadcrumb"><b>位置</b><span>»</span><u>{activeTab}</u></div>
        <div className="overview-tabs" role="tablist" aria-label="总货查询分类">
          {overviewTabs.map((tab) => (
            <button key={tab} type="button" role="tab" aria-selected={activeTab === tab} className={activeTab === tab ? "active" : ""} onClick={() => setActiveTab(tab)}>{tab}</button>
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
  const location = useLocation();
  const navigate = useNavigate();
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
  const [collapsed, setCollapsed] = useState(false);
  const [organizationProfile, setOrganizationProfile] = useState<AgentOrganizationProfile | null>(null);
  const [permissions, setPermissions] = useState<string[]>(() => { try { const value=JSON.parse(localStorage.getItem("agent_permissions")||"[]");return Array.isArray(value)?value.map(String):[]; } catch { return []; } });
  const lineRedirectTimer = useRef<number | null>(null);
  const lineCountdownTimer = useRef<number | null>(null);
  const lineRun = useRef(0);
  const isSubaccount = localStorage.getItem("agent_is_subaccount") === "1";
  const can = (permission: string) => permissions.includes("*") || permissions.includes(permission);
  const menuPermissions: Record<string,string[]> = { overview:["overview","order_details","bet_details","winning_details"], ledger:["contribution","daily_ledger","monthly_ledger","daily_path","monthly_path"], reports:["reports","monthly_reports"], results:["results"], organizations:["organization.manage"], subordinates:["subordinates"], intercept:["interception_details","interception_winning","interception_plate"], logs:["logs"], rules:["rules"], settings:["settings"], subaccounts:["subaccounts"] };
  const currentLevel = organizationProfile?.organization?.level || localStorage.getItem("agent_organization_level") || "agent";
  const systemName = levelSystemNames[currentLevel] || "业务系统";
  const resolvedSiteName = organizationProfile?.site.name || siteName || "站点管理系统";
  const workspaceName = levelWorkspaceNames[currentLevel] || "业务工作台";
  const visibleMenus = menus.filter((item) => {
    if (item.path === "organizations" && (currentLevel === "agent" || isSubaccount)) return false;
    if (item.path === "subordinates" && organizationProfile?.organization && currentLevel !== "agent") return false;
    return permissions.includes("*") || (menuPermissions[item.path] || []).some(can);
  });
  const visibleMenuPaths = visibleMenus.map((item) => item.path).join("|");
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
      setPermissions(nextPermissions);localStorage.setItem("agent_permissions",JSON.stringify(nextPermissions));
    }).catch(() => undefined);
  }, []);
  useEffect(() => {
    if (!organizationProfile) return;
    const currentPath=location.pathname.replace(/^\/+/,"")||"overview";
    const allowed=visibleMenus.some((item)=>currentPath===item.path||currentPath.startsWith(`${item.path}/`));
    if (!allowed) navigate(`/${visibleMenus[0]?.path||"overview"}`,{replace:true});
  }, [organizationProfile, location.pathname, navigate, visibleMenuPaths]);
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
    <div className={`app agent-app management-shell${collapsed ? " collapsed" : ""}`}>
      <aside className="management-sidebar">
        <div className="management-brand"><span className="management-brand-mark">K</span>{!collapsed && <div><b>{resolvedSiteName}</b><small>{systemName}</small></div>}</div>
        <nav className="management-navigation">
          {visibleMenus.map(({ path, title, icon: Icon }) => <NavLink key={path} to={"/" + path} title={title} className={({ isActive }) => isActive ? "selected" : ""}><Icon /><span>{title}</span></NavLink>)}
        </nav>
        <div className="management-sidebar-foot">{!collapsed && <><span>当前身份</span><b>{organizationProfile?.organization?.level_label || localStorage.getItem("agent_level_label") || "代理"}</b></>}</div>
      </aside>
      <section className="management-main">
        <header className="management-topbar">
          <button className="management-collapse" type="button" title={collapsed ? "展开菜单" : "收起菜单"} onClick={() => setCollapsed((value) => !value)}>{collapsed ? <MenuUnfoldOutlined /> : <MenuFoldOutlined />}</button>
          <div className="management-crumb">控制台 / <strong>{organizationProfile?.organization?.name || "业务管理"}</strong></div>
          <div className="management-actions"><button type="button" title="公告" onClick={() => modal.info({ title: announcement.title, content: <div className="announcement-modal-content">{announcement.content}</div>, okText: "关闭" })}><BellOutlined /></button><button type="button" onClick={() => void checkLines()}><SwapOutlined /><span>更换线路</span></button><span className="management-user"><b>{name}</b><small>{organizationProfile?.organization?.level_label || localStorage.getItem("agent_level_label") || "代理"}</small></span><button type="button" className="management-logout" onClick={onLogout}><LogoutOutlined /><span>退出</span></button></div>
        </header>
        <div className="management-tabbar"><span>{workspaceName}</span></div>
      <Modal title="切换线路" open={lineOpen} onCancel={closeLineModal} footer={null} width={460} destroyOnHidden>
        <div className="agent-line-tip">测速完成后将 <b>自动跳转</b> 至 <b>速度最快</b> 的线路</div>
        <div className="agent-line-tip">数字越 <b>小</b>，速度越 <b>快</b></div>
        {lineLoading && <div className="agent-line-modal-state">正在检测线路...</div>}
        {!lineLoading && lineResults.length === 0 && <div className="agent-line-modal-state">当前站点暂无可用线路</div>}
        {lineResults.length > 0 && <div className="agent-line-table"><div className="agent-line-table-row agent-line-table-head"><span>线路</span><span>延时</span></div>{lineResults.map((item) => <div className="agent-line-table-row" key={item.line}><span className="agent-line-name">线路{item.line}</span><strong className={delayClass(item.delay)}>{item.delay === null ? "检测失败" : <>{item.delay}ms{item.fastest && <em className="agent-line-fastest">最快</em>}</>}</strong></div>)}</div>}
        {lineCountdown !== null && <div className="agent-line-countdown"><b>{lineCountdown}</b> 秒后自动跳转至最快线路</div>}
      </Modal>
      <div className="agent-lottery-strip">
        {lotteriesLoading ? <div className="agent-lottery-empty">正在加载彩票...</div> : lotteries.length === 0 ? <div className="agent-lottery-empty">当前站点暂未分配彩票</div> : lotteries.map((item) => {
          const timing = lotteryTiming(item.next_open_time, now);
          return <button key={item.id} type="button" className={`agent-lottery-item${selectedLotteryId === item.id ? " selected" : ""}`} onClick={() => { setSelectedLotteryId(item.id); sessionStorage.setItem("agent_selected_lottery_id", String(item.id)); }}><b>{item.name}</b><strong>{item.next_code || item.latest_code || "--"}</strong><span>{timing.status}</span><em>{timing.countdown}</em></button>;
        })}
      </div>
      <div className="body agent-body management-workspace">
        <main><Routes><Route path="/" element={<OverviewPage />} /><Route path="/overview" element={<OverviewPage />} /><Route path="/organizations" element={<HierarchyPage />} /><Route path="/rules" element={<RulesPage lottery={lotteries.find((item) => item.id === selectedLotteryId)?.name || ""} />} /><Route path="/settings" element={<SettingsPage lottery={lotteries.find((item) => item.id === selectedLotteryId)?.name || ""} />} /><Route path="/reports" element={<ReportsPage lottery={lotteries.find((item) => item.id === selectedLotteryId)?.name || ""} />} /><Route path="/subordinates" element={<SubordinatesPage agentName={name} />} /><Route path="/subordinates/new" element={<SubordinateFormPage />} /><Route path="/subordinates/:id/edit" element={<SubordinateEditPage agentName={name} />} /><Route path="/logs" element={<LogsPage />} /><Route path="/ledger" element={<LedgerPage lottery={lotteries.find((item) => item.id === selectedLotteryId)?.name || ""} />} /><Route path="/results" element={<ResultsPage lottery={lotteries.find((item) => item.id === selectedLotteryId)?.name || ""} />} /><Route path="/intercept" element={<InterceptionsPage lottery={lotteries.find((item) => item.id === selectedLotteryId)?.name || ""} />} /><Route path="/subaccounts" element={<SubaccountsPage/>}/>{menus.filter((item) => !["overview", "organizations", "rules", "settings", "reports", "subordinates", "logs", "ledger", "results", "intercept", "subaccounts"].includes(item.path)).map((item) => <Route key={item.path} path={"/" + item.path} element={<Placeholder title={item.title} />} />)}<Route path="*" element={<OverviewPage />} /></Routes></main>
      </div>
      </section>
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
