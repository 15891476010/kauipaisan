import { useEffect, useMemo, useRef, useState } from "react";
import { Empty } from "antd";
import { CalendarOutlined } from "@ant-design/icons";
import { getAgentLedger, getAgentLotteryHistory, getAgentReports } from "../../api/user";
import { apiErrorMessage } from "../../utils/request";
import type {
  AgentLedgerAccountRow,
  AgentLedgerContributionRow,
  AgentLotteryHistory,
  AgentReportRow,
  Lottery,
} from "../../api/user";

type UtilityKind = "ledger" | "reports" | "results" | "intercept" | "logs" | "settings";
type Props = { kind: UtilityKind; lotteries: Lottery[]; selectedLotteryId?: number | null };

const ledgerTabs = [
  { label: "贡献度", columns: ["会员", "占成金额", "占成总金额", "占成总盈亏", "百分比占成盈亏", "实际占成盈亏", "占成百分比", "贡献度"] },
  { label: "日分类账", columns: ["代理", "类别", "笔数", "总投", "回水", "中奖", "盈亏"] },
  { label: "月分类账", columns: ["期号", "类别", "笔数", "总投", "回水", "中奖", "盈亏"] },
  { label: "日路径账", columns: ["代理", "类别", "笔数", "总投", "回水", "中奖", "盈亏"] },
  { label: "月路径账", columns: ["期号", "类别", "笔数", "总投", "回水", "中奖", "盈亏"] },
];

const ledgerTypeMap = {
  贡献度: "contribution",
  日分类账: "daily",
  月分类账: "monthly",
  日路径账: "daily_path",
  月路径账: "monthly_path",
} as const;

const pageMeta: Record<Exclude<UtilityKind, "ledger">, { title: string; tabs: string[] }> = {
  reports: { title: "报表", tabs: ["总货报表", "会员报表", "路径报表"] },
  results: { title: "开奖号码", tabs: ["最近开奖", "历史开奖"] },
  intercept: { title: "拦货", tabs: ["拦货设置", "拦货记录"] },
  logs: { title: "日志", tabs: ["登录日志", "操作日志"] },
  settings: { title: "设置", tabs: ["基本设置", "彩票设置", "安全设置"] },
};

function issueOptions(lotteries: Lottery[]) {
  const rawIssue = String(lotteries[0]?.next_code || lotteries[0]?.latest_code || "2026222").replace(/\D/g, "");
  const issue = /^26\d{3}$/.test(rawIssue) ? `20${rawIssue}` : rawIssue;
  const base = Number(issue) || 2026222;
  const now = new Date();
  return Array.from({ length: 15 }, (_, index) => {
    const d = new Date(now);
    d.setDate(now.getDate() - index);
    return `${d.getMonth() + 1}-${d.getDate()}(${base - index})`;
  });
}

function issueValue(value: string) {
  return value.match(/\(([^)]+)\)$/)?.[1] || value;
}

function EmptyTable({ columns, loading = false }: { columns: string[]; loading?: boolean }) {
  return (
    <div className="utility-table-wrap ledger-table-wrap">
      <table className="utility-table ledger-table">
        <thead><tr>{columns.map((column) => <th key={column}>{column}</th>)}</tr></thead>
        <tbody>
          <tr>
            <td className="utility-empty" colSpan={columns.length}>
              {loading ? <span className="report-spinner" aria-label="正在查询" /> : <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="暂无数据" />}
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  );
}

function LedgerDataTable({
  columns,
  type,
  rows,
  loading,
  error,
}: {
  columns: string[];
  type: string;
  rows: Array<AgentLedgerContributionRow | AgentLedgerAccountRow>;
  loading: boolean;
  error: string;
}) {
  return (
    <div className="utility-table-wrap ledger-table-wrap">
      <table className="utility-table ledger-table">
        <thead><tr>{columns.map((column) => <th key={column}>{column}</th>)}</tr></thead>
        <tbody>
          {loading ? (
            <tr><td className="utility-empty" colSpan={columns.length}><span className="report-spinner" aria-label="正在查询" /></td></tr>
          ) : error ? (
            <tr><td className="utility-empty results-error" colSpan={columns.length}>{error}</td></tr>
          ) : rows.length === 0 ? (
            <tr><td className="utility-empty" colSpan={columns.length}><Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="暂无数据" /></td></tr>
          ) : rows.map((item, index) => {
            if (type === "contribution") {
              const row = item as AgentLedgerContributionRow;
              return <tr key={row.user_id || index}>
                <td>{row.username}</td><td>{row.share_amount}</td><td>{row.share_total_amount}</td><td>{row.share_total_profit}</td>
                <td>{row.percentage_share_profit}</td><td>{row.actual_share_profit}</td><td>{row.share_percentage}</td><td>{row.contribution}</td>
              </tr>;
            }
            const row = item as AgentLedgerAccountRow;
            const account = type.includes("path") ? row.path : row.account;
            return <tr key={`${row.account}-${row.category}-${index}`}>
              <td>{account}</td><td>{row.category}</td><td>{row.bet_count}</td><td>{row.total_bet}</td>
              <td>{row.rebate}</td><td>{row.win_amount}</td><td>{row.profit}</td>
            </tr>;
          })}
        </tbody>
      </table>
    </div>
  );
}

function LedgerPage({ lotteries }: { lotteries: Lottery[] }) {
  const [active, setActive] = useState(ledgerTabs[0].label);
  const [period, setPeriod] = useState("本期");
  const options = useMemo(() => issueOptions(lotteries), [lotteries]);
  const [from, setFrom] = useState(options[0] || "");
  const [to, setTo] = useState(options[0] || "");
  const [rows, setRows] = useState<Array<AgentLedgerContributionRow | AgentLedgerAccountRow>>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const current = ledgerTabs.find((item) => item.label === active) || ledgerTabs[0];
  const type = ledgerTypeMap[active as keyof typeof ledgerTypeMap] || "contribution";

  useEffect(() => {
    if (options.length === 0) return;
    setFrom((value) => options.includes(value) ? value : options[0]);
    setTo((value) => options.includes(value) ? value : options[0]);
  }, [options]);

  useEffect(() => {
    if (!options[0]) return;
    let cancelled = false;
    setLoading(true);
    setError("");
    const daily = type === "daily" || type === "daily_path";
    const params = {
      type,
      from_issue: daily ? issueValue(options[0]) : issueValue(from),
      to_issue: daily ? issueValue(options[0]) : issueValue(to),
      page: 1,
      page_size: 100,
    };
    void getAgentLedger(params)
      .then((response) => {
        if (!cancelled) setRows(response.data.data?.list || []);
      })
      .catch((reason: unknown) => {
        if (cancelled) return;
        setRows([]);
        setError(apiErrorMessage(reason, "分类账加载失败"));
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => { cancelled = true; };
  }, [from, options, to, type]);

  function choosePeriod(value: string) {
    setPeriod(value);
    const ranges: Record<string, [number, number]> = {
      本期: [0, 0],
      全部: [options.length - 1, 0],
      本周: [Math.min(6, options.length - 1), 0],
      上周: [Math.min(13, options.length - 1), Math.min(7, options.length - 1)],
      "2026年08月": [options.length - 1, 0],
    };
    const [fromIndex, toIndex] = ranges[value] || [options.length - 1, 0];
    setFrom(options[fromIndex] || options[0] || "");
    setTo(options[toIndex] || options[0] || "");
  }

  return (
    <section className="ledger-page utility-page">
      <div className="ledger-topbar">
        <div className="ledger-breadcrumb"><span>位置</span><b>»</b><span>分类账</span><b>»</b><strong>{active}</strong></div>
        <nav className="ledger-subnav" aria-label="分类账导航">
          {ledgerTabs.map((tab) => (
            <button key={tab.label} type="button" className={active === tab.label ? "active" : ""} onClick={() => setActive(tab.label)}>{tab.label}</button>
          ))}
        </nav>
      </div>
      <form className="ledger-filters" onSubmit={(event) => event.preventDefault()}>
        {active === "日分类账" || active === "日路径账" ? <div className="ledger-daily-issue">第 {issueValue(options[0] || "2026222")} 期</div> : <>
          <div className="ledger-period-left"><strong>{active}</strong><div className="ledger-shortcuts" role="tablist" aria-label="周期">
            {(active === "贡献度" ? ["【2026年08月】", "【本期】", "【全部】", "【本周】", "【上周】"] : ["2026年08月", "全部", "本周", "上周"]).map((item) => {
              const value = item.replace(/[【】]/g, "");
              return <button key={item} type="button" className={period === value ? "active" : ""} onClick={() => choosePeriod(value)}>{item}</button>;
            })}
          </div></div>
          <div className="ledger-range">
            <select value={from} onChange={(event) => setFrom(event.target.value)} aria-label="开始期号">{options.map((item) => <option key={item}>{item}</option>)}</select>
            <span>至</span>
            <select value={to} onChange={(event) => setTo(event.target.value)} aria-label="结束期号">{options.map((item) => <option key={item}>{item}</option>)}</select>
          </div>
        </>}
      </form>
      <LedgerDataTable columns={current.columns} type={type} rows={rows} loading={loading} error={error} />
    </section>
  );
}

function formatReportDate(date: Date) {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`;
}

function reportDateRange(period: string) {
  const today = new Date();
  const start = new Date(today);
  const end = new Date(today);
  if (period === "昨天") {
    start.setDate(start.getDate() - 1);
    end.setDate(end.getDate() - 1);
  } else if (period === "本周") {
    const weekday = (start.getDay() + 6) % 7;
    start.setDate(start.getDate() - weekday);
  } else if (period === "上周") {
    const weekday = (start.getDay() + 6) % 7;
    start.setDate(start.getDate() - weekday - 7);
    end.setDate(start.getDate() + 6);
  } else if (period.includes("年")) {
    start.setDate(1);
  }
  return [formatReportDate(start), formatReportDate(end)] as const;
}

function ReportRowCells({ row }: { row: AgentReportRow }) {
  return <>
    <td>{row.label}</td><td>{row.bet_count}</td><td>{row.total_bet}</td><td>{row.total_win}</td><td>{row.total_rebate}</td><td>{row.member_profit}</td>
    <td>{row.agent_share_amount}</td><td>{row.agent_share_profit}</td><td>{row.offline_rebate}</td><td>{row.agent_total_rebate}</td><td>{row.agent_total_profit}</td>
    <td>{row.master_total_bet}</td><td>{row.master_profit}</td>
  </>;
}

function ReportTotalsTable({
  monthly,
  rows,
  totals,
  loading,
  error,
}: {
  monthly: boolean;
  rows: AgentReportRow[];
  totals: AgentReportRow | null;
  loading: boolean;
  error: string;
}) {
  return (
    <div className="business-report-table-wrap">
      <table className="business-report-table">
        <thead>
          <tr>
            <th className="report-account-head" rowSpan={2}>{monthly ? "期号" : "会员"}</th>
            <th className="report-member-group" colSpan={5}>会员</th>
            <th className="report-agent-group" colSpan={5}>代理</th>
            <th className="report-master-group" colSpan={2}>总代理</th>
          </tr>
          <tr>
            {["笔数", "总投", "总中", "总赚水", "盈亏"].map((item) => <th className="report-member-group" key={`member-${item}`}>{item}</th>)}
            {["占成金额", "占成盈亏"].map((item) => <th className="report-agent-group" key={`agent-${item}`}>{item}</th>)}
            {["离线赚水", "总赚水", "总盈亏"].map((item) => <th className="report-agent-profit" key={`profit-${item}`}>{item}</th>)}
            {["总投", "盈亏"].map((item) => <th className="report-master-group" key={`master-${item}`}>{item}</th>)}
          </tr>
        </thead>
        <tbody>
          {loading ? (
            <tr><td className="utility-empty" colSpan={13}><span className="report-spinner" aria-label="正在查询" /></td></tr>
          ) : error ? (
            <tr><td className="utility-empty results-error" colSpan={13}>{error}</td></tr>
          ) : <>
            {rows.map((row, index) => <tr key={`${row.label}-${index}`}><ReportRowCells row={row} /></tr>)}
            {totals && <tr><ReportRowCells row={totals} /></tr>}
          </>}
        </tbody>
      </table>
    </div>
  );
}

function normalizeResultIssue(value: string | null | undefined) {
  const digits = String(value || "").replace(/\D/g, "");
  return /^26\d{3}$/.test(digits) ? `20${digits}` : digits || "--";
}

function resultNumbers(row: AgentLotteryHistory): string[] {
  const direct = [row.one, row.two, row.three];
  if (direct.some((value) => value !== null && value !== undefined)) {
    return direct.map((value) => value === null || value === undefined ? "" : String(value));
  }
  const parts = String(row.numbers || "").trim().split(/\s+/).filter(Boolean);
  return [parts[0] || "", parts[1] || "", parts[2] || ""];
}

function resultTime(row: AgentLotteryHistory) {
  if (row.open_time) return row.open_time.replace("T", " ");
  if (row.draw_day) return `${row.draw_day} -- : -- : --`;
  return "-- : -- : --";
}

function resultSummary(numbers: string[]) {
  if (numbers.some((value) => value === "")) return "---";
  const total = numbers.reduce((sum, value) => sum + Number(value), 0);
  return `${total} / ${total >= 14 ? "大" : "小"} / ${total % 2 === 0 ? "双" : "单"}`;
}

function resultSpan(numbers: string[]) {
  if (numbers.some((value) => value === "")) return "---";
  const values = numbers.map(Number);
  return String(Math.max(...values) - Math.min(...values));
}

function ResultsNumbersPage({ lotteries, selectedLotteryId }: { lotteries: Lottery[]; selectedLotteryId?: number | null }) {
  const [lotteryId, setLotteryId] = useState<number | null>(() => selectedLotteryId ?? lotteries[0]?.id ?? null);
  const [rows, setRows] = useState<AgentLotteryHistory[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    const fallback = lotteries[0]?.id ?? null;
    const next = selectedLotteryId !== undefined && selectedLotteryId !== null
      ? selectedLotteryId
      : fallback;
    setLotteryId(lotteries.some((item) => item.id === next) ? next : fallback);
  }, [lotteries, selectedLotteryId]);

  useEffect(() => {
    if (lotteryId === null) {
      setRows([]);
      setLoading(false);
      return;
    }
    let active = true;
    setLoading(true);
    setError("");
    void getAgentLotteryHistory(lotteryId, { page: 1, page_size: 16 })
      .then((response) => {
        if (!active) return;
        setRows(response.data.data?.list || []);
      })
      .catch((reason: unknown) => {
        if (!active) return;
        setRows([]);
        setError(reason instanceof Error ? reason.message : "开奖数据加载失败");
      })
      .finally(() => {
        if (active) setLoading(false);
      });
    return () => {
      active = false;
    };
  }, [lotteryId]);


  return (
    <section className="results-numbers-page">
      <div className="results-breadcrumb"><span>位置</span><b>»</b><strong>开奖号码</strong></div>
      <div className="results-card">
        <table className="results-numbers-table">
          <thead><tr><th>期号</th><th>开奖时间</th><th>佰</th><th>拾</th><th>个</th><th>和值</th><th>跨度</th></tr></thead>
          <tbody>
            {loading ? (
              <tr><td className="results-status-cell" colSpan={7}><span className="report-spinner" aria-label="正在查询" /></td></tr>
            ) : error ? (
              <tr><td className="results-status-cell results-error" colSpan={7}>{error}</td></tr>
            ) : rows.length === 0 ? (
              <tr><td className="results-status-cell" colSpan={7}><Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="暂无数据" /></td></tr>
            ) : rows.map((row) => {
              const numbers = resultNumbers(row);
              return (
                <tr key={row.id}>
                  <td className="result-issue">{normalizeResultIssue(row.code)}</td>
                  <td>{resultTime(row)}</td>
                  {numbers.map((number, index) => <td key={`${row.id}-${index}`}><span className={number ? "result-ball" : "result-ball pending"}>{number}</span></td>)}
                  <td>{resultSummary(numbers)}</td>
                  <td className="result-span">{resultSpan(numbers)}</td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </section>
  );
}

function ReportDateInput({ label, value, onChange }: { label: string; value: string; onChange: (value: string) => void }) {
  const inputRef = useRef<HTMLInputElement>(null);

  function openPicker() {
    const input = inputRef.current;
    if (!input) return;
    if (typeof input.showPicker === "function") input.showPicker();
    else input.click();
  }

  return (
    <div className="business-report-date-control">
      <button className="business-report-date-input" type="button" aria-label={label} onClick={openPicker}>
        <span>{value}</span>
        <CalendarOutlined aria-hidden="true" />
      </button>
      <input ref={inputRef} className="business-report-native-date" type="date" value={value} tabIndex={-1} onChange={(event) => onChange(event.target.value)} />
    </div>
  );
}

function ReportsPage({ lotteries }: { lotteries: Lottery[] }) {
  const [active, setActive] = useState<"综合报表" | "月报表">("综合报表");
  const [welfare, setWelfare] = useState(true);
  const [sports, setSports] = useState(true);
  const today = useMemo(() => formatReportDate(new Date()), []);
  const [fromDate, setFromDate] = useState(today);
  const [toDate, setToDate] = useState(today);
  const issues = useMemo(() => issueOptions(lotteries), [lotteries]);
  const [fromIssue, setFromIssue] = useState(issues[issues.length - 1] || "");
  const [toIssue, setToIssue] = useState(issues[0] || "");
  const [period, setPeriod] = useState("今天");
  const [rows, setRows] = useState<AgentReportRow[]>([]);
  const [totals, setTotals] = useState<AgentReportRow | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    if (issues.length === 0) return;
    setFromIssue((value) => issues.includes(value) ? value : issues[issues.length - 1]);
    setToIssue((value) => issues.includes(value) ? value : issues[0]);
  }, [issues]);

  useEffect(() => {
    let cancelled = false;
    const selectedLotteries = [welfare ? "福彩3D" : "", sports ? "排列三" : ""].filter(Boolean).join(",");
    const params = active === "综合报表"
      ? { type: "summary", from: fromDate, to: toDate, lotteries: selectedLotteries || "__none__", page: 1, page_size: 100 }
      : { type: "monthly", from_issue: issueValue(fromIssue), to_issue: issueValue(toIssue), lotteries: selectedLotteries || "__none__", page: 1, page_size: 100 };
    setLoading(true);
    setError("");
    void getAgentReports(params)
      .then((response) => {
        if (cancelled) return;
        setRows(response.data.data?.list || []);
        setTotals(response.data.data?.totals || null);
      })
      .catch((reason: unknown) => {
        if (cancelled) return;
        setRows([]);
        setTotals(null);
        setError(apiErrorMessage(reason, "报表加载失败"));
      })
      .finally(() => {
        if (!cancelled) setLoading(false);
      });
    return () => { cancelled = true; };
  }, [active, fromDate, fromIssue, sports, toDate, toIssue, welfare]);

  function chooseDatePeriod(value: string) {
    const [from, to] = reportDateRange(value);
    setPeriod(value);
    setFromDate(from);
    setToDate(to);
  }

  function chooseIssuePeriod(value: string) {
    setPeriod(value);
    const ranges: Record<string, [number, number]> = {
      全部: [issues.length - 1, 0],
      今天: [0, 0],
      昨天: [1, 1],
      本周: [Math.min(6, issues.length - 1), 0],
      上周: [Math.min(13, issues.length - 1), Math.min(7, issues.length - 1)],
      "2026年08月": [issues.length - 1, 0],
    };
    const [fromIndex, toIndex] = ranges[value] || [issues.length - 1, 0];
    setFromIssue(issues[fromIndex] || issues[0] || "");
    setToIssue(issues[toIndex] || issues[0] || "");
  }

  return (
    <section className="business-report-page">
      <div className="business-report-topbar">
        <div className="business-report-breadcrumb"><span>位置</span><b>»</b><span>报表</span><b>»</b><strong>{active}</strong></div>
        <nav className="business-report-subnav" aria-label="报表导航">
          {(["综合报表", "月报表"] as const).map((tab) => (
            <button key={tab} type="button" className={active === tab ? "active" : ""} onClick={() => { setActive(tab); setPeriod(tab === "综合报表" ? "今天" : "全部"); }}>{tab}</button>
          ))}
        </nav>
      </div>

      {active === "综合报表" && (
        <div className="business-report-date-filter">
          <label><input type="checkbox" checked={welfare} onChange={(event) => setWelfare(event.target.checked)} />福</label>
          <label><input type="checkbox" checked={sports} onChange={(event) => setSports(event.target.checked)} />体</label>
          <ReportDateInput label="开始日期" value={fromDate} onChange={setFromDate} />
          <span>至</span>
          <ReportDateInput label="结束日期" value={toDate} onChange={setToDate} />
        </div>
      )}

      <div className="business-report-periodbar">
        <div className="business-report-period-left">
          <strong>{active}</strong>
          {(active === "综合报表" ? ["2026年08月", "今天", "昨天", "本周", "上周"] : ["2026年08月", "全部", "今天", "昨天", "本周", "上周"]).map((item) => (
            <button key={item} type="button" className={period === item ? "active" : ""} onClick={() => active === "综合报表" ? chooseDatePeriod(item) : chooseIssuePeriod(item)}>{item}</button>
          ))}
        </div>
        {active === "月报表" && (
          <div className="business-report-issue-range">
            <select value={fromIssue} aria-label="开始期号" onChange={(event) => setFromIssue(event.target.value)}>{issues.map((item) => <option key={item}>{item}</option>)}</select>
            <span>至</span>
            <select value={toIssue} aria-label="结束期号" onChange={(event) => setToIssue(event.target.value)}>{issues.map((item) => <option key={item}>{item}</option>)}</select>
          </div>
        )}
      </div>

      <ReportTotalsTable monthly={active === "月报表"} rows={rows} totals={totals} loading={loading} error={error} />
    </section>
  );
}

function GenericPage({ kind, lotteries }: Props) {
  const meta = pageMeta[kind as Exclude<UtilityKind, "ledger">];
  const [activeTab, setActiveTab] = useState(meta.tabs[0]);
  const [keyword, setKeyword] = useState("");
  const [loading, setLoading] = useState(false);
  const [saved, setSaved] = useState(false);
  const isSettings = kind === "settings";
  const isIntercept = kind === "intercept";

  function submit() {
    setSaved(false); setLoading(true); window.setTimeout(() => setLoading(false), 360);
  }

  return (
    <section className="utility-page">
      <div className="utility-toolbar"><div className="utility-title">{meta.title}</div><div className="utility-tabs">{meta.tabs.map((tab) => <button key={tab} type="button" className={activeTab === tab ? "active" : ""} onClick={() => setActiveTab(tab)}>{tab}</button>)}</div></div>
      <form className="utility-filters" onSubmit={(event) => { event.preventDefault(); submit(); }}>
        <label className="utility-field"><span>{kind === "logs" ? "关键词" : "查账号"}</span><input value={keyword} onChange={(event) => setKeyword(event.target.value)} placeholder={kind === "logs" ? "账号或操作内容" : "输入账号"} /></label>
        {kind !== "logs" && <><label className="utility-field"><span>开始日期</span><input type="date" /></label><label className="utility-field"><span>结束日期</span><input type="date" /></label></>}
        {kind === "results" && <label className="utility-field"><span>彩种</span><select><option value="">全部彩种</option>{lotteries.map((lottery) => <option key={lottery.id}>{lottery.name}</option>)}</select></label>}
        <button className="utility-submit" type="submit">提 交</button>
      </form>
      {isSettings || isIntercept ? <section className="utility-settings"><div className="utility-setting-row"><label>{isIntercept ? "拦货比例" : "默认线路"}<input defaultValue={isIntercept ? "0" : "主线路"} /></label><label>状态<select defaultValue="on"><option value="on">开启</option><option value="off">关闭</option></select></label></div><button className="utility-submit" type="button" onClick={() => { setSaved(true); window.setTimeout(() => setSaved(false), 1800); }}>保存设置</button>{saved && <span className="utility-saved">设置已保存</span>}{isIntercept && <EmptyTable columns={["时间", "会员账号", "期号", "拦货金额", "状态"]} />}</section> : <EmptyTable columns={kind === "results" ? ["彩种", "期号", "开奖时间", "开奖号码", "和值", "大小", "单双"] : ["日期", "期号", "注单数", "下注金额", "中奖金额", "赚水"]} loading={loading} />}
    </section>
  );
}

export function NavigationPage(props: Props) {
  if (props.kind === "ledger") return <LedgerPage lotteries={props.lotteries} />;
  if (props.kind === "reports") return <ReportsPage lotteries={props.lotteries} />;
  if (props.kind === "results") return <ResultsNumbersPage lotteries={props.lotteries} selectedLotteryId={props.selectedLotteryId} />;
  return <GenericPage {...props} />;
}
