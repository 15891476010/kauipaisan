import { useEffect, useMemo, useState } from "react";
import { Empty, Modal } from "antd";
import {
  getAgentBetRecords,
  getAgentOrderDetails,
  getAgentRefunds,
  getAgentReportCategories,
  getAgentWinningDetails,
  type AgentBetRecord,
  type AgentDetailRow,
  type AgentRefundRecord,
} from "../../api/user";

type DetailTab = "总货明细" | "中奖明细";
type ReportTab = DetailTab | "投注明细" | "查看退码";
type Props = { activeTab: ReportTab; dateOptions: string[]; lotteryId: number | null; accountFilter?: string };

const pageSizes = [20, 40, 80, 100];

function issueValue(label: string) {
  return label.match(/\((\d+)\)/)?.[1] || label.replace(/\D/g, "");
}

function todayRange() {
  const date = new Date();
  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, "0");
  const day = String(date.getDate()).padStart(2, "0");
  return { from: `${year}-${month}-${day}T00:00`, to: `${year}-${month}-${day}T23:59` };
}

function dbTime(value: string) {
  return value ? `${value.replace("T", " ")}:00` : "";
}

function money(value?: string | number | null) {
  const amount = Number(value || 0);
  return Number.isFinite(amount) ? amount.toFixed(2) : "0.00";
}

function Pagination({ page, pageSize, total, onChange }: { page: number; pageSize: number; total: number; onChange: (page: number, pageSize: number) => void }) {
  const pages = Math.max(1, Math.ceil(total / pageSize));
  return (
    <div className="report-pagination">
      <span>总计： {total} 条数据</span>
      <button type="button" disabled={page <= 1} onClick={() => onChange(page - 1, pageSize)}>‹</button>
      <b>{page}</b>
      <button type="button" disabled={page >= pages} onClick={() => onChange(page + 1, pageSize)}>›</button>
      <select value={pageSize} onChange={(event) => onChange(1, Number(event.target.value))} aria-label="每页条数">
        {pageSizes.map((size) => <option key={size} value={size}>{size} 条/页</option>)}
      </select>
    </div>
  );
}

function EmptyRows({ colSpan, loading }: { colSpan: number; loading: boolean }) {
  return (
    <tbody><tr><td className="report-empty-cell" colSpan={colSpan}>
      {loading ? <span className="report-spinner" role="status" aria-label="正在查询" /> : <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="暂无数据" />}
    </td></tr></tbody>
  );
}

export function OverviewReportPanel({ activeTab, dateOptions, lotteryId, accountFilter = "" }: Props) {
  const issues = useMemo(() => dateOptions.map((label) => ({ label, value: issueValue(label) })), [dateOptions]);
  const initialTime = useMemo(todayRange, []);
  const [categories, setCategories] = useState<string[]>([]);
  const [account, setAccount] = useState(accountFilter);
  const [number, setNumber] = useState("");
  const [grouped, setGrouped] = useState(false);
  const [metric, setMetric] = useState("odds");
  const [minimum, setMinimum] = useState("");
  const [maximum, setMaximum] = useState("");
  const [category, setCategory] = useState("所有");
  const [source, setSource] = useState("all");
  const [device, setDevice] = useState("all");
  const [sort, setSort] = useState("desc");
  const [winningStatus, setWinningStatus] = useState("all");
  const [sourceText, setSourceText] = useState("");
  const [fromTime, setFromTime] = useState(initialTime.from);
  const [toTime, setToTime] = useState(initialTime.to);
  const [fromIssue, setFromIssue] = useState(issues[0]?.value || "");
  const [toIssue, setToIssue] = useState(issues[0]?.value || "");
  const [detailRows, setDetailRows] = useState<AgentDetailRow[]>([]);
  const [betRows, setBetRows] = useState<AgentBetRecord[]>([]);
  const [refundRows, setRefundRows] = useState<AgentRefundRecord[]>([]);
  const [refundDetails, setRefundDetails] = useState<AgentDetailRow[]>([]);
  const [refundVisible, setRefundVisible] = useState(false);
  const [refundLoading, setRefundLoading] = useState(false);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [total, setTotal] = useState(0);
  const [totalAmount, setTotalAmount] = useState("0.00");
  const [winAmount, setWinAmount] = useState("0.00");
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(40);

  useEffect(() => {
    setAccount(accountFilter);
  }, [accountFilter]);

  useEffect(() => {
    void getAgentReportCategories().then((response) => setCategories(response.data.data?.list || [])).catch(() => setCategories([]));
  }, []);

  useEffect(() => {
    const first = issues[0]?.value || "";
    setFromIssue((current) => issues.some((item) => item.value === current) ? current : first);
    setToIssue((current) => issues.some((item) => item.value === current) ? current : first);
  }, [issues]);

  async function query(nextPage = page, nextPageSize = pageSize, tab: ReportTab = activeTab) {
    const startedAt = Date.now();
    setLoading(true);
    setError("");
    try {
      if (tab === "总货明细" || tab === "中奖明细") {
        const params = { lottery_id: lotteryId || undefined, account, number, grouped: grouped ? 1 : 0, metric, min: minimum, max: maximum, category, source, device, sort, from_issue: fromIssue, to_issue: toIssue, page: nextPage, page_size: nextPageSize };
        const response = tab === "中奖明细" ? await getAgentWinningDetails(params) : await getAgentOrderDetails(params);
        const data = response.data.data;
        setDetailRows(data?.list || []);
        setTotal(data?.total || 0);
        setTotalAmount(data?.total_amount || "0.00");
        setWinAmount(data?.win_amount || "0.00");
      } else if (tab === "投注明细") {
        const response = await getAgentBetRecords({ lottery_id: lotteryId || undefined, account, source_text: sourceText, status: winningStatus, from: dbTime(fromTime), to: dbTime(toTime), page: nextPage, page_size: nextPageSize });
        const data = response.data.data;
        setBetRows(data?.list || []);
        setTotal(data?.total || 0);
      } else {
        const response = await getAgentRefunds({ lottery_id: lotteryId || undefined, account, from_issue: fromIssue, to_issue: toIssue, page: nextPage, page_size: nextPageSize });
        const data = response.data.data;
        setRefundRows(data?.list || []);
        setTotal(data?.total || 0);
      }
      setPage(nextPage);
      setPageSize(nextPageSize);
    } catch (reason) {
      setError(reason instanceof Error ? reason.message : "查询失败，请稍后重试");
      setDetailRows([]);
      setBetRows([]);
      setRefundRows([]);
      setTotal(0);
    } finally {
      const elapsed = Date.now() - startedAt;
      if (elapsed < 360) await new Promise((resolve) => window.setTimeout(resolve, 360 - elapsed));
      setLoading(false);
    }
  }

  useEffect(() => {
    void query(1, pageSize, activeTab);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [activeTab, lotteryId]);

  async function showRefundDetails(record: AgentRefundRecord) {
    setRefundVisible(true);
    setRefundLoading(true);
    setRefundDetails([]);
    try {
      const response = await getAgentOrderDetails({ record_id: record.id, page: 1, page_size: 100 });
      setRefundDetails(response.data.data?.list || []);
    } catch {
      setRefundDetails([]);
    } finally {
      setRefundLoading(false);
    }
  }

  const issueSelectors = (
    <>
      <select value={fromIssue} onChange={(event) => setFromIssue(event.target.value)} aria-label="开始期号">
        {issues.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}
      </select>
      <span>至</span>
      <select value={toIssue} onChange={(event) => setToIssue(event.target.value)} aria-label="结束期号">
        {issues.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}
      </select>
    </>
  );

  return (
    <>
      {activeTab === "投注明细" ? (
        <form key={`filters-${activeTab}`} className="report-filters bet-record-filters" onSubmit={(event) => { event.preventDefault(); void query(1); }}>
          <fieldset className="report-field report-status-field"><legend>中奖</legend><select value={winningStatus} onChange={(event) => setWinningStatus(event.target.value)} aria-label="中奖状态"><option value="all">全部</option><option value="won">仅中奖</option><option value="unwon">未中奖</option></select></fieldset>
          <fieldset className="report-field report-account-field"><legend>会员账号</legend><input value={account} onChange={(event) => setAccount(event.target.value)} placeholder="搜索会员名" /></fieldset>
          <fieldset className="report-field report-source-text-field"><legend>原始文本搜索</legend><input value={sourceText} onChange={(event) => setSourceText(event.target.value)} placeholder="输入原始文本" /></fieldset>
          <fieldset className="report-field report-time-field"><legend>投注时间</legend><input type="datetime-local" value={fromTime} onChange={(event) => setFromTime(event.target.value)} /><span>至</span><input type="datetime-local" value={toTime} onChange={(event) => setToTime(event.target.value)} /></fieldset>
          <button className="report-search" type="submit">搜 索</button>
        </form>
      ) : activeTab === "查看退码" ? (
        <form key={`filters-${activeTab}`} className="report-filters refund-filters" onSubmit={(event) => { event.preventDefault(); void query(1); }}>
          <fieldset className="report-field report-account-field"><legend>查账号</legend><input value={account} onChange={(event) => setAccount(event.target.value)} placeholder="查账号" /></fieldset>
          <button className="report-submit" type="submit">提 交</button>
        </form>
      ) : (
        <form key={`filters-${activeTab}`} className="report-filters detail-filters" onSubmit={(event) => { event.preventDefault(); void query(1); }}>
          <fieldset className="report-field report-account-field"><legend>查账号</legend><input value={account} onChange={(event) => setAccount(event.target.value)} placeholder="查账号" /></fieldset>
          <fieldset className="report-field report-number-field"><legend>查号码</legend><input value={number} onChange={(event) => setNumber(event.target.value)} placeholder="查号码" /></fieldset>
          <label className="report-check"><span>组</span><input type="checkbox" checked={grouped} onChange={(event) => setGrouped(event.target.checked)} /></label>
          <fieldset className="report-field report-range-field"><legend>列出</legend><select value={metric} onChange={(event) => setMetric(event.target.value)}><option value="odds">赔率</option><option value="amount">金额</option></select><input value={minimum} onChange={(event) => setMinimum(event.target.value)} inputMode="decimal" aria-label="最小值" /><span>至</span><input value={maximum} onChange={(event) => setMaximum(event.target.value)} inputMode="decimal" aria-label="最大值" /></fieldset>
          <fieldset className="report-field report-category-field"><legend>分类</legend><select value={category} onChange={(event) => setCategory(event.target.value)}><option>所有</option>{categories.map((item) => <option key={item}>{item}</option>)}</select></fieldset>
          {activeTab === "中奖明细" ? <><fieldset className="report-field report-mini-field"><legend>来源</legend><select value={source} onChange={(event) => setSource(event.target.value)}><option value="all">全部</option><option value="quick">快录</option></select></fieldset><fieldset className="report-field report-mini-field"><legend>设备</legend><select value={device} onChange={(event) => setDevice(event.target.value)}><option value="all">全部</option><option value="web">网</option><option value="manual">手</option></select></fieldset><fieldset className="report-field report-sort-field"><legend>按下注时间排序</legend><label><input type="radio" name="sort" value="desc" checked={sort === "desc"} onChange={() => setSort("desc")} />倒序</label><label><input type="radio" name="sort" value="asc" checked={sort === "asc"} onChange={() => setSort("asc")} />正序</label></fieldset></> : null}
          <button className="report-submit" type="submit">提 交</button>
        </form>
      )}

      {error ? <div className="report-error">{error}</div> : null}

      <section key={`panel-${activeTab}`} className="report-table-panel">
        <div className="report-table-title"><strong>{activeTab}</strong>{activeTab === "投注明细" ? null : issueSelectors}</div>

        {activeTab === "总货明细" ? (
          <div className="report-table-scroll"><table className="report-table report-detail-table">
            <thead><tr><th>注单编号</th><th>会员</th><th>下单时间</th><th>号码</th><th>下注金额</th><th>赔率</th><th>中奖</th><th>下线回水</th><th>实收下线</th><th>自己回水</th><th>实付上线</th><th>赚水</th></tr></thead>
            {detailRows.length ? <tbody>{detailRows.map((row) => <tr key={row.id}><td>{row.order_no}</td><td>{row.username}</td><td>{row.placed_at}</td><td title={row.number_text}>{grouped ? row.number_text.replace(/\s+/g, " / ") : row.number_text}</td><td>{row.amount}</td><td>{row.odds}</td><td>{row.win_amount}</td><td>{row.downline_rebate}</td><td>{row.received_amount}</td><td>{row.own_rebate}</td><td>{row.paid_upstream}</td><td>{row.rebate_profit}</td></tr>)}</tbody> : <EmptyRows colSpan={12} loading={loading} />}
          </table></div>
        ) : activeTab === "中奖明细" ? (
          <div className="report-table-scroll"><table className="report-table report-winning-table">
            <thead><tr><th>注单编号</th><th>会员</th><th>下单时间</th><th>号码</th><th>下注金额</th><th>赔率</th><th>中奖</th><th>下线回水</th><th>实收下线</th><th>路径</th><th>小票或全截</th></tr></thead>
            {detailRows.length ? <tbody>{detailRows.map((row) => <tr key={row.id}><td>{row.order_no}</td><td>{row.username}</td><td>{row.placed_at}</td><td title={row.number_text}>{row.number_text}</td><td>{row.amount}</td><td>{row.odds}</td><td>{row.win_amount}</td><td>{row.downline_rebate}</td><td>{row.received_amount}</td><td>{row.path}</td><td className="report-ticket" title={row.ticket}>{row.ticket || "-"}</td></tr>)}</tbody> : <EmptyRows colSpan={11} loading={loading} />}
          </table></div>
        ) : activeTab === "投注明细" ? (
          <div className="report-table-scroll"><table className="report-table report-record-table">
            <thead><tr><th>会员账号</th><th>期号</th><th>笔数/金额</th><th>中奖笔数/金额</th><th>文本</th><th>封盘情况</th><th>投注时间</th></tr></thead>
            {betRows.length ? <tbody>{betRows.map((row) => <tr key={row.id}><td>{row.username}</td><td>{row.issue_no}</td><td>{row.bet_count} / {row.amount}</td><td>{row.win_count} / {row.win_amount}</td><td className="report-text-cell" title={row.source_text}>{row.source_text}</td><td>{row.sealed_label}</td><td>{row.placed_at}</td></tr>)}</tbody> : <EmptyRows colSpan={7} loading={loading} />}
          </table></div>
        ) : (
          <div className="report-table-scroll"><table className="report-table report-refund-table">
            <thead><tr><th>编号</th><th>会员名</th><th>期号</th><th>退码时间</th><th>注单数量</th><th>注单总额</th><th>操作</th></tr></thead>
            {refundRows.length ? <tbody>{refundRows.map((row) => <tr key={row.id}><td>{row.id}</td><td>{row.username}</td><td>{row.issue_no}</td><td>{row.refunded_at}</td><td>{row.bet_count}</td><td>{row.amount}</td><td><button className="report-link-button" type="button" onClick={() => void showRefundDetails(row)}>查看注单</button></td></tr>)}</tbody> : <EmptyRows colSpan={7} loading={loading} />}
          </table></div>
        )}

        {activeTab === "中奖明细" ? <div className="report-summary"><span>总下注金额(仅中奖注单)： <b>{money(totalAmount)}</b></span><span>总货中奖金额： <b>{money(winAmount)}</b></span></div> : null}
        <Pagination page={page} pageSize={pageSize} total={total} onChange={(nextPage, nextSize) => void query(nextPage, nextSize)} />
      </section>

      <Modal open={refundVisible} title="退码注单详情" width={920} footer={<button className="report-modal-close" type="button" onClick={() => setRefundVisible(false)}>关 闭</button>} onCancel={() => setRefundVisible(false)}>
        <div className="refund-detail-scroll"><table className="report-table">
          <thead><tr><th>注单编号</th><th>会员</th><th>号码</th><th>分类</th><th>金额</th><th>赔率</th><th>下单时间</th></tr></thead>
          {refundDetails.length ? <tbody>{refundDetails.map((row) => <tr key={row.id}><td>{row.order_no}</td><td>{row.username}</td><td>{row.number_text}</td><td>{row.play_type || row.category}</td><td>{row.amount}</td><td>{row.odds}</td><td>{row.placed_at}</td></tr>)}</tbody> : <EmptyRows colSpan={7} loading={refundLoading} />}
        </table></div>
      </Modal>
    </>
  );
}
