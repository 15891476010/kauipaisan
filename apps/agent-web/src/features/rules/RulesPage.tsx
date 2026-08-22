import { DoubleRightOutlined } from '@ant-design/icons';
import { Spin } from 'antd';
import DOMPurify from 'dompurify';
import { useEffect, useMemo, useState } from 'react';
import { getRules } from '../../api/user';
import { apiErrorMessage } from '../../utils/request';

function RichRule({ source }: { source: string }) {
  const safeHtml = useMemo(() => DOMPurify.sanitize(source, {
    FORBID_TAGS: ['script', 'iframe', 'object', 'embed'],
    FORBID_ATTR: ['onerror', 'onclick', 'onload'],
  }), [source]);
  return <article className="agent-rule-rich" dangerouslySetInnerHTML={{ __html: safeHtml }} />;
}

export function RulesPage({ lottery }: { lottery: string }) {
  const [content, setContent] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [version, setVersion] = useState(0);

  useEffect(() => {
    let active = true;
    setLoading(true); setError(''); setContent('');
    void getRules({ lottery }).then((response) => {
      if (!active) return;
      const data = response.data.data;
      const value = data?.content || [data?.basic, data?.special, data?.amount, data?.text].filter(Boolean).join('<br><br>');
      setContent(value || '');
    }).catch((reason: unknown) => { if (active) setError(apiErrorMessage(reason, '规则说明加载失败')); })
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [lottery, version]);

  return <section className="agent-rule-page">
    <div className="agent-rule-location"><div className="agent-rule-path"><strong>位置</strong><DoubleRightOutlined /><span>规则说明</span></div></div>
    <div className="agent-rule-shell">
      {loading && <div className="agent-rule-state"><Spin /></div>}
      {!loading && error && <div className="agent-rule-state error"><span>{error}</span><button type="button" onClick={() => setVersion((value) => value + 1)}>重试</button></div>}
      {!loading && !error && content && <RichRule source={content} />}
      {!loading && !error && !content && <div className="agent-rule-state">当前彩种暂无规则说明</div>}
    </div>
  </section>;
}
