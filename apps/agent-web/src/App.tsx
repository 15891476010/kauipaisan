import { useEffect, useRef, useState } from "react";
import { App as AntdApp, Button, DatePicker, Empty, Input, Modal, Select, Spin } from "antd";
import dayjs from "dayjs";
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
import { getAgentBetRecords, getAgentLineOptions, getAgentOrganizationProfile, getAgentOrderDetails, getAgentRefunds, getAgentWinningDetails, getAgreement, getAnnouncement, getBranding, getLedgerIssues, getLotteries, type AgentBetRecord, type AgentOrganizationProfile, type AgentOrderDetail, type AgentRefundRecord, type Announcement, type LedgerIssue, type Lottery } from "./api/user";
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
import { OverviewDetailsTable, OverviewRecordsTable, OverviewRefundsTable } from "./components/OverviewTables";

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
  small_shareholder: "小股东系统",
  general_agent: "总代理系统",
  agent: "代理系统",
};

// 代理端顶部只展示当前登录账号及其组织层级，避免把信用额度等
// 业务数据误认为登录身份。后端返回的 level_label 优先用于兼容自定义层级名称。
const levelDisplayNames: Record<string, string> = {
  director: "总监",
  shareholder: "大股东",
  small_shareholder: "小股东",
  general_agent: "总代理",
  agent: "代理",
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

function lotteryTiming(lottery: Lottery, now: number) {
  const permissionCanBet = lottery.can_bet !== false;
  const rules = lottery.timing_rules || [];
  const clock = new Date(now);
  const currentMinutes = clock.getHours() * 60 + clock.getMinutes();
  const parseMinutes = (value: unknown, fallback: number) => {
    const match = String(value ?? "").match(/^(\d{1,2}):(\d{2})$/);
    if (!match) return fallback;
    const hours = Number(match[1]);
    const minutes = Number(match[2]);
    return hours >= 0 && hours <= 23 && minutes >= 0 && minutes <= 59
      ? hours * 60 + minutes
      : fallback;
  };
  const rule = rules.find((item) => {
    const start = parseMinutes(item.start_time, 0);
    const end = parseMinutes(item.end_time, 1439);
    return start === end
      ? true
      : start < end
        ? currentMinutes >= start && currentMinutes < end
        : currentMinutes >= start || currentMinutes < end;
  });
  if (rule) {
    const canBet = permissionCanBet && rule.allow_bet === 1;
    const end = parseMinutes(rule.end_time, 1439);
    let seconds = (end - currentMinutes) * 60 - clock.getSeconds();
    if (seconds < 0) seconds += 24 * 60 * 60;
    const hours = String(Math.floor(seconds / 3600)).padStart(2, "0");
    const minutes = String(Math.floor((seconds % 3600) / 60)).padStart(2, "0");
    const remaining = String(seconds % 60).padStart(2, "0");
    return {
      status: rule.display_text?.trim() || (canBet ? "开盘中" : "即将开盘"),
      countdown: `${hours} : ${minutes} : ${remaining}`,
    };
  }
  const openTime = lottery.header_next_open_time || lottery.next_open_time;
  if (!openTime)
    return { status: lottery.timing_text?.trim() || "时间待定", countdown: "-- : -- : --" };
  const target = new Date(openTime.replace(" ", "T")).getTime();
  if (!Number.isFinite(target)) return { status: "时间待定", countdown: "-- : -- : --" };
  const seconds = Math.max(0, Math.floor((target - now) / 1000));
  const hours = String(Math.floor(seconds / 3600)).padStart(2, "0");
  const minutes = String(Math.floor((seconds % 3600) / 60)).padStart(2, "0");
  const remaining = String(seconds % 60).padStart(2, "0");
  return {
    status: lottery.timing_text?.trim() || (now >= target ? "已封盘" : "开盘中"),
    countdown: `${hours} : ${minutes} : ${remaining}`,
  };
}

const overviewTabs = [
  { label: "总货概览", permission: "overview" },
  { label: "总货明细", permission: "order_details" },
  { label: "中奖明细", permission: "winning_details" },
  { label: "投注明细", permission: "bet_details" },
  { label: "查看退码", permission: "refunds" },
];
function formatIssueOption(issue: LedgerIssue) {
  const date = String(issue.date || "");
  const match = /^(\d{4})-(\d{1,2})-(\d{1,2})/.exec(date);
  return match ? `${Number(match[2])}-${Number(match[3])}(${issue.issue_no})` : issue.issue_no;
}

function OverviewPage({ lottery: suppliedLottery = "" }: { lottery?: string } = {}) {
  const visibleTabs = overviewTabs.filter((tab) => hasAgentPermission(tab.permission));
  const [activeTab, setActiveTab] = useState(() => visibleTabs[0]?.label || "总货概览");
  const [account, setAccount] = useState("");
  const [source, setSource] = useState("全部");
  const [issues, setIssues] = useState<LedgerIssue[]>([]);
  const [issuesLoading, setIssuesLoading] = useState(false);
  const [startDate, setStartDate] = useState("");
  const [endDate, setEndDate] = useState("");
  const [lottery, setLottery] = useState(suppliedLottery);
  const [lotteryId, setLotteryId] = useState<number | null>(() => {
    const value = Number(sessionStorage.getItem("agent_selected_lottery_id"));
    return Number.isInteger(value) && value > 0 ? value : null;
  });
  const [details, setDetails] = useState<AgentOrderDetail[]>([]);
  const [records, setRecords] = useState<AgentBetRecord[]>([]);
  const [refunds, setRefunds] = useState<AgentRefundRecord[]>([]);
  const [dataLoading, setDataLoading] = useState(false);
  const [dataError, setDataError] = useState("");
  const [number, setNumber] = useState("");
  const [metric, setMetric] = useState("odds");
  const [min, setMin] = useState("");
  const [max, setMax] = useState("");
  const [category, setCategory] = useState("所有");
  const [winningStatus, setWinningStatus] = useState("all");
  const [sourceText, setSourceText] = useState("");
  const [fromTime, setFromTime] = useState("");
  const [toTime, setToTime] = useState("");
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(40);
  const [queryVersion, setQueryVersion] = useState(0);
  const [total, setTotal] = useState(0);
  const [detailModalOpen, setDetailModalOpen] = useState(false);
  const [detailModalRows, setDetailModalRows] = useState<AgentOrderDetail[]>([]);
  const [detailModalLoading, setDetailModalLoading] = useState(false);

  useEffect(() => {
    let active = true;
    if (suppliedLottery) {
      setLottery(suppliedLottery);
    } else {
      const selectedId = Number(sessionStorage.getItem("agent_selected_lottery_id"));
      void getLotteries().then((response) => {
        if (!active) return;
        const list = response.data.data?.list || [];
        const selected = list.find((item) => item.id === selectedId) || list[0];
        setLottery(selected?.name || "");
        setLotteryId(selected?.id || null);
      }).catch(() => active && setLottery(""));
    }
    const handleLotteryChange = (event: Event) => {
      const detail = (event as CustomEvent<{ id?: number; name?: string } | string>).detail;
      if (typeof detail === "string") setLottery(detail);
      else { setLottery(detail?.name || ""); setLotteryId(detail?.id || null); }
    };
    window.addEventListener("agent:lottery-changed", handleLotteryChange);
    return () => {
      active = false;
      window.removeEventListener("agent:lottery-changed", handleLotteryChange);
    };
  }, [suppliedLottery]);

  useEffect(() => {
    if (!lottery) {
      setIssues([]);
      setStartDate("");
      setEndDate("");
      return;
    }
    let active = true;
    setIssuesLoading(true);
    void getLedgerIssues({ lottery }).then((response) => {
      if (!active) return;
      const list = response.data.data?.list || [];
      setIssues(list);
      setStartDate(list[0]?.issue_no || "");
      setEndDate(list[0]?.issue_no || "");
    }).catch(() => {
      if (!active) return;
      setIssues([]);
      setStartDate("");
      setEndDate("");
    }).finally(() => {
      if (active) setIssuesLoading(false);
    });
    return () => { active = false; };
  }, [lottery]);

  useEffect(() => {
    if (!lottery || !startDate || !endDate) {
      setDetails([]); setRecords([]); setRefunds([]); return;
    }
    let active = true;
    setDataLoading(true); setDataError("");
    const params: Record<string, unknown> = {
      lottery_id: lotteryId || undefined,
      from_issue: startDate,
      to_issue: endDate,
      account: account.trim() || undefined,
      source: source === "全部" ? "all" : source,
      number: number.trim() || undefined,
      metric,
      min: min || undefined,
      max: max || undefined,
      category: category === "所有" ? undefined : category,
      status: winningStatus,
      source_text: sourceText.trim() || undefined,
      from: fromTime || undefined,
      to: toTime || undefined,
      page,
      page_size: pageSize,
    };
    const task = activeTab === "总货明细" ? getAgentOrderDetails(params)
      : activeTab === "中奖明细" ? getAgentWinningDetails(params)
      : activeTab === "查看退码" ? getAgentRefunds(params)
      : getAgentBetRecords(params);
    void task.then((response) => {
      if (!active) return;
      const data = response.data.data;
      if (activeTab === "总货明细" || activeTab === "中奖明细") { setDetails((data as { list: AgentOrderDetail[] }).list || []); setRecords([]); setRefunds([]); }
      else if (activeTab === "查看退码") { setRefunds((data as { list: AgentRefundRecord[] }).list || []); setDetails([]); setRecords([]); }
      else { setRecords((data as { list: AgentBetRecord[] }).list || []); setDetails([]); setRefunds([]); }
      setTotal(Number((data as { total?: number }).total || 0));
    }).catch((reason: unknown) => {
      if (!active) return;
      setDetails([]); setRecords([]); setRefunds([]);
      setDataError(apiErrorMessage(reason, "数据加载失败，请稍后重试"));
    }).finally(() => { if (active) setDataLoading(false); });
    return () => { active = false; };
  }, [activeTab, account, category, endDate, fromTime, lottery, lotteryId, max, metric, min, number, page, pageSize, queryVersion, source, sourceText, startDate, toTime, winningStatus]);

  const openRecordDetails = async (id: number) => {
    setDetailModalOpen(true);
    setDetailModalLoading(true);
    try {
      const response = await getAgentOrderDetails({ lottery_id: lotteryId || undefined, record_id: id, include_refunded: 1, page: 1, page_size: 100 });
      setDetailModalRows(response.data.data?.list || []);
    } catch (error) {
      setDetailModalRows([]);
      setDataError(apiErrorMessage(error, "注单明细加载失败"));
    } finally {
      setDetailModalLoading(false);
    }
  };

  return (
    <section className="overview-page">
      <div className="overview-location">
        <div className="overview-breadcrumb"><b>位置</b><span>»</span><u>{activeTab}</u></div>
        <div className="overview-tabs" role="tablist" aria-label="总货查询分类">
          {visibleTabs.map((tab) => (
            <button key={tab.permission} type="button" role="tab" aria-selected={activeTab === tab.label} className={activeTab === tab.label ? "active" : ""} onClick={() => { setActiveTab(tab.label); setPage(1); }}>{tab.label}</button>
          ))}
        </div>
      </div>

      <form className={`overview-filters overview-filters-${activeTab === "总货概览" ? "overview" : activeTab === "查看退码" ? "refund" : activeTab === "投注明细" ? "bets" : activeTab === "中奖明细" ? "winning" : "details"}`} onSubmit={(event) => { event.preventDefault(); setPage(1); setQueryVersion((value) => value + 1); }}>
        {activeTab === "投注明细" ? <>
          <fieldset className="status-filter"><legend>中奖</legend><Select className="overview-select" size="small" value={winningStatus} onChange={setWinningStatus} options={[{ value: "all", label: "全部" }, { value: "won", label: "中奖" }, { value: "unwon", label: "未中" }]} /></fieldset>
          <fieldset><legend>会员账号</legend><Input className="overview-input" size="small" value={account} onChange={(event) => setAccount(event.target.value)} placeholder="搜索会员名" /></fieldset>
          <fieldset className="text-filter"><legend>原始文本搜索</legend><Input className="overview-input" size="small" value={sourceText} onChange={(event) => setSourceText(event.target.value)} placeholder="输入文本" /></fieldset>
          <fieldset className="date-range"><legend>投注时间</legend><DatePicker size="small" value={fromTime ? dayjs(fromTime) : null} onChange={(date) => setFromTime(date ? date.format("YYYY-MM-DD") : "")} placeholder="开始日期" /><span>至</span><DatePicker size="small" value={toTime ? dayjs(toTime) : null} onChange={(date) => setToTime(date ? date.format("YYYY-MM-DD") : "")} placeholder="结束日期" /></fieldset>
        </> : <>
          <fieldset><legend>查账号：</legend><Input className="overview-input" size="small" value={account} onChange={(event) => setAccount(event.target.value)} placeholder="查账号" /></fieldset>
          {activeTab !== "总货概览" && activeTab !== "查看退码" ? <fieldset><legend>查号码：</legend><Input className="overview-input" size="small" value={number} onChange={(event) => setNumber(event.target.value)} placeholder="查号码" /></fieldset> : null}
          {activeTab === "中奖明细" ? <fieldset className="check-filter"><legend>组</legend><label><input type="checkbox" /> 是?</label></fieldset> : null}
          {activeTab !== "总货概览" && activeTab !== "查看退码" ? <fieldset className="range-filter"><legend>列出</legend><div><Select className="overview-select metric-select" size="small" value={metric} onChange={setMetric} options={[{ value: "odds", label: "赔率" }, { value: "amount", label: "金额" }]} /><Input className="overview-range-input" size="small" value={min} onChange={(event) => setMin(event.target.value.replace(/[^\d.]/g, ""))} /><span>至</span><Input className="overview-range-input" size="small" value={max} onChange={(event) => setMax(event.target.value.replace(/[^\d.]/g, ""))} /></div></fieldset> : null}
          {activeTab !== "总货概览" && activeTab !== "查看退码" ? <fieldset className="category-filter"><legend>分类</legend><Select className="overview-select" size="small" value={category} onChange={setCategory} options={["所有", "直选", "组三", "组六", "定位", "和值", "跨度"].map((value) => ({ value, label: value }))} /></fieldset> : null}
          {activeTab === "中奖明细" ? <><fieldset className="source-filter"><legend>来源</legend><Select className="overview-select" size="small" value={source} onChange={setSource} options={[{ value: "全部", label: "全部" }, { value: "quick", label: "快录" }]} /></fieldset><fieldset className="source-filter"><legend>设备</legend><Select className="overview-select" size="small" defaultValue="全部" options={[{ value: "全部", label: "全部" }, { value: "快录网", label: "快录网" }]} /></fieldset></> : activeTab === "总货概览" ? <fieldset className="source-filter"><legend>来源：</legend><Select className="overview-select" size="small" value={source} onChange={setSource} options={[{ value: "全部", label: "全部" }, { value: "quick", label: "快录" }]} /></fieldset> : null}
        </>}
        <div className="overview-submit-wrap"><Button className="overview-submit" htmlType="submit" type="primary">{activeTab === "投注明细" ? "搜索" : "提交"}</Button></div>
      </form>

      <section className="overview-table-panel">
        <div className="overview-table-title">
          <strong>{activeTab}</strong>
          <Select className="overview-issue-select" size="small" value={startDate} onChange={setStartDate} aria-label="开始日期" disabled={issuesLoading || issues.length === 0} options={issues.length === 0 ? [{ value: "", label: issuesLoading ? "正在加载期号" : "暂无可用期号" }] : issues.map((item) => ({ value: item.issue_no, label: formatIssueOption(item) }))} />
          <span>至</span>
          <Select className="overview-issue-select" size="small" value={endDate} onChange={setEndDate} aria-label="结束日期" disabled={issuesLoading || issues.length === 0} options={issues.length === 0 ? [{ value: "", label: issuesLoading ? "正在加载期号" : "暂无可用期号" }] : issues.map((item) => ({ value: item.issue_no, label: formatIssueOption(item) }))} />
        </div>
        <div className="overview-table-scroll">
          {dataLoading ? <div className="overview-no-data"><Spin /></div> : dataError ? <div className="overview-no-data">{dataError}</div> : activeTab === "总货明细" || activeTab === "中奖明细" ? (details.length ? <OverviewDetailsTable rows={details} winning={activeTab === "中奖明细"} /> : <div className="overview-no-data"><Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="暂无数据" /></div>) : activeTab === "查看退码" ? (refunds.length ? <OverviewRefundsTable rows={refunds} onDetails={(row) => void openRecordDetails(row.id)} /> : <div className="overview-no-data"><Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="暂无数据" /></div>) : records.length ? <OverviewRecordsTable rows={records} onDetails={(row) => void openRecordDetails(row.id)} /> : <div className="overview-no-data"><Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="暂无数据" /></div>}
        </div>
        <div className="overview-pagination"><span>总计：<b>{total}</b> 条数据</span><button type="button" disabled={page <= 1} onClick={() => setPage((value) => Math.max(1, value - 1))}>‹</button><strong>{page}</strong><button type="button" disabled={page >= Math.max(1, Math.ceil(total / pageSize))} onClick={() => setPage((value) => value + 1)}>›</button><Select className="overview-page-size" size="small" value={pageSize} onChange={(value) => { setPageSize(Number(value)); setPage(1); }} options={[10, 40, 100].map((value) => ({ value, label: `${value} 条/页` }))} /></div>
      </section>
      <Modal className="overview-detail-modal" title="注单明细" open={detailModalOpen} footer={null} width={1500} onCancel={() => setDetailModalOpen(false)}>{detailModalLoading ? <div className="overview-no-data"><Spin /></div> : detailModalRows.length ? <div className="overview-modal-table-scroll"><OverviewDetailsTable rows={detailModalRows} /></div> : <Empty description="暂无明细" />}</Modal>
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
  const [warmVisible, setWarmVisible] = useState(true);
  const [warmOpen, setWarmOpen] = useState(false);
  const [organizationProfile, setOrganizationProfile] = useState<AgentOrganizationProfile | null>(null);
  const [permissions, setPermissions] = useState<string[]>(() => { try { const value=JSON.parse(localStorage.getItem("agent_permissions")||"[]");return Array.isArray(value)?value.map(String):[]; } catch { return []; } });
  const [permissionsReady, setPermissionsReady] = useState(false);
  const [permissionsFailed, setPermissionsFailed] = useState(false);
  const lineRedirectTimer = useRef<number | null>(null);
  const lineCountdownTimer = useRef<number | null>(null);
  const lineRun = useRef(0);
  const isSubaccount = localStorage.getItem("agent_is_subaccount") === "1";
  const currentLevel = organizationProfile?.organization?.level || localStorage.getItem("agent_organization_level") || "agent";
  const currentLevelLabel = organizationProfile?.organization?.level_label || localStorage.getItem("agent_level_label") || levelDisplayNames[currentLevel] || "代理";
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
    const selected = lotteries.find((item) => item.id === selectedLotteryId);
    window.dispatchEvent(new CustomEvent("agent:lottery-changed", { detail: { id: selected?.id || null, name: selected?.name || "" } }));
  }, [lotteries, selectedLotteryId]);
  useEffect(() => {
    const send = () => { void heartbeat().catch(() => undefined); };
    send();
    const timer = window.setInterval(send, 20_000);
    return () => window.clearInterval(timer);
  }, []);
  useEffect(() => {
    let firstLoad = true;
    const refreshPermissions = () => {
      void getAgentOrganizationProfile().then((response) => {
        const profile=response.data.data; setOrganizationProfile(profile);
        if(profile.organization){localStorage.setItem("agent_organization_level",profile.organization.level);localStorage.setItem("agent_level_label",profile.organization.level_label);}
        const nextPermissions=Array.isArray(profile.permissions)?profile.permissions.map(String):[];
        setPermissions(nextPermissions);localStorage.setItem("agent_permissions",JSON.stringify(nextPermissions));setPermissionsFailed(false);
      }).catch(() => { if (firstLoad) { setPermissions([]); setPermissionsFailed(true); } }).finally(() => { if (firstLoad) { setPermissionsReady(true); firstLoad=false; } });
    };
    refreshPermissions();
    const timer=window.setInterval(refreshPermissions,30_000);
    window.addEventListener("focus",refreshPermissions);
    return () => { window.clearInterval(timer); window.removeEventListener("focus",refreshPermissions); };
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
      <header className="site-header agent-header">
        <div className="agent-identity">
          <img className="fish-logo agent-logo" src={fishLogo} alt="快排" />
          <div className="account agent-account-box">
            <label className="account-field account-current agent-account-field">
              <span className="agent-account-label">账号</span>
              <input className="agent-account-value" value={`${currentLevelLabel}：${name}${organizationProfile?.organization?.boards?.length ? `（${organizationProfile.organization.boards.map((board) => board.name).join("、")}）` : ""}`} readOnly />
            </label>
          </div>
        </div>
        <nav className="site-navigation agent-navigation">
          {visibleMenus.map(({ path, title, icon: Icon }) => <NavLink key={path} to={`/${path}`} title={title} className={({ isActive }) => isActive ? "selected" : ""}><span className="nav-icon-shell"><Icon className="nav-icon" /></span>{path === "ledger" ? "贡献度" : title}</NavLink>)}
          <button className="line" type="button" onClick={() => void checkLines()}><span className="nav-icon-shell"><SwapOutlined className="nav-icon" /></span><em>更换线路</em></button>
          <button className="exit" type="button" onClick={onLogout}><span className="nav-icon-shell"><LogoutOutlined className="nav-icon" /></span><em>退出</em></button>
        </nav>
      </header>
      <div className="agent-lottery-strip" aria-label="彩票列表">
        <ul className="lottery">
          {lotteriesLoading ? <li>正在加载彩票...</li> : lotteries.length === 0 ? <li>当前站点暂未分配彩票</li> : lotteries.map((item) => {
            const timing = lotteryTiming(item, now);
            // The API returns 0/1 for this setting; treat both numeric and
            // boolean false as "show the current opened issue".
            const showHeaderNext = item.header_show_next_issue !== false && Number(item.header_show_next_issue ?? 1) !== 0;
            const issue = showHeaderNext ? (item.header_next_code || item.next_code) : item.latest_code;
            return <li key={item.id} className={selectedLotteryId === item.id ? "selected" : ""} role="button" tabIndex={0} onClick={() => { setSelectedLotteryId(item.id); sessionStorage.setItem("agent_selected_lottery_id", String(item.id)); }} onKeyDown={(event) => { if (event.key === "Enter" || event.key === " ") { setSelectedLotteryId(item.id); sessionStorage.setItem("agent_selected_lottery_id", String(item.id)); } }}><div className="lottery-row"><div className="lottery-name"><span>{item.name}</span><b>{timing.status}</b></div><div className="lottery-meta"><label>{issue || "--"}</label><strong>{timing.countdown}</strong></div></div></li>;
          })}
        </ul>
      </div>
      <Modal title="切换线路" open={lineOpen} onCancel={closeLineModal} footer={null} width={460} destroyOnHidden>
        <div className="agent-line-tip">测速完成后将 <b>自动跳转</b> 至 <b>速度最快</b> 的线路</div>
        <div className="agent-line-tip">数字越 <b>小</b>，速度越 <b>快</b></div>
        {lineLoading && <div className="agent-line-modal-state">正在检测线路...</div>}
        {!lineLoading && lineResults.length === 0 && <div className="agent-line-modal-state">当前站点暂无可用线路</div>}
        {lineResults.length > 0 && <div className="agent-line-table"><div className="agent-line-table-row agent-line-table-head"><span>线路</span><span>延时</span></div>{lineResults.map((item) => <div className="agent-line-table-row" key={item.line}><span className="agent-line-name">线路{item.line}</span><strong className={delayClass(item.delay)}>{item.delay === null ? "检测失败" : <>{item.delay}ms{item.fastest && <em className="agent-line-fastest">最快</em>}</>}</strong></div>)}</div>}
        {lineCountdown !== null && <div className="agent-line-countdown"><b>{lineCountdown}</b> 秒后自动跳转至最快线路</div>}
      </Modal>
      <div className="body agent-body management-workspace">
        <main>{!permissionsReady ? <section className="agent-workspace"><div className="agent-empty">正在加载实时权限...</div></section> : permissionsFailed ? <PermissionState failed /> : firstRoute === null ? <PermissionState /> : <Routes><Route path="/" element={<Navigate to={`/${firstRoute}`} replace />} />{routeAllowed("overview") && <Route path="/overview" element={<OverviewPage />} />}{routeAllowed("rules") && <Route path="/rules" element={<RulesPage lottery={lotteries.find((item) => item.id === selectedLotteryId)?.name || ""} />} />}{routeAllowed("settings") && <Route path="/settings" element={<SettingsPage lottery={lotteries.find((item) => item.id === selectedLotteryId)?.name || ""} />} />}{routeAllowed("reports") && <Route path="/reports" element={<ReportsPage lottery={lotteries.find((item) => item.id === selectedLotteryId)?.name || ""} />} />}{routeAllowed("subordinates") && <Route path="/subordinates" element={<SubordinatesPage agentName={name} />} />}{routeAllowed("subordinates") && (currentLevel === "agent" ? hasAgentPermission("member.create", permissions) : hasAgentPermission("organization.create", permissions)) && <Route path="/subordinates/new" element={<SubordinateFormPage />} />}{routeAllowed("subordinates") && hasAgentPermission("member.update", permissions) && <Route path="/subordinates/:id/edit" element={<SubordinateEditPage agentName={name} />} />}{routeAllowed("logs") && <Route path="/logs" element={<LogsPage />} />}{routeAllowed("ledger") && <Route path="/ledger" element={<LedgerPage lottery={lotteries.find((item) => item.id === selectedLotteryId)?.name || ""} />} />}{routeAllowed("results") && <Route path="/results" element={<ResultsPage lottery={lotteries.find((item) => item.id === selectedLotteryId)?.name || ""} />} />}{routeAllowed("intercept") && <Route path="/intercept" element={<InterceptionsPage lottery={lotteries.find((item) => item.id === selectedLotteryId)?.name || ""} />} />}{routeAllowed("subaccounts") && <Route path="/subaccounts" element={<SubaccountsPage/>}/>}<Route path="*" element={<Navigate to={`/${firstRoute}`} replace />} /></Routes>}</main>
      </div>
      {warmVisible ? <section className={`warm${warmOpen ? " is-open" : " is-collapsed"}`} aria-label="温馨提示"><header className="warm-header"><strong>温馨提示</strong><div className="warm-actions"><button type="button" title={warmOpen ? "收回温馨提示" : "弹出温馨提示"} aria-label={warmOpen ? "收回温馨提示" : "弹出温馨提示"} onClick={() => setWarmOpen((value) => !value)}><span aria-hidden="true">{warmOpen ? "−" : "↑"}</span></button><button type="button" title="关闭温馨提示" aria-label="关闭温馨提示" onClick={() => setWarmVisible(false)}><span aria-hidden="true">×</span></button></div></header><div className="warm-content">【温馨提示】各位会员在下注确定后请到“下注明细”里确认注单，一切注单结算以下注明细里资料为准！</div></section> : null}
    </div>
  );
}

export default function App() {
  const { modal } = AntdApp.useApp();
  const [siteName, setSiteName] = useState("站点管理系统");
  const [name, setName] = useState(() => localStorage.getItem("agent_token") ? localStorage.getItem("agent_name") || "" : "");
  const [mustChangePassword, setMustChangePassword] = useState(() => localStorage.getItem("agent_must_change_password") === "1");
  const [agreementVisible, setAgreementVisible] = useState(() => {
    const token = localStorage.getItem("agent_token");
    return Boolean(token && localStorage.getItem("agent_name") && sessionStorage.getItem("agent_agreement_accepted_token") !== token);
  });
  const [agreement, setAgreement] = useState<AgreementData>(defaultAgentAgreement);
  const [announcement, setAnnouncement] = useState<Announcement>({ title: "代理端公告", content: "暂无公告" });
  const clearSession = () => { clearAgentAuthQuery(); localStorage.removeItem("agent_token"); localStorage.removeItem("agent_name"); localStorage.removeItem("agent_permissions"); localStorage.removeItem("agent_is_subaccount"); localStorage.removeItem("agent_organization_level"); localStorage.removeItem("agent_level_label"); localStorage.removeItem("agent_must_change_password"); sessionStorage.removeItem("agent_agreement_accepted_token"); setAgreementVisible(false); setMustChangePassword(false); setName(""); };
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
    const handleUnauthorized = () => {
      modal.confirm({
        title: "登录已过期",
        content: "请重新登录",
        okText: "确认",
        cancelButtonProps: { style: { display: "none" } },
        maskClosable: false,
        closable: false,
        onOk: clearSession,
      });
    };
    window.addEventListener("agent:unauthorized", handleUnauthorized);
    return () => window.removeEventListener("agent:unauthorized", handleUnauthorized);
  }, [modal]);
  const logout = () => { void logoutSession().catch(() => undefined).finally(clearSession); };
  return <HashRouter>{name ? agreementVisible ? <div className="agent-agreement-theme"><Agreement agreement={agreement} onReject={logout} onAccept={() => { const token=localStorage.getItem("agent_token"); if (token) sessionStorage.setItem("agent_agreement_accepted_token",token); setAgreementVisible(false); }} /></div> : mustChangePassword ? <ForcedPasswordPage username={name} onSuccess={() => setMustChangePassword(false)} /> : <AgentMain name={name} onLogout={logout} announcement={announcement} siteName={siteName} /> : <Login siteName={siteName} onLogin={(value) => { localStorage.setItem("agent_name", value); sessionStorage.removeItem("agent_agreement_accepted_token"); setMustChangePassword(localStorage.getItem("agent_must_change_password") === "1"); setName(value); setAgreementVisible(true); }} />}</HashRouter>;
}
