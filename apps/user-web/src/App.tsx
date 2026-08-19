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
import { changePassword, createQuickTag, deleteQuickTag, getAgreement, getAnnouncement, getBetDetails, getBetRecords, getBills, getDraws, getLotteries, getProfile, getQuickSettings, getRules, getStopDrops, placeQuickEntry, previewQuickEntry, refundBetRecord, saveQuickSettings, type BetDetail, type BetRecord, type Bill, type Draw, type Lottery, type QuickEntryLine, type QuickTag, type RuleSettings, type StopDrop } from "./api/user";
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
    const locked = now >= cutoff;
    // The reference site shows the elapsed time from today's midnight while
    // the lottery is waiting to open, rather than counting down to cutoff.
    const midnight = new Date(date.getFullYear(), date.getMonth(), date.getDate()).getTime();
    const seconds = Math.max(0, Math.floor((now - midnight) / 1000));
    const hh = String(Math.floor(seconds / 3600)).padStart(2, "0");
    const mm = String(Math.floor((seconds % 3600) / 60)).padStart(2, "0");
    const ss = String(seconds % 60).padStart(2, "0");
    return { status: locked ? "已封盘" : "即将开盘", countdown: `${hh} : ${mm} : ${ss}`, locked, mask: lottery?.mask_enabled !== 0 };
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
  useEffect(() => {
    const timer = window.setInterval(() => setNow(Date.now()), 1_000);
    return () => window.clearInterval(timer);
  }, []);
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
          <button className="line" onClick={() => alert("正在测速 10 条线路…")}>
            <span className="nav-icon-shell"><img className="nav-icon" src={swapIcon} alt="" aria-hidden="true" /></span>
            <em>更换线路</em>
          </button>
          <button className="exit" onClick={logout}>
            <span className="nav-icon-shell"><img className="nav-icon" src={logoutIcon} alt="" aria-hidden="true" /></span>
            <em>退出</em>
          </button>
        </nav>
      </header>
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
  useEffect(() => { getRules().then((response) => { if (response.data?.data) setRuleSettings(response.data.data); }).catch(() => undefined); }, []);
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
  const generateText = async (sourceText: string, showMessage = true) => { if (!sourceText.trim()) { if (showMessage) message.warning("请输入投注文本"); return false; } setGenerating(true); try { const response = await previewQuickEntry({ text: sourceText, lottery }); const data = response.data?.data; setGeneratedLines(data?.lines || []); setGeneratedTotal({ count: data?.count || 0, amount: data?.amount || "0.00" }); const warning = Number(warningAmount); if (warning > 0 && Number(data?.amount || 0) >= warning) message.warning(`总金额已达到预警金额 ¥${displayAmount(warningAmount)}`); return Boolean(data?.lines?.some((line) => line.status === "success")); } catch (error) { setGeneratedLines([]); setGeneratedTotal({ count: 0, amount: "0.00" }); message.error(apiErrorMessage(error, "生成失败")); return false; } finally { setGenerating(false); } };
  const generate = () => void generateText(text);
  useEffect(() => { if (suppressResultRecognition.current) { suppressResultRecognition.current = false; return; } if (!options[1] || !text.trim()) return; const timer = window.setTimeout(() => { void generateText(text, false); }, 450); return () => window.clearTimeout(timer); }, [text, options[1], lottery]);
  const copyTicket = async (sourceText: string, includeHeader: boolean) => { const body = generatedLines.filter((line) => line.status === "success").map((line) => `${line.number_text} ${line.category || ""}各${line.amount}`).join("\n"); const header = includeHeader ? `快排小票\n${new Date().toLocaleString()}\n` : ""; try { await navigator.clipboard.writeText(`${header}${body || sourceText}`); message.success("小票已复制"); } catch { message.error("复制失败，请检查浏览器剪贴板权限"); } };
  const place = () => { const valid = generatedLines.filter((line) => line.status === "success"); if (!valid.length) { message.warning("请先生成有效投注内容"); return; } modal.confirm({ title: "确认下注", content: `共 ${generatedTotal.count} 码，共 ¥ ${generatedTotal.amount}，确认提交吗？`, okText: "确认下注", cancelText: "取消", onOk: async () => { try { const response = await placeQuickEntry({ text, lottery, confirmed: true }); message.success(response.data?.message || "下注提交成功"); if (options[2]) await copyTicket(text, options[3]); setGeneratedLines([]); setGeneratedTotal({ count: 0, amount: "0.00" }); window.dispatchEvent(new Event("bet-records-updated")); window.dispatchEvent(new CustomEvent("profile-updated", { detail: { amount: response.data?.data?.amount || generatedTotal.amount } })); } catch (error) { message.error(apiErrorMessage(error, "下注失败")); } } }); };
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
                setText(pasted);
                if (options[0]) window.setTimeout(() => { void generateText(pasted); }, 0);
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
                  unCheckedChildren={index === 4 ? "文" : "否"}
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
function BettingRecords() {
  const { message } = AntdApp.useApp();
  const today = dayjs().format("YYYY-MM-DD");
  const [records, setRecords] = useState<BetRecord[]>([]); const [status, setStatus] = useState("all"); const [from, setFrom] = useState(today); const [to, setTo] = useState(today); const [source, setSource] = useState(""); const [page, setPage] = useState(1); const [total, setTotal] = useState(0); const [loading, setLoading] = useState(false);
  const loadRecords = (nextPage = page) => { setLoading(true); getBetRecords({ status: status === "all" ? undefined : status, from, to, source, page: nextPage, page_size: 20 }).then((response) => { setRecords(response.data?.data?.list || []); setTotal(Number(response.data?.data?.total || 0)); setPage(nextPage); }).catch((error) => { setRecords([]); setTotal(0); message.error(apiErrorMessage(error, "投注记录加载失败")); }).finally(() => setLoading(false)); };
  useEffect(() => { loadRecords(1); }, []);
  const exportRecords = () => { if (!records.length) { message.info("当前没有可导出的记录"); return; } const csv=["期号,笔数/金额,中奖金额,原始文本,封盘情况,投注时间",...records.map((r) => [r.issue_no,`${r.bet_count}/${r.amount}`,r.win_amount,`"${(r.source_text || "").replaceAll('"','""')}"`,r.sealed ? "已封盘" : "-",r.placed_at].join(","))].join("\n"); const url=URL.createObjectURL(new Blob([`\ufeff${csv}`],{type:"text/csv;charset=utf-8"})); const a=document.createElement("a"); a.href=url; a.download=`投注记录-${from}-${to}.csv`; a.click(); URL.revokeObjectURL(url); };
  return <div className="records-panel"><div className="records-filter"><label className="records-field records-prize"><span>中奖</span><select value={status} onChange={(event) => setStatus(event.target.value)}><option value="all">全部</option><option value="won">仅中奖</option><option value="unwon">未中奖</option></select></label><label className="records-field records-source"><span>原始文本搜索</span><textarea rows={1} maxLength={200} value={source} onChange={(event) => setSource(event.target.value)} placeholder="输入文本" /></label><div className="records-time-range"><span>投注时间</span><DatePicker locale={zhCN} showTime allowClear={false} value={dayjs(`${from} 00:00:00`)} format="YYYY-MM-DD HH:mm:ss" onChange={(value) => value && setFrom(value.format("YYYY-MM-DD"))} /><em>至</em><DatePicker locale={zhCN} showTime allowClear={false} value={dayjs(`${to} 23:59:59`)} format="YYYY-MM-DD HH:mm:ss" onChange={(value) => value && setTo(value.format("YYYY-MM-DD"))} /></div><div className="records-buttons"><button type="button" className="records-search" onClick={() => loadRecords(1)} disabled={loading}><SearchOutlined /> 搜索</button><button type="button" className="records-export" onClick={exportRecords}><ExportOutlined /> 导出金额</button></div></div><div className="records-table"><div className="records-head"><span>期号</span><span>笔数/金额</span><span>中奖笔数/金额</span><span>文本</span><span>ⓘ 封盘情况</span><span>投注时间</span><span>退码</span></div>{records.length === 0 ? <div className="records-empty">{loading ? <span>加载中...</span> : <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="暂无数据" />}</div> : records.map((record) => <div className="records-row" key={record.id}><span>{record.issue_no}</span><span>{record.bet_count}/{record.amount}</span><span>{record.win_amount}</span><span>{record.source_text || "-"}</span><span>{record.sealed ? "已封盘" : "-"}</span><span>{record.placed_at}</span><span>{record.status === "refunded" ? "已退" : "-"}</span></div>)}</div><div className="records-pagination"><span>共 {total} 条</span><button type="button" disabled={page<=1 || loading} onClick={() => loadRecords(page-1)}>上一页</button><span>第 {page} 页</span><button type="button" disabled={page*20>=total || loading} onClick={() => loadRecords(page+1)}>下一页</button></div></div>;
}

function SideBetRecords({ onMore, panelRight, onToggleSide }: { onMore: () => void; panelRight: boolean; onToggleSide: () => void }) {
  const { message, modal } = AntdApp.useApp();
  const [records, setRecords] = useState<BetRecord[]>([]); const [amountTotal, setAmountTotal] = useState("0.00"); const [loading, setLoading] = useState(false); const [details, setDetails] = useState<BetDetail[]>([]); const [detailRecord, setDetailRecord] = useState<BetRecord>(); const [detailMode, setDetailMode] = useState<"detail" | "numbers">("detail"); const [detailLoading, setDetailLoading] = useState(false);
  const load = () => { const today=dayjs().format("YYYY-MM-DD"); setLoading(true); getBetRecords({ from: today, to: today, page: 1, page_size: 100 }).then((response) => { setRecords(response.data?.data?.list || []); setAmountTotal(response.data?.data?.amount_total || "0.00"); }).catch((error) => { setRecords([]); setAmountTotal("0.00"); message.error(apiErrorMessage(error,"下单记录加载失败")); }).finally(() => setLoading(false)); };
  useEffect(() => { load(); const refresh=() => load(); window.addEventListener("bet-records-updated",refresh); return () => window.removeEventListener("bet-records-updated",refresh); }, []);
  const showRecord = async (record: BetRecord, mode: "detail" | "numbers") => { setDetailRecord(record); setDetailMode(mode); setDetailLoading(true); try { const response=await getBetDetails({ bet_record_id: record.id, page: 1, page_size: 100 }); setDetails(response.data?.data?.list || []); } catch (error) { setDetails([]); message.error(apiErrorMessage(error,"注单详情加载失败")); } finally { setDetailLoading(false); } };
  const refund = (record: BetRecord) => modal.confirm({ title:"确认退单", content:`确定退回第 ${record.issue_no} 期注单，金额 ¥ ${record.amount} 吗？`, okText:"确认退单", cancelText:"取消", okButtonProps:{danger:true}, onOk:async()=>{ try { const response=await refundBetRecord(record.id); message.success(response.data?.message || "退单成功"); setDetailRecord(undefined); await load(); window.dispatchEvent(new Event("profile-updated")); } catch(error) { message.error(apiErrorMessage(error,"退单失败")); } } });
  return <><div className="side-total"><span>总金额: <b>{amountTotal}</b></span><div className="side-actions"><button type="button" onClick={onMore}>更多</button><button className="side-right" type="button" onClick={onToggleSide}><img className={panelRight ? "is-left" : ""} src={arrowRightIcon} alt="" aria-hidden="true" />{panelRight ? "居左" : "居右"}</button></div></div><div className="side-record-list">{records.map((record)=><article className={`side-record-item${record.status==="refunded"?" refunded":""}`} key={record.id}><time>{record.placed_at}</time><div className="side-record-text"><b>{record.lottery || "-"}</b><p>{record.source_text || "-"}</p></div><footer><strong>{record.amount}</strong><div><button type="button" className="side-record-action detail" onClick={()=>showRecord(record,"detail")}>详</button><button type="button" className="side-record-action numbers" onClick={()=>showRecord(record,"numbers")}>号</button><button type="button" className="side-record-action refund" disabled={!record.can_refund || loading} title={record.can_refund?`开奖时间 ${record.open_time}`:"仅开奖前可以退单"} onClick={()=>refund(record)}>{record.status==="refunded"?"已退":"退"}</button></div></footer></article>)}{!records.length&&!loading&&<div className="side-record-empty"><Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="暂无数据" /></div>}</div><Modal className="record-detail-modal" open={Boolean(detailRecord)} title={detailRecord?`${detailMode==="detail"?"注单详情":"号码"} · ${detailRecord.issue_no}`:""} footer={null} onCancel={()=>setDetailRecord(undefined)} width={760}>{detailLoading?<div className="record-detail-loading">加载中...</div>:details.length?<div className="record-detail-list">{details.map((detail)=><div className="record-detail-line" key={detail.id}><span className={detail.lottery==="福彩3D"?"fu":"ti"}>{detail.lottery==="福彩3D"?"福":detail.lottery==="排列三"?"体":detail.lottery||"-"}</span>{detailMode==="detail"?<><b>{detail.category||detail.play_type||"投注"}</b><p>{detail.source_text||detail.number_text}</p><strong>¥ {detail.amount}</strong></>:<div className="record-number-chips">{detail.number_text.split(/[\s,，]+/).filter(Boolean).map((number,index)=><i key={`${number}-${index}`}>{number}</i>)}</div>}</div>)}</div>:<Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="暂无数据" />}</Modal></>;
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
      <div className="stop-table"><div className="stop-head"><span>编号</span><span>期号</span><span>下单时间</span><span>号码</span><span>应打/实打/停押</span><span>原水/实水/降水</span><span>查看文本</span></div>{rows.length ? rows.map((row) => <div className="stop-row" key={row.id}><span>{row.id}</span><span>{row.issue_no}</span><span>{row.placed_at}</span><span>{row.number_text}</span><span>{row.original_amount}/{row.actual_amount}/{row.stop_amount}</span><span>{row.original_odds || "-"}/{row.actual_odds || "-"}/{row.drop_odds || "-"}</span><span>{row.source_text || "-"}</span></div>) : <div className="records-empty">{loading ? <span>加载中...</span> : <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="暂无数据" />}</div>}</div>
    </div>
  );
}
function BetDetailsPage({ lotteries, selectedLotteryId }: { lotteries: Lottery[]; selectedLotteryId: number | null }) {
  const { message } = AntdApp.useApp();
  const categories = ["所有", "一码定位", "口XX", "X口X", "XX口", "二码定位", "口口X", "口X口", "X口口", "直选", "独胆", "双飞", "组选", "组三多码", "组三二码", "组三三码", "组三四码", "组三五码", "组三六码", "组三七码", "组三八码", "组三九码", "组三全包", "组六多码", "组六四码", "组六五码", "组六六码", "组六七码", "组六八码", "组六九码", "组六全包", "复式多码", "跨度", "和值", "大小单双", "豹子全包", "对子全包"];
  const [rows, setRows] = useState<BetDetail[]>([]);
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
      <div className="bet-detail-table"><div className="bet-detail-head"><span>注单编号</span><span>下单时间</span><span>号码</span><span>金额</span><span>赔率</span><span>中奖</span><span>回水</span><span>离线回水</span><span>盈亏</span><span>状态</span><span>查看文本</span></div>{rows.length ? rows.map((row) => <div className="bet-detail-row" key={row.id}><span>{row.id}</span><span>{row.placed_at}</span><span>{row.number_text}</span><span>{row.amount}</span><span>{row.odds || "-"}</span><span>{row.win_amount}</span><span>{row.rebate}</span><span>-</span><span>{(Number(row.win_amount)-Number(row.amount)+Number(row.rebate)).toFixed(2)}</span><span>{({pending:"未结算",won:"中奖",unwon:"未中奖"} as Record<string,string>)[row.status] || row.status}</span><span title={row.source_text || ""}>{row.source_text ? "查看" : "-"}</span></div>) : <div className="bet-detail-empty">{loading ? <span>加载中...</span> : <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="暂无数据" />}</div>}</div>
    </div>
  </div>;
}
function BillsPage() {
  const [rows, setRows] = useState<Bill[]>([]);
  const [period, setPeriod] = useState("today");
  useEffect(() => { getBills({ period }).then((response) => setRows(response.data?.data?.list || [])).catch(() => setRows([])); }, [period]);
  const openDatePicker = (event: React.MouseEvent<HTMLDivElement>) => {
    if ((event.target as HTMLElement).closest(".ant-picker")) return;
    const range = event.currentTarget;
    const pickers = Array.from(range.querySelectorAll(".ant-picker-input input")) as HTMLInputElement[];
    const index = event.clientX > range.getBoundingClientRect().left + range.getBoundingClientRect().width / 2 ? 1 : 0;
    pickers[Math.min(index, pickers.length - 1)]?.click();
  };
  return <div className="business-page"><div className="business-toolbar bill-toolbar"><label className="bill-lottery-filter"><span>彩种</span><label><input type="checkbox" defaultChecked /> 福</label><label><input type="checkbox" defaultChecked /> 体</label></label><div className="bill-date-range" onClick={openDatePicker}><span>日期</span><DatePicker className="bill-date-picker" defaultValue={dayjs("2026-08-19")} format="YYYY/MM/DD" allowClear={false} /><em>至</em><DatePicker className="bill-date-picker" defaultValue={dayjs("2026-08-19")} format="YYYY/MM/DD" allowClear={false} /></div></div><div className="bill-subbar"><b>历史账单</b><strong className={period === "month" ? "month-selected" : ""} role="button" tabIndex={0} onClick={() => setPeriod("month")}>2026年08月</strong><button type="button" className={period === "today" ? "selected" : ""} onClick={() => setPeriod("today")}>今天</button><button type="button" className={period === "yesterday" ? "selected" : ""} onClick={() => setPeriod("yesterday")}>昨天</button><button type="button" className={period === "week" ? "selected" : ""} onClick={() => setPeriod("week")}>本周</button><button type="button" className={period === "last-week" ? "selected" : ""} onClick={() => setPeriod("last-week")}>上周</button></div><div className="business-table bill-table"><div className="business-head"><span>日期</span><span>笔数</span><span>金额</span><span>总回水</span><span>离线回水</span><span>中奖</span><span>盈亏</span></div>{rows.length ? rows.map((row) => <div className="business-row" key={row.bill_date}><span>{row.bill_date}</span><span>{row.bet_count}</span><span>{row.amount}</span><span>{row.rebate}</span><span>{row.offline_rebate}</span><span>{row.win_amount}</span><span>{row.profit}</span></div>) : null}<div className="bill-total"><span>合计</span><span>0</span><span>0</span><span>0</span><span>0</span><span>0</span><span>0</span></div></div></div>;
}
function DrawsPage() {
  const [rows, setRows] = useState<Draw[]>([]);
  useEffect(() => { getDraws().then((response) => setRows(response.data?.data?.list || [])).catch(() => setRows([])); }, []);
  return <div className="business-page"><div className="business-toolbar"><select defaultValue="all"><option value="all">所有彩种</option><option value="福彩3D">福彩3D</option><option value="排列三">排列三</option></select><button type="button" className="business-primary"><SearchOutlined /> 搜索</button></div><div className="business-table draw-table"><div className="business-head"><span>期号</span><span>开奖时间</span><span>百位</span><span>十位</span><span>个位</span><span>和值/大小/单双</span><span>试机号</span></div>{rows.length ? rows.map((row) => { const n=row.numbers.split(/[,\s]+/); return <div className="business-row" key={`${row.lottery}-${row.issue_no}`}><span>{row.issue_no}</span><span>{row.draw_time || row.draw_date}</span><span>{n[0] || "-"}</span><span>{n[1] || "-"}</span><span>{n[2] || "-"}</span><span>{row.sum_value || "-"} / {row.size || "-"} / {row.parity || "-"}</span><span>-</span></div>; }) : <div className="business-empty"><Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="暂无数据" /></div>}</div></div>;
}
function MemberPage({ name }: { name: string }) { return <div className="member-page"><h3>会员资料</h3><div className="member-grid"><span>账号</span><b>{name}</b><span>代号</span><b>---</b><span>信用额度</span><b>0</b></div><p>投注类别、赔率上限、单注上限等资料由管理端配置。</p></div>; }
function ChangePasswordPage() {
  const [oldPassword, setOldPassword] = useState(""); const [password, setPassword] = useState(""); const [confirm, setConfirm] = useState(""); const [message, setMessage] = useState("");
  const submit = () => { setMessage(""); changePassword({ old_password: oldPassword, password, confirm_password: confirm }).then(() => { setMessage("密码修改成功"); setOldPassword(""); setPassword(""); setConfirm(""); }).catch((error) => setMessage(error instanceof Error ? error.message : "密码修改失败")); };
  return <div className="password-page"><div className="password-fields"><label><span>原密码</span><input type="password" maxLength={20} value={oldPassword} onChange={(e) => setOldPassword(e.target.value)} placeholder="请输入原密码" /></label><label className="password-new"><span>新密码</span><input type="password" maxLength={20} value={password} onChange={(e) => setPassword(e.target.value)} placeholder="请输入密码" /><small>1. 新密码不能跟账号和原密码相同<br />2. 必须是数字和字母组合，至少6位以上</small></label><label><span>确认新密码</span><input type="password" maxLength={20} value={confirm} onChange={(e) => setConfirm(e.target.value)} placeholder="请确认新密码" /></label></div><div className="password-forbidden"><span>系统禁止不可用密码：</span><span>a12345,ab1234,abc123,a1b2c3,aaa111,123qwe</span></div><button type="button" className="password-save" onClick={submit}>保 存</button>{message && <p className="password-message">{message}</p>}</div>;
}
function MorePanel({ onBack }: { onBack: () => void }) {
  return (
    <section className="more-panel">
      <div className="more-search">
        <label className="more-field more-date-field">
          <span>日期</span>
          <select defaultValue="today">
            <option value="today">8-19 (体彩-2026221 福彩-2026221)</option>
          </select>
        </label>
        <label className="more-field more-text-field">
          <span>原始文本搜索：</span>
          <input placeholder="输入文本" />
        </label>
        <button className="more-search-button" type="button">⌕ 搜索</button>
        <button className="more-back-button" type="button" onClick={onBack}>返回</button>
      </div>
      <div className="more-total">总金额: <b>0</b></div>
    </section>
  );
}
function Main({ name, logout }: { name: string; logout: () => void }) {
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
  return (
    <div className="app">
      <Header name={name} logout={logout} announcement={announcement} balances={balances} lotteries={lotteries} selectableLottery={location.pathname === "/zh"} selectedLotteryId={selectedLotteryId} onSelectLottery={setSelectedLotteryId} />
      {moreOpen ? <MorePanel onBack={() => setMoreOpen(false)} /> : <div className={`body${panelRight ? " panel-right" : ""}${fullPage ? " full-page" : ""}`}>
        {!fullPage && <aside><SideBetRecords onMore={() => setMoreOpen(true)} panelRight={panelRight} onToggleSide={() => setPanelRight((value) => !value)} /></aside>}
        <main>
            <Routes>
              <Route path="/" element={<QuickEntry lotteries={lotteries} selectedLottery={lotteries.find((item) => item.id === selectedLotteryId)} />} />
              <Route path="/kb" element={<QuickEntry lotteries={lotteries} selectedLottery={lotteries.find((item) => item.id === selectedLotteryId)} />} />
              <Route path="/zh" element={<BetDetailsPage lotteries={lotteries} selectedLotteryId={selectedLotteryId} />} />
              <Route path="/zd" element={<BillsPage />} />
              <Route path="/hyxx" element={<MemberPage name={name} />} />
              <Route path="/jg" element={<DrawsPage />} />
              <Route path="/xgmm" element={<ChangePasswordPage />} />
            <Route path="*" element={<Generic title={title} />} />
          </Routes>
        </main>
      </div>}
      {warmVisible && (
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
  const [agreementVisible, setAgreementVisible] = useState(() => {
    const token = localStorage.getItem("user_token");
    return Boolean(token && localStorage.getItem("user_name") && sessionStorage.getItem("agreement_accepted_token") !== token);
  });
  const [agreement, setAgreement] = useState<AgreementData>(defaultAgreement);
  useEffect(() => {
    const handleUnauthorized = () => {
      setAgreementVisible(false);
      setName("");
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
    const token = new URLSearchParams(window.location.search).get("auto_token");
    if (!token) return;
    if (localStorage.getItem("user_token") === token && name) return;
    localStorage.setItem("user_token", token);
    localStorage.setItem("user_name", "站点管理员");
    sessionStorage.removeItem("agreement_accepted_token");
    setName("站点管理员");
    setAgreementVisible(true);
  }, [name]);
  return (
    <HashRouter>
      {name ? (
        agreementVisible ? (
          <Agreement
            agreement={agreement}
            onReject={() => {
              localStorage.removeItem("user_name");
              localStorage.removeItem("user_token");
              sessionStorage.removeItem("agreement_accepted_token");
              setAgreementVisible(false);
              setName("");
            }}
            onAccept={() => {
              const token = localStorage.getItem("user_token");
              if (token) sessionStorage.setItem("agreement_accepted_token", token);
              setAgreementVisible(false);
            }}
          />
        ) : <Main name={name} logout={() => { localStorage.removeItem("user_name"); localStorage.removeItem("user_token"); sessionStorage.removeItem("agreement_accepted_token"); setName(""); }} />
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
