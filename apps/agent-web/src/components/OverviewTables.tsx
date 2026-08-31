import type { ReactNode } from "react";
import { Button } from "antd";
import type { AgentBetRecord, AgentOrderDetail, AgentRefundRecord } from "../api/user";

type Column = { key: string; width: number; label: string };

function displayBetNumber(value: string | undefined, playType?: string): string {
  const text = String(value || "");
  if (String(playType || "").includes("双飞") || text.endsWith("飞")) {
    return text.replace(/^0(?=\d{2}(?:飞)?$)/, "");
  }
  return text;
}

function TableShell({
  className,
  columns,
  children,
}: {
  className: string;
  columns: Column[];
  children: ReactNode;
}) {
  return (
    <table className={`overview-data-table ${className}`}>
      <colgroup>{columns.map((column) => <col key={column.key} style={{ width: `${column.width}px` }} />)}</colgroup>
      <thead><tr>{columns.map((column) => <th key={column.key}>{column.label}</th>)}</tr></thead>
      <tbody>{children}</tbody>
    </table>
  );
}

const detailColumns: Column[] = [
  { key: "order", width: 150, label: "注单编号" }, { key: "user", width: 110, label: "会员" },
  { key: "placed", width: 170, label: "下单时间" }, { key: "number", width: 130, label: "号码" },
  { key: "amount", width: 100, label: "下注金额" }, { key: "odds", width: 90, label: "赔率" },
  { key: "win", width: 100, label: "中奖" }, { key: "downline", width: 110, label: "下线回水" },
  { key: "received", width: 120, label: "实收下线" }, { key: "own", width: 110, label: "自己回水" },
  { key: "upstream", width: 110, label: "实付上线" }, { key: "profit", width: 100, label: "明水" },
];

const winningColumns: Column[] = [
  ...detailColumns.slice(0, 9),
  { key: "path", width: 110, label: "路径" }, { key: "ticket", width: 220, label: "小票或全截" },
];

export function OverviewDetailsTable({ rows, winning = false }: { rows: AgentOrderDetail[]; winning?: boolean }) {
  const columns = winning ? winningColumns : detailColumns;
  return (
    <TableShell className={winning ? "overview-winning-table" : "overview-detail-table"} columns={columns}>
      {rows.map((row) => winning ? (
        <tr key={row.id}>
          <td>{row.order_no}</td><td>{row.username}</td><td>{row.placed_at}</td>
          <td className="overview-number">{displayBetNumber(row.number_text, row.play_type) || row.play_type}</td><td>{row.amount}</td><td>{row.odds || "-"}</td>
          <td className="overview-win">{row.win_amount || "0"}</td><td>{row.downline_rebate || "0"}</td>
          <td>{row.received_amount || row.amount}</td><td className="overview-path">{row.path || "会员"}</td>
          <td className="overview-ticket" title={row.ticket || ""}>{row.ticket || ""}</td>
        </tr>
      ) : (
        <tr key={row.id}>
          <td>{row.order_no}</td><td>{row.username}</td><td>{row.placed_at}</td>
          <td className="overview-number">{displayBetNumber(row.number_text, row.play_type) || row.play_type}</td><td>{row.amount}</td><td>{row.odds || "-"}</td>
          <td className="overview-win">{row.win_amount || "0"}</td><td>{row.downline_rebate || "0"}</td>
          <td>{row.received_amount || row.amount}</td><td>{row.own_rebate || "0"}</td>
          <td>{row.paid_upstream || row.amount}</td><td>{row.rebate_profit || "0"}</td>
        </tr>
      ))}
    </TableShell>
  );
}

export function OverviewRecordsTable({ rows, onDetails }: { rows: AgentBetRecord[]; onDetails: (row: AgentBetRecord) => void }) {
  const columns: Column[] = [
    { key: "id", width: 90, label: "编号" }, { key: "order", width: 150, label: "注单编号" },
    { key: "user", width: 130, label: "会员名" }, { key: "issue", width: 110, label: "期号" },
    { key: "placed", width: 180, label: "下单时间" }, { key: "source", width: 90, label: "来源" },
    { key: "count", width: 100, label: "注单数量" }, { key: "amount", width: 120, label: "注单总额" },
    { key: "actions", width: 80, label: "操作" },
  ];
  return <TableShell className="overview-records-table" columns={columns}>{rows.map((row) => <tr key={row.id}>
    <td>{row.id}</td><td>{row.order_no || "--"}</td><td>{row.username}</td><td>{row.issue_no}</td>
    <td>{row.placed_at}</td><td>快录</td><td>{row.bet_count}</td><td className="overview-money">{row.amount}</td>
    <td><Button className="overview-action" type="default" size="small" onClick={() => onDetails(row)}>明细</Button></td>
  </tr>)}</TableShell>;
}

export function OverviewRefundsTable({ rows, onDetails }: { rows: AgentRefundRecord[]; onDetails: (row: AgentRefundRecord) => void }) {
  const columns: Column[] = [
    { key: "id", width: 90, label: "编号" }, { key: "user", width: 160, label: "会员名" },
    { key: "issue", width: 120, label: "期号" }, { key: "refunded", width: 180, label: "退码时间" },
    { key: "count", width: 110, label: "注单数量" }, { key: "amount", width: 120, label: "注单总额" },
    { key: "actions", width: 80, label: "操作" },
  ];
  return <TableShell className="overview-refund-table" columns={columns}>{rows.map((row) => <tr key={row.id}>
    <td>{row.id}</td><td>{row.username}</td><td>{row.issue_no}</td><td>{row.refunded_at}</td><td>{row.bet_count}</td>
    <td className="overview-money">{row.amount}</td><td><Button className="overview-action" type="default" size="small" onClick={() => onDetails(row)}>明细</Button></td>
  </tr>)}</TableShell>;
}
