import { useEffect, useState } from "react";
import { App as AntdApp, Empty } from "antd";
import { HashRouter, NavLink, Route, Routes } from "react-router-dom";
import {
  AccountBookOutlined, AlertOutlined, FileDoneOutlined, FileTextOutlined,
  FolderOutlined, LogoutOutlined,
  SettingOutlined, ShareAltOutlined, SwapOutlined, TeamOutlined, TrophyOutlined,
  TransactionOutlined,
} from "@ant-design/icons";
import "./App.css";
import { Login } from "./features/auth/Login";
import { Agreement, defaultAgentAgreement, type AgreementData } from "./features/agreement/Agreement";
import { getAgreement, getAnnouncement, getLotteries, type Announcement, type Lottery } from "./api/user";
import { RulesPage } from "./features/rules/RulesPage";
import { SubordinatesPage } from "./features/subordinates/SubordinatesPage";
import { SubordinateFormPage } from "./features/subordinates/SubordinateFormPage";
import { SubordinateEditPage } from "./features/subordinates/SubordinateEditPage";
import agentLogo from "./assets/agent-logo.svg";

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

function AgentMain({ name, onLogout, announcement }: { name: string; onLogout: () => void; announcement: Announcement }) {
  const { modal } = AntdApp.useApp();
  const [lotteries, setLotteries] = useState<Lottery[]>([]);
  const [selectedLotteryId, setSelectedLotteryId] = useState<number | null>(() => {
    const stored = Number(sessionStorage.getItem("agent_selected_lottery_id"));
    return Number.isInteger(stored) && stored > 0 ? stored : null;
  });
  const [lotteriesLoading, setLotteriesLoading] = useState(true);
  const [now, setNow] = useState(Date.now());
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
  return (
    <div className="app agent-app">
      <button className="notice" type="button" onClick={() => modal.info({ title: announcement.title, content: <div className="announcement-modal-content">{announcement.content}</div>, okText: "关闭" })}>
        <span className="notice-track">{announcement.content || "暂无公告"}</span><span className="notice-track" aria-hidden="true">{announcement.content || "暂无公告"}</span>
      </button>
      <header className="agent-header">
        <div className="agent-identity">
          <img className="agent-logo" src={agentLogo} alt="代理端" />
          <div className="agent-account-box"><span className="agent-account-label">账号</span><span className="agent-account-value">代理： {name}</span></div>
        </div>
        <nav className="agent-navigation">
          {menus.map(({ path, title, icon: Icon }) => <NavLink key={path} to={"/" + path} className={({ isActive }) => isActive ? "selected" : ""}><span className="nav-icon-shell"><Icon className="nav-icon-svg" /></span><span>{title}</span></NavLink>)}
          <button className="line" type="button" onClick={() => modal.info({ title: "更换线路", content: "线路测速功能即将接入。", okText: "关闭" })}><span className="nav-icon-shell"><SwapOutlined className="nav-icon-svg" /></span><span>更换线路</span></button>
          <button className="exit" type="button" onClick={onLogout}><span className="nav-icon-shell"><LogoutOutlined className="nav-icon-svg" /></span><span>退出</span></button>
        </nav>
      </header>
      <div className="agent-lottery-strip">
        {lotteriesLoading ? <div className="agent-lottery-empty">正在加载彩票...</div> : lotteries.length === 0 ? <div className="agent-lottery-empty">当前站点暂未分配彩票</div> : lotteries.map((item) => {
          const timing = lotteryTiming(item.next_open_time, now);
          return <button key={item.id} type="button" className={`agent-lottery-item${selectedLotteryId === item.id ? " selected" : ""}`} onClick={() => { setSelectedLotteryId(item.id); sessionStorage.setItem("agent_selected_lottery_id", String(item.id)); }}><b>{item.name}</b><strong>{item.next_code || item.latest_code || "--"}</strong><span>{timing.status}</span><em>{timing.countdown}</em></button>;
        })}
      </div>
      <div className="body agent-body">
        <main><Routes><Route path="/" element={<OverviewPage />} /><Route path="/overview" element={<OverviewPage />} /><Route path="/rules" element={<RulesPage />} /><Route path="/subordinates" element={<SubordinatesPage agentName={name} />} /><Route path="/subordinates/new" element={<SubordinateFormPage />} /><Route path="/subordinates/:id/edit" element={<SubordinateEditPage agentName={name} />} />{menus.filter((item) => !["overview", "rules", "subordinates"].includes(item.path)).map((item) => <Route key={item.path} path={"/" + item.path} element={<Placeholder title={item.title} />} />)}<Route path="*" element={<OverviewPage />} /></Routes></main>
      </div>
      <div className="warm">代理端　↑　✕</div>
    </div>
  );
}

export default function App() {
  const [name, setName] = useState(() => localStorage.getItem("agent_token") ? localStorage.getItem("agent_name") || "" : "");
  const [agreementVisible, setAgreementVisible] = useState(() => {
    const token = localStorage.getItem("agent_token");
    return Boolean(token && localStorage.getItem("agent_name") && sessionStorage.getItem("agent_agreement_accepted_token") !== token);
  });
  const [agreement, setAgreement] = useState<AgreementData>(defaultAgentAgreement);
  const [announcement, setAnnouncement] = useState<Announcement>({ title: "代理端公告", content: "暂无公告" });
  useEffect(() => {
    const token = new URLSearchParams(window.location.search).get("auto_token");
    if (!token) return;
    localStorage.setItem("agent_token", token);
    const agentName = new URLSearchParams(window.location.search).get("agent_name") || "站点管理员";
    localStorage.setItem("agent_name", agentName);
    sessionStorage.removeItem("agent_agreement_accepted_token");
    window.history.replaceState({}, "", window.location.pathname + (window.location.hash || "#/overview"));
    setName(agentName);
    setAgreementVisible(true);
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
    const logout = () => setName("");
    window.addEventListener("agent:unauthorized", logout);
    return () => window.removeEventListener("agent:unauthorized", logout);
  }, []);
  const logout = () => { localStorage.removeItem("agent_token"); localStorage.removeItem("agent_name"); sessionStorage.removeItem("agent_agreement_accepted_token"); setAgreementVisible(false); setName(""); };
  return <HashRouter>{name ? agreementVisible ? <div className="agent-agreement-theme"><Agreement agreement={agreement} onReject={logout} onAccept={() => { const token=localStorage.getItem("agent_token"); if (token) sessionStorage.setItem("agent_agreement_accepted_token",token); setAgreementVisible(false); }} /></div> : <AgentMain name={name} onLogout={logout} announcement={announcement} /> : <Login onLogin={(value) => { localStorage.setItem("agent_name", value); sessionStorage.removeItem("agent_agreement_accepted_token"); setName(value); setAgreementVisible(true); }} />}</HashRouter>;
}
