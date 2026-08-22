import { DoubleRightOutlined } from '@ant-design/icons';
import { Empty, Spin } from 'antd';
import { useEffect, useState } from 'react';
import { getResults, type ResultRow } from '../../api/user';

export function ResultsPage({ lottery }: { lottery: string }) {
  const [rows, setRows] = useState<ResultRow[]>([]);
  const [loading, setLoading] = useState(false);
  useEffect(() => {
    let active = true;
    setLoading(true);
    getResults({ lottery }).then((response) => active && setRows(response.data.data?.list || [])).catch(() => active && setRows([])).finally(() => active && setLoading(false));
    return () => { active = false; };
  }, [lottery]);
  return <section className="results-numbers-page">
    <div className="results-location">
      <div className="results-location-path"><strong>位置</strong><DoubleRightOutlined /><span>开奖号码</span></div>
    </div>
    {loading ? <div className="results-loading"><Spin /></div> : <div className="results-content">
      <div className="results-card">
        <table className="results-number-table">
          <colgroup><col /><col /><col /><col /><col /><col /><col /></colgroup>
          <thead><tr><th>期号</th><th>开奖时间</th><th>佰</th><th>拾</th><th>个</th><th>和值</th><th>跨度</th></tr></thead>
          <tbody>{rows.map((row) => { const numbers = row.numbers.split(',').filter(Boolean); return <tr key={row.issue_no}>
            <td><strong className="result-issue">{row.issue_no}</strong></td><td>{row.draw_time || '-'}</td>
            {[0,1,2].map((index) => <td key={index}>{numbers[index] ? <i className="result-ball">{numbers[index]}</i> : <i className="result-ball result-ball-pending" />}</td>)}
            <td>{row.pending ? '---' : `${row.sum_value} / ${row.size} / ${row.parity}`}</td><td>{row.pending ? '---' : <strong className="result-span">{row.span_value}</strong>}</td>
          </tr>; })}</tbody>
        </table>
        {rows.length === 0 && <div className="results-empty"><Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="暂无数据" /></div>}
      </div>
    </div>}
  </section>;
}
