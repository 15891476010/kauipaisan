import { Empty } from "antd";
import { FileTextOutlined } from "@ant-design/icons";
import type { BetDetail } from "../../../api/user";

type DetailTotals = { amount: string; win_amount: string; rebate: string; offline_rebate: string; profit: string };

function numberValue(value: unknown): number {
  const parsed = Number(value);
  return Number.isFinite(parsed) ? parsed : 0;
}

function rowProfit(row: BetDetail): string {
  if (row.status === "pending") return "0.00";
  if (row.profit !== undefined) return row.profit;
  return (numberValue(row.win_amount) - numberValue(row.amount) + numberValue(row.rebate) + numberValue(row.offline_rebate)).toFixed(2);
}

function detailOrderKey(row: BetDetail): string {
  return String(row.order_no || row.submission_id || row.bet_record_id || row.id);
}

function displayNumber(row: BetDetail): string {
  const value = String(row.number_text || "");
  const play = `${row.play_label || ""}${row.play_type || ""}`;
  const source = String(row.source_text || row.parsed_source_text || "");
  const stickyFamily = play.match(/(组六|组三)沾边赖/u)?.[1];
  if (stickyFamily) {
    const digits = value.replace(/\s+/gu, "").match(/^(?:六赖|三赖|六|三)?(\d{1,10})/u)?.[1]
      || source.match(/(?<!\d)(\d{1,10})(?!\d)/u)?.[1] || "";
    return `${stickyFamily === "组六" ? "六赖" : "三赖"}${digits ? ` ${digits}` : ""}`;
  }
  if (/(?:复式|复试)/u.test(play)) {
    const digits = value.replace(/\s+/gu, "").match(/^[复六三]?(\d{3,10})$/u)?.[1]
      || source.match(/(?<!\d)(\d{3,10})\s*(?:复式|复试)/u)?.[1] || "";
    return digits ? `复式 ${digits}` : "复式";
  }
  const dragFamily = play.match(/(组六|组三)胆拖/u)?.[1];
  if (dragFamily) {
    const drag = value.match(/^胆\d{1,2}拖(\d{1,9})$/u)?.[1]
      || value.match(/^[三六](\d{2,9})$/u)?.[1];
    if (drag) return `${dragFamily === "组六" ? "六" : "三"}拖${drag}`;
  }
  if (play.includes("双飞") || source.includes("对子")) {
    return value.replace(/^0(?=\d{2}(?:飞)?$)/, "").replace(/飞$/, "");
  }
  if (play.includes("组3") || play.includes("组6")) return value.replace(/^[三六]/u, "");
  // Lottery numbers are fixed-width expressions. Keep leading zeroes so
  // direct and multi-position displays align as 0-0-3, never 3.
  return value;
}

function displayPlayLabel(row: BetDetail): string {
  const raw = String(row.play_label || row.play_type || "");
  const meta = `${row.play_label || ""} ${row.play_type || ""}`;
  if (/(?:组六|组6)/u.test(meta) && /(?:\d{4,10}|九码|八码|七码|六码|五码|四码)/u.test(meta)) return "组六多码";
  if (/(?:组三|组3)/u.test(meta) && /(?:\d{4,10}|九码|八码|七码|六码|五码|四码)/u.test(meta)) return "组三多码";
  if (/(?:复式|复试)/u.test(raw)) return "复式多码";
  return raw;
}

const statusLabels: Record<string, string> = {
  pending: "未结算", won: "已结算", unwon: "已结算", refunded: "已退码", cancelled: "已取消", failed: "失败",
};

export function BetDetailsTable({
  rows,
  totals,
  loading,
  onPreview,
}: {
  rows: BetDetail[];
  totals: DetailTotals;
  loading: boolean;
  onPreview: (row: BetDetail) => void;
}) {
  return (
    <div className="bet-detail-table">
      <div className="bet-detail-head"><span>注单编号</span><span>下单时间</span><span>号码</span><span>金额</span><span>赔率</span><span>中奖</span><span>回水</span><span>离线回水</span><span>盈亏</span><span>状态</span><span>查看文本</span></div>
      {rows.length ? rows.map((row, index) => {
        const sameOrder = index > 0 && detailOrderKey(rows[index - 1]) === detailOrderKey(row);
        const rowClass = ["bet-detail-row", index % 2 === 0 ? "stripe" : "plain", row.status === "refunded" ? "refunded" : ""].filter(Boolean).join(" ");
        return <div className={rowClass} key={row.row_key || `${row.id}-${index}`}>
          <span className="bet-order-no">{row.order_no || row.bet_record_id || row.id}</span>
          <span className="bet-placed-at">{row.placed_at}</span>
          <span className="bet-number-link"><b>{displayNumber(row) || "-"}</b>{row.play_label || row.play_type ? <em>{displayPlayLabel(row)}</em> : null}</span>
          <span className="bet-money">{row.amount}</span><span className="bet-odds">{row.odds || "-"}</span><span>{row.win_amount}</span><span>{row.rebate}</span><span>{row.offline_rebate || "0"}</span><span>{rowProfit(row)}</span><span>{statusLabels[row.status] || "未知状态"}</span>
          {sameOrder ? <span className="bet-same-order">同上</span> : <button type="button" className="bet-text-link" disabled={!row.source_text && !row.parsed_source_text} title="查看投注文本" onClick={() => onPreview(row)}><FileTextOutlined /></button>}
        </div>;
      }) : !loading && <div className="bet-detail-empty"><Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="暂无数据" /></div>}
      {rows.length ? <div className="bet-detail-total"><span>合计</span><span /><span /><span>{totals.amount}</span><span /><span>{totals.win_amount || "0"}</span><span>{totals.rebate}</span><span>{totals.offline_rebate}</span><span>{totals.profit}</span><span /><span /></div> : null}
      {loading && <div className="page-local-loading" role="status" aria-label="加载中" />}
    </div>
  );
}
