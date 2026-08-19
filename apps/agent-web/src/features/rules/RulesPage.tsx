import { useEffect, useState } from "react";
import DOMPurify from "dompurify";
import { AlertOutlined, ReloadOutlined } from "@ant-design/icons";
import { apiErrorMessage } from "../../utils/request";
import { getRules, type RuleSettings } from "../../api/user";

const tabs = [
  ["basic", "基础玩法"],
  ["special", "特殊打法"],
  ["amount", "总金额"],
  ["text", "文本规范"],
] as const;

function RuleContent({ source }: { source: string }) {
  const isHtml = /<\/?[a-z][^>]*>/i.test(source);
  if (isHtml) {
    const safeHtml = DOMPurify.sanitize(source, {
      FORBID_TAGS: ["script", "iframe", "style"],
      FORBID_ATTR: ["onerror", "onclick", "onload", "javascript"],
    });
    return <div className="agent-rules-rich" dangerouslySetInnerHTML={{ __html: safeHtml }} />;
  }
  return (
    <div className="agent-rules-rich agent-rules-plain">
      {source.split(/\r?\n/).map((line, index) => {
        const value = line.trim();
        if (!value) return <div className="agent-rules-gap" key={index} />;
        if (value.startsWith("【重点】")) return <p key={index}><mark>{value.slice(4)}</mark></p>;
        if (/^[一二三四五六七八九十]+、/.test(value)) return <h3 key={index}>{value}</h3>;
        return <p key={index}>{value}</p>;
      })}
    </div>
  );
}

export function RulesPage() {
  const [rules, setRules] = useState<RuleSettings | null>(null);
  const [activeTab, setActiveTab] = useState<(typeof tabs)[number][0]>("text");
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState("");

  const loadRules = () => {
    setLoading(true);
    setError("");
    void getRules()
      .then((response) => {
        if (!response.data.data) throw new Error("规则说明暂无内容");
        setRules(response.data.data);
      })
      .catch((reason: unknown) => setError(apiErrorMessage(reason, "规则说明加载失败")))
      .finally(() => setLoading(false));
  };

  useEffect(() => { loadRules(); }, []);

  const active = tabs.find(([key]) => key === activeTab) || tabs[3];
  const content = rules?.[active[0]] || "";

  return (
    <section className="agent-rules-page">
      <header className="agent-rules-header">
        <div className="agent-rules-title"><AlertOutlined /> <span>{rules?.title || "规则说明"}</span></div>
        <button type="button" className="agent-rules-refresh" onClick={loadRules} disabled={loading} title="刷新规则说明">
          <ReloadOutlined /> 刷新
        </button>
      </header>
      <div className="agent-rules-tabs" role="tablist" aria-label="规则说明分类">
        {tabs.map(([key, label]) => (
          <button key={key} type="button" role="tab" aria-selected={activeTab === key} className={activeTab === key ? "active" : ""} onClick={() => setActiveTab(key)}>{label}</button>
        ))}
      </div>
      <div className="agent-rules-content" aria-live="polite">
        {loading && <div className="agent-rules-state">正在加载规则说明...</div>}
        {!loading && error && <div className="agent-rules-state agent-rules-error"><span>{error}</span><button type="button" onClick={loadRules}>重试</button></div>}
        {!loading && !error && <RuleContent source={content} />}
      </div>
    </section>
  );
}
