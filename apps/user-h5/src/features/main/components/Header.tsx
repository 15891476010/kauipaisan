import { useEffect, useRef, useState } from "react";
import { App as AntdApp } from "antd";
import { NavLink } from "react-router-dom";
import loginLogo from "../../../assets/login-logo.svg";
import logoutIcon from "../../../assets/logout.svg";
import swapIcon from "../../../assets/swap.svg";
import { getLineOptions, type LineOption, type Lottery } from "../../../api/user";

import { apiErrorMessage } from "../../../utils/request";
import {
  displayIssueCode,
  lotteryTiming,
  nav,
  type Announcement,
  type Balances,
} from "../shared";

export function Header({
  name,
  logout,
  announcement,
  balances,
  lotteries,
  selectableLottery = false,
  selectedLotteryId,
  onSelectLottery,
  locked = false,
}: {
  name: string;
  logout: () => void;
  announcement: Announcement;
  balances: Balances;
  lotteries: Lottery[];
  selectableLottery?: boolean;
  selectedLotteryId?: number | null;
  onSelectLottery?: (id: number) => void;
  locked?: boolean;
}) {
  const { modal } = AntdApp.useApp();
  const [now, setNow] = useState(Date.now());
  const [lineOpen, setLineOpen] = useState(false);
  const [lineLoading, setLineLoading] = useState(false);
  const [headerCollapsed, setHeaderCollapsed] = useState(false);
  const [lineOptions, setLineOptions] = useState<LineOption[]>([]);
  const [lineResults, setLineResults] = useState<
    Array<{
      line: number;
      delay: number | null;
      fastest?: boolean;
      status: "testing" | "pending" | "loaded" | "failed";
    }>
  >([]);
  const [lineCountdown, setLineCountdown] = useState<number | null>(null);
  const lineRedirectTimer = useRef<number | null>(null);
  const lineCountdownTimer = useRef<number | null>(null);
  useEffect(() => {
    const timer = window.setInterval(() => setNow(Date.now()), 1_000);
    return () => window.clearInterval(timer);
  }, []);
  async function checkLines() {
    if (lineRedirectTimer.current !== null)
      window.clearTimeout(lineRedirectTimer.current);
    if (lineCountdownTimer.current !== null)
      window.clearInterval(lineCountdownTimer.current);
    setLineOpen(true);
    setLineLoading(true);
    setLineResults([]);
    setLineCountdown(null);
    try {
      const response = await getLineOptions();
      const options = response.data?.data?.list || [];
      setLineOptions(options);
      setLineResults(
        options.map((option, index) => ({
          line: option.line,
          delay: null,
          fastest: false,
          status: index < 4 ? "testing" : "pending",
        })),
      );
      const results = await Promise.all(
        options.map(async (option) => {
          const started = performance.now();
          const controller = new AbortController();
          const timeout = window.setTimeout(() => controller.abort(), 6000);
          try {
            const response = await fetch(
              `${option.url}/prod_api/v1/health?line=${option.line}&t=${Date.now()}`,
              { mode: "cors", cache: "no-store", signal: controller.signal },
            );
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            return {
              line: option.line,
              delay: Math.max(1, Math.round(performance.now() - started)),
              status: "loaded" as const,
            };
          } catch {
            return { line: option.line, delay: null, status: "failed" as const };
          } finally {
            window.clearTimeout(timeout);
          }
        }),
      );
      const available = results.filter((item) => item.delay !== null) as Array<{
        line: number;
        delay: number;
      }>;
      const fastest = available.sort((a, b) => a.delay - b.delay)[0]?.line;
      setLineResults(
        results.map((item) => ({ ...item, fastest: item.line === fastest })),
      );
      const target = options.find((option) => option.line === fastest)?.url;
      if (target) {
        setLineCountdown(3);
        lineCountdownTimer.current = window.setInterval(
          () =>
            setLineCountdown((value) =>
              value !== null && value > 1 ? value - 1 : 1,
            ),
          1_000,
        );
        lineRedirectTimer.current = window.setTimeout(() => {
          if (lineCountdownTimer.current !== null)
            window.clearInterval(lineCountdownTimer.current);
          const destination = new URL(
            `${window.location.pathname}${window.location.search}${window.location.hash}`,
            `${target}/`,
          );
          const token = localStorage.getItem("user_token");
          if (token) destination.searchParams.set("auto_token", token);
          destination.searchParams.set("line_switch", "1");
          window.location.assign(destination.toString());
        }, 3_000);
      }
    } catch (error) {
      modal.error({
        title: "线路检测失败",
        content: apiErrorMessage(error, "暂时无法获取线路"),
      });
    } finally {
      setLineLoading(false);
    }
  }
  function closeLineModal() {
    if (lineRedirectTimer.current !== null)
      window.clearTimeout(lineRedirectTimer.current);
    if (lineCountdownTimer.current !== null)
      window.clearInterval(lineCountdownTimer.current);
    lineRedirectTimer.current = null;
    lineCountdownTimer.current = null;
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
      <button
        className="notice"
        type="button"
        onClick={() =>
          modal.info({
            title: announcement.title,
            content: (
              <div className="announcement-modal-content">
                {announcement.content}
              </div>
            ),
            okText: "关闭",
            width: 500,
          })
        }
      >
        <span className="notice-track">
          {announcement.content || "暂无公告"}
        </span>
        <span className="notice-track" aria-hidden="true">
          {announcement.content || "暂无公告"}
        </span>
      </button>
      <header className={`site-header${headerCollapsed ? " header-collapsed" : ""}`}>
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
        <ul
          className={`lottery ${lotteries.length === 1 ? "lottery-single" : ""}`}
        >
          {lotteries.map((item) => {
            const timing = lotteryTiming(item, now);
            return (
              <li
                key={item.id}
                className={
                  selectableLottery && selectedLotteryId === item.id
                    ? "selected"
                    : ""
                }
                role={selectableLottery ? "button" : undefined}
                tabIndex={selectableLottery ? 0 : undefined}
                onClick={() => selectableLottery && onSelectLottery?.(item.id)}
                onKeyDown={(event) => {
                  if (
                    selectableLottery &&
                    (event.key === "Enter" || event.key === " ")
                  )
                    onSelectLottery?.(item.id);
                }}
              >
                <div className="lottery-row">
                  <div className="lottery-name">
                    <span>{item.name}</span>
                    <b>{timing.status}</b>
                  </div>
                  <div className="lottery-meta">
                    <label>{displayIssueCode((timing.headerShowNextIssue ? (item.header_next_code || item.next_code) : item.latest_code) || "--")}</label>
                    <strong>{timing.countdown}</strong>
                  </div>
                </div>
              </li>
            );
          })}
        </ul>
        <nav className="site-navigation">
          {nav.map(({ path, title, icon }) => (
            <NavLink
              key={path}
              to={"/" + path}
              className={({ isActive }) => `${isActive ? "selected" : ""}${locked ? " locked" : ""}`}
              aria-disabled={locked}
              onClick={(event) => { if (locked) event.preventDefault(); }}
            >
              <span className="nav-icon-shell">
                <img
                  className="nav-icon"
                  src={icon}
                  alt=""
                  aria-hidden="true"
                />
              </span>
              {title}
            </NavLink>
          ))}
          <button className="line" disabled={locked} onClick={() => void checkLines()}>
            <span className="nav-icon-shell">
              <img
                className="nav-icon"
                src={swapIcon}
                alt=""
                aria-hidden="true"
              />
            </span>
            <em>测速</em>
          </button>
          <span
            className={`nav-arrow t-b ${headerCollapsed ? "rv" : "v"}`}
            role="button"
            tabIndex={0}
            aria-label={headerCollapsed ? "展开顶部信息" : "收起顶部信息"}
            onClick={() => setHeaderCollapsed((value) => !value)}
            onKeyDown={(event) => {
              if (event.key === "Enter" || event.key === " ") {
                event.preventDefault();
                setHeaderCollapsed((value) => !value);
              }
            }}
          />
          <button className="exit" onClick={logout}>
            <span className="nav-icon-shell">
              <img
                className="nav-icon"
                src={logoutIcon}
                alt=""
                aria-hidden="true"
              />
            </span>
            <em>退出</em>
          </button>
        </nav>
      </header>
      {lineOpen && (
        <div className="line-overlay" role="dialog" aria-modal="true" aria-label="切换线路" onClick={(event) => { if (event.target === event.currentTarget) closeLineModal(); }}>
          <div className="line-container">
            <div className="line-title">
              <h5>切换线路</h5>
              <button type="button" aria-label="关闭测速窗口" onClick={closeLineModal}>×</button>
            </div>
            <div className="line-tip">
              测速完成后将 <b>自动跳转</b> 至 <b>速度最快</b> 的线路
            </div>
            <div className="line-tip">
              数字越 <b>小</b>，速度越 <b>快</b>
            </div>
            {!lineLoading && lineResults.length === 0 && (
              <div className="line-modal-empty">当前站点暂无可用线路</div>
            )}
            {lineResults.length > 0 && (
              <div className="line-table">
                <div className="line-table-row line-table-head">
                  <span>线路</span>
                  <span>速度</span>
                </div>
                {lineResults.map((item) => {
                  const option = lineOptions.find((entry) => entry.line === item.line);
                  const href = option?.url ? `${option.url.replace(/\/$/, "")}/` : "#";
                  return (
                    <div className="line-table-row" key={item.line}>
                      <span className="line-name">
                        <a href={href}>线路{item.line}</a>
                      </span>
                      <strong className={item.status === "testing" ? "line-delay-medium" : delayClass(item.delay)}>
                        {item.status === "testing"
                          ? "测速中..."
                          : item.status === "pending"
                            ? "等待中"
                            : item.delay === null
                              ? "检测失败"
                              : (
                                <>
                                  {item.delay}ms
                                  {item.fastest && <em className="line-fastest">最快</em>}
                                </>
                              )}
                      </strong>
                    </div>
                  );
                })}
              </div>
            )}
            {lineCountdown !== null && (
              <div className="line-countdown">
                <b>{lineCountdown}</b> 秒后自动跳转至最快线路
              </div>
            )}
          </div>
        </div>
      )}
    </>
  );
}
