import { DoubleRightOutlined } from '@ant-design/icons';
import { App as AntdApp, ConfigProvider, DatePicker, Spin } from 'antd';
import zhCN from 'antd/locale/zh_CN';
import dayjs from 'dayjs';
import 'dayjs/locale/zh-cn';
import { memo, useEffect, useMemo, useState } from 'react';
import type { ReactNode } from 'react';
import { getAgentMonthlyReport, getAgentReport, getAgentReportIssues, type AgentMonthlyReportRow, type AgentReportIssue, type AgentReportLevel, type AgentReportMemberRow, type AgentReportMetrics } from '../../api/user';
import { apiErrorMessage } from '../../utils/request';

type ReportMode = 'summary' | 'monthly';
type QuickRange = 'today' | 'yesterday' | 'week' | 'lastWeek';
type SelectedRange = QuickRange | 'month' | 'all' | null;
const quickRanges: Array<{ value: QuickRange; label: string }> = [
  { value: 'today', label: '今天' },
  { value: 'yesterday', label: '昨天' },
  { value: 'week', label: '本周' },
  { value: 'lastWeek', label: '上周' },
];
const calendarDate = (value: string) => dayjs(value).locale('zh-cn');
const calendarContainer = (trigger: HTMLElement) => trigger.parentElement || document.body;
const lotteryOptions = [{ label: '福', value: '福彩3D' }, { label: '体', value: '排列三' }];
const emptyMetrics: AgentReportMetrics = { bet_count: 0, amount: '0', win_amount: '0', water: '0', member_profit: '0', share_amount: '0', share_profit: '0', agent_water: '0', agent_profit: '0', platform_amount: '0', platform_profit: '0' };

function localDate(date: Date) {
  return `${date.getFullYear()}-${String(date.getMonth()+1).padStart(2,'0')}-${String(date.getDate()).padStart(2,'0')}`;
}
function dateRange(type: 'today'|'yesterday'|'week'|'lastWeek') {
  const now=new Date(); now.setHours(12,0,0,0);
  if(type==='today') return [localDate(now),localDate(now)];
  if(type==='yesterday'){now.setDate(now.getDate()-1);return[localDate(now),localDate(now)];}
  const day=now.getDay()||7; const monday=new Date(now); monday.setDate(now.getDate()-day+1);
  if(type==='week') return[localDate(monday),localDate(now)];
  const start=new Date(monday);start.setDate(start.getDate()-7);const end=new Date(monday);end.setDate(end.getDate()-1);return[localDate(start),localDate(end)];
}
const show=(value:string|number)=>Number.isFinite(Number(value))?String(Number(value)):'0';

export const ReportsPage = memo(function ReportsPage({lottery}:{lottery:string}) {
  const {message}=AntdApp.useApp(); const today=localDate(new Date());
  const permissions=(()=>{try{const value=JSON.parse(localStorage.getItem('agent_permissions')||'["*"]');return Array.isArray(value)?value.map(String):['*']}catch{return['*']}})(); const canSummary=permissions.includes('*')||permissions.includes('reports'); const canMonthly=permissions.includes('*')||permissions.includes('monthly_reports');
  const [mode,setMode]=useState<ReportMode>(()=>canSummary?'summary':'monthly'); const [from,setFrom]=useState(today); const [to,setTo]=useState(today);
  const [selectedRange,setSelectedRange]=useState<SelectedRange>(()=>canSummary?'today':'all');
  // The shell refreshes its countdown once per second. Keep each calendar's
  // browsed month independent so that refresh does not snap it back to value.
  const [fromPickerValue,setFromPickerValue]=useState(()=>calendarDate(today));
  const [toPickerValue,setToPickerValue]=useState(()=>calendarDate(today));
  useEffect(()=>{setFromPickerValue(current=>from?(current.format('YYYY-MM-DD')===from?current:calendarDate(from)):calendarDate(today))},[from,today]);
  useEffect(()=>{setToPickerValue(current=>to?(current.format('YYYY-MM-DD')===to?current:calendarDate(to)):calendarDate(today))},[to,today]);
  const [lotteries,setLotteries]=useState(['福彩3D','排列三']); const [summary,setSummary]=useState(emptyMetrics); const [rows,setRows]=useState<AgentMonthlyReportRow[]>([]); const [memberRows,setMemberRows]=useState<AgentReportMemberRow[]>([]); const [reportLevels,setReportLevels]=useState<AgentReportLevel[]>([]); const [loading,setLoading]=useState(true);
  const [issues,setIssues]=useState<AgentReportIssue[]>([]); const [fromIssue,setFromIssue]=useState(''); const [toIssue,setToIssue]=useState('');
  const reportMonth=(from||today).slice(0,7);
  const params=useMemo(()=>({from,to,lotteries:mode==='monthly'?(lottery||'__none__'):(lotteries.length?lotteries.join(','):'__none__')}),[from,to,lotteries,lottery,mode]);
  useEffect(()=>{if(mode!=='monthly'||!lottery)return;let active=true;const monthStart=`${reportMonth}-01`;const monthEnd=dayjs(monthStart).endOf('month').format('YYYY-MM-DD');setIssues([]);void getAgentReportIssues({lottery,from:monthStart,to:monthEnd}).then(response=>{if(active)setIssues(response.data.data?.list||[])}).catch(()=>{if(active)setIssues([])});return()=>{active=false}},[mode,lottery,reportMonth]);
  // Loading a month's issues must not replace a quick range the user just chose.
  useEffect(()=>{
    if(mode!=='monthly')return;
    const currentIssues=issues.filter(item=>item.date.slice(0,7)===reportMonth);
    const within=selectedRange==='all'?currentIssues:currentIssues.filter(item=>item.date>=from&&item.date<=to);
    setFromIssue(within[within.length-1]?.issue_no||'');
    setToIssue(within[0]?.issue_no||'');
    if(selectedRange==='all'&&within.length){setFrom(within[within.length-1].date);setTo(within[0].date);}
  },[mode,issues,reportMonth,selectedRange,from,to]);
  useEffect(()=>{let active=true;setLoading(true);const task=mode==='summary'?getAgentReport(params):getAgentMonthlyReport(params);void task.then(response=>{if(!active||!response.data.data)return;if(mode==='summary'){const data=response.data.data as {summary:AgentReportMetrics;list:AgentReportMemberRow[];report_levels?:AgentReportLevel[]};setSummary(data.summary);setMemberRows(data.list||[]);setRows([]);setReportLevels(data.report_levels||[]);}else{const data=response.data.data as {list:AgentMonthlyReportRow[];total:AgentReportMetrics;report_levels?:AgentReportLevel[]};setRows(data.list);setMemberRows([]);setSummary(data.total);setReportLevels(data.report_levels||[]);}}).catch((reason:unknown)=>{if(active){setSummary(emptyMetrics);setRows([]);setMemberRows([]);setReportLevels([]);void message.error(apiErrorMessage(reason,'报表加载失败'));}}).finally(()=>{if(active)setLoading(false)});return()=>{active=false}},[mode,params,message]);
  const chooseRange=(type:QuickRange)=>{const range=dateRange(type);setSelectedRange(type);setFrom(range[0]);setTo(range[1]);};
  const chooseMonth=()=>{const month=dayjs(`${reportMonth}-01`);setSelectedRange('month');setFrom(month.format('YYYY-MM-DD'));setTo(month.endOf('month').format('YYYY-MM-DD'));};
  const toggleLottery=(value:string,checked:boolean)=>setLotteries(current=>checked?Array.from(new Set([...current,value])):current.filter(item=>item!==value));
  const monthTitle=`${from.slice(0,4)}年${from.slice(5,7)}月`;
  return <ConfigProvider locale={zhCN}><section className="agent-reports-page">
    <div className="agent-reports-location"><div className="agent-reports-path"><strong>位置</strong><DoubleRightOutlined/><span>报表</span><DoubleRightOutlined/><span>{mode==='summary'?'综合报表':'月报表'}</span></div><nav className="agent-reports-tabs">{canSummary&&<button className={mode==='summary'?'active':''} onClick={()=>{if(mode!=='summary'){setMode('summary');setSelectedRange(null)}}} type="button">综合报表</button>}{canMonthly&&<button className={mode==='monthly'?'active':''} onClick={()=>{if(mode!=='monthly'){setMode('monthly');setSelectedRange('all')}}} type="button">月报表</button>}</nav></div>
    {mode==='summary'&&<div className="agent-reports-filters">{lotteryOptions.map(item=><label key={item.value}><input type="checkbox" checked={lotteries.includes(item.value)} onChange={event=>toggleLottery(item.value,event.target.checked)}/>{item.label}</label>)}<DatePicker size="small" aria-label="开始日期" value={from?calendarDate(from):null} pickerValue={fromPickerValue} getPopupContainer={calendarContainer} onPanelChange={value=>setFromPickerValue(value)} onChange={date=>{setSelectedRange(null);setFrom(date?date.format('YYYY-MM-DD'):'');if(date)setFromPickerValue(date)}} placeholder="开始日期"/><span>至</span><DatePicker size="small" aria-label="结束日期" value={to?calendarDate(to):null} pickerValue={toPickerValue} getPopupContainer={calendarContainer} onPanelChange={value=>setToPickerValue(value)} onChange={date=>{setSelectedRange(null);setTo(date?date.format('YYYY-MM-DD'):'');if(date)setToPickerValue(date)}} placeholder="结束日期"/></div>}
    <div className="agent-reports-band">
      <strong>{mode==='summary'?'综合报表':'月报表'}</strong>
      <button type="button" className={selectedRange==='month'?'active':''} aria-pressed={selectedRange==='month'} onClick={chooseMonth}>{monthTitle}</button>
      {mode==='monthly'&&<button type="button" className={selectedRange==='all'?'active':''} aria-pressed={selectedRange==='all'} onClick={()=>setSelectedRange('all')}>全部</button>}
      {quickRanges.map(item=><button key={item.value} type="button" className={selectedRange===item.value?'active':''} aria-pressed={selectedRange===item.value} onClick={()=>chooseRange(item.value)}>{item.label}</button>)}
    </div>
    {mode==='monthly'&&<div className="agent-reports-month-range"><select className="reports-issue-select" aria-label="月报开始期号" value={fromIssue} onChange={event=>{const value=event.target.value;const issue=issues.find(item=>item.issue_no===value);setSelectedRange(null);setFromIssue(value);if(issue)setFrom(issue.date)}}>{!fromIssue&&<option value="">暂无匹配期号</option>}{issues.map(item=><option key={item.issue_no} value={item.issue_no}>{Number(item.date.slice(5,7))}-{Number(item.date.slice(8,10))}({item.issue_no})</option>)}</select><span>至</span><select className="reports-issue-select" aria-label="月报结束期号" value={toIssue} onChange={event=>{const value=event.target.value;const issue=issues.find(item=>item.issue_no===value);setSelectedRange(null);setToIssue(value);if(issue)setTo(issue.date)}}>{!toIssue&&<option value="">暂无匹配期号</option>}{issues.map(item=><option key={item.issue_no} value={item.issue_no}>{Number(item.date.slice(5,7))}-{Number(item.date.slice(8,10))}({item.issue_no})</option>)}</select></div>}
    <div className="agent-reports-card">{loading?<div className="agent-reports-loading"><Spin/></div>:<ReportTable mode={mode} rows={rows} memberRows={memberRows} summary={summary} reportLevels={reportLevels}/>}</div>
  </section></ConfigProvider>;
});

function ReportTable({mode,rows,memberRows,summary,reportLevels}:{mode:ReportMode;rows:AgentMonthlyReportRow[];memberRows:AgentReportMemberRow[];summary:AgentReportMetrics;reportLevels:AgentReportLevel[]}) {
  const levels=reportLevels.length?reportLevels:[{key:'agent',label:'代理',relation:'self' as const}];
  const groups=[{key:'member',label:'会员',relation:'member' as const},...levels];
  return <div className="agent-reports-table-scroll"><table className="agent-reports-table"><thead><tr><th rowSpan={2}>{mode==='summary'?'会员':'期号'}</th>{groups.map(group=><th key={group.key} colSpan={group.relation==='member'?4:group.relation==='self'?5:group.relation==='downline'?3:2} className={`${group.relation==='member'?'member-group':group.relation==='self'?'agent-group':'platform-group'}`}>{group.label}</th>)}</tr><tr>{groups.flatMap(group=>group.relation==='member'?['笔数','总投','总中','盈亏']:group.relation==='self'?['占成金额','占成盈亏','离线反水','总赚水','总盈亏']:group.relation==='downline'?['总投','总赚水','盈亏']:['总投','盈亏']).map((title,index)=><th key={`${title}-${index}`}>{title}</th>)}</tr></thead><tbody>{mode==='summary'&&memberRows.map(row=><MetricRow key={row.member} label={row.member} metrics={row.summary} levels={levels}/>)}{mode==='monthly'&&rows.map(row=><MetricRow key={row.issue_no} label={row.issue_no} metrics={row.summary} levels={levels}/>)}<MetricRow label="合计" metrics={summary} levels={levels} total/></tbody></table></div>;
}
function MetricRow({label,metrics,levels,total=false}:{label:string;metrics:AgentReportMetrics;levels:AgentReportLevel[];total?:boolean}) {
  const cells:ReactNode[]=[<td key="member-label">{label}</td>,<td key="member-count">{metrics.bet_count}</td>,<td key="member-amount">{show(metrics.amount)}</td>,<td key="member-win">{show(metrics.win_amount)}</td>,<td key="member-profit">{show(metrics.member_profit)}</td>];
  levels.forEach(level=>{if(level.relation==='self') cells.push(<td key={`${level.key}-share`}>{show(metrics.share_amount)}</td>,<td key={`${level.key}-share-profit`}>{show(metrics.share_profit)}</td>,<td key={`${level.key}-offline`}>0</td>,<td key={`${level.key}-water`}>0</td>,<td key={`${level.key}-profit`}>{show(metrics.agent_profit)}</td>);else if(level.relation==='downline') cells.push(<td key={`${level.key}-amount`}>{show(metrics.amount)}</td>,<td key={`${level.key}-water`}>0</td>,<td key={`${level.key}-profit`}>{show(metrics.member_profit)}</td>);else cells.push(<td key={`${level.key}-amount`}>{show(metrics.platform_amount)}</td>,<td key={`${level.key}-profit`}>{show(metrics.platform_profit)}</td>);});
  return <tr className={total?'report-total-row':''}>{cells}</tr>;
}
