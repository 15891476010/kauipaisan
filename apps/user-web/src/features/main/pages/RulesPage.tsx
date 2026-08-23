import { useEffect, useState } from "react";
import { Empty } from "antd";
import DOMPurify from "dompurify";
import { getRules, type Lottery, type RuleSettings } from "../../../api/user";

export function RulesPage({ selectedLottery }: { selectedLottery?: Lottery }) {
  const [rules, setRules] = useState<RuleSettings>();
  const [loading, setLoading] = useState(false);
  useEffect(() => {
    setLoading(true);
    getRules({ lottery: selectedLottery?.code || selectedLottery?.name || "" })
      .then((response) => setRules(response.data?.data))
      .catch(() => setRules(undefined))
      .finally(() => setLoading(false));
  }, [selectedLottery?.id]);
  const content = rules?.content || "";
  const isHtml = /<\/?[a-z][^>]*>/i.test(content);
  const safeHtml = isHtml
    ? DOMPurify.sanitize(content, {
        FORBID_TAGS: ["script", "iframe", "style"],
        FORBID_ATTR: ["onerror", "onclick", "onload"],
      })
    : "";
  return (
    <div className="rules-page">
      <section className="rules-content">
        {content ? (
          isHtml ? (
            <div dangerouslySetInnerHTML={{ __html: safeHtml }} />
          ) : (
            <div>{content}</div>
          )
        ) : (
          <Empty
            image={Empty.PRESENTED_IMAGE_SIMPLE}
            description="暂无规则内容"
          />
        )}
        {loading && (
          <div
            className="page-local-loading"
            role="status"
            aria-label="加载中"
          />
        )}
      </section>
    </div>
  );
}
