import { DoubleRightOutlined } from '@ant-design/icons';
import { Button, DatePicker, Empty, Input, Pagination, Select, Spin } from 'antd';
import dayjs from 'dayjs';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { getAuditLogs, type AuditLog, type AuditLogList } from '../../api/user';

type LogType = 'interception' | 'account' | 'login';
const tabs: Array<{ key: LogType; label: string }> = [
  { key: 'interception', label: '拦货赚水修改日志' },
  { key: 'account', label: '账号修改日志' },
  { key: 'login', label: '登录日志' },
];
const empty: AuditLogList = { list: [], total: 0, page: 1, page_size: 40 };
const today = () => { const date = new Date(); return new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 10); };

export function LogsPage() {
  const [type, setType] = useState<LogType>('interception');
  const [username, setUsername] = useState('');
  const [operator, setOperator] = useState('');
  const [content, setContent] = useState('');
  const [viewScope, setViewScope] = useState('all');
  const [startDate, setStartDate] = useState(today);
  const [endDate, setEndDate] = useState(today);
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState(40);
  const [loading, setLoading] = useState(false);
  const [result, setResult] = useState<AuditLogList>(empty);
  const [queryVersion, setQueryVersion] = useState(0);
  const currentTitle = useMemo(() => tabs.find((item) => item.key === type)?.label || '', [type]);

  const load = useCallback(async () => {
    setLoading(true);
    try {
      const response = await getAuditLogs({ type, username: type === 'login' ? username : operator, target_username: type === 'login' ? undefined : username, content: type === 'login' ? undefined : content, start_date: type === 'login' ? startDate : undefined, end_date: type === 'login' ? endDate : undefined, view_scope: viewScope, page, page_size: pageSize });
      setResult(response.data.data || { ...empty, page_size: pageSize });
    } catch { setResult({ ...empty, page_size: pageSize }); }
    finally { setLoading(false); }
  }, [content, endDate, operator, page, pageSize, queryVersion, startDate, type, username, viewScope]);
  useEffect(() => { void load(); }, [load]);

  const switchType = (next: LogType) => { setType(next); setPage(1); setUsername(''); setOperator(''); setContent(''); setViewScope('all'); };
  const submit = (event: React.FormEvent) => { event.preventDefault(); setPage(1); setQueryVersion((value) => value + 1); };
  const rowNumber = (index: number) => (page - 1) * pageSize + index + 1;

  return <section className="agent-logs-page">
    <div className="agent-logs-location">
      <div className="agent-logs-path"><strong>位置</strong><DoubleRightOutlined /><span>日志</span><DoubleRightOutlined /><span>{currentTitle}</span></div>
      <nav className="agent-log-tabs">{tabs.map((tab) => <button key={tab.key} type="button" className={type === tab.key ? 'active' : ''} onClick={() => switchType(tab.key)}>{tab.label}</button>)}</nav>
    </div>
    <form className={`agent-log-filters ${type === 'login' ? 'login-filter' : ''}`} onSubmit={submit}>
      {type === 'login' ? <>
        <fieldset><legend>账号：</legend><Input value={username} onChange={(event) => setUsername(event.target.value)} placeholder="请输入账号名" /></fieldset>
        <fieldset className="log-date-field"><legend>登录时间：</legend><DatePicker value={startDate ? dayjs(startDate) : null} onChange={(date) => setStartDate(date ? date.format('YYYY-MM-DD') : '')} placeholder="开始日期" /><span>至</span><DatePicker value={endDate ? dayjs(endDate) : null} onChange={(date) => setEndDate(date ? date.format('YYYY-MM-DD') : '')} placeholder="结束日期" /></fieldset>
      </> : <>
        <fieldset><legend>被修改账号：</legend><Input value={username} onChange={(event) => setUsername(event.target.value)} /></fieldset>
        <fieldset><legend>操作账号：</legend><Input value={operator} onChange={(event) => setOperator(event.target.value)} /></fieldset>
        <fieldset className="log-small-field"><legend>内容：</legend><Select value={content} onChange={setContent} options={[{ value: '', label: '全部' }, { value: 'credit', label: '分数额度' }, { value: 'status', label: '账号状态' }, { value: 'password', label: '密码' }, { value: 'interception', label: '拦货赚水' }]} /></fieldset>
      </>}
      <fieldset className="log-small-field"><legend>查看对象：</legend><Select value={viewScope} onChange={setViewScope} options={[{ value: 'all', label: '所有' }, { value: 'self', label: '仅查看自己' }, { value: 'subordinate', label: '仅查看下线' }]} /></fieldset>
      <Button className="agent-log-submit" type="primary" htmlType="submit">提交</Button>
    </form>
    <div className="agent-log-table-card">
      {loading ? <div className="agent-log-loading"><Spin /></div> : <>
        <table className="agent-log-table">{type === 'login' ? <LoginTable rows={result.list} rowNumber={rowNumber} /> : <ChangeTable type={type} rows={result.list} rowNumber={rowNumber} />}</table>
        {result.list.length === 0 && <div className="agent-log-empty"><Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="暂无数据" /></div>}
      </>}
    </div>
    <div className="agent-log-pagination"><span>总计：<b>{result.total}</b> 条数据</span><Pagination current={page} pageSize={pageSize} total={result.total} showSizeChanger pageSizeOptions={[20,40,80]} locale={{ items_per_page: '条/页' }} onChange={(next, size) => { setPage(next); setPageSize(size); }} /></div>
  </section>;
}

function LoginTable({ rows, rowNumber }: { rows: AuditLog[]; rowNumber: (index: number) => number }) {
  return <><thead><tr><th>编号</th><th>账号</th><th>设备</th><th>登录时间</th></tr></thead><tbody>{rows.map((row, index) => <tr key={row.id}><td>{rowNumber(index)}</td><td>{row.username || '---'}</td><td>{row.device || '电脑'}</td><td>{row.created_at}</td></tr>)}</tbody></>;
}

function ChangeTable({ type, rows, rowNumber }: { type: LogType; rows: AuditLog[]; rowNumber: (index: number) => number }) {
  return <><thead><tr><th>编号</th><th>操作账号</th><th>被修改账号</th><th>内容</th>{type === 'account' ? <><th>操作前</th><th>操作后</th></> : <th>操作内容</th>}<th>操作时间</th></tr></thead><tbody>{rows.map((row, index) => <tr key={row.id}><td>{rowNumber(index)}</td><td>{row.username || '---'}</td><td>{row.target_username || '---'}</td><td>{row.content || '---'}</td>{type === 'account' ? <><td>{row.before_value || '---'}</td><td>{row.after_value || '---'}</td></> : <td>{row.after_value || row.content || '---'}</td>}<td>{row.created_at}</td></tr>)}</tbody></>;
}
