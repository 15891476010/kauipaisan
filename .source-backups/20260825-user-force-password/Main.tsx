import { useEffect, useMemo, useState } from "react";
import { Route, Routes, useLocation } from "react-router-dom";
import {
  getAnnouncement,
  getLotteries,
  getProfile,
  heartbeat,
  type Lottery,
} from "../../api/user";
import { Header } from "./components/Header";
import { SideBetRecords } from "./components/SideBetRecords";
import { MorePanel } from "./components/MorePanel";
import { QuickEntryPage } from "./pages/QuickEntryPage";
import { BetDetailsPage } from "./pages/BetDetailsPage";
import { BillsPage } from "./pages/BillsPage";
import { DrawsPage } from "./pages/DrawsPage";
import { MemberPage } from "./pages/MemberPage";
import { ChangePasswordPage } from "./pages/ChangePasswordPage";
import { RulesPage } from "./pages/RulesPage";
import { GenericPage } from "./pages/GenericPage";
import { displayAmount, nav, type Announcement, type Balances } from "./shared";
import "./Main.scss";

function MainShell({ name, logout }: { name: string; logout: () => void }) {
  const location = useLocation();
  const [panelRight, setPanelRight] = useState(false);
  const [moreOpen, setMoreOpen] = useState(false);
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
    getAnnouncement()
      .then((response) => {
        if (active && response.data?.data)
          setAnnouncement({
            title: String(response.data.data.title || "公告"),
            content: String(response.data.data.content || "暂无公告"),
          });
      })
      .catch(() => {
        if (active) setAnnouncement({ title: "公告", content: "暂无公告" });
      });
    return () => {
      active = false;
    };
  }, []);
  useEffect(() => {
    getLotteries()
      .then((response) => {
        const list = response.data?.data?.list || [];
        setLotteries(list);
        setSelectedLotteryId((current) =>
          current && list.some((item) => item.id === current)
            ? current
            : list[0]?.id || null,
        );
      })
      .catch(() => {
        setLotteries([]);
        setSelectedLotteryId(null);
      });
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
  const selectedLottery = lotteries.find(
    (item) => item.id === selectedLotteryId,
  );
  const fullPage = location.pathname !== "/" && location.pathname !== "/kb";
  const lotterySwitchPages = new Set(["/zh", "/hyxx", "/jg", "/gz"]);
  const title = useMemo(
    () =>
      nav.find((item) => "/" + item.path === location.pathname)?.title ||
      "快速录入",
    [location.pathname],
  );
  return (
    <div className="app">
      <Header
        name={name}
        logout={logout}
        announcement={announcement}
        balances={balances}
        lotteries={lotteries}
        selectableLottery={lotterySwitchPages.has(location.pathname)}
        selectedLotteryId={selectedLotteryId}
        onSelectLottery={setSelectedLotteryId}
      />
      {moreOpen ? (
        <MorePanel lotteries={lotteries} onBack={() => setMoreOpen(false)} />
      ) : (
        <div
          className={`body${panelRight ? " panel-right" : ""}${fullPage ? " full-page" : ""}`}
        >
          {!fullPage && (
            <aside>
              <SideBetRecords
                onMore={() => setMoreOpen(true)}
                panelRight={panelRight}
                onToggleSide={() => setPanelRight((value) => !value)}
              />
            </aside>
          )}
          <main>
            <Routes>
              <Route
                path="/"
                element={
                  <QuickEntryPage
                    lotteries={lotteries}
                    selectedLottery={selectedLottery}
                  />
                }
              />
              <Route
                path="/kb"
                element={
                  <QuickEntryPage
                    lotteries={lotteries}
                    selectedLottery={selectedLottery}
                  />
                }
              />
              <Route
                path="/zh"
                element={
                  <BetDetailsPage
                    lotteries={lotteries}
                    selectedLotteryId={selectedLotteryId}
                  />
                }
              />
              <Route path="/zd" element={<BillsPage />} />
              <Route
                path="/hyxx"
                element={<MemberPage name={name} selectedLottery={selectedLottery} />}
              />
              <Route path="/jg" element={<DrawsPage selectedLottery={selectedLottery} />} />
              <Route path="/gz" element={<RulesPage selectedLottery={selectedLottery} />} />
              <Route path="/xgmm" element={<ChangePasswordPage />} />
              <Route path="*" element={<GenericPage title={title} />} />
            </Routes>
          </main>
        </div>
      )}
      <section className="warm is-open" aria-label="温馨提示">
        <header className="warm-header">
          <strong>温馨提示</strong>
        </header>
        <div className="warm-content">
          【温馨提示】各位会员在下注确定后请到“下注明细”里确认注单，一切注单结算以下注明细里资料为准！
        </div>
      </section>
    </div>
  );
}

export function Main({ name, logout }: { name: string; logout: () => void }) {
  return <MainShell name={name} logout={logout} />;
}
