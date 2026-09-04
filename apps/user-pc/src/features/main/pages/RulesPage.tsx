import { useEffect, useState } from "react";
import { Empty } from "antd";
import DOMPurify from "dompurify";
import { getRules, type Lottery, type RuleSettings } from "../../../api/user";

function defaultLotteryRuleContent(lottery?: Lottery): string {
  const isPl3 = /排列三|pl3/i.test(`${lottery?.code || ""} ${lottery?.name || ""}`);
  const name = isPl3 ? "排列三" : lottery?.name || "福彩3D";
  const issuer = isPl3
    ? "国家体育总局《体育彩票发行与销售管理暂行办法》以及《计算机销售体育彩票管理暂行办法》"
    : "国家福利彩票发行管理中心《福利彩票发行与销售管理暂行办法》";
  const resultRule = isPl3
    ? "每期开奖后，以国家体育总局体育彩票管理中心公布的开奖号码为准。"
    : "每期开奖后，以国家福利彩票发行管理中心公布的开奖号码为准。";
  return `<ul class="rule-list">
  <li><h4>本站销售<span class="emphasize">${name}</span>电脑数字型彩票游戏规则</h4></li>
  <li><h5>第一章　总　则</h5><p>第一条　根据财政部《彩票发行与销售管理暂行规定》和${issuer}，制定本游戏规则。<br>第二条　本站彩票实行自愿购买,量力而行；凡下注者即被视为同意并遵守本规则。</p></li>
  <li><h5>第二章　游戏方法</h5><p>第三条　“${name}”的每注彩票由000-999中的任意3位自然数排列而成。<span class="emphasize">本站主要取全部3位开奖号码做为游戏规则！</span></p>
    <div><span class="emphasize">假设下列为开奖结果:</span><ul><li><span>百</span><span>十</span><span>个</span></li><li><span>1</span><span>2</span><span>3</span></li></ul>
      <p><span>依照开奖结果，中奖范例如下：</span><span>直选中奖：</span><b>123</b><span>二码定位中奖：</span><b>12x；1x3；x23</b><span>一码定位中奖：</span><b>1xx；x2x；xx3</b><span>独胆中奖：</span><b>1；2；3</b><span>双飞中奖：</span><b>12；13；23</b><span>组选中奖：</span><b>123 组</b></p>
    </div>
  </li>
  <li><h5>第三章　开奖及公告</h5><p>第四条　“${name}”每日开奖，摇奖过程在公证人员监督下进行，通过电视台播出。<br>${resultRule}</p></li>
  <li><h5>第四章　附　则</h5><p>第六条　本站游戏规则最终解释权归本公司。<br></p></li>
</ul>`;
}

export function RulesPage({ selectedLottery }: { selectedLottery?: Lottery }) {
  const [rules, setRules] = useState<RuleSettings>();
  const [loading, setLoading] = useState(false);
  useEffect(() => {
    let active = true;
    setLoading(true);
    const lottery = selectedLottery?.code || selectedLottery?.name || "";
    const load = async () => {
      try {
        const response = await getRules(lottery ? { lottery } : undefined);
        let value = response.data?.data;
        // Prefer the lottery document. If it is not configured yet, use the
        // same per-lottery rule template as the reference site instead of
        // showing unrelated quick-entry instructions.
        if (lottery && !String(value?.content || "").trim()) {
          value = {
            ...(value || {}),
            title: `${selectedLottery?.name || "规则"}规则说明`,
            content: defaultLotteryRuleContent(selectedLottery),
          } as RuleSettings;
        }
        if (active) setRules(value);
      } catch {
        if (active) setRules(undefined);
      } finally {
        if (active) setLoading(false);
      }
    };
    void load();
    return () => {
      active = false;
    };
  }, [selectedLottery?.id, selectedLottery?.code, selectedLottery?.name]);
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
            <div className="rule-html-content" dangerouslySetInnerHTML={{ __html: safeHtml }} />
          ) : (
            <div className="rule-plain-content">{content}</div>
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
