import { useEffect, useLayoutEffect, useMemo, useState } from "react";
import { Route, Routes, useLocation } from "react-router-dom";
import {
  getAnnouncement,
  getLotteries,
  getProfile,
  heartbeat,
  type BetRecord,
  type Lottery,
} from "../../api/user";
import { Header } from "./components/Header";
import { SideBetRecords } from "./components/SideBetRecords";
import { MorePanel } from "./components/MorePanel";
import { QuickEntryPage } from "./pages/QuickEntryPage";
import { RecordsPage } from "./pages/RecordsPage";
import { BillsPage } from "./pages/BillsPage";
import { DrawsPage } from "./pages/DrawsPage";
import { MemberPage } from "./pages/MemberPage";
import { ChangePasswordPage } from "./pages/ChangePasswordPage";
import { RulesPage } from "./pages/RulesPage";
import { GenericPage } from "./pages/GenericPage";
import { StopDropPage } from "./pages/StopDropPage";
import { displayAmount, nav, type Announcement, type Balances } from "./shared";
import "./Main.scss";

function MainShell({ name, logout, forcePasswordChange = false, onPasswordChanged }: { name: string; logout: () => void; forcePasswordChange?: boolean; onPasswordChanged?: () => void }) {
  const location = useLocation();
  const locked = forcePasswordChange;
  const [panelRight, setPanelRight] = useState(false);
  const [moreOpen, setMoreOpen] = useState(false);
  const [moreNumberRecord, setMoreNumberRecord] = useState<BetRecord>();
  const [quickEntryHasResults, setQuickEntryHasResults] = useState(false);
  const [warmVisible, setWarmVisible] = useState(false);
  const [warmOpen, setWarmOpen] = useState(true);
  const [balances, setBalances] = useState<Balances>({
    balance: "0",
    total_balance: "0",
    credit_balance: "0",
    used_balance: "0",
    available_balance: "0",
  });
  const [announcement, setAnnouncement] = useState<Announcement>({
    title: "公告",
    content: "暂无公告",
  });
  const [lotteries, setLotteries] = useState<Lottery[]>([]);
  const [selectedLotteryId, setSelectedLotteryId] = useState<number | null>(
    null,
  );
  useEffect(() => {
    const send = () => {
      void heartbeat().catch(() => undefined);
    };
    send();
    const timer = window.setInterval(send, 20_000);
    return () => window.clearInterval(timer);
  }, []);
  useEffect(() => {
    let active = true;
    const token = localStorage.getItem("user_token");
    if (!token)
      return () => {
        active = false;
      };
    const dismissedKey = "h5_announcement_dismissed:" + encodeURIComponent(name);
    getAnnouncement()
      .then((response) => {
        if (!active) return;
        if (!response.data?.data) {
          setWarmVisible(false);
          return;
        }
        const next = {
          title: String(response.data.data.title || "公告"),
          content: String(response.data.data.content || "暂无公告"),
        };
        setAnnouncement(next);
        try {
          const dismissed = localStorage.getItem(dismissedKey);
          if (dismissed !== next.title + "\u0000" + next.content) {
            setWarmOpen(true);
            setWarmVisible(true);
          }
        } catch {
          setWarmOpen(true);
          setWarmVisible(true);
        }
      })
      .catch(() => {
        if (active) setWarmVisible(false);
      });
    return () => {
      active = false;
    };
  }, [name]);
  useEffect(() => {
    let active = true;
    const loadLotteries = () => {
      getLotteries()
        .then((response) => {
          if (!active) return;
          const list = response.data?.data?.list || [];
          setLotteries(list);
          setSelectedLotteryId((current) =>
            current && list.some((item) => item.id === current)
              ? current
              : list[0]?.id || null,
          );
        })
        .catch(() => {
          if (!active) return;
          setLotteries([]);
          setSelectedLotteryId(null);
        });
    };
    loadLotteries();
    const timer = window.setInterval(loadLotteries, 30_000);
    return () => {
      active = false;
      window.clearInterval(timer);
    };
  }, []);
  useEffect(() => {
    const token = localStorage.getItem("user_token");
    if (!token) return;
    const refreshProfile = (event?: Event) => {
      const amount = Number(
        (event as CustomEvent<{ amount?: string }> | undefined)?.detail
          ?.amount || 0,
      );
      if (amount > 0) {
        setBalances((current) => ({
          ...current,
          used_balance: displayAmount(Number(current.used_balance) + amount),
          available_balance: displayAmount(
            Math.max(0, Number(current.available_balance) - amount),
          ),
        }));
      }
      return getProfile()
        .then((response) => {
          if (response.data?.data) {
            const data = response.data.data;
            setBalances((current) => {
              const normalized = {} as Balances;
              (Object.keys(current) as Array<keyof Balances>).forEach((key) => {
                normalized[key] = displayAmount(data[key] ?? current[key]);
              });
              return { ...current, ...normalized };
            });
          }
        })
        .catch(() => undefined);
    };
    refreshProfile();
    window.addEventListener("profile-updated", refreshProfile);
    return () => window.removeEventListener("profile-updated", refreshProfile);
  }, []);
  useEffect(() => {
    const shouldOpen = location.pathname === "/rtl";
    setMoreOpen((current) => (current === shouldOpen ? current : shouldOpen));
  }, [location.pathname]);
  useLayoutEffect(() => {
    const resetScroll = () => {
      const scroller = document.querySelector<HTMLElement>(".app");
      scroller?.scrollTo({ top: 0, left: 0, behavior: "auto" });
    };
    resetScroll();
    const frame = window.requestAnimationFrame(() => {
      resetScroll();
      window.requestAnimationFrame(resetScroll);
    });
    // Header/lottery data arrives asynchronously. Repeat the initial reset
    // after those nodes mount so browser scroll restoration cannot reopen the
    // wide H5 page at its previous bottom position.
    const timers = [60, 250, 700, 1200].map((delay) =>
      window.setTimeout(resetScroll, delay),
    );
    return () => {
      window.cancelAnimationFrame(frame);
      timers.forEach((timer) => window.clearTimeout(timer));
    };
  }, [location.pathname]);
  const selectedLottery = lotteries.find(
    (item) => item.id === selectedLotteryId,
  );
  const fullPage = !forcePasswordChange && location.pathname !== "/" && location.pathname !== "/kb";
  const lotterySwitchPages = new Set(["/hyxx", "/jg", "/gz"]);
  const title = useMemo(
    () =>
      nav.find((item) => "/" + item.path === location.pathname)?.title ||
      "快速录入",
    [location.pathname],
  );
  return (
    <div className={`app${location.pathname === "/zh" ? " records-app" : ""}`}>
      <Header
        name={name}
        logout={logout}
        announcement={announcement}
        balances={balances}
        lotteries={lotteries}
        selectableLottery={lotterySwitchPages.has(location.pathname)}
        selectedLotteryId={selectedLotteryId}
        onSelectLottery={setSelectedLotteryId}
        locked={locked}
      />
      {moreOpen ? (
        <MorePanel
          lotteries={lotteries}
          memberName={name}
          initialNumberRecord={moreNumberRecord}
          onBack={() => {
            setMoreNumberRecord(undefined);
            setMoreOpen(false);
            window.location.hash = "#/kb";
          }}
        />
      ) : (
        <div
          className={`body${panelRight ? " panel-right" : ""}${fullPage ? " full-page" : ""}`}
        >
          {!fullPage && !quickEntryHasResults && (
            <aside>
              <SideBetRecords
                onMore={() => {
                  setMoreNumberRecord(undefined);
                  setMoreOpen(true);
                  window.location.hash = "#/rtl";
                }}
                onNumbers={(record) => {
                  setMoreNumberRecord(record);
                  setMoreOpen(true);
                  window.location.hash = "#/rtl";
                }}
                panelRight={panelRight}
                onToggleSide={() => {
                  if (window.matchMedia("(max-width: 599px)").matches) {
                    window.location.hash = "#/dbl";
                    return;
                  }
                  setPanelRight((value) => !value);
                }}
                disabled={locked}
              />
            </aside>
          )}
          <main>
            {forcePasswordChange ? (
              <ChangePasswordPage
                forced
                onPasswordChanged={() => {
                  onPasswordChanged?.();
                  window.location.hash = "#/kb";
                }}
              />
            ) : <Routes>
              <Route
                path="/"
                element={
                  <QuickEntryPage
                    lotteries={lotteries}
                    selectedLottery={selectedLottery}
                    onResultsVisibilityChange={setQuickEntryHasResults}
                  />
                }
              />
              <Route
                path="/kb"
                element={
                  <QuickEntryPage
                    lotteries={lotteries}
                    selectedLottery={selectedLottery}
                    onResultsVisibilityChange={setQuickEntryHasResults}
                  />
                }
              />
              <Route path="/zh" element={<RecordsPage />} />
              <Route path="/zd" element={<BillsPage />} />
              <Route path="/dbl" element={<StopDropPage />} />
              <Route path="/rtl" element={<MorePanel lotteries={lotteries} memberName={name} onBack={() => { setMoreNumberRecord(undefined); window.location.hash = "#/kb"; }} />} />
              <Route
                path="/hyxx"
                element={<MemberPage name={name} selectedLottery={selectedLottery} />}
              />
              <Route path="/jg" element={<DrawsPage selectedLottery={selectedLottery} />} />
              <Route path="/gz" element={<RulesPage selectedLottery={selectedLottery} />} />
              <Route path="/xgmm" element={<ChangePasswordPage />} />
              <Route path="*" element={<GenericPage title={title} />} />
            </Routes>}
          </main>
        </div>
      )}
      {warmVisible ? <section className={`warm${warmOpen ? " is-open" : " is-collapsed"}`} aria-label="温馨提示">
        <header className="warm-header">
          <strong>温馨提示</strong>
          <div className="warm-actions">
            <button type="button" title={warmOpen ? "收回温馨提示" : "弹出温馨提示"} aria-label={warmOpen ? "收回温馨提示" : "弹出温馨提示"} onClick={() => setWarmOpen((value) => !value)}>
              <span aria-hidden="true">{warmOpen ? "−" : "+"}</span>
            </button>
            <button
              type="button"
              title="关闭温馨提示"
              aria-label="关闭温馨提示"
              onClick={() => {
                try {
                  localStorage.setItem(
                    "h5_announcement_dismissed:" + encodeURIComponent(name),
                    announcement.title + "\u0000" + announcement.content,
                  );
                } catch {
                  // Storage can be unavailable in private browsing; closing
                  // the current overlay still remains possible.
                }
                setWarmVisible(false);
              }}
            >
              <span aria-hidden="true">×</span>
            </button>
          </div>
        </header>
        <div className="warm-content">
          {announcement.content || "暂无公告"}
        </div>
      </section> : null}
    </div>
  );
}

export function Main({ name, logout, forcePasswordChange, onPasswordChanged }: { name: string; logout: () => void; forcePasswordChange?: boolean; onPasswordChanged?: () => void }) {
  useEffect(() => {
    if (forcePasswordChange && window.location.hash !== "#/xgmm") window.location.hash = "#/xgmm";
  }, [forcePasswordChange]);
  return <MainShell name={name} logout={logout} forcePasswordChange={forcePasswordChange} onPasswordChanged={onPasswordChanged} />;
}
