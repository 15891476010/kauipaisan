import type { ReactNode } from 'react';
import type { AgentMonthlyReportRow, AgentReportLevel, AgentReportMemberRow, AgentReportMetrics } from '../../api/user';
import { reportNumber as show } from './reportNumber';
import { reportColumnGroups, type ReportColumnGroup } from './reportColumns';
import { ReportTotalBreakdown } from './ReportTotalBreakdown';
import type { ShipmentTotals } from './reportShipment';
import './ReportTable.css';

type ReportMode = 'summary' | 'monthly';

export function ReportTable({mode,rows,memberRows,summary,reportLevels,rowLabel,browse,shipment}:{mode:ReportMode;rows:AgentMonthlyReportRow[];memberRows:AgentReportMemberRow[];summary:AgentReportMetrics;reportLevels:AgentReportLevel[];rowLabel:string;shipment?:ShipmentTotals;browse:(id:number)=>void}) {
  const groups = reportColumnGroups(reportLevels);
  const columns = groups.reduce((count, group) => count + group.columns.length, 1);
  const accountTitle = groups.find((group) => group.relation === 'downline')?.label ?? rowLabel;
  return <div className="agent-reports-table-scroll"><table className="agent-reports-table">
    <colgroup><col className="report-account-col"/>{Array.from({length:columns-1},(_,index)=><col key={index} className="report-metric-col"/>)}</colgroup>
    <thead>
      <tr><th rowSpan={2} className="report-name-column">{mode==='summary'?accountTitle:'期号'}</th>{groups.map(group=><th key={group.key} colSpan={group.columns.length} className={group.className}>{group.label}</th>)}</tr>
      <tr>{groups.flatMap(group=>group.columns.map(column=><th key={`${group.key}-${column.key}`} className={`${group.className}${group.relation==='self'&&column.key==='agent_profit'?' report-total-profit':''}`}>{column.title}</th>))}</tr>
    </thead>
    <tbody>
      {mode==='summary'&&memberRows.map((row,index)=><MetricRow key={`${row.type}-${row.id}`} label={<><span className="report-branch-name">{row.type==='organization'?<button type="button" onClick={()=>browse(row.id)}><span className="report-row-index">[{index+1}]</span>{row.member}</button>:<><span className="report-row-index">[{index+1}]</span>{row.member}</>}</span><small className="report-issue-count">{row.issue_count}期数</small></>} metrics={row.summary} groups={groups}/>)}
      {mode==='monthly'&&rows.map(row=><MetricRow key={row.issue_no} label={row.issue_no} metrics={row.summary} groups={groups}/>)}
      {(mode==='summary'?memberRows.length:rows.length)===0&&<tr><td colSpan={columns}>当前范围暂无投注数据</td></tr>}
      <MetricRow label="合计" metrics={summary} groups={groups} shipment={shipment} total/>
    </tbody>
  </table></div>;
}

function MetricRow({label,metrics,groups,shipment,total=false}:{label:ReactNode;metrics:AgentReportMetrics;groups:ReportColumnGroup[];shipment?:ShipmentTotals;total?:boolean}) {
  return <tr className={total?'report-total-row':''}>
    <td><div className="report-label">{label}</div></td>
    {groups.flatMap(group=>group.columns.map(column=><td key={`${group.key}-${column.key}`} className={`${group.className}${group.relation==='self'&&column.key==='agent_profit'?' report-total-profit':''}`}>{total && group.relation==='self' && group.key==='director' && (column.key==='share_profit' || column.key==='agent_profit') ? <ReportTotalBreakdown title={column.title} base={column.value(metrics)} shipment={shipment}/> : show(column.value(metrics))}</td>))}
  </tr>;
}
