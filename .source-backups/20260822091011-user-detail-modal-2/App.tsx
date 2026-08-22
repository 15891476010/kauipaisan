import { useEffect, useMemo, useRef, useState } from "react";
import { App as AntdApp, DatePicker, Empty, Input, Modal, Switch, Tooltip } from "antd";
import zhCN from "antd/es/date-picker/locale/zh_CN";
import { CloseOutlined, DeleteOutlined, ExportOutlined, SearchOutlined, VerticalAlignBottomOutlined } from "@ant-design/icons";
import {
  HashRouter,
  NavLink,
  Route,
  Routes,
  useLocation,
} from "react-router-dom";
import "./App.css";
import axios from "axios";
import DOMPurify from "dompurify";
import { changePassword, createQuickTag, deleteQuickTag, getAgreement, getAnnouncement, getBetDetails, getBetRecords, getBills, getDraws, getLineOptions, getLotteries, getProfile, getQuickSettings, getRules, getStopDrops, heartbeat, logoutSession, placeQuickEntry, previewQuickEntry, refundBetRecord, saveQuickSettings, waitDraws, type BetDetail, type BetRecord, type Bill, type Draw, type Lottery, type QuickEntryLine, type QuickPreview, type QuickTag, type RuleSettings, type StopDrop, type UserProfile } from "./api/user";
import { apiErrorMessage, request } from "./utils/request";
import loginLogo from "./assets/login-logo.svg";
import { CaptchaModal } from "./components/CaptchaModal";
import { Toast } from "./components/Toast";
import { RuleInstructionsModal } from "./components/RuleInstructionsModal";
import { QuickResultTable } from "./components/QuickResultTable";
import { Login } from "./features/auth/Login";
import { Agreement, defaultAgreement, type AgreementData } from "./features/agreement/Agreement";
import accountBookIcon from "./assets/account-book.svg";
import arrowRightIcon from "./assets/arrow-right.svg";
import alertIcon from "./assets/alert.svg";
import fileDoneIcon from "./assets/file-done.svg";
import importIcon from "./assets/import.svg";
import keyIcon from "./assets/key.svg";
import logoutIcon from "./assets/logout.svg";
import swapIcon from "./assets/swap.svg";
import trophyIcon from "./assets/trophy.svg";
import userIcon from "./assets/user.svg";
import dayjs from "dayjs";
import { motion } from "motion/react";

const nav = [
  { path: "kb", title: "快速录入", icon: importIcon },
  { path: "zh", title: "下注明细", icon: fileDoneIcon },
  { path: "zd", title: "历史账单", icon: accountBookIcon },
  { path: "hyxx", title: "会员资料", icon: userIcon },
  { path: "jg", title: "开奖号码", icon: trophyIcon },
  { path: "gz", title: "规则说明", icon: alertIcon },
  { path: "xgmm", title: "修改密码", icon: keyIcon },
];
function clearUserAuthQuery() {
  const url = new URL(window.location.href);
  url.searchParams.delete("auto_token");
  url.searchParams.delete("line_switch");
  window.history.replaceState({}, "", `${url.pathname}${url.search}${url.hash || "#/kb"}`);
}
function LegacyLogin({ onLogin }: { onLogin: (name: string) => void }) {
  const [name, setName] = useState("");
  const [password, setPassword] = useState("");
  const [captcha, setCaptcha] = useState("");
  const [showCaptcha, setShowCaptcha] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");
  useMemo(() => { const token = new URLSearchParams(window.location.search).get("auto_token"); if (token) { localStorage.setItem("user_token", token); onLogin("站点管理员"); } }, [onLogin]);
  async function authenticate() {
    setBusy(true);
    try {
      const r = await request.post("/user/auth/login", { username: name, password, captcha });
      const token = r.data?.data?.token;
      if (token) localStorage.setItem("user_token", token);
      onLogin(name);
    } catch (reason) {
      setError(axios.isAxiosError(reason) ? String(reason.response?.data?.message || "登录失败") : "登录失败");
    } finally { setBusy(false); }
  }
  async function submit(e: React.FormEvent) {
    e.preventDefault();
    setError("");
    if (!name || !password) {
      setError("请输入账号和密码");
      return;
    }
    if (!showCaptcha) {
      setShowCaptcha(true);
      return;
    }
    if (captcha.toUpperCase() !== "K8P3" && captcha !== "11") {
      setError("验证码错误");
      return;
    }
    await authenticate();
  }
  return (
    <div className="login">
      <form className="login-panel" onSubmit={submit}>
        <img className="login-logo" src={loginLogo} alt="快排" />
        <label className="input-wrapper">
          <svg viewBox="64 64 896 896" aria-hidden="true"><path d="M858.5 763.6a374 374 0 0 0-80.6-119.5A375.6 375.6 0 0 0 658.4 563c37-45 59.6-103 59.6-167 0-137-111-248-248-248S222 259 222 396c0 64 22.6 122 59.6 167a375.6 375.6 0 0 0-119.5 80.6A371.7 371.7 0 0 0 82 901.8a8 8 0 0 0 8 8.2h60c4.4 0 7.9-3.5 8-7.8 2-77.2 33-149.5 87.8-204.3 56.7-56.7 132-87.9 212.2-87.9s155.5 31.2 212.2 87.9C725 752.7 756 825 758 902.2c.1 4.4 3.6 7.8 8 7.8h60a8 8 0 0 0 8-8.2 371.7 371.7 0 0 0-29.5-138.2zM470 534c-45.9 0-89.1-17.9-121.6-50.4S298 441.9 298 396s17.9-89.1 50.4-121.6S424.1 224 470 224s89.1 17.9 121.6 50.4S642 350.1 642 396c0 45.9-17.9 89.1-50.4 121.6S515.9 534 470 534z" /></svg>
          <input placeholder="账号" autoComplete="username" value={name} onChange={(e) => setName(e.target.value)} />
        </label>
        <label className="input-wrapper">
          <svg viewBox="64 64 896 896" aria-hidden="true"><path d="M832 464h-68V240c0-70.7-57.3-128-128-128H388c-70.7 0-128 57.3-128 128v224h-68c-17.7 0-32 14.3-32 32v384c0 17.7 14.3 32 32 32h640c17.7 0 32-14.3 32-32V496c0-17.7-14.3-32-32-32zM332 240c0-30.9 25.1-56 56-56h248c30.9 0 56 25.1 56 56v224H332V240zm460 600H232V536h560v304zM484 701v53c0 4.4 3.6 8 8 8h40c4.4 0 8-3.6 8-8v-53a48.01 48.01 0 1 0-56 0z" /></svg>
          <input placeholder="密码" type="password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} />
        </label>
        {showCaptcha && <div className="captcha-row"><input placeholder="验证码" value={captcha} onChange={(e) => setCaptcha(e.target.value)} /><b>K8P3</b></div>}
        <Toast message={error} />
        <div className="button-wrapper"><button type="submit" aria-label="登录" disabled={busy}><svg viewBox="64 64 896 896" aria-hidden="true"><path d="M705.6 124.9a8 8 0 0 0-11.6 7.2v64.2c0 5.5 2.9 10.6 7.5 13.6a352.2 352.2 0 0 1 62.2 49.8c32.7 32.8 58.4 70.9 76.3 113.3a355 355 0 0 1 27.9 138.7c0 48.1-9.4 94.8-27.9 138.7a355.9 355.9 0 0 1-76.3 113.3 353.1 353.1 0 0 1-113.2 76.4c-43.8 18.6-90.5 28-138.5 28s-94.7-9.4-138.5-28a353.1 353.1 0 0 1-113.2-76.4A355.9 355.9 0 0 1 184 650.4a355 355 0 0 1-27.9-138.7c0-48.1 9.4-94.8 27.9-138.7 17.9-42.4 43.6-80.5 76.3-113.3 19-19 39.8-35.6 62.2-49.8 4.7-2.9 7.5-8.1 7.5-13.6V132c0-6-6.3-9.8-11.6-7.2C178.5 195.2 82 339.3 80 506.3 77.2 745.1 272.5 943.5 511.2 944c239 .5 432.8-193.3 432.8-432.4 0-169.2-97-315.7-238.4-386.7zM480 560h64c4.4 0 8-3.6 8-8V88c0-4.4-3.6-8-8-8h-64c-4.4 0-8 3.6-8 8v464c0 4.4 3.6 8 8 8z" /></svg></button></div>
      </form>
      {showCaptcha && <CaptchaModal value={captcha} busy={busy} onChange={setCaptcha} onSubmit={() => { if (captcha.toUpperCase() !== "K8P3" && captcha !== "11") { setError("验证码错误"); return; } setShowCaptcha(false); void authenticate(); }} />}
    </div>
  );
}
void LegacyLogin;
type Announcement = { title: string; content: string };
type Balances = { balance: string; total_balance: string; credit_balance: string; used_balance: string; available_balance: string };
const displayAmount = (value: unknown) => {
  const text = String(value ?? "0");
  if (!text.includes(".")) return text;
  return text.replace(/0+$/, "").replace(/\.$/, "") || "0";
};

function lotteryTiming(lottery: Lottery | undefined, now: number) {
  const openTime = lottery?.next_open_time ?? null;
  const cutoffEnabled = lottery?.cutoff_enabled === 1 && Boolean(lottery.cutoff_time);
  if (cutoffEnabled) {
    const date = new Date(now);
    const [hours, minutes] = String(lottery?.cutoff_time).split(":").map(Number);
    const cutoff = new Date(date.getFullYear(), date.getMonth(), date.getDate(), hours, minutes, 0, 0).getTime();
    const midnight = new Date(date.getFullYear(), date.getMonth(), date.getDate()).getTime();
    const nextMidnight = midnight + 24 * 60 * 60 * 1000;
    const locked = now >= cutoff;
    // Before cutoff count down to closing; after cutoff count down to next midnight.
    const countdownTarget = locked ? nextMidnight : cutoff;
    const seconds = Math.max(0, Math.floor((countdownTarget - now) / 1000));
    const hh = String(Math.floor(seconds / 3600)).padStart(2, "0");
    const mm = String(Math.floor((seconds % 3600) / 60)).padStart(2, "0");
    const ss = String(seconds % 60).padStart(2, "0");
    return { status: locked ? "即将开盘" : "开盘中", countdown: `${hh} : ${mm} : ${ss}`, locked, mask: lottery?.mask_enabled !== 0 };
  }
  if (!openTime) return { status: "时间待定", countdown: "-- : -- : --", locked: false, mask: lottery?.mask_enabled !== 0 };
  const target = new Date(openTime.replace(" ", "T")).getTime();
  if (!Number.isFinite(target)) return { status: "时间待定", countdown: "-- : -- : --", locked: false, mask: lottery?.mask_enabled !== 0 };
  const openingDay = new Date(target);
  openingDay.setHours(0, 0, 0, 0);
  const beforeOpeningDay = now < openingDay.getTime();
  const status = now >= target ? "已封盘" : beforeOpeningDay ? "即将开盘" : "开盘中";
  // Before the draw day, show the remaining time until midnight. Betting is locked
  // during this pre-opening window; after midnight the countdown runs to the draw.
  const countdownTarget = beforeOpeningDay ? openingDay.getTime() : target;
  const seconds = Math.max(0, Math.floor((countdownTarget - now) / 1000));
  const hours = String(Math.floor(seconds / 3600)).padStart(2, "0");
  const minutes = String(Math.floor((seconds % 3600) / 60)).padStart(2, "0");
  const remaining = String(seconds % 60).padStart(2, "0");
  return { status, countdown: `${hours} : ${minutes} : ${remaining}`, locked: beforeOpeningDay || now >= target, mask: lottery?.mask_enabled !== 0 };
}

function Header({ name, logout, announcement, balances, lotteries, selectableLottery = false, selectedLotteryId, onSelectLottery }: { name: string; logout: () => void; announcement: Announcement; balances: Balances; lotteries: Lottery[]; selectableLottery?: boolean; selectedLotteryId?: number | null; onSelectLottery?: (id: number) => void }) {
  const { modal } = AntdApp.useApp();
  const [now, setNow] = useState(Date.now());
  const [lineOpen, setLineOpen] = useState(false);
  const [lineLoading, setLineLoading] = useState(false);
  const [lineResults, setLineResults] = useState<Array<{ line: number; delay: number | null; fastest?: boolean }>>([]);
  const [lineCountdown, setLineCountdown] = useState<number | null>(null);
  const lineRedirectTimer = useRef<number | null>(null);
  const lineCountdownTimer = useRef<number | null>(null);
  useEffect(() => {
    const timer = window.setInterval(() => setNow(Date.now()), 1_000);
    return () => window.clearInterval(timer);
  }, []);
  async function checkLines() {
    if (lineRedirectTimer.current !== null) window.clearTimeout(lineRedirectTimer.current);
    if (lineCountdownTimer.current !== null) window.clearInterval(lineCountdownTimer.current);
    setLineOpen(true);
    setLineLoading(true);
    setLineResults([]);
    setLineCountdown(null);
    try {
      const response = await getLineOptions();
      const options = response.data?.data?.list || [];
      const results = await Promise.all(options.map(async (option) => {
        const started = performance.now();
        const controller = new AbortController();
        const timeout = window.setTimeout(() => controller.abort(), 6000);
        try {
          const response = await fetch(`${option.url}/prod_api/v1/health?line=${option.line}&t=${Date.now()}`, { mode: "cors", cache: "no-store", signal: controller.signal });
          if (!response.ok) throw new Error(`HTTP ${response.status}`);
          return { line: option.line, delay: Math.max(1, Math.round(performance.now() - started)) };
        } catch {
          return { line: option.line, delay: null };
        } finally { window.clearTimeout(timeout); }
      }));
      const available = results.filter((item) => item.delay !== null) as Array<{ line: number; delay: number }>;
      const fastest = available.sort((a, b) => a.delay - b.delay)[0]?.line;
      setLineResults(results.map((item) => ({ ...item, fastest: item.line === fastest })));
      const target = options.find((option) => option.line === fastest)?.url;
      if (target) {
        setLineCountdown(3);
        lineCountdownTimer.current = window.setInterval(() => setLineCountdown((value) => value !== null && value > 1 ? value - 1 : 1), 1_000);
        lineRedirectTimer.current = window.setTimeout(() => {
          if (lineCountdownTimer.current !== null) window.clearInterval(lineCountdownTimer.current);
          const destination = new URL(`${window.location.pathname}${window.location.search}${window.location.hash}`, `${target}/`);
          const token = localStorage.getItem("user_token");
          if (token) destination.searchParams.set("auto_token", token);
          destination.searchParams.set("line_switch", "1");
          window.location.assign(destination.toString());
        }, 3_000);
      }
    } catch (error) {
      modal.error({ title: "线路检测失败", content: apiErrorMessage(error, "暂时无法获取线路") });
    } finally { setLineLoading(false); }
  }
  function closeLineModal() {
    if (lineRedirectTimer.current !== null) window.clearTimeout(lineRedirectTimer.current);
    if (lineCountdownTimer.current !== null) window.clearInterval(lineCountdownTimer.current);
    lineRedirectTimer.current=null;
    lineCountdownTimer.current=null;
    setLineCountdown(null);
    setLineOpen(false);
  }
  const delayClass = (delay: number | null) => {
    if (delay === null) return "line-delay-failed";
    if (delay <= 100) return "line-delay-fast";
    if (delay <= 300) return "line-delay-medium";
    return "line-delay-slow";
  };
  return (
    <>
      <button className="notice" type="button" onClick={() => modal.info({
        title: announcement.title,
        content: <div className="announcement-modal-content">{announcement.content}</div>,
        okText: "关闭",
        width: 500,
      })}>
        <span className="notice-track">{announcement.content || "暂无公告"}</span>
        <span className="notice-track" aria-hidden="true">{announcement.content || "暂无公告"}</span>
      </button>
      <header className="site-header">
        <img className="fish-logo" src={loginLogo} alt="快排" />
        <div className="account">
          <label className="account-field">
            <span>账号</span>
            <input value={name} readOnly />
          </label>
          <label className="account-field account-credit">
            <span>信用</span>
            <input value={balances.credit_balance} readOnly />
          </label>
          <label className="account-field account-used">
            <span>已用</span>
            <input value={balances.used_balance} readOnly />
          </label>
          <label className="account-field account-available">
            <span>可用</span>
            <input value={balances.available_balance} readOnly />
          </label>
        </div>
        <ul className={`lottery ${lotteries.length === 1 ? "lottery-single" : ""}`}>{lotteries.map((item) => {
          const timing = lotteryTiming(item, now);
          return <li key={item.id} className={selectableLottery && selectedLotteryId === item.id ? "selected" : ""} role={selectableLottery ? "button" : undefined} tabIndex={selectableLottery ? 0 : undefined} onClick={() => selectableLottery && onSelectLottery?.(item.id)} onKeyDown={(event) => { if (selectableLottery && (event.key === "Enter" || event.key === " ")) onSelectLottery?.(item.id); }}><div className="lottery-row"><div className="lottery-name"><span>{item.name}</span><b>{timing.status}</b></div><div className="lottery-meta"><label>{item.next_code || item.latest_code || "--"}</label><strong>{timing.countdown}</strong></div></div></li>;
        })}</ul>
        <nav className="site-navigation">
          {nav.map(({ path, title, icon }) => (
            <NavLink
              key={path}
              to={"/" + path}
              className={({ isActive }) => (isActive ? "selected" : "")}
            >
              <span className="nav-icon-shell"><img className="nav-icon" src={icon} alt="" aria-hidden="true" /></span>
              {title}
            </NavLink>
          ))}
          <button className="line" onClick={() => void checkLines()}>
            <span className="nav-icon-shell"><img className="nav-icon" src={swapIcon} alt="" aria-hidden="true" /></span>
            <em>更换线路</em>
          </button>
          <button className="exit" onClick={logout}>
            <span className="nav-icon-shell"><img className="nav-icon" src={logoutIcon} alt="" aria-hidden="true" /></span>
            <em>退出</em>
          </button>
        </nav>
      </header>
      <Modal title="切换线路" open={lineOpen} onCancel={closeLineModal} footer={null} width={460} destroyOnHidden>
        <div className="line-tip">测速完成后将 <b>自动跳转</b> 至 <b>速度最快</b> 的线路</div>
        <div className="line-tip">数字越 <b>小</b>，速度越 <b>快</b></div>
        {lineLoading && <div className="line-modal-loading">正在检测线路…</div>}
        {!lineLoading && lineResults.length === 0 && <div className="line-modal-empty">当前站点暂无可用线路</div>}
        {lineResults.length > 0 && <div className="line-table"><div className="line-table-row line-table-head"><span>线路</span><span>延时</span></div>{lineResults.map((item) => <div className="line-table-row" key={item.line}><span className="line-name">线路{item.line}</span><strong className={delayClass(item.delay)}>{item.delay === null ? "检测失败" : <>{item.delay}ms{item.fastest && <em className="line-fastest">最快</em>}</>}</strong></div>)}</div>}
        {lineCountdown !== null && <div className="line-countdown"><b>{lineCountdown}</b> 秒后自动跳转至最快线路</div>}
      </Modal>
    </>
  );
}
function QuickEntry({ lotteries, selectedLottery }: { lotteries: Lottery[]; selectedLottery?: Lottery }) {
  const { message, modal } = AntdApp.useApp();
  const [text, setText] = useState("");
  const [replaceFrom, setReplaceFrom] = useState("");
  const [replaceTo, setReplaceTo] = useState("");
  const [tagOpen, setTagOpen] = useState(false);
  const [tagName, setTagName] = useState("");
  const [tags, setTags] = useState<QuickTag[]>([]);
  const [tab, setTab] = useState("快速录入");
  const [rulesOpen, setRulesOpen] = useState(false);
  const [ruleSettings, setRuleSettings] = useState<RuleSettings>();
  const [lottery, setLottery] = useState("福彩3D");
  const [now, setNow] = useState(Date.now());
  useEffect(() => { const timer = window.setInterval(() => setNow(Date.now()), 1_000); return () => window.clearInterval(timer); }, []);
  const timing = lotteryTiming(selectedLottery, now);
  const locked = tab === "快速录入" && timing.locked;
  const showMask = locked && timing.mask;
  const [generatedLines, setGeneratedLines] = useState<QuickEntryLine[]>([]);
  const [generatedTotal, setGeneratedTotal] = useState({ count: 0, amount: "0.00" });
  const [generating, setGenerating] = useState(false);
  const [warningAmount, setWarningAmount] = useState("0");
  const suppressResultRecognition = useRef(false);
  useEffect(() => { getRules().then((response) => { if (response.data?.data) setRuleSettings(response.data.data); }).catch(() => setRuleSettings(undefined)); }, []);
  useEffect(() => { getQuickSettings().then((response) => { const data = response.data?.data; if (!data) return; setTags(data.tags || []); const p = data.preferences || {}; setLottery(String(p.lottery || lotteries[0]?.name || "福彩3D")); setWarningAmount(String(p.warningAmount || "0")); setOptions([p.autoBet !== false, p.recognize === true, p.copyTicket === true, p.copyHeader === true, p.textMode === true]); }).catch(() => undefined); }, [lotteries]);
  const [options, setOptions] = useState([true, false, false, false, false]);
  const optionTips = [
    "",
    "当文本被编辑后是否立即识别",
    "下注成功后是否自动复制小票信息",
    "复制小票信息时，是否带上时间和总金额",
    "切换复制文本或者号码",
  ];
  const optionNames = ["粘贴后自动下注", "立即识别", "自动复制小票", "复制小票头尾", "文本或号码"];
  const persistPreferences = (nextOptions: boolean[], nextLottery = lottery, nextWarning = warningAmount) => { setOptions(nextOptions); void saveQuickSettings({ autoBet: nextOptions[0], recognize: nextOptions[1], copyTicket: nextOptions[2], copyHeader: nextOptions[3], textMode: nextOptions[4], lottery: nextLottery, warningAmount: nextWarning }).catch((error) => message.error(apiErrorMessage(error, "设置保存失败"))); };
  const generateText = async (sourceText: string, showMessage = true): Promise<QuickPreview | null> => { if (!sourceText.trim()) { if (showMessage) message.warning("请输入投注文本"); return null; } setGenerating(true); try { const response = await previewQuickEntry({ text: sourceText, lottery }); const data = response.data?.data || null; setGeneratedLines(data?.lines || []); setGeneratedTotal({ count: data?.count || 0, amount: data?.amount || "0.00" }); const warning = Number(warningAmount); if (warning > 0 && Number(data?.amount || 0) >= warning) message.warning(`总金额已达到预警金额 ¥${displayAmount(warningAmount)}`); return data; } catch (error) { setGeneratedLines([]); setGeneratedTotal({ count: 0, amount: "0.00" }); message.error(apiErrorMessage(error, "生成失败")); return null; } finally { setGenerating(false); } };
  const generate = () => void generateText(text);
  useEffect(() => { if (suppressResultRecognition.current) { suppressResultRecognition.current = false; return; } if (!options[1] || !text.trim()) return; const timer = window.setTimeout(() => { void generateText(text, false); }, 450); return () => window.clearTimeout(timer); }, [text, options[1], lottery]);
  const copyTicket = async (sourceText: string, lines: QuickEntryLine[], includeHeader: boolean) => { const body = lines.filter((line) => line.status === "success").map((line) => `${line.number_text} ${line.category || ""}各${line.amount}`).join("\n"); const ticket = options[4] ? sourceText : body || sourceText; const header = includeHeader ? `快排小票\n${new Date().toLocaleString()}\n` : ""; try { await navigator.clipboard.writeText(`${header}${ticket}`); message.success("小票已复制"); } catch { message.error("复制失败，请检查浏览器剪贴板权限"); } };
  const submitBet = async (sourceText: string, preview: QuickPreview, showSuccess = true) => {
    const valid = preview.lines.filter((line) => line.status === "success");
    if (!valid.length) { if (showSuccess) message.warning("请先生成有效投注内容"); return false; }
    try {
      const response = await placeQuickEntry({ text: sourceText, lottery, confirmed: true });
      if (showSuccess) message.success(response.data?.message || "下注提交成功");
      if (options[2]) await copyTicket(sourceText, valid, options[3]);
      setGeneratedLines([]); setGeneratedTotal({ count: 0, amount: "0.00" });
      window.dispatchEvent(new Event("bet-records-updated"));
      window.dispatchEvent(new CustomEvent("profile-updated", { detail: { amount: response.data?.data?.amount || preview.amount } }));
      return true;
    } catch (error) { message.error(apiErrorMessage(error, "下注失败")); return false; }
  };
  const place = () => { const preview: QuickPreview = { lines: generatedLines, count: generatedTotal.count, amount: generatedTotal.amount }; if (!preview.lines.some((line) => line.status === "success")) { message.warning("请先生成有效投注内容"); return; } modal.confirm({ title: "确认下注", content: `共 ${preview.count} 码，共 ¥ ${preview.amount}，确认提交吗？`, okText: "确认下注", cancelText: "取消", onOk: () => submitBet(text, preview) }); };
  return (
    <div className={`entry${showMask ? " entry-locked" : ""}`}>
      {showMask && <div className="entry-lock-overlay" aria-label="当前不可下注" />}
      <div className="tabs">
        {["快速录入", "投注记录", "停押降水"].map((x) => (
          <button
            className={tab === x ? "active" : ""}
            onClick={() => setTab(x)}
            key={x}
          >
            {x}
          </button>
        ))}
      </div>
      {tab === "快速录入" ? (
        <>
          <div className="replace">
            将：
            <input value={replaceFrom} onChange={(event) => setReplaceFrom(event.target.value)} />
            替换为：
            <input value={replaceTo} onChange={(event) => setReplaceTo(event.target.value)} />
            <button type="button" onClick={() => { if (replaceFrom) setText((value) => value.split(replaceFrom).join(replaceTo)); }}>替换</button>
            <button className="new" type="button" onClick={() => setTagOpen(true)}>＋ 新标签</button>
            {tags.length > 0 && <div className="tag-list">{tags.map((tag) => <span key={tag.id}><button type="button" onClick={() => setText(tag.name)}>{tag.name}</button><button type="button" aria-label={`删除标签 ${tag.name}`} onClick={async () => { try { await deleteQuickTag(tag.id); setTags((current) => current.filter((item) => item.id !== tag.id)); } catch (error) { message.error(apiErrorMessage(error, "标签删除失败")); } }}>×</button></span>)}</div>}
          </div>
          <div className={`text-entry ${lottery === "排列三" ? "lottery-ti" : "lottery-fu"}`}>
            <textarea
              value={text}
              onChange={(e) => setText(e.target.value.slice(0, 10000))}
              onPaste={(event) => {
                const pasted = event.clipboardData.getData("text").slice(0, 10000);
                event.preventDefault();
                if (options[0] && options[1]) suppressResultRecognition.current = true;
                setText(pasted);
                if (options[0]) window.setTimeout(() => { void generateText(pasted).then((preview) => { if (preview) void submitBet(pasted, preview); }); }, 0);
              }}
              placeholder="请复制文本"
              maxLength={10000}
            />
            <div className="text-entry-footer">
              <button
                type="button"
                className="clear-text"
                disabled={!text}
                onClick={() => setText("")}
              >
                <DeleteOutlined /> 清空
              </button>
              <span>{text.length.toLocaleString()}/10,000</span>
            </div>
          </div>
          <div className="options">
            {optionNames.map((name, index) => (
              <label className={`option-card option-card-${index}`} key={name}>
                <Tooltip title={optionTips[index] || undefined} placement="top">
                  <span className={`option-label${index === 0 ? " info" : ""}`}>
                    {index > 0 && <span className="option-hint">?</span>}{name}
                  </span>
                </Tooltip>
                <Switch
                  checked={options[index]}
                  checkedChildren={index === 4 ? "文" : "是"}
                  unCheckedChildren={index === 4 ? "号" : "否"}
                  onChange={(checked) => { const next = options.map((value, item) => item === index ? checked : value); persistPreferences(next); }}
                />
              </label>
            ))}
            <label className="option-card option-card-lottery">
              <span className="option-label">默认彩种</span>
              {lotteries.map((item) => {
                const tone = item.name === "排列三" ? "ti" : "fu";
                return <b key={item.id} className={`lottery-choice lottery-choice-${tone}${lottery === item.name ? " selected" : ""}`} onClick={() => { setLottery(item.name); persistPreferences(options, item.name); }}>{item.name}</b>;
              })}
            </label>
          </div>
          <div className="actions">
            <button type="button" onClick={place}>下 注</button>
            <button type="button" className="gold" onClick={generate} disabled={generating}>{generating ? "生成中" : "生 成"}</button>
            <button type="button" className="rule-action" onClick={() => setRulesOpen(true)}>规则说明</button>
            <span className="action-separator" aria-hidden="true" />
            <span className="action-total">
              <span><i>共</i><b>{generatedTotal.count}</b><i>码</i></span>
              <span><i>共 ¥</i><b>{displayAmount(generatedTotal.amount)}</b></span>
            </span>
            <span className="action-separator" aria-hidden="true" />
            <div className="warning-label"><label htmlFor="warning-amount">大金额预警：</label></div>
            <input
              id="warning-amount"
              className="warning-input"
              value={warningAmount}
              onFocus={(event) => event.currentTarget.select()}
              onChange={(event) => { const value = event.target.value.replace(/[^\d.]/g, "") || "0"; setWarningAmount(value); persistPreferences(options, lottery, value); }}
              inputMode="decimal"
            />
          </div>
          <QuickResultTable
            lines={generatedLines}
            onChange={(lines, reason) => {
              if (reason === "structure") suppressResultRecognition.current = true;
              setText(lines.map((line) => line.raw_text).join("\n"));
              setGeneratedLines(lines);
              const successful = lines.filter((line) => line.status === "success");
              setGeneratedTotal({
                count: successful.reduce((total, line) => total + line.count, 0),
                amount: successful.reduce((total, line) => total + Number(line.amount || 0), 0).toFixed(2),
              });
            }}
          />
          <RuleInstructionsModal open={rulesOpen} onClose={() => setRulesOpen(false)} rules={ruleSettings} />
          <Modal
            open={tagOpen}
            title="添加新标签"
            okText="确认"
            cancelText="关闭"
            onCancel={() => { setTagOpen(false); setTagName(""); }}
            onOk={async () => { const value = tagName.trim(); if (!value) return; try { const response = await createQuickTag(value); const created = response.data?.data; setTags((current) => [...current, created || { id: Date.now(), name: value }]); setTagOpen(false); setTagName(""); } catch (error) { message.error(apiErrorMessage(error, "标签添加失败")); } }}
            width={500}
            className="tag-modal"
          >
            <Input value={tagName} onChange={(event) => setTagName(event.target.value)} maxLength={30} autoFocus />
          </Modal>
        </>
      ) : tab === "投注记录" ? (
        <BettingRecords />
      ) : (
        <StopDrop />
      )}
    </div>
  );
}
function Generic({ title }: { title: string }) {
  return (
    <div className="generic">
      <h2>{title}</h2>
      <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="暂无数据" />
    </div>
  );
}
function RulesPage({ selectedLottery }: { selectedLottery?: Lottery }) {
  const [rules, setRules] = useState<RuleSettings>();
  const [loading, setLoading] = useState(false);
  useEffect(() => { setLoading(true); getRules({ lottery: selectedLottery?.code || selectedLottery?.name || "" }).then((response) => setRules(response.data?.data)).catch(() => setRules(undefined)).finally(() => setLoading(false)); }, [selectedLottery?.id]);
  const content=rules?.content || "";
  const isHtml=/<\/?[a-z][^>]*>/i.test(content);
  const safeHtml=isHtml ? DOMPurify.sanitize(content, { FORBID_TAGS: ["script", "iframe", "style"], FORBID_ATTR: ["onerror", "onclick", "onload"] }) : "";
  return <div className="rules-page"><section className="rules-content">{content ? (isHtml ? <div dangerouslySetInnerHTML={{ __html: safeHtml }} /> : <div>{content}</div>) : <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="暂无规则内容" />}{loading && <div className="page-local-loading" role="status" aria-label="加载中" />}</section></div>;
}
function BettingRecords() {
  const { message } = AntdApp.useApp();
  const today = dayjs().format("YYYY-MM-DD");
  const [records, setRecords] = useState<BetRecord[]>([]); const [status, setStatus] = useState("all"); const [from, setFrom] = useState(today); const [to, setTo] = useState(today); const [source, setSource] = useState(""); const [page, setPage] = useState(1); const [total, setTotal] = useState(0); const [loading, setLoading] = useState(false);
  const loadRecords = (nextPage = page) => { setLoading(true); getBetRecords({ status: status === "all" ? undefined : status, from, to, source, page: nextPage, page_size: 20 }).then((response) => { setRecords(response.data?.data?.list || []); setTotal(Number(response.data?.data?.total || 0)); setPage(nextPage); }).catch((error) => { setRecords([]); setTotal(0); message.error(apiErrorMessage(error, "投注记录加载失败")); }).finally(() => setLoading(false)); };
  useEffect(() => { loadRecords(1); }, []);
  const exportRecords = () => { if (!records.length) { message.info("当前没有可导出的记录"); return; } const csv=["期号,笔数/金额,中奖金额,原始文本,封盘情况,投注时间",...records.map((r) => [r.issue_no,`${r.bet_count}/${r.amount}`,r.win_amount,`"${(r.source_text || "").replaceAll('"','""')}"`,r.sealed ? "已封盘" : "-",r.placed_at].join(","))].join("\n"); const url=URL.createObjectURL(new Blob([`\ufeff${csv}`],{type:"text/csv;charset=utf-8"})); const a=document.createElement("a"); a.href=url; a.download=`投注记录-${from}-${to}.csv`; a.click(); URL.revokeObjectURL(url); };
  return <div className="records-panel"><div className="records-filter"><label className="records-field records-prize"><span>中奖</span><select value={status} onChange={(event) => setStatus(event.target.value)}><option value="all">全部</option><option value="won">仅中奖</option><option value="unwon">未中奖</option></select></label><label className="records-field records-source"><span>原始文本搜索</span><textarea rows={1} maxLength={200} value={source} onChange={(event) => setSource(event.target.value)} placeholder="输入文本" /></label><div className="records-time-range"><span>投注时间</span><DatePicker locale={zhCN} allowClear={false} value={dayjs(from)} format="YYYY-MM-DD" onChange={(value) => value && setFrom(value.format("YYYY-MM-DD"))} /><em>至</em><DatePicker locale={zhCN} allowClear={false} value={dayjs(to)} format="YYYY-MM-DD" onChange={(value) => value && setTo(value.format("YYYY-MM-DD"))} /></div><div className="records-buttons"><button type="button" className="records-search" onClick={() => loadRecords(1)} disabled={loading}><SearchOutlined /> 搜索</button><button type="button" className="records-export" onClick={exportRecords}><ExportOutlined /> 导出金额</button></div></div><div className="records-table"><div className="records-head"><span>期号</span><span>笔数/金额</span><span>中奖笔数/金额</span><span>文本</span><span>ⓘ 封盘情况</span><span>投注时间</span><span>退码</span></div>{records.length ? records.map((record) => <div className="records-row" key={record.id}><span>{record.issue_no}</span><span>{record.bet_count}/{record.amount}</span><span>{record.win_amount}</span><span>{record.source_text || "-"}</span><span>{record.sealed ? "已封盘" : "-"}</span><span>{record.placed_at}</span><span>{record.status === "refunded" ? "已退" : "-"}</span></div>) : !loading && <div className="records-empty"><Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="暂无数据" /></div>}{loading && <div className="page-local-loading" role="status" aria-label="加载中" />}</div><div className="records-pagination"><span>共 {total} 条</span><button type="button" disabled={page<=1 || loading} onClick={() => loadRecords(page-1)}>上一页</button><span>第 {page} 页</span><button type="button" disabled={page*20>=total || loading} onClick={() => loadRecords(page+1)}>下一页</button></div></div>;
}

function normalizeDetailLottery(lottery?: string) {
  const value = String(lottery || "").trim();
  if (!value) return "其他";
  if (["福", "福彩", "福彩3D", "FC3D"].includes(value)) return "福彩3D";
  if (["体", "体彩", "排列三", "PL3"].includes(value)) return "排列三";
  return value;
}

function normalizeDetailPlay(detail: BetDetail) {
  const text = [detail.category, detail.play_type, detail.source_text, detail.number_text].filter(Boolean).join(" ").replace(/\s+/g, "");
  if (/组六/.test(text)) return /多码|五码|四码|六码|七码|八码|九码|全包/.test(text) ? "组六多码" : "组六";
  if (/组三/.test(text)) return /多码|五码|四码|六码|七码|八码|九码|全包/.test(text) ? "组三多码" : "组三";
  if (/对子/.test(text)) return "对子";
  if (/双飞/.test(text)) return "双飞";
  if (/胆拖|拖/.test(text)) return "胆拖";
  if (/直选|单式|直/.test(text)) return "直选";
  if (/定位胆|一定位/.test(text)) return "定位胆";
  if (/二定位|2D/.test(text)) return "二定位";
  if (/和值/.test(text)) return "和值";
  if (/跨度/.test(text)) return "跨度";
  if (/豹子/.test(text)) return "豹子";
  if (/通选/.test(text)) return "通选";
  return String(detail.category || detail.play_type || "投注").replace(/^体$|^福$|^福彩3D$|^排列三$/, "投注");
}

function SideBetRecords({ onMore, panelRight, onToggleSide }: { onMore: () => void; panelRight: boolean; onToggleSide: () => void }) {
  const { message, modal } = AntdApp.useApp();
  const [records, setRecords] = useState<BetRecord[]>([]); const [amountTotal, setAmountTotal] = useState("0.00"); const [loading, setLoading] = useState(false); const [details, setDetails] = useState<BetDetail[]>([]); const [detailRecord, setDetailRecord] = useState<BetRecord>(); const [detailMode, setDetailMode] = useState<"detail" | "numbers">("detail"); const [detailLoading, setDetailLoading] = useState(false);
  const detailGroups = useMemo(() => {
    const groups = new Map<string, BetDetail[]>();
    for (const detail of details) {
      const lottery = normalizeDetailLottery(detail.lottery);
      const list = groups.get(lottery) || [];
      list.push(detail);
      groups.set(lottery, list);
    }
    return Array.from(groups.entries()).map(([lottery, rows]) => ({
      lottery,
      rows,
      playNames: Array.from(new Set(rows.map((row) => normalizeDetailPlay(row)))).filter(Boolean),
    }));
  }, [details]);
  const load = () => { const today=dayjs().format("YYYY-MM-DD"); setLoading(true); getBetRecords({ from: today, to: today, page: 1, page_size: 100 }).then((response) => { setRecords(response.data?.data?.list || []); setAmountTotal(response.data?.data?.amount_total || "0.00"); }).catch((error) => { setRecords([]); setAmountTotal("0.00"); message.error(apiErrorMessage(error,"下单记录加载失败")); }).finally(() => setLoading(false)); };
  useEffect(() => { load(); const refresh=() => load(); window.addEventListener("bet-records-updated",refresh); return () => window.removeEventListener("bet-records-updated",refresh); }, []);
  const showRecord = async (record: BetRecord, mode: "detail" | "numbers") => { setDetailRecord(record); setDetailMode(mode); setDetailLoading(true); try { const response=await getBetDetails({ bet_record_id: record.id, page: 1, page_size: 100 }); setDetails(response.data?.data?.list || []); } catch (error) { setDetails([]); message.error(apiErrorMessage(error,"注单详情加载失败")); } finally { setDetailLoading(false); } };
  const refund = (record: BetRecord) => modal.confirm({ title:"确认退单", content:`确定退回第 ${record.issue_no} 期注单，金额 ¥ ${record.amount} 吗？`, okText:"确认退单", cancelText:"取消", okButtonProps:{danger:true}, onOk:async()=>{ try { const response=await refundBetRecord(record.id); message.success(response.data?.message || "退单成功"); setDetailRecord(undefined); await load(); window.dispatchEvent(new Event("profile-updated")); } catch(error) { message.error(apiErrorMessage(error,"退单失败")); } } });
  return <><div className="side-total"><span>总金额: <b>{amountTotal}</b></span><div className="side-actions"><button type="button" onClick={onMore}>更多</button><button className="side-right" type="button" onClick={onToggleSide}><img className={panelRight ? "is-left" : ""} src={arrowRightIcon} alt="" aria-hidden="true" />{panelRight ? "居左" : "居右"}</button></div></div><div className="side-record-list">{records.map((record)=><article className={`side-record-item${record.status==="refunded"?" refunded":""}`} key={record.id}><time>{record.placed_at}</time><div className="side-record-text"><b>{record.lottery || "-"}</b><p>{record.source_text || "-"}</p></div><footer><strong>{record.amount}</strong><div><button type="button" className="side-record-action detail" onClick={()=>showRecord(record,"detail")}>详</button><button type="button" className="side-record-action numbers" onClick={()=>showRecord(record,"numbers")}>号</button><button type="button" className="side-record-action refund" disabled={!record.can_refund || loading} title={record.can_refund?`开奖时间 ${record.open_time}`:"仅开奖前可以退单"} onClick={()=>refund(record)}>{record.status==="refunded"?"已退":"退"}</button></div></footer></article>)}</div><Modal className="record-detail-modal side-record-detail-modal" open={Boolean(detailRecord)} title={detailRecord?`${detailMode==="detail"?"注单详情":"号码"}`:""} footer={null} onCancel={()=>setDetailRecord(undefined)} width={1000}>{detailLoading?<div className="record-detail-loading">加载中...</div>:details.length&&detailMode==="detail"?<div className="record-detail-reference"><div className="record-detail-tabs">{detailGroups.map((group) => <span key={group.lottery} className={group.lottery === "福彩3D" ? "fu" : "ti"}>{group.lottery}</span>)}</div>{detailGroups.map((group) => <section className={`record-detail-group ${group.lottery === "福彩3D" ? "fu" : "ti"}`} key={group.lottery}><h3>{group.lottery}</h3><h4>{group.playNames.length ? group.playNames.join("、") : "投注"}</h4><table className="record-detail-table"><colgroup><col /><col /><col /><col /></colgroup><thead><tr><th>号码</th><th>金额</th><th>赔率</th><th>中奖</th></tr></thead><tbody>{group.rows.map((detail) => <tr key={detail.id}><td>{detail.number_text || detail.source_text || "-"}</td><td>{detail.amount}</td><td>{detail.odds || "-"}</td><td>{detail.win_amount || "---"}</td></tr>)}</tbody></table></section>)}</div>:details.length?<div className="record-number-chips">{details.flatMap((detail) => (detail.number_text || "").split(/[\s,，]+/).filter(Boolean).map((number, index) => <i key={`${detail.id}-${number}-${index}`}>{number}</i>))}</div>:<Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="暂无数据" />}</Modal></>;
}
function StopDrop() {
  const { message } = AntdApp.useApp();
  const categories = ["所有", "一码定位", "口XX", "X口X", "XX口", "二码定位", "口口X", "口X口", "X口口", "直选", "独胆", "双飞", "组选", "组三多码", "组三二码", "组三三码", "组三四码", "组三五码", "组三六码", "组三七码", "组三八码", "组三九码", "组三全包", "组六多码", "组六四码", "组六五码", "组六六码", "组六七码", "组六八码", "组六九码", "组六全包", "复式多码", "复式三码", "复式四码", "复式五码", "复式六码", "复式七码", "复式八码", "复式九码", "组三胆拖", "1码拖2", "1码拖3", "1码拖4", "1码拖5", "1码拖6", "1码拖7", "1码拖8", "1码拖9", "组六胆拖", "1码拖2", "1码拖3", "1码拖4", "1码拖5", "1码拖6", "1码拖7", "1码拖8", "1码拖9", "跨度", "跨度0", "跨度1", "跨度2", "跨度3", "跨度4", "跨度5", "跨度6", "跨度7", "跨度8", "跨度9", "和值", "和值0-27", "和值1-26", "和值2-25", "和值3-24", "和值4-23", "和值5-22", "和值6-21", "和值7-20", "和值8-19", "和值9-18", "和值10-17", "和值11-16", "和值12-15", "和值13-14", "大小单双", "豹子全包", "组三沾边赖", "三赖一码", "三赖二码", "三赖三码", "三赖四码", "三赖五码", "三赖六码", "三赖七码", "组六沾边赖", "六赖一码", "六赖二码", "六赖三码", "六赖四码", "六赖五码", "六赖六码", "六赖七码", "对子全包", "组六2胆拖", "组六2胆拖二码", "组六2胆拖三码", "组六2胆拖四码", "组六2胆拖五码", "组六2胆拖六码", "组六2胆拖七码", "组六2胆拖八码", "单选全胆拖", "单选全胆拖二码", "单选全胆拖三码", "单选全胆拖四码", "单选全胆拖五码", "单选全胆拖六码", "单选全胆拖七码", "单选全胆拖八码"];
  const [number, setNumber] = useState(""); const [type, setType] = useState("all"); const [lottery, setLottery] = useState("all"); const [category, setCategory] = useState("所有"); const [date, setDate] = useState(() => dayjs().format("YYYY-MM-DD")); const [sort, setSort] = useState("desc"); const [rows, setRows] = useState<StopDrop[]>([]); const [loading, setLoading] = useState(false);
  const load = (sortOverride = sort) => { setLoading(true); getStopDrops({ number, type, lottery, category, from: date, to: date, sort: sortOverride, page: 1, page_size: 50 }).then((response) => { setRows(response.data?.data?.list || []); }).catch((error) => { setRows([]); message.error(apiErrorMessage(error, "停押降水加载失败")); }).finally(() => setLoading(false)); };
  useEffect(() => { load(); }, []);
  return (
    <div className="stop-drop-panel">
      <div className="stop-filter">
        <label className="stop-search-field"><span>查号码</span><input value={number} onChange={(e) => setNumber(e.target.value)} placeholder="查号码" /></label>
        <label className="stop-select-field"><span>类型</span><select value={type} onChange={(e) => setType(e.target.value)}><option value="all">所有</option><option value="stop">仅停押</option><option value="drop">仅降水</option></select></label>
        <label className="stop-select-field"><span>彩种</span><select value={lottery} onChange={(e) => setLottery(e.target.value)}><option value="all">所有</option><option value="体">体</option><option value="福">福</option></select></label>
        <label className="stop-category-field"><span>分类</span><select value={category} onChange={(e) => setCategory(e.target.value)}>{categories.map((item) => <option key={item}>{item}</option>)}</select></label>
        <label className="stop-date-field"><span>投注日期</span><DatePicker locale={zhCN} allowClear={false} value={dayjs(date)} format="YYYY-MM-DD" onChange={(value) => value && setDate(value.format("YYYY-MM-DD"))} /></label>
        <button type="button" className="stop-search-button" onClick={() => load()} disabled={loading}><SearchOutlined /> 搜索</button>
      </div>
      <div className="stop-sort"><strong>停押和降水</strong><span>按下注时间排序:</span><label><input type="radio" name="stop-sort" checked={sort === "desc"} onChange={() => { setSort("desc"); load("desc"); }} /> 倒序</label><label><input type="radio" name="stop-sort" checked={sort === "asc"} onChange={() => { setSort("asc"); load("asc"); }} /> 正序</label></div>
      <div className="stop-table"><div className="stop-head"><span>编号</span><span>期号</span><span>下单时间</span><span>号码</span><span>应打/实打/停押</span><span>原水/实水/降水</span><span>查看文本</span></div>{rows.length ? rows.map((row) => <div className="stop-row" key={row.id}><span>{row.id}</span><span>{row.issue_no}</span><span>{row.placed_at}</span><span>{row.number_text}</span><span>{row.original_amount}/{row.actual_amount}/{row.stop_amount}</span><span>{row.original_odds || "-"}/{row.actual_odds || "-"}/{row.drop_odds || "-"}</span><span>{row.source_text || "-"}</span></div>) : !loading && <div className="records-empty"><Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="暂无数据" /></div>}{loading && <div className="page-local-loading" role="status" aria-label="加载中" />}</div>
    </div>
  );
}
function BetDetailsPage({ lotteries, selectedLotteryId }: { lotteries: Lottery[]; selectedLotteryId: number | null }) {
  const { message } = AntdApp.useApp();
  const categories = ["所有", "一码定位", "口XX", "X口X", "XX口", "二码定位", "口口X", "口X口", "X口口", "直选", "独胆", "双飞", "组选", "组三多码", "组三二码", "组三三码", "组三四码", "组三五码", "组三六码", "组三七码", "组三八码", "组三九码", "组三全包", "组六多码", "组六四码", "组六五码", "组六六码", "组六七码", "组六八码", "组六九码", "组六全包", "复式多码", "跨度", "和值", "大小单双", "豹子全包", "对子全包"];
  const [rows, setRows] = useState<BetDetail[]>([]);
  const [previewRow, setPreviewRow] = useState<BetDetail>();
  const [previewMode, setPreviewMode] = useState<"text" | "numbers">("text");
  const [number, setNumber] = useState(""); const [metric, setMetric] = useState("odds"); const [min, setMin] = useState(""); const [max, setMax] = useState(""); const [category, setCategory] = useState("所有"); const [sort, setSort] = useState("desc"); const [winning, setWinning] = useState(false); const [loading, setLoading] = useState(false); const [issue, setIssue] = useState(""); const [issues, setIssues] = useState<Array<{ code: string; draw_day: string | null }>>([]);
  const selectedLottery=lotteries.find((item) => item.id === selectedLotteryId) || lotteries[0];
  const load = (overrides: Record<string, unknown> = {}) => { setLoading(true); getBetDetails({ number, metric, min, max, category, sort, lottery: selectedLottery?.name, issue_no: issue || undefined, winning: winning ? 1 : undefined, page: 1, page_size: 50, ...overrides }).then((response) => setRows(response.data?.data?.list || [])).catch((error) => { setRows([]); message.error(apiErrorMessage(error, "下注明细加载失败")); }).finally(() => setLoading(false)); };
  useEffect(() => {
    if (!selectedLottery) return;
    const recent=(selectedLottery.recent_issues || []).slice(0, 10);
    setIssues(recent);
    const nextIssue=recent[0]?.code || "";
    setIssue(nextIssue);
    load({ lottery: selectedLottery.name, issue_no: nextIssue || undefined });
  }, [selectedLottery?.id]);
  return <div className="bet-detail-page">
    <div className="bet-detail-filter">
      <button type="button" className={winning ? "bet-winning active" : "bet-winning"} onClick={() => { const next=!winning; setWinning(next); load({ winning: next ? 1 : undefined }); }}>查看中奖</button>
      <label className="bet-filter-number"><span>查号码</span><input value={number} onChange={(event) => setNumber(event.target.value)} placeholder="查号码" /></label>
      <label className="bet-filter-range"><span>列出</span><select value={metric} onChange={(event) => setMetric(event.target.value)}><option value="odds">赔率</option><option value="amount">金额</option></select><input value={min} inputMode="decimal" onChange={(event) => setMin(event.target.value.replace(/[^\d.]/g, ""))} /><em>至</em><input value={max} inputMode="decimal" onChange={(event) => setMax(event.target.value.replace(/[^\d.]/g, ""))} /></label>
      <label className="bet-filter-category"><span>分类</span><select value={category} onChange={(event) => setCategory(event.target.value)}>{categories.map((item) => <option key={item}>{item}</option>)}</select></label>
      <button type="button" className="bet-filter-search" onClick={() => load()} disabled={loading}><SearchOutlined /> 搜索</button>
    </div>
    <div className="bet-detail-results">
      <div className="bet-detail-sort"><strong>总货明细(红色为退码)</strong><span>按下注时间排序:</span><label><input type="radio" name="detail-sort" checked={sort === "desc"} onChange={() => { setSort("desc"); load({ sort: "desc" }); }} /> 倒序</label><label><input type="radio" name="detail-sort" checked={sort === "asc"} onChange={() => { setSort("asc"); load({ sort: "asc" }); }} /> 正序</label><select aria-label="期号" value={issue} onChange={(event) => { const next=event.target.value; setIssue(next); load({ issue_no: next }); }}>{issues.length ? issues.map((item) => <option key={item.code} value={item.code}>{item.draw_day ? dayjs(item.draw_day).format("M-D") : "--"}({item.code})</option>) : <option value="">暂无期号</option>}</select></div>
      <div className="bet-detail-table"><div className="bet-detail-head"><span>注单编号</span><span>下单时间</span><span>号码</span><span>金额</span><span>赔率</span><span>中奖</span><span>回水</span><span>离线回水</span><span>盈亏</span><span>状态</span><span>查看文本</span></div>{rows.length ? rows.map((row) => <div className="bet-detail-row" key={row.id}><span>{row.id}</span><span>{row.placed_at}</span><button type="button" className="bet-number-link" onClick={() => { setPreviewRow(row); setPreviewMode("numbers"); }}>{row.number_text || "-"}</button><span>{row.amount}</span><span>{row.odds || "-"}</span><span>{row.win_amount}</span><span>{row.rebate}</span><span>-</span><span>{(Number(row.win_amount)-Number(row.amount)+Number(row.rebate)).toFixed(2)}</span><span>{({pending:"未结算",won:"中奖",unwon:"未中奖",refunded:"已退码",cancelled:"已取消",failed:"失败"} as Record<string,string>)[row.status] || "未知状态"}</span><button type="button" className="bet-text-link" disabled={!row.source_text} onClick={() => { setPreviewRow(row); setPreviewMode("text"); }}>{row.source_text ? "查看" : "-"}</button></div>) : !loading && <div className="bet-detail-empty"><Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="暂无数据" /></div>}{loading && <div className="page-local-loading" role="status" aria-label="加载中" />}</div>
      <Modal className="record-detail-modal" open={Boolean(previewRow)} title={previewRow ? `${previewMode === "text" ? "原始投注文本" : "投注号码"} · 注单 ${previewRow.id}` : ""} footer={null} onCancel={() => setPreviewRow(undefined)} width={760}>
        {previewRow && previewMode === "text" ? <pre className="bet-text-preview">{previewRow.source_text || "暂无原始文本"}</pre> : <div className="bet-number-preview">{(previewRow?.number_text || "").split(/[\s,，]+/).filter(Boolean).map((number, index) => <i key={`${number}-${index}`}>{number}</i>)}</div>}
      </Modal>
    </div>
  </div>;
}
function BillsPage() {
  const [rows, setRows] = useState<Bill[]>([]);
  const today = dayjs();
  const [from, setFrom] = useState(today.startOf("month"));
  const [to, setTo] = useState(today);
  const [period, setPeriod] = useState("month");
  const [lotteries, setLotteries] = useState({ fu: true, ti: true });
  const [total, setTotal] = useState({ bet_count: 0, amount: "0.00", rebate: "0.00", offline_rebate: "0.00", win_amount: "0.00", profit: "0.00" });
  const [loading, setLoading] = useState(false);
  const setRange = (nextFrom: dayjs.Dayjs, nextTo: dayjs.Dayjs, nextPeriod: string) => { setFrom(nextFrom.startOf("day")); setTo(nextTo.startOf("day")); setPeriod(nextPeriod); };
  const applyPeriod = (next: string) => {
    const now = dayjs();
    if (next === "today") return setRange(now, now, next);
    if (next === "yesterday") { const day = now.subtract(1, "day"); return setRange(day, day, next); }
    if (next === "week") return setRange(now.startOf("week"), now, next);
    if (next === "last-week") return setRange(now.subtract(1, "week").startOf("week"), now.subtract(1, "week").endOf("week"), next);
    setRange(now.startOf("month"), now, "month");
  };
  useEffect(() => { setLoading(true); getBills({ from: from.format("YYYY-MM-DD"), to: to.format("YYYY-MM-DD") }).then((response) => { const data = response.data?.data; setRows(data?.list || []); setTotal((data?.total as typeof total) || { bet_count: 0, amount: "0.00", rebate: "0.00", offline_rebate: "0.00", win_amount: "0.00", profit: "0.00" }); }).catch(() => { setRows([]); setTotal({ bet_count: 0, amount: "0.00", rebate: "0.00", offline_rebate: "0.00", win_amount: "0.00", profit: "0.00" }); }).finally(() => setLoading(false)); }, [from, to]);
  return <div className="business-page"><div className="business-toolbar bill-toolbar"><fieldset className="bill-lottery-filter"><legend>彩种</legend><label><input type="checkbox" checked={lotteries.fu} onChange={(event) => setLotteries((value) => ({ ...value, fu: event.target.checked }))} /> 福</label><label><input type="checkbox" checked={lotteries.ti} onChange={(event) => setLotteries((value) => ({ ...value, ti: event.target.checked }))} /> 体</label></fieldset><div className="bill-date-range"><span>日期</span><DatePicker className="bill-date-picker" value={from} format="YYYY-MM-DD" allowClear={false} onChange={(value) => value && setRange(value, to.isBefore(value, "day") ? to : value, "custom")} /><em>至</em><DatePicker className="bill-date-picker" value={to} format="YYYY-MM-DD" allowClear={false} onChange={(value) => value && setRange(from, value, "custom")} /></div></div><div className="bill-subbar"><b>历史账单</b><strong className={period === "month" ? "month-selected" : ""} role="button" tabIndex={0} onClick={() => applyPeriod("month")}>{today.format("YYYY年MM月")}</strong><button type="button" className={period === "today" ? "selected" : ""} onClick={() => applyPeriod("today")}>今天</button><button type="button" className={period === "yesterday" ? "selected" : ""} onClick={() => applyPeriod("yesterday")}>昨天</button><button type="button" className={period === "week" ? "selected" : ""} onClick={() => applyPeriod("week")}>本周</button><button type="button" className={period === "last-week" ? "selected" : ""} onClick={() => applyPeriod("last-week")}>上周</button></div><div className="business-table bill-table"><div className="business-head"><span>日期</span><span>笔数</span><span>金额</span><span>总回水</span><span>离线回水</span><span>中奖</span><span>盈亏</span></div>{rows.length ? rows.map((row) => <div className="business-row" key={row.bill_date}><span>{row.bill_date}</span><span>{row.bet_count}</span><span>{row.amount}</span><span>{row.rebate}</span><span>{row.offline_rebate}</span><span>{row.win_amount}</span><span>{row.profit}</span></div>) : <div className="business-empty"><Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="暂无数据" /></div>}<div className="bill-total"><span>合计</span><span>{total.bet_count}</span><span>{total.amount}</span><span>{total.rebate}</span><span>{total.offline_rebate}</span><span>{total.win_amount}</span><span>{total.profit}</span></div>{loading && <div className="page-local-loading" role="status" aria-label="加载中" />}</div></div>;
}
function DrawsPage({ selectedLottery }: { selectedLottery?: Lottery }) {
  const [rows, setRows] = useState<Draw[]>([]);
  const [loading, setLoading] = useState(false);
  const drawRequestId = useRef(0);
  const drawSignature = useRef("");
  useEffect(() => {
    if (!selectedLottery) return;
    const requestId=++drawRequestId.current;
    drawSignature.current="";
    setRows([]);
    setLoading(true);
    window.setTimeout(() => {
      getDraws({ lottery: selectedLottery.name, _t: Date.now() }).then((response) => { if (requestId===drawRequestId.current) setRows(response.data?.data?.list || []); }).catch(() => { if (requestId===drawRequestId.current) setRows([]); }).finally(() => { if (requestId===drawRequestId.current) setLoading(false); });
    }, 0);
    let active=true;
    const watch=async () => {
      try {
        const response=await waitDraws({ lottery: selectedLottery.name, since: drawSignature.current });
        if (!active || requestId!==drawRequestId.current) return;
        const data=response.data?.data;
        if (data?.changed) {
          drawSignature.current=data.signature || "";
          const refreshed=await getDraws({ lottery: selectedLottery.name, _t: Date.now() });
          if (active && requestId===drawRequestId.current) setRows(refreshed.data?.data?.list || []);
        } else if (data?.signature) drawSignature.current=data.signature;
      } catch { /* reconnect on the next iteration */ }
      if (active && requestId===drawRequestId.current) void watch();
    };
    void watch();
    return () => { active=false; };
  }, [selectedLottery?.id]);
  return <div className="draw-page">{loading ? <div className="draw-loading-only" role="status" aria-label="加载中" /> : <div className="draw-table"><div className="draw-head"><span>期号</span><span>开奖时间</span><span>佰</span><span>拾</span><span>个</span><span>和值</span><span>跨度</span></div><div className="draw-body">{rows.length ? rows.map((row) => { const numbers=row.numbers.split(/[,，\s]+/).filter(Boolean); const pending=numbers.length<3; return <div className="draw-row" key={`${row.lottery}-${row.issue_no}`}><strong>{row.issue_no}</strong><time>{row.draw_time || row.draw_date || "---"}</time>{[0,1,2].map((index)=><span className={`draw-ball${pending ? " pending" : ""}`} key={index}>{numbers[index] || ""}</span>)}<span className="draw-sum">{pending || row.sum_value == null ? "---" : `${row.sum_value} / ${row.size} / ${row.parity}`}</span><b className={`draw-span${pending ? " pending" : ""}`}>{pending || row.span_value == null ? "---" : row.span_value}</b></div>; }) : <div className="draw-empty"><Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="暂无数据" /></div>}</div></div>}</div>;
}
function MemberPage({ name, selectedLottery }: { name: string; selectedLottery?: Lottery }) {
  const [profile, setProfile] = useState<UserProfile>();
  const [loading, setLoading] = useState(false);
  useEffect(() => {
    if (!selectedLottery) { setProfile(undefined); setLoading(false); return; }
    setProfile(undefined);
    setLoading(true);
    getProfile({ lottery: selectedLottery.code || selectedLottery.name })
      .then((response) => setProfile(response.data?.data))
      .catch(() => setProfile(undefined))
      .finally(() => setLoading(false));
  }, [selectedLottery?.id]);
  const rows = profile?.odds || [];
  const displayNumber = (value: string | number | undefined) => { const number = Number(value); return Number.isFinite(number) ? number.toFixed(4).replace(/0+$/, "").replace(/\.$/, "") : String(value ?? "-"); };
  const directNames = new Set(["三码定位", "双飞", "对子", "组六", "组三"]);
  const selectedLotteryCode = selectedLottery?.code || rows[0]?.lottery_code;
  const grouped = rows.filter((row) => !selectedLotteryCode || row.lottery_code === selectedLotteryCode).reduce<Array<{ category: string; rows: typeof rows; direct: boolean }>>((groups, row) => { const direct = Boolean(row.direct_category) || directNames.has(row.name); const category = String(row.category || row.name || "其他"); if (direct) { groups.push({ category, rows: [row], direct: true }); return groups; } const group = groups.find((item) => !item.direct && item.category === category); if (group) group.rows.push(row); else groups.push({ category, rows: [row], direct: false }); return groups; }, []);
  const displayName = (name: string) => ({ "百位定位": "口XX", "十位定位": "X口X", "个位定位": "XX口", "百十定位": "口口X", "百个定位": "口X口", "十个定位": "X口口" } as Record<string, string>)[name] || name;
  const rowClass = (row: typeof rows[number]) => row.name === "双飞" || row.name === "对子" ? "member-odds-row direct-cyan" : row.name === "组六" || row.name === "组三" ? "member-odds-row direct-yellow" : "member-odds-row";
  return <div className="member-page"><div className="member-summary"><div><span>账号</span><b>{name}</b></div><div><span>代号</span><b>---</b></div><div><span>信用额度</span><b>{displayNumber(profile?.credit_balance)}</b></div></div><div className="member-odds-panel"><div className="member-odds-head"><span>类别</span><span>最小下注</span><span>赔率上限</span><span>单注上限</span><span>单项上限</span><span>离线赚水</span><span>赔率</span></div><div className="member-odds-body">{grouped.length ? grouped.map((group, groupIndex) => <div key={`${group.category}-${groupIndex}`}>{!group.direct && <div className="member-odds-category">{group.category}</div>}{group.rows.map((row) => <div className={rowClass(row)} key={row.id}><span>{displayName(row.name)}</span><span>{displayNumber(row.min_bet)}</span><span>{displayNumber(row.odds_limit)}</span><span>{displayNumber(row.single_bet_limit)}</span><span>{displayNumber(row.single_item_limit)}</span><span>{displayNumber(row.offline_rebate)}</span><span>{displayNumber(row.odds)}</span></div>)}</div>) : !loading && <div className="member-odds-empty"><Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="暂无赔率配置" /></div>}{loading && <div className="page-local-loading" role="status" aria-label="加载中" />}</div></div></div>;
}
function ChangePasswordPage({ forced = false, onSuccess }: { forced?: boolean; onSuccess?: () => void }) {
  const [oldPassword, setOldPassword] = useState(""); const [password, setPassword] = useState(""); const [confirm, setConfirm] = useState(""); const [message, setMessage] = useState("");
  const submit = () => { setMessage(""); changePassword({ old_password: forced ? "" : oldPassword, password, confirm_password: confirm }).then(() => { localStorage.setItem("user_must_change_password", "0"); setMessage("密码修改成功"); setOldPassword(""); setPassword(""); setConfirm(""); onSuccess?.(); }).catch((error) => setMessage(error instanceof Error ? error.message : "密码修改失败")); };
  return <div className={`password-page${forced ? " forced" : ""}`}>{forced && <div className="password-required-title"><h2>首次登录，请修改密码</h2><p>完成密码修改后才能进入系统</p></div>}<div className="password-fields">{!forced && <label><span>原密码</span><input type="password" maxLength={20} value={oldPassword} onChange={(e) => setOldPassword(e.target.value)} placeholder="请输入原密码" /></label>}<label className="password-new"><span>新密码</span><input type="password" maxLength={20} value={password} onChange={(e) => setPassword(e.target.value)} placeholder="请输入密码" /><small>1. 新密码不能跟账号和原密码相同<br />2. 必须是数字和字母组合，至少6位以上</small></label><label><span>确认新密码</span><input type="password" maxLength={20} value={confirm} onChange={(e) => setConfirm(e.target.value)} placeholder="请确认新密码" /></label></div><div className="password-forbidden"><span>系统禁止不可用密码：</span><span>a12345,ab1234,abc123,a1b2c3,aaa111,123qwe</span></div><button type="button" className="password-save" onClick={submit}>保 存</button>{message && <p className="password-message">{message}</p>}</div>;
}
function MorePanel({ onBack, lotteries }: { onBack: () => void; lotteries: Lottery[] }) {
  const { message } = AntdApp.useApp();
  const today = dayjs().format("YYYY-MM-DD");
  const dateOptions = Array.from(new Map(lotteries.flatMap((lottery) => (lottery.recent_issues || []).map((issue) => [issue.draw_day || issue.code, { day: issue.draw_day || today, code: issue.code }]))).values()).slice(0, 30);
  const [selectedDay, setSelectedDay] = useState(dateOptions[0]?.day || today);
  const [source, setSource] = useState("");
  const [records, setRecords] = useState<BetRecord[]>([]);
  const [amountTotal, setAmountTotal] = useState("0.00");
  const [loading, setLoading] = useState(false);
  useEffect(() => { if (dateOptions.length && !dateOptions.some((item) => item.day === selectedDay)) setSelectedDay(dateOptions[0].day); }, [lotteries]);
  const search = () => {
    setLoading(true);
    getBetRecords({ from: selectedDay, to: selectedDay, source: source.trim() || undefined, page: 1, page_size: 100 })
      .then((response) => { const data = response.data?.data; setRecords(data?.list || []); setAmountTotal(data?.amount_total || "0.00"); })
      .catch((error) => { setRecords([]); setAmountTotal("0.00"); message.error(apiErrorMessage(error, "投注记录加载失败")); })
      .finally(() => setLoading(false));
  };
  useEffect(() => { if (dateOptions.length) search(); }, [selectedDay]);
  return (
    <section className="more-panel">
      <div className="more-search">
        <label className="more-field more-date-field">
          <span>日期</span>
          <select value={selectedDay} onChange={(event) => setSelectedDay(event.target.value)}>
            {dateOptions.length ? dateOptions.map((item) => <option key={`${item.day}-${item.code}`} value={item.day}>{dayjs(item.day).format("M-D")} ({lotteries.map((lottery) => `${lottery.name === "排列三" ? "体" : "福"}-${item.code}`).join(" ")})</option>) : <option value={today}>{dayjs(today).format("M-D")}</option>}
          </select>
        </label>
        <label className="more-field more-text-field">
          <span>原始文本搜索：</span>
          <input value={source} onChange={(event) => setSource(event.target.value)} placeholder="输入文本" onKeyDown={(event) => { if (event.key === "Enter") search(); }} />
        </label>
        <button className="more-search-button" type="button" onClick={search} disabled={loading}>⌕ 搜索</button>
        <button className="more-back-button" type="button" onClick={onBack}>返回</button>
      </div>
      <div className="more-total">总金额: <b>{amountTotal}</b></div>
      <div className="more-results">
        {records.length > 0 && <div className="more-table"><div className="more-table-head"><span>期号</span><span>笔数/金额</span><span>中奖金额</span><span>原始文本</span><span>投注时间</span><span>状态</span></div>{records.map((record) => <div className="more-table-row" key={record.id}><span>{record.issue_no}</span><span>{record.bet_count}/{record.amount}</span><span>{record.win_amount}</span><span>{record.source_text || "-"}</span><span>{record.placed_at}</span><span>{record.status === "refunded" ? "已退" : record.status === "won" ? "中奖" : record.status === "unwon" ? "未中奖" : "未结算"}</span></div>)}</div>}
        {loading && <div className="page-local-loading" role="status" aria-label="加载中" />}
      </div>
    </section>
  );
}
function Main({ name, logout, forcePasswordChange = false, onPasswordChanged }: { name: string; logout: () => void; forcePasswordChange?: boolean; onPasswordChanged?: () => void }) {
  const location = useLocation();
  const [panelRight, setPanelRight] = useState(false);
  const [moreOpen, setMoreOpen] = useState(false);
  const [warmOpen, setWarmOpen] = useState(true);
  const [warmVisible, setWarmVisible] = useState(true);
  const [balances, setBalances] = useState<Balances>({ balance: "0", total_balance: "0", credit_balance: "0", used_balance: "0", available_balance: "0" });
  const [announcement, setAnnouncement] = useState<Announcement>({ title: "公告", content: "暂无公告" });
  const [lotteries, setLotteries] = useState<Lottery[]>([]);
  const [selectedLotteryId, setSelectedLotteryId] = useState<number | null>(null);
  useEffect(() => {
    const send = () => { void heartbeat().catch(() => undefined); };
    send();
    const timer = window.setInterval(send, 20_000);
    return () => window.clearInterval(timer);
  }, []);
  useEffect(() => {
    let active = true;
    const token = localStorage.getItem("user_token");
    if (!token) return () => { active = false; };
    getAnnouncement().then((response) => {
      if (active && response.data?.data) setAnnouncement({ title: String(response.data.data.title || "公告"), content: String(response.data.data.content || "暂无公告") });
    }).catch(() => { if (active) setAnnouncement({ title: "公告", content: "暂无公告" }); });
    return () => { active = false; };
  }, []);
  useEffect(() => { getLotteries().then((response) => { const list=response.data?.data?.list || []; setLotteries(list); setSelectedLotteryId((current) => current && list.some((item) => item.id === current) ? current : list[0]?.id || null); }).catch(() => { setLotteries([]); setSelectedLotteryId(null); }); }, []);
  useEffect(() => {
    const token = localStorage.getItem("user_token");
    if (!token) return;
    const refreshProfile = (event?: Event) => {
      const amount = Number((event as CustomEvent<{ amount?: string }> | undefined)?.detail?.amount || 0);
      if (amount > 0) {
        setBalances((current) => ({
          ...current,
          used_balance: displayAmount(Number(current.used_balance) + amount),
          available_balance: displayAmount(Math.max(0, Number(current.available_balance) - amount)),
        }));
      }
      return getProfile().then((response) => {
      if (response.data?.data) {
        const data = response.data.data;
        setBalances((current) => {
          const normalized = {} as Balances;
          (Object.keys(current) as Array<keyof Balances>).forEach((key) => { normalized[key] = displayAmount(data[key] ?? current[key]); });
          return { ...current, ...normalized };
        });
      }
      }).catch(() => undefined);
    };
    refreshProfile();
    window.addEventListener("profile-updated", refreshProfile);
    return () => window.removeEventListener("profile-updated", refreshProfile);
  }, []);
  const title = useMemo(
    () => nav.find((item) => "/" + item.path === location.pathname)?.title || "快速录入",
    [location.pathname],
  );
  const fullPage = location.pathname !== "/" && location.pathname !== "/kb";
  const lotterySwitchPages = new Set(["/zh", "/hyxx", "/jg", "/gz"]);
  const selectedLottery = lotteries.find((item) => item.id === selectedLotteryId);
  return (
    <div className="app">
      <Header name={name} logout={logout} announcement={announcement} balances={balances} lotteries={lotteries} selectableLottery={lotterySwitchPages.has(location.pathname)} selectedLotteryId={selectedLotteryId} onSelectLottery={setSelectedLotteryId} />
      {forcePasswordChange ? <div className="body full-page"><main><ChangePasswordPage forced onSuccess={onPasswordChanged} /></main></div> : moreOpen ? <MorePanel lotteries={lotteries} onBack={() => setMoreOpen(false)} /> : <div className={`body${panelRight ? " panel-right" : ""}${fullPage ? " full-page" : ""}`}>
        {!fullPage && <aside><SideBetRecords onMore={() => setMoreOpen(true)} panelRight={panelRight} onToggleSide={() => setPanelRight((value) => !value)} /></aside>}
        <main>
            <Routes>
              <Route path="/" element={<QuickEntry lotteries={lotteries} selectedLottery={lotteries.find((item) => item.id === selectedLotteryId)} />} />
              <Route path="/kb" element={<QuickEntry lotteries={lotteries} selectedLottery={lotteries.find((item) => item.id === selectedLotteryId)} />} />
              <Route path="/zh" element={<BetDetailsPage lotteries={lotteries} selectedLotteryId={selectedLotteryId} />} />
              <Route path="/zd" element={<BillsPage />} />
              <Route path="/hyxx" element={<MemberPage name={name} selectedLottery={selectedLottery} />} />
              <Route path="/jg" element={<DrawsPage selectedLottery={selectedLottery} />} />
              <Route path="/gz" element={<RulesPage selectedLottery={selectedLottery} />} />
              <Route path="/xgmm" element={<ChangePasswordPage />} />
            <Route path="*" element={<Generic title={title} />} />
          </Routes>
        </main>
      </div>}
      {!forcePasswordChange && warmVisible && (
        <section className={`warm${warmOpen ? " is-open" : ""}`} aria-label="温馨提示">
          <header className="warm-header">
            <strong>温馨提示</strong>
            <div className="warm-actions">
              <button type="button" aria-label={warmOpen ? "收起温馨提示" : "展开温馨提示"} onClick={() => setWarmOpen((value) => !value)}>
                <motion.span animate={{ rotate: warmOpen ? 0 : 180 }} transition={{ type: "spring", stiffness: 360, damping: 28 }}>
                  <VerticalAlignBottomOutlined />
                </motion.span>
              </button>
              <button type="button" aria-label="关闭温馨提示" onClick={() => setWarmVisible(false)}>
                <CloseOutlined />
              </button>
            </div>
          </header>
          <motion.div
            className="warm-content"
            initial={false}
            animate={{
              height: warmOpen ? 190 : 0,
              paddingTop: warmOpen ? 11 : 0,
              paddingBottom: warmOpen ? 11 : 0,
            }}
            transition={{ type: "spring", stiffness: 260, damping: 30, mass: 0.8 }}
            aria-hidden={!warmOpen}
          >
            【温馨提示】各位会员在下注确定后请到“下注明细”里确认注单，一切注单结算以下注明细里资料为准！
          </motion.div>
        </section>
      )}
    </div>
  );
}
export default function App() {
  const [name, setName] = useState(() => localStorage.getItem("user_token") ? localStorage.getItem("user_name") || "" : "");
  const [mustChangePassword, setMustChangePassword] = useState(() => localStorage.getItem("user_must_change_password") === "1");
  const [agreementVisible, setAgreementVisible] = useState(() => {
    const token = localStorage.getItem("user_token");
    return Boolean(token && localStorage.getItem("user_name") && sessionStorage.getItem("agreement_accepted_token") !== token);
  });
  const [agreement, setAgreement] = useState<AgreementData>(defaultAgreement);
  useEffect(() => {
    const handleUnauthorized = () => {
      setAgreementVisible(false);
      setMustChangePassword(false); setName("");
    };
    window.addEventListener("user:unauthorized", handleUnauthorized);
    return () => window.removeEventListener("user:unauthorized", handleUnauthorized);
  }, []);
  useEffect(() => {
    if (!name || !agreementVisible) return;
    const token = localStorage.getItem("user_token");
    if (!token) return;
    void getAgreement()
      .then((response) => {
        const data = response.data?.data;
        if (data?.title && data?.content) setAgreement({ title: String(data.title), content: String(data.content) });
      })
      .catch(() => setAgreement(defaultAgreement));
  }, [name, agreementVisible]);
  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const autoToken = params.get("auto_token");
    const lineSwitch = params.get("line_switch") === "1";
    if (!autoToken && !lineSwitch) return;
    const token = autoToken || localStorage.getItem("user_token");
    clearUserAuthQuery();
    if (!token) return;
    if (autoToken) localStorage.setItem("user_token", autoToken);
    const userName = localStorage.getItem("user_name") || "站点管理员";
    localStorage.setItem("user_name", userName);
    if (lineSwitch) sessionStorage.setItem("agreement_accepted_token", token);
    else sessionStorage.removeItem("agreement_accepted_token");
    setName(userName);
    setAgreementVisible(!lineSwitch);
  }, []);
  const clearSession = () => { clearUserAuthQuery(); localStorage.removeItem("user_name"); localStorage.removeItem("user_token"); localStorage.removeItem("user_must_change_password"); sessionStorage.removeItem("agreement_accepted_token"); setAgreementVisible(false); setMustChangePassword(false); setName(""); };
  const logout = () => { void logoutSession().catch(() => undefined).finally(clearSession); };
  return (
    <HashRouter>
      {name ? (
        agreementVisible ? (
          <Agreement
            agreement={agreement}
            onReject={logout}
            onAccept={() => {
              const token = localStorage.getItem("user_token");
              if (token) sessionStorage.setItem("agreement_accepted_token", token);
              setAgreementVisible(false);
            }}
          />
        ) : <Main name={name} logout={logout} forcePasswordChange={mustChangePassword} onPasswordChanged={() => setMustChangePassword(false)} />
      ) : (
        <Routes>
          <Route
            path="*"
            element={
              <Login
                onLogin={(n) => {
                  localStorage.setItem("user_name", n);
                  sessionStorage.removeItem("agreement_accepted_token");
                  setAgreement(defaultAgreement);
                  setMustChangePassword(localStorage.getItem("user_must_change_password") === "1");
                  setName(n);
                  setAgreementVisible(true);
                }}
              />
            }
          />
        </Routes>
      )}
    </HashRouter>
  );
}
