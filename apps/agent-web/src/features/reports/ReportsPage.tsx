import { DoubleRightOutlined } from '@ant-design/icons';
import { App as AntdApp, DatePicker, Select, Spin } from 'antd';
import dayjs from 'dayjs';
import { useEffect, useMemo, useState } from 'react';
import { getAgentMonthlyReport, getAgentReport, getAgentReportIssues, type AgentMonthlyReportRow, type AgentReportIssue, type AgentReportMemberRow, type AgentReportMetrics } from '../../api/user';
import { apiErrorMessage } from '../../utils/request';

type ReportMode = 'summary' | 'monthly';
const lotteryOptions = [{ label: '福', value: '福彩3D' }, { label: '体', value: '排列三' }];
const emptyMetrics: AgentReportMetrics = { bet_count: 0, amount: '0', win_amount: '0', water: '0', member_profit: '0', share_amount: '0', share_profit: '0', offline_water: '0', agent_water: '0', agent_profit: '0', platform_amount: '0', platform_profit: '0' };

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

export function ReportsPage({lottery}:{lottery:string}) {
  const {message}=AntdApp.useApp(); const today=localDate(new Date());
  const permissions=(()=>{try{const value=JSON.parse(localStorage.getItem('agent_permissions')||'["*"]');return Array.isArray(value)?value.map(String):['*']}catch{return['*']}})(); const canSummary=permissions.includes('*')||permissions.includes('reports'); const canMonthly=permissions.includes('*')||permissions.includes('monthly_reports');
  const [mode,setMode]=useState<ReportMode>(()=>canSummary?'summary':'monthly'); const [from,setFrom]=useState(today); const [to,setTo]=useState(today);
  const [lotteries,setLotteries]=useState(['福彩3D','排列三']); const [summary,setSummary]=useState(emptyMetrics); const [rows,setRows]=useState<AgentMonthlyReportRow[]>([]); const [memberRows,setMemberRows]=useState<AgentReportMemberRow[]>([]); const [loading,setLoading]=useState(true);
  const [issues,setIssues]=useState<AgentReportIssue[]>([]); const [fromIssue,setFromIssue]=useState(''); const [toIssue,setToIssue]=useState('');
  const params=useMemo(()=>({from,to,lotteries:mode==='monthly'?(lottery||'__none__'):(lotteries.length?lotteries.join(','):'__none__')}),[from,to,lotteries,lottery,mode]);
  useEffect(()=>{if(mode!=='monthly'||!lottery)return;let active=true;void getAgentReportIssues({lottery}).then(response=>{if(!active)return;const list=response.data.data?.list||[];setIssues(list);if(list.length){const end=list[0];const start=list[Math.min(14,list.length-1)];setFromIssue(start.issue_no);setToIssue(end.issue_no);setFrom(start.date);setTo(end.date);}}).catch(()=>{if(active)setIssues([])});return()=>{active=false}},[mode,lottery]);
  useEffect(()=>{let active=true;setLoading(true);const task=mode==='summary'?getAgentReport(params):getAgentMonthlyReport(params);void task.then(response=>{if(!active||!response.data.data)return;if(mode==='summary'){const data=response.data.data as {summary:AgentReportMetrics;list:AgentReportMemberRow[]};setSummary(data.summary);setMemberRows(data.list||[]);setRows([]);}else{const data=response.data.data as {list:AgentMonthlyReportRow[];total:AgentReportMetrics};setRows(data.list);setMemberRows([]);setSummary(data.total);}}).catch((reason:unknown)=>{if(active){setSummary(emptyMetrics);setRows([]);setMemberRows([]);void message.error(apiErrorMessage(reason,'报表加载失败'));}}).finally(()=>{if(active)setLoading(false)});return()=>{active=false}},[mode,params,message]);
  const chooseRange=(type:'today'|'yesterday'|'week'|'lastWeek')=>{const range=dateRange(type);setFrom(range[0]);setTo(range[1]);if(mode==='monthly'&&issues.length){const within=issues.filter(item=>item.date>=range[0]&&item.date<=range[1]);if(within.length){setToIssue(within[0].issue_no);setFromIssue(within[within.length-1].issue_no);}}};
  const toggleLottery=(value:string,checked:boolean)=>setLotteries(current=>checked?Array.from(new Set([...current,value])):current.filter(item=>item!==value));
  const monthTitle=`${from.slice(0,4)}年${from.slice(5,7)}月`;
  return <section className="agent-reports-page">
    <div className="agent-reports-location"><div className="agent-reports-path"><strong>位置</strong><DoubleRightOutlined/><span>报表</span><DoubleRightOutlined/><span>{mode==='summary'?'综合报表':'月报表'}</span></div><nav className="agent-reports-tabs">{canSummary&&<button className={mode==='summary'?'active':''} onClick={()=>setMode('summary')} type="button">综合报表</button>}{canMonthly&&<button className={mode==='monthly'?'active':''} onClick={()=>setMode('monthly')} type="button">月报表</button>}</nav></div>
    {mode==='summary'&&<div className="agent-reports-filters">{lotteryOptions.map(item=><label key={item.value}><input type="checkbox" checked={lotteries.includes(item.value)} onChange={event=>toggleLottery(item.value,event.target.checked)}/>{item.label}</label>)}<DatePicker size="small" aria-label="开始日期" value={from?dayjs(from):null} onChange={date=>setFrom(date?date.format('YYYY-MM-DD'):'')} placeholder="开始日期"/><span>至</span><DatePicker size="small" aria-label="结束日期" value={to?dayjs(to):null} onChange={date=>setTo(date?date.format('YYYY-MM-DD'):'')} placeholder="结束日期"/></div>}
    <div className="agent-reports-band"><strong>{mode==='summary'?'综合报表':'月报表'}</strong><b>{monthTitle}</b>{mode==='monthly'&&<button type="button" onClick={()=>{if(!issues.length)return;const start=issues[issues.length-1];const end=issues[0];setFromIssue(start.issue_no);setToIssue(end.issue_no);setFrom(start.date);setTo(end.date)}}>全部</button>}<button type="button" className="today" onClick={()=>chooseRange('today')}>今天</button><button type="button" onClick={()=>chooseRange('yesterday')}>昨天</button><button type="button" onClick={()=>chooseRange('week')}>本周</button><button type="button" onClick={()=>chooseRange('lastWeek')}>上周</button></div>
    {mode==='monthly'&&<div className="agent-reports-month-range"><Select className="reports-issue-select" size="small" aria-label="月报开始期号" value={fromIssue} onChange={value=>{const issue=issues.find(item=>item.issue_no===value);setFromIssue(value);if(issue)setFrom(issue.date)}} options={issues.map(item=>({value:item.issue_no,label:`${Number(item.date.slice(5,7))}-${Number(item.date.slice(8,10))}(${item.issue_no})`}))}/><span>至</span><Select className="reports-issue-select" size="small" aria-label="月报结束期号" value={toIssue} onChange={value=>{const issue=issues.find(item=>item.issue_no===value);setToIssue(value);if(issue)setTo(issue.date)}} options={issues.map(item=>({value:item.issue_no,label:`${Number(item.date.slice(5,7))}-${Number(item.date.slice(8,10))}(${item.issue_no})`}))}/></div>}
    <div className="agent-reports-card">{loading?<div className="agent-reports-loading"><Spin/></div>:<ReportTable mode={mode} rows={rows} memberRows={memberRows} summary={summary}/>}</div>
  </section>;
}

function ReportTable({mode,rows,memberRows,summary}:{mode:ReportMode;rows:AgentMonthlyReportRow[];memberRows:AgentReportMemberRow[];summary:AgentReportMetrics}) {
  return <div className="agent-reports-table-scroll"><table className="agent-reports-table"><thead><tr><th rowSpan={2}>{mode==='summary'?'会员':'期号'}</th><th colSpan={5} className="member-group">会员</th><th colSpan={5} className="agent-group">代理</th><th colSpan={2} className="platform-group">总代理</th></tr><tr><th>笔数</th><th>总投</th><th>总中</th><th>总赚水</th><th>盈亏</th><th>占成金额</th><th>占成盈亏</th><th>离线赚水</th><th>总赚水</th><th>总盈亏</th><th>总投</th><th>盈亏</th></tr></thead><tbody>{mode==='summary'&&memberRows.map(row=><MetricRow key={row.member} label={row.member} metrics={row.summary}/>)}{mode==='monthly'&&rows.map(row=><MetricRow key={row.issue_no} label={row.issue_no} metrics={row.summary}/>)}<MetricRow label="合计" metrics={summary} total/></tbody></table></div>;
}
function MetricRow({label,metrics,total=false}:{label:string;metrics:AgentReportMetrics;total?:boolean}) {
  return <tr className={total?'report-total-row':''}><td>{label}</td><td>{metrics.bet_count}</td><td>{show(metrics.amount)}</td><td>{show(metrics.win_amount)}</td><td>{show(metrics.water)}</td><td>{show(metrics.member_profit)}</td><td>{show(metrics.share_amount)}</td><td>{show(metrics.share_profit)}</td><td>{show(metrics.offline_water)}</td><td>{show(metrics.agent_water)}</td><td>{show(metrics.agent_profit)}</td><td>{show(metrics.platform_amount)}</td><td>{show(metrics.platform_profit)}</td></tr>;
}
