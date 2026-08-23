import { useEffect, useMemo, useState } from "react";
import { App as AntdApp, Empty, Modal } from "antd";
import dayjs from "dayjs";
import { FileTextOutlined, SearchOutlined } from "@ant-design/icons";
import {
  getBetDetails,
  type BetDetail,
  type Lottery,
} from "../../../api/user";
import { apiErrorMessage } from "../../../utils/request";

const DETAIL_PAGE_SIZE = 40;

const categories = [
  "所有", "一码定位", "口XX", "X口X", "XX口", "二码定位", "口口X", "口X口", "X口口",
  "直选", "独胆", "双飞", "组选", "组三多码", "组三二码", "组三三码", "组三四码", "组三五码",
  "组三六码", "组三七码", "组三八码", "组三九码", "组三全包", "组六多码", "组六四码", "组六五码",
  "组六六码", "组六七码", "组六八码", "组六九码", "组六全包", "复式多码", "跨度", "和值", "大小单双",
  "豹子全包", "对子全包",
];

type DetailTotals = {
  amount: string;
  win_amount: string;
  rebate: string;
  offline_rebate: string;
  profit: string;
};

const emptyTotals: DetailTotals = {
  amount: "0.00", win_amount: "0.00", rebate: "0.00", offline_rebate: "0.00", profit: "0.00",
};

const statusLabels: Record<string, string> = {
  pending: "未结算", won: "已结算", unwon: "已结算", refunded: "已退码", cancelled: "已取消", failed: "失败",
};

function numberValue(value: unknown): number {
  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : 0;
}

function rowProfit(row: BetDetail): string {
  if (row.profit !== undefined) return row.profit;
  return (numberValue(row.win_amount) - numberValue(row.amount) + numberValue(row.rebate) + numberValue(row.offline_rebate)).toFixed(2);
}

function detailOrderKey(row: BetDetail): string {
  return String(row.order_no || row.submission_id || row.bet_record_id || row.id);
}

function pageNumbers(page: number, pageCount: number): Array<number | "ellipsis-left" | "ellipsis-right"> {
  if (pageCount <= 7) return Array.from({ length: pageCount }, (_, index) => index + 1);
  const values: Array<number | "ellipsis-left" | "ellipsis-right"> = [1];
  if (page > 4) values.push("ellipsis-left");
  const start = Math.max(2, page - 2);
  const end = Math.min(pageCount - 1, page + 2);
  for (let value = start; value <= end; value += 1) values.push(value);
  if (page < pageCount - 3) values.push("ellipsis-right");
  values.push(pageCount);
  return values;
}

export function BetDetailsPage({ lotteries, selectedLotteryId }: { lotteries: Lottery[]; selectedLotteryId: number | null }) {
  const { message } = AntdApp.useApp();
  const [rows, setRows] = useState<BetDetail[]>([]);
  const [previewRow, setPreviewRow] = useState<BetDetail>();
  const [previewMode, setPreviewMode] = useState<"text" | "numbers">("text");
  const [number, setNumber] = useState("");
  const [metric, setMetric] = useState("odds");
  const [min, setMin] = useState("");
  const [max, setMax] = useState("");
  const [category, setCategory] = useState("所有");
  const [sort, setSort] = useState("desc");
  const [winning, setWinning] = useState(false);
  const [loading, setLoading] = useState(false);
  const [issue, setIssue] = useState("");
  const [issues, setIssues] = useState<Array<{ code: string; draw_day: string | null }>>([]);
  const [page, setPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [pageTotals, setPageTotals] = useState<DetailTotals>(emptyTotals);
  const [jumpPage, setJumpPage] = useState("");
  const selectedLottery = lotteries.find((item) => item.id === selectedLotteryId) || lotteries[0];

  const load = (overrides: Record<string, unknown> = {}, targetPage = page) => {
    setLoading(true);
    getBetDetails({ number, metric, min, max, category, sort, lottery: selectedLottery?.name, issue_no: issue || undefined, winning: winning ? 1 : undefined, page: targetPage, page_size: DETAIL_PAGE_SIZE, ...overrides })
      .then((response) => {
        const data = response.data?.data;
        setRows(data?.list || []);
        setTotal(Number(data?.total || 0));
        setPageTotals({ ...emptyTotals, ...(data?.page_total || {}) });
        setPage(Number(data?.page || targetPage));
      })
      .catch((error) => {
        setRows([]); setTotal(0); setPageTotals(emptyTotals);
        message.error(apiErrorMessage(error, "下注明细加载失败"));
      })
      .finally(() => setLoading(false));
  };

  useEffect(() => {
    if (!selectedLottery) return;
    const recent = (selectedLottery.recent_issues || []).slice(0, 40);
    setIssues(recent);
    const nextIssue = recent[0]?.code || "";
    setIssue(nextIssue); setPage(1);
    load({ lottery: selectedLottery.name, issue_no: nextIssue || undefined }, 1);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selectedLottery?.id]);

  const pageCount = Math.max(1, Math.ceil(total / DETAIL_PAGE_SIZE));
  const pageList = useMemo(() => pageNumbers(page, pageCount), [page, pageCount]);
  const search = (overrides: Record<string, unknown> = {}) => { setPage(1); load(overrides, 1); };
  const goPage = (nextPage: number) => {
    const target = Math.min(pageCount, Math.max(1, nextPage));
    if (target === page) return;
    setPage(target); load({}, target);
  };
  const goJumpPage = () => {
    const target = Number.parseInt(jumpPage, 10);
    if (Number.isFinite(target)) goPage(target);
    setJumpPage("");
  };

  return (
    <div className="bet-detail-page">
      <div className="bet-detail-filter">
        <button type="button" className={winning ? "bet-winning active" : "bet-winning"} onClick={() => { const next = !winning; setWinning(next); search({ winning: next ? 1 : undefined }); }}>查看中奖</button>
        <label className="bet-filter-number"><span>查号码</span><input value={number} onChange={(event) => setNumber(event.target.value)} placeholder="查号码" /></label>
        <label className="bet-filter-range"><span>列出</span><select value={metric} onChange={(event) => setMetric(event.target.value)}><option value="odds">赔率</option><option value="amount">金额</option></select><input value={min} inputMode="decimal" onChange={(event) => setMin(event.target.value.replace(/[^\d.]/g, ""))} /><em>至</em><input value={max} inputMode="decimal" onChange={(event) => setMax(event.target.value.replace(/[^\d.]/g, ""))} /></label>
        <label className="bet-filter-category"><span>分类</span><select value={category} onChange={(event) => setCategory(event.target.value)}>{categories.map((item) => <option key={item}>{item}</option>)}</select></label>
        <button type="button" className="bet-filter-search" onClick={() => search()} disabled={loading}><SearchOutlined /> 搜索</button>
      </div>
      <div className="bet-detail-results">
        <div className="bet-detail-sort">
          <strong>总货明细(红色为退码)</strong><span>按下注时间排序:</span>
          <label><input type="radio" name="detail-sort" checked={sort === "desc"} onChange={() => { setSort("desc"); search({ sort: "desc" }); }} />倒序</label>
          <label><input type="radio" name="detail-sort" checked={sort === "asc"} onChange={() => { setSort("asc"); search({ sort: "asc" }); }} />正序</label>
          <select aria-label="期号" value={issue} onChange={(event) => { const next = event.target.value; setIssue(next); search({ issue_no: next }); }}>
            {issues.length ? issues.map((item) => <option key={item.code} value={item.code}>{item.draw_day ? dayjs(item.draw_day).format("M-D") : "--"}({item.code})</option>) : <option value="">暂无期号</option>}
          </select>
        </div>
        <div className="bet-detail-table">
          <div className="bet-detail-head"><span>注单编号</span><span>下单时间</span><span>号码</span><span>金额</span><span>赔率</span><span>中奖</span><span>回水</span><span>离线回水</span><span>盈亏</span><span>状态</span><span>查看文本</span></div>
          {rows.length ? rows.map((row, index) => {
            const sameOrder = index > 0 && detailOrderKey(rows[index - 1]) === detailOrderKey(row);
            const rowClass = ["bet-detail-row", index % 2 === 0 ? "stripe" : "plain", row.status === "refunded" ? "refunded" : ""].filter(Boolean).join(" ");
            return <div className={rowClass} key={row.row_key || `${row.id}-${index}`}>
              <span className="bet-order-no">{sameOrder ? "" : row.order_no || row.bet_record_id || row.id}</span>
              <span className="bet-placed-at">{sameOrder ? "" : row.placed_at}</span>
              <button type="button" className="bet-number-link" onClick={() => { setPreviewRow(row); setPreviewMode("numbers"); }}><b>{row.number_text || "-"}</b>{row.play_label ? <em>{row.play_label}</em> : null}</button>
              <span className="bet-money">{row.amount}</span><span className="bet-odds">{row.odds || "-"}</span><span>{row.win_amount}</span><span>{row.rebate}</span><span>{row.offline_rebate || "0"}</span><span>{rowProfit(row)}</span><span>{statusLabels[row.status] || "未知状态"}</span>
              {sameOrder ? <span className="bet-same-order">同上</span> : <button type="button" className="bet-text-link" disabled={!row.source_text} title="查看原始投注文本" onClick={() => { setPreviewRow(row); setPreviewMode("text"); }}><FileTextOutlined /></button>}
            </div>;
          }) : !loading && <div className="bet-detail-empty"><Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="暂无数据" /></div>}
          {rows.length ? <div className="bet-detail-total"><span>合计</span><span /><span /><span>{pageTotals.amount}</span><span /><span>---</span><span>{pageTotals.rebate}</span><span>{pageTotals.offline_rebate}</span><span>{pageTotals.profit}</span><span /><span /></div> : null}
          {loading && <div className="page-local-loading" role="status" aria-label="加载中" />}
        </div>
        <div className="bet-detail-pagination" aria-label="下注明细分页">
          <span className="bet-detail-total-count">总计：<b>{total}</b> 条数据</span><button type="button" onClick={() => goPage(page - 1)} disabled={page <= 1}>‹</button>
          {pageList.map((item) => item === "ellipsis-left" || item === "ellipsis-right" ? <span className="bet-page-ellipsis" key={item}>•••</span> : <button type="button" className={item === page ? "active" : ""} key={item} onClick={() => goPage(item)}>{item}</button>)}
          <button type="button" onClick={() => goPage(page + 1)} disabled={page >= pageCount}>›</button><span className="bet-page-size">{DETAIL_PAGE_SIZE} 条/页</span><span>跳至</span><input aria-label="跳至页" value={jumpPage} onChange={(event) => setJumpPage(event.target.value.replace(/\D/g, ""))} onKeyDown={(event) => { if (event.key === "Enter") goJumpPage(); }} /><span>页</span><button type="button" className="bet-page-jump" onClick={goJumpPage}>跳 转</button>
        </div>
        <Modal className="record-detail-modal" open={Boolean(previewRow)} title={previewRow ? `${previewMode === "text" ? "原始投注文本" : "投注号码"} · 注单 ${previewRow.order_no || previewRow.bet_record_id || previewRow.id}` : ""} footer={null} onCancel={() => setPreviewRow(undefined)} width={760}>
          {previewRow && previewMode === "text" ? <pre className="bet-text-preview">{previewRow.source_text || "暂无原始文本"}</pre> : <div className="bet-number-preview">{(previewRow?.number_text || "").split(/[\s,，、]+/).filter(Boolean).map((value, index) => <i key={`${value}-${index}`}>{value}</i>)}</div>}
        </Modal>
      </div>
    </div>
  );
}
