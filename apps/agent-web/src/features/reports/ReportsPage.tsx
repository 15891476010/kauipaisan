import { DoubleRightOutlined } from '@ant-design/icons';
import { App as AntdApp, ConfigProvider, DatePicker, Spin } from 'antd';
import zhCN from 'antd/locale/zh_CN';
import dayjs from 'dayjs';
import 'dayjs/locale/zh-cn';
import { memo, useEffect, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { hasAgentPermission } from '../../routePermissions';
import { getAgentMonthlyReport, getAgentReport, getAgentReportIssues, type AgentMonthlyReportRow, type AgentReportContext, type AgentReportIssue, type AgentReportLevel, type AgentReportMemberRow, type AgentReportMetrics } from '../../api/user';
import { apiErrorMessage } from '../../utils/request';
import { ReportTable } from './ReportTable';
import { readShipmentTotals, type ShipmentTotals } from './reportShipment';
import './ReportsPage.css';

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
const emptyMetrics: AgentReportMetrics = { bet_count: 0, amount: '0', win_amount: '0', water: '0', member_profit: '0', viewer_amount: '0', share_amount: '0', share_profit: '0', offline_water: '0', agent_water: '0', agent_profit: '0', platform_amount: '0', platform_profit: '0' };

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

export const ReportsPage = memo(function ReportsPage({lottery,permissions}:{lottery:string;permissions:string[]}) {
  const {message}=AntdApp.useApp(); const today=localDate(new Date());
  const [searchParams,setSearchParams]=useSearchParams();
  const organizationId=searchParams.get('organization_id')||'';
  const [context,setContext]=useState<AgentReportContext|null>(null);
  const [rowLabel,setRowLabel]=useState('下级');
  const [shipment,setShipment]=useState<ShipmentTotals>();
  const [error,setError]=useState('');
  const canSummary=hasAgentPermission('reports',permissions); const canMonthly=hasAgentPermission('monthly_reports',permissions);
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
  const params=useMemo(()=>({from,to,organization_id:organizationId||undefined,lotteries:mode==='monthly'?(lottery||'__none__'):(lotteries.length?lotteries.join(','):'__none__')}),[from,to,organizationId,lotteries,lottery,mode]);
  const browse=(id:number)=>setSearchParams(current=>{const next=new URLSearchParams(current);next.set('organization_id',String(id));return next;});
  useEffect(()=>{if(mode==='summary'&&!canSummary&&canMonthly)setMode('monthly');if(mode==='monthly'&&!canMonthly&&canSummary)setMode('summary');},[mode,canSummary,canMonthly]);
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
  useEffect(()=>{
    if((mode==='summary'&&!canSummary)||(mode==='monthly'&&!canMonthly))return;
    let active=true;setLoading(true);setError('');
    const task=mode==='summary'?getAgentReport(params):getAgentMonthlyReport(params);
    void task.then(response=>{
      if(!active||!response.data.data)return;
      const data=response.data.data;
      setContext(data);setReportLevels(data.report_levels||[]);
      setShipment(mode==='summary' && 'chuhuoList' in data ? readShipmentTotals(data.chuhuoList) : undefined);
      if('summary' in data){setSummary(data.summary);setMemberRows(data.list);setRows([]);setRowLabel(data.row_label);}
      else{setRows(data.list);setMemberRows([]);setSummary(data.total);}
    }).catch((reason:unknown)=>{
      if(!active)return;
      setShipment(undefined);setSummary(emptyMetrics);setRows([]);setMemberRows([]);setReportLevels([]);setContext(null);
      const text=apiErrorMessage(reason,'报表加载失败');setError(text);void message.error(text);
    }).finally(()=>{if(active)setLoading(false)});
    return()=>{active=false};
  },[mode,params,message,canSummary,canMonthly]);
  const chooseRange=(type:QuickRange)=>{const range=dateRange(type);setSelectedRange(type);setFrom(range[0]);setTo(range[1]);};
  const chooseMonth=()=>{const month=dayjs(`${reportMonth}-01`);setSelectedRange('month');setFrom(month.format('YYYY-MM-DD'));setTo(month.endOf('month').format('YYYY-MM-DD'));};
  const toggleLottery=(value:string,checked:boolean)=>setLotteries(current=>checked?Array.from(new Set([...current,value])):current.filter(item=>item!==value));
  const monthTitle=`${from.slice(0,4)}年${from.slice(5,7)}月`;
  return <ConfigProvider locale={zhCN}><section className="agent-reports-page">
    <div className="agent-reports-location"><div className="agent-reports-path"><strong>位置</strong><DoubleRightOutlined/><span>报表</span><DoubleRightOutlined/><span>{mode==='summary'?'综合报表':'月报表'}</span></div><nav className="agent-reports-tabs">{canSummary&&<button className={mode==='summary'?'active':''} onClick={()=>{if(mode!=='summary'){setMode('summary');setSelectedRange(null)}}} type="button">综合报表</button>}{canMonthly&&<button className={mode==='monthly'?'active':''} onClick={()=>{if(mode!=='monthly'){setMode('monthly');setSelectedRange('all')}}} type="button">月报表</button>}</nav></div>
    <div className="agent-reports-content">
    {context&&context.breadcrumbs.length>1&&<div className="agent-report-account-title">{context.current.name}({context.current.level_label})</div>}
    {mode==='summary'&&<div className="agent-reports-filters">{lotteryOptions.map(item=><label key={item.value}><input type="checkbox" checked={lotteries.includes(item.value)} onChange={event=>toggleLottery(item.value,event.target.checked)}/>{item.label}</label>)}<DatePicker size="small" aria-label="开始日期" value={from?calendarDate(from):null} pickerValue={fromPickerValue} getPopupContainer={calendarContainer} onPanelChange={value=>setFromPickerValue(value)} onChange={date=>{setSelectedRange(null);setFrom(date?date.format('YYYY-MM-DD'):'');if(date)setFromPickerValue(date)}} placeholder="开始日期"/><span>至</span><DatePicker size="small" aria-label="结束日期" value={to?calendarDate(to):null} pickerValue={toPickerValue} getPopupContainer={calendarContainer} onPanelChange={value=>setToPickerValue(value)} onChange={date=>{setSelectedRange(null);setTo(date?date.format('YYYY-MM-DD'):'');if(date)setToPickerValue(date)}} placeholder="结束日期"/></div>}
    {context&&context.breadcrumbs.length>1&&<div className="agent-reports-return"><button type="button" className="report-back-button" onClick={()=>browse(context.breadcrumbs[context.breadcrumbs.length-2].id)}>返回上级</button></div>}
    <div className="agent-reports-band">
      <strong>{mode==='summary'?'综合报表':'月报表'}</strong>
      <button type="button" className={selectedRange==='month'?'active':''} aria-pressed={selectedRange==='month'} onClick={chooseMonth}>{monthTitle}</button>
      {mode==='monthly'&&<button type="button" className={selectedRange==='all'?'active':''} aria-pressed={selectedRange==='all'} onClick={()=>setSelectedRange('all')}>全部</button>}
      {quickRanges.map(item=><button key={item.value} type="button" className={selectedRange===item.value?'active':''} aria-pressed={selectedRange===item.value} onClick={()=>chooseRange(item.value)}>{item.label}</button>)}
    </div>
    {mode==='monthly'&&<div className="agent-reports-month-range"><select className="reports-issue-select" aria-label="月报开始期号" value={fromIssue} onChange={event=>{const value=event.target.value;const issue=issues.find(item=>item.issue_no===value);setSelectedRange(null);setFromIssue(value);if(issue)setFrom(issue.date)}}>{!fromIssue&&<option value="">暂无匹配期号</option>}{issues.map(item=><option key={item.issue_no} value={item.issue_no}>{Number(item.date.slice(5,7))}-{Number(item.date.slice(8,10))}({item.issue_no})</option>)}</select><span>至</span><select className="reports-issue-select" aria-label="月报结束期号" value={toIssue} onChange={event=>{const value=event.target.value;const issue=issues.find(item=>item.issue_no===value);setSelectedRange(null);setToIssue(value);if(issue)setTo(issue.date)}}>{!toIssue&&<option value="">暂无匹配期号</option>}{issues.map(item=><option key={item.issue_no} value={item.issue_no}>{Number(item.date.slice(5,7))}-{Number(item.date.slice(8,10))}({item.issue_no})</option>)}</select></div>}
    <div className="agent-reports-card">{loading?<div className="agent-reports-loading"><Spin/></div>:error?<div className="agent-reports-loading" role="alert">{error}</div>:<ReportTable mode={mode} rows={rows} memberRows={memberRows} summary={summary} reportLevels={reportLevels} rowLabel={rowLabel} browse={browse} shipment={mode==='summary'?shipment:undefined}/>}</div>
    </div>
  </section></ConfigProvider>;
});
