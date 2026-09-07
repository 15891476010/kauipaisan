import { useEffect, useState } from "react";
import { Modal, Spin } from "antd";
import { getAgentOrderDetails, type AgentOrderDetail } from "../api/user";
import { apiErrorMessage } from "../utils/request";
import "./OrderDetailsModal.css";

const CANVAS_WIDTH = 1500;
const initialFilter = { number: "", group: false, metric: "odds", min: "", max: "", category: "所有" };
const columns = [
  ["注单编号", 120], ["会员", 105], ["下单时间", 195], ["号码", 95],
  ["下注金额", 90], ["赔率", 90], ["中奖", 95], ["下线回水", 100],
  ["实收下线", 100], ["自己回水", 100], ["实付上线", 100], ["赚水", 95], ["路径", 100],
] as const;

function money(value: unknown): string {
  const n = Number(value);
  return Number.isFinite(n) ? Number(n.toFixed(2)).toString() : "0";
}

function BetNumber({ row }: { row: AgentOrderDetail }) {
  const value = row.number_text || row.play_type || "";
  return <div className="detail-number-text" title={value}>{value.split(/(组三|组六|组|直)/).map((part, i) =>
    /^(组三|组六|组|直)$/.test(part) ? <em key={i}>{part}</em> : <span key={i}>{part}</span>
  )}</div>;
}

export function OrderDetailsModal({ recordId, orderNo, lotteryId, onClose }: {
  recordId: number; orderNo: string; lotteryId: number | null; onClose: () => void;
}) {
  const [scale, setScale] = useState(() => window.innerWidth * 0.98 / CANVAS_WIDTH);
  const [draft, setDraft] = useState(initialFilter);
  const [filter, setFilter] = useState(initialFilter);
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(40);
  const [rows, setRows] = useState<AgentOrderDetail[]>([]);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");
  const [version, setVersion] = useState(0);
  const [resolvedOrderNo, setResolvedOrderNo] = useState(orderNo);

  useEffect(() => {
    const resize = () => setScale(window.innerWidth * 0.98 / CANVAS_WIDTH);
    window.addEventListener("resize", resize);
    return () => window.removeEventListener("resize", resize);
  }, []);

  useEffect(() => {
    let active = true;
    setLoading(true);
    setError("");
    void getAgentOrderDetails({
      record_id: recordId, lottery_id: lotteryId || undefined, include_refunded: 1,
      page, page_size: pageSize, number: filter.number.trim() || undefined,
      metric: filter.metric, min: filter.min || undefined, max: filter.max || undefined,
      category: filter.group ? "组选" : filter.category === "所有" ? undefined : filter.category,
    }).then(response => {
      if (!active) return;
      const data = response.data.data;
      setRows(data.list || []);
      setTotal(Number(data.total || 0));
      if (!orderNo && data.list?.[0]?.order_no) setResolvedOrderNo(data.list[0].order_no);
    }).catch(reason => {
      if (!active) return;
      setRows([]);
      setTotal(0);
      setError(apiErrorMessage(reason, "注单明细加载失败，请重试"));
    }).finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [recordId, lotteryId, orderNo, page, pageSize, filter, version]);

  const sum = (key: keyof AgentOrderDetail) => money(rows.reduce((value, row) =>
    value + Math.round((Number(row[key]) || 0) * 100), 0) / 100);
  const maxPage = Math.max(1, Math.ceil(total / pageSize));
  return <Modal
    getContainer={() => document.body}
    className="overview-detail-modal reference-order-detail"
    transitionName=""
    title={`明细注单编号： ${resolvedOrderNo || recordId}`}
    open footer={null} width={CANVAS_WIDTH} onCancel={onClose}
    style={{ width: CANVAS_WIDTH, maxWidth: "none", margin: 0, left: "50%",
      top: 100, transform: `translateX(-50%) scale(${scale})`, transformOrigin: "top center" }}
  >
    <form className="detail-query" onSubmit={event => { event.preventDefault(); setPage(1); setFilter({ ...draft }); setVersion(value => value + 1); }}>
      <fieldset className="detail-number-filter"><legend>查号码</legend><input aria-label="查号码" placeholder="查号码" value={draft.number} onChange={e => setDraft({ ...draft, number: e.target.value })} /></fieldset>
      <fieldset className="detail-group-filter"><legend><label><input type="checkbox" checked={draft.group} onChange={e => setDraft({ ...draft, group: e.target.checked })} />组</label></legend></fieldset>
      <fieldset className="detail-range-filter"><legend>列出</legend><select aria-label="列出指标" value={draft.metric} onChange={e => setDraft({ ...draft, metric: e.target.value })}><option value="odds">赔率</option><option value="amount">金额</option></select><input aria-label="最小值" type="number" step="any" value={draft.min} onChange={e => setDraft({ ...draft, min: e.target.value })} /><span>至</span><input aria-label="最大值" type="number" step="any" value={draft.max} onChange={e => setDraft({ ...draft, max: e.target.value })} /></fieldset>
      <fieldset className="detail-category-filter"><legend>分类</legend><select aria-label="分类" disabled={draft.group} value={draft.category} onChange={e => setDraft({ ...draft, category: e.target.value })}>{["所有", "直选", "组选", "组三", "组六", "复式", "独胆", "双飞", "对子", "豹子", "和值", "跨度"].map(item => <option key={item}>{item}</option>)}</select></fieldset>
      <fieldset className="detail-submit-filter"><button type="submit" disabled={loading}>提交</button></fieldset>
    </form>
    <section className="detail-table-panel">
      <div className="detail-table-caption">总货明细</div>
      <div className="detail-table-viewport" aria-busy={loading}>
        {loading ? <div className="detail-state"><Spin /></div> : error ? <div role="alert" className="detail-state detail-load-error">{error}<button onClick={() => setVersion(value => value + 1)}>重试</button></div> : <table className="reference-detail-table">
          <colgroup>{columns.map(([title, width]) => <col key={title} style={{ width: `${width / 1385 * 100}%` }} />)}</colgroup>
          <thead><tr>{columns.map(([title]) => <th key={title}>{title}</th>)}</tr></thead>
          <tbody>{rows.length ? rows.map(row => <tr key={row.id}>
            <td>{row.order_no}</td><td>{row.username}</td><td>{row.placed_at}</td><td><BetNumber row={row} /></td>
            <td className="detail-stake">{money(row.amount)}</td><td>{row.odds || "---"}</td><td>{row.status === "pending" ? "---" : money(row.win_amount)}</td>
            <td>{money(row.downline_rebate)}</td><td>{row.received_amount == null ? "---" : money(row.received_amount)}</td>
            <td>{row.own_rebate == null ? "---" : money(row.own_rebate)}</td><td>{row.paid_upstream == null ? "---" : money(row.paid_upstream)}</td>
            <td>{money(row.rebate_profit)}</td><td className="detail-source">{row.source || "---"}<span>{row.source === "快录" ? "网" : ""}</span></td>
          </tr>) : <tr><td colSpan={13} className="detail-empty">暂无明细</td></tr>}</tbody>
          <tfoot><tr title="当前页明细合计"><td>{total > pageSize ? "本页合计" : "合计"}</td><td /><td /><td /><td className="detail-stake">{sum("amount")}</td><td /><td>{sum("win_amount")}</td><td>{sum("downline_rebate")}</td><td>{sum("received_amount")}</td><td>{sum("own_rebate")}</td><td>{sum("paid_upstream")}</td><td>{sum("rebate_profit")}</td><td /></tr></tfoot>
        </table>}
      </div>
      <div className="detail-pagination"><span>总计： <b>{total}</b> 条数据</span><button aria-label="上一页" disabled={loading || page <= 1} onClick={() => setPage(value => value - 1)}>‹</button><span className="detail-current-page">{page}</span><button aria-label="下一页" disabled={loading || page >= maxPage} onClick={() => setPage(value => value + 1)}>›</button><select aria-label="每页条数" disabled={loading} value={pageSize} onChange={e => { setPageSize(Number(e.target.value)); setPage(1); }}>{[10, 40, 100].map(size => <option key={size} value={size}>{size} 条/页</option>)}</select></div>
    </section>
    <footer className="detail-close-footer"><button onClick={onClose}>关闭</button></footer>
  </Modal>;
}
