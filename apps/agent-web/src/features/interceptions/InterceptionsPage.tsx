import { DoubleRightOutlined, ReloadOutlined } from '@ant-design/icons';
import { Empty, Spin } from 'antd';
import { useEffect, useMemo, useState } from 'react';
import { getInterceptionCategories, getInterceptionIssues, getInterceptionPlate, getInterceptions, type InterceptionCategory, type InterceptionIssue, type InterceptionPlateGroup, type InterceptionRow, type InterceptionSummary } from '../../api/user';

type View = 'details' | 'winning' | 'plate';
type Filters = { account: string; number: string; groupOnly: boolean; metric: 'odds' | 'amount'; min: string; max: string; oddsId: string; source: string; device: string; order: 'desc' | 'asc' };
const emptyFilters: Filters = { account: '', number: '', groupOnly: false, metric: 'odds', min: '', max: '', oddsId: '', source: 'all', device: 'all', order: 'desc' };
const emptySummary: InterceptionSummary = { bet_amount: '0', intercepted_amount: '0', rebate: '0', win_amount: '0', profit: '0' };
const tabs: Array<{ key: View; label: string }> = [{ key: 'details', label: '拦货明细' }, { key: 'winning', label: '拦货中奖' }, { key: 'plate', label: '拦货盘' }];
const interceptionPermission:Record<View,string>={details:'interception_details',winning:'interception_winning',plate:'interception_plate'};
const storedPermissions=()=>{try{const value=JSON.parse(localStorage.getItem('agent_permissions')||'["*"]');return Array.isArray(value)?value.map(String):['*']}catch{return['*']}};

export function InterceptionsPage({ lottery }: { lottery: string }) {
  const visibleTabs=tabs.filter((tab)=>{const permissions=storedPermissions();return permissions.includes('*')||permissions.includes(interceptionPermission[tab.key])});
  const [view, setView] = useState<View>(()=>visibleTabs[0]?.key||'details');
  const [issues, setIssues] = useState<InterceptionIssue[]>([]);
  const [categories, setCategories] = useState<InterceptionCategory[]>([]);
  const [fromIssue, setFromIssue] = useState('');
  const [toIssue, setToIssue] = useState('');
  const [filters, setFilters] = useState<Filters>(emptyFilters);
  const [applied, setApplied] = useState<Filters>(emptyFilters);
  const [refreshKey, setRefreshKey] = useState(0);
  const [rows, setRows] = useState<InterceptionRow[]>([]);
  const [summary, setSummary] = useState<InterceptionSummary>(emptySummary);
  const [groups, setGroups] = useState<InterceptionPlateGroup[]>([]);
  const [activeGroup, setActiveGroup] = useState('');
  const [loading, setLoading] = useState(true);
  const title = tabs.find((item) => item.key === view)?.label || '拦货明细';

  useEffect(() => {
    if (!lottery) { setIssues([]); setCategories([]); return; }
    let active = true; setLoading(true);
    Promise.all([getInterceptionIssues({ lottery }), getInterceptionCategories({ lottery })]).then(([issueResponse, categoryResponse]) => {
      if (!active) return;
      const issueList = issueResponse.data.data?.list || []; setIssues(issueList); setCategories(categoryResponse.data.data?.list || []);
      setFromIssue(issueList[0]?.issue_no || ''); setToIssue(issueList[0]?.issue_no || '');
    }).catch(() => { if (active) { setIssues([]); setCategories([]); } }).finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [lottery]);

  useEffect(() => {
    if (!lottery || !toIssue) return;
    let active = true; setLoading(true);
    if (view === 'plate') {
      void getInterceptionPlate({ lottery, issue_no: toIssue, refresh: refreshKey }).then((response) => {
        if (!active) return; const next = response.data.data?.groups || []; setGroups(next); setActiveGroup((current) => next.some((group) => group.category === current) ? current : (next[0]?.category || ''));
      }).catch(() => active && setGroups([])).finally(() => active && setLoading(false));
    } else {
      void getInterceptions({ lottery, view, from_issue: view === 'details' ? toIssue : fromIssue, to_issue: toIssue, account: applied.account, number: applied.number, group_only: applied.groupOnly ? 1 : 0, metric: applied.metric, min: applied.min, max: applied.max, odds_id: applied.oddsId, source: applied.source, device: applied.device, order: applied.order, refresh: refreshKey }).then((response) => {
        if (!active) return; setRows(response.data.data?.list || []); setSummary(response.data.data?.summary || emptySummary);
      }).catch(() => { if (active) { setRows([]); setSummary(emptySummary); } }).finally(() => active && setLoading(false));
    }
    return () => { active = false; };
  }, [lottery, view, fromIssue, toIssue, applied, refreshKey]);

  const categoryGroups = useMemo(() => {
    const result: Array<{ label: string; rows: InterceptionCategory[] }> = [];
    for (const row of categories) { const found = result.find((group) => group.label === row.category); if (found) found.rows.push(row); else result.push({ label: row.category, rows: [row] }); }
    return result;
  }, [categories]);

  return <section className="interception-page">
    <div className="interception-location"><div className="interception-path"><strong>位置</strong><DoubleRightOutlined/><span>拦货</span><DoubleRightOutlined/><span>{title}</span></div><nav className="interception-tabs">{visibleTabs.map((tab) => <button key={tab.key} type="button" className={view === tab.key ? 'active' : ''} onClick={() => setView(tab.key)}>{tab.label}</button>)}</nav></div>
    {view === 'plate' ? <PlateView groups={groups} activeGroup={activeGroup} setActiveGroup={setActiveGroup} issues={issues} issue={toIssue} setIssue={setToIssue} loading={loading} refresh={() => setRefreshKey((value) => value + 1)}/>
      : <>
        <form className="interception-filters" onSubmit={(event) => { event.preventDefault(); setApplied({ ...filters }); }}>
          <FilterField label="查账号"><input aria-label="查账号" value={filters.account} onChange={(event) => setFilters({ ...filters, account: event.target.value })}/></FilterField>
          <FilterField label="查号码"><input aria-label="查号码" value={filters.number} onChange={(event) => setFilters({ ...filters, number: event.target.value })}/></FilterField>
          <label className="interception-check"><span>组<br/>是？</span><input type="checkbox" checked={filters.groupOnly} onChange={(event) => setFilters({ ...filters, groupOnly: event.target.checked })}/></label>
          <FilterField label="列出"><select value={filters.metric} onChange={(event) => setFilters({ ...filters, metric: event.target.value as Filters['metric'] })}><option value="odds">赔率</option><option value="amount">金额</option></select></FilterField>
          <div className="interception-range"><input type="number" aria-label="最小值" value={filters.min} onChange={(event) => setFilters({ ...filters, min: event.target.value })}/><span>至</span><input type="number" aria-label="最大值" value={filters.max} onChange={(event) => setFilters({ ...filters, max: event.target.value })}/></div>
          <FilterField label="分类"><select value={filters.oddsId} onChange={(event) => setFilters({ ...filters, oddsId: event.target.value })}><option value="">所有</option>{categoryGroups.map((group) => <optgroup key={group.label} label={group.label}>{group.rows.map((row) => <option key={row.id} value={row.id}>{row.name}</option>)}</optgroup>)}</select></FilterField>
          <FilterField label="来源"><select value={filters.source} onChange={(event) => setFilters({ ...filters, source: event.target.value })}><option value="all">全部</option><option value="quick">快录</option></select></FilterField>
          <FilterField label="设备"><select value={filters.device} onChange={(event) => setFilters({ ...filters, device: event.target.value })}><option value="all">全部</option><option value="web">网</option></select></FilterField>
          <button className="interception-submit" type="submit">提 交</button>
        </form>
        <div className="interception-band"><strong>{title}</strong><div className="interception-order"><span>按下注时间排序:</span><label><input type="radio" checked={filters.order === 'desc'} onChange={() => { setFilters({ ...filters, order: 'desc' }); setApplied({ ...filters, order: 'desc' }); }}/>倒序</label><label><input type="radio" checked={filters.order === 'asc'} onChange={() => { setFilters({ ...filters, order: 'asc' }); setApplied({ ...filters, order: 'asc' }); }}/>正序</label></div><IssueSelect label="开始期号" value={view === 'details' ? toIssue : fromIssue} issues={issues} onChange={view === 'details' ? setToIssue : setFromIssue}/>{view === 'winning' && <><span>至</span><IssueSelect label="结束期号" value={toIssue} issues={issues} onChange={setToIssue}/></>}</div>
        <div className="interception-card">{loading ? <Loading/> : rows.length ? <DetailsTable rows={rows} summary={summary}/> : <EmptyState/>}</div>
      </>}
  </section>;
}

function FilterField({ label, children }: { label: string; children: React.ReactNode }) { return <label className="interception-filter-field"><span>{label}</span>{children}</label>; }
function IssueSelect({ label, value, issues, onChange }: { label: string; value: string; issues: InterceptionIssue[]; onChange: (value: string) => void }) { return <select aria-label={label} value={value} onChange={(event) => onChange(event.target.value)}>{issues.map((item) => <option key={`${label}-${item.issue_no}`} value={item.issue_no}>{`${Number(item.date.slice(5, 7))}-${Number(item.date.slice(8, 10))}(${item.issue_no})`}</option>)}</select>; }
function Loading() { return <div className="interception-state"><Spin/></div>; }
function EmptyState() { return <div className="interception-state"><Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="暂无数据"/></div>; }

function DetailsTable({ rows, summary }: { rows: InterceptionRow[]; summary: InterceptionSummary }) {
  return <div className="interception-table-scroll"><table className="interception-table"><thead><tr><th>注单编号</th><th>会员</th><th>下单时间</th><th>号码</th><th>下注金额</th><th>占成</th><th>拦货金额</th><th>赔率</th><th>回水</th><th>中奖</th><th>盈亏</th></tr></thead><tbody>{rows.map((row) => <tr key={row.id}><td>{row.order_no}</td><td>{row.member}</td><td>{row.placed_at}</td><td><b>{row.number}</b><small>{row.category}</small></td><td>{row.bet_amount}</td><td>{row.share_rate}</td><td>{row.intercepted_amount}</td><td>{row.odds}</td><td>{row.rebate}</td><td>{row.win_amount}</td><td className={Number(row.profit) < 0 ? 'negative' : 'positive'}>{row.profit}</td></tr>)}<tr className="interception-total"><td colSpan={4}>合计</td><td>{summary.bet_amount}</td><td></td><td>{summary.intercepted_amount}</td><td></td><td>{summary.rebate}</td><td>{summary.win_amount}</td><td>{summary.profit}</td></tr></tbody></table></div>;
}

function PlateView({ groups, activeGroup, setActiveGroup, issues, issue, setIssue, loading, refresh }: { groups: InterceptionPlateGroup[]; activeGroup: string; setActiveGroup: (value: string) => void; issues: InterceptionIssue[]; issue: string; setIssue: (value: string) => void; loading: boolean; refresh: () => void }) {
  const selected = groups.find((group) => group.category === activeGroup);
  return <><div className="interception-plate-tabs">{groups.map((group) => <button type="button" key={group.category} className={activeGroup === group.category ? 'active' : ''} onClick={() => setActiveGroup(group.category)}>{group.category}</button>)}</div><div className="interception-plate-toolbar"><p>提示：当前盘面显示每个玩法的拦货额度、已拦金额与剩余额度。</p><IssueSelect label="期号" value={issue} issues={issues} onChange={setIssue}/><button type="button" onClick={refresh}><ReloadOutlined/>刷新</button></div><div className="interception-plate-card">{loading ? <Loading/> : selected?.items.length ? <div className="interception-plate-grid">{selected.items.map((item) => <article key={item.odds_id}><header><strong>{item.name}</strong><span>赔率 {item.odds}</span></header><dl><div><dt>设置额度</dt><dd>{item.limit}</dd></div><div><dt>已拦金额</dt><dd>{item.used}</dd></div><div><dt>剩余额度</dt><dd>{item.remaining}</dd></div></dl>{item.numbers.length > 0 && <ul>{item.numbers.map((number) => <li key={number.number}><span>{number.number}</span><b>{number.used}</b></li>)}</ul>}</article>)}</div> : <EmptyState/>}</div></>;
}
