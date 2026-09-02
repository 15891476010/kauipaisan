import { Modal } from "antd";
import { InfoCircleOutlined } from "@ant-design/icons";
import DOMPurify from "dompurify";
import { useEffect, useMemo, useRef, useState } from "react";
import type { ReactNode } from "react";
import type { RuleSettings } from "../api/user";
import "./RuleInstructionsModal.css";

type Props = { open: boolean; onClose: () => void };

const Highlight = ({ children }: { children: ReactNode }) => <span className="rule-highlight">{children}</span>;

function ConfiguredRules({ source }: { source: string }) {
  if (/<\/?[a-z][^>]*>/i.test(source)) {
    const safeHtml = DOMPurify.sanitize(source, { FORBID_TAGS: ["script", "iframe", "style"], FORBID_ATTR: ["onerror", "onclick", "onload"] });
    return <div className="rule-content rule-rich-content" dangerouslySetInnerHTML={{ __html: safeHtml }} />;
  }
  return <div className="rule-content">{source.split(/\r?\n/).map((line, index) => {
    const value = line.trim();
    if (!value) return <div key={index} className="rule-gap" />;
    if (value.startsWith('【重点】')) return <p key={index}><Highlight>{value.slice(4)}</Highlight></p>;
    if (/^[一二三四五六七八九十]+、/.test(value)) return <h4 key={index}>{value}</h4>;
    return <p key={index}>{value}</p>;
  })}</div>;
}

function TextRules() {
  return (
    <div className="rule-content">
      <p>123</p><p>456</p><p>789</p><p>一直一组</p>
      <p><Highlight>建议写成：</Highlight></p>
      <p>123 456 789—一直一组</p>
      <h4>四、金额</h4>
      <p><Highlight>单注金额前面尽量带上【各】字</Highlight></p>
      <p>12 45 89 88 62 飞各6米</p>
      <p><Highlight>总金额前面尽量带上【共】字</Highlight></p>
      <p>1拖2345 5拖23468 6拖123组六各10米共30米</p>
      <p><Highlight>金额单位尽量不要使用【倍】，因为不同代理的倍数标准可能不一样；建议以【元】或【角】为单位</Highlight></p>
      <h4>五、彩种</h4>
      <p><Highlight>彩种文本尽量使用【福】和【体】，其后不要加【3】或【三】，例如：【福三】或【体3】是不建议的</Highlight></p>
      <p><Highlight>彩种文本尽可能放在句首，例如</Highlight></p>
      <p>福123 456—一直一组</p>
      <p><Highlight>支持同时打【福体】，例如</Highlight></p>
      <p>福体345 890直组各一倍</p>
      <p><Highlight>如果彩种文本的后面紧跟数字3，那么在其之间建议打上一个空格；在一些特殊情形，彩种后面可能会紧跟【3】</Highlight></p>
      <p>福3 4 5 6 7 8 9各10米</p>
      <p><Highlight>这句话带有理解偏差，是【福 34567胆各10米】还是【福3 4567胆各10米】？</Highlight></p>
      <h4>六、分隔符</h4>
      <p><Highlight>号码、金额和玩法之间建议使用空格分隔，不要全部连写</Highlight></p>
      <p>例如：福 123 456 直选 各10元</p>
      <p><Highlight>多组内容建议换行输入，每一行只表达一组完整注单</Highlight></p>
      <p>这样可以减少号码、玩法和金额之间的理解偏差。</p>
      <h4>七、提交前检查</h4>
      <p><Highlight>生成后请检查识别出的彩种、号码数量、玩法和总金额</Highlight></p>
      <p>识别结果与原始文本不一致时，请先修改文本再重新生成。</p>
      <p><Highlight>下注成功后请到“下注明细”确认最终注单</Highlight></p>
      <p>所有注单结算以下注明细中的资料为准。</p>
    </div>
  );
}

const panes: Record<string, ReactNode> = {
  基础玩法: <div className="rule-content"><h4>基础玩法</h4><p>支持直选、组选、定位和胆拖等常用玩法。</p><p>请输入完整的号码、玩法和金额，系统会根据当前彩种进行识别。</p></div>,
  特殊打法: <div className="rule-content"><h4>特殊打法</h4><p>特殊玩法请按照对应格式输入，多个号码之间使用空格分隔。</p><p>如有疑问，请先输入示例并查看识别结果。</p></div>,
  总金额: <div className="rule-content"><h4>总金额</h4><p>每注金额和总金额请使用清晰的单位标识，避免产生歧义。</p><p>系统会在生成投注内容时自动计算号码数量和金额。</p></div>,
  文本规范: <TextRules />,
};

function RuleInstructionsContent({ panes }: { panes: Record<string, ReactNode> }) {
  const keys = Object.keys(panes);
  const [activeKey, setActiveKey] = useState("文本规范");

  return (
    <div className="rule-reference-content">
      <div className="rule-reference-tabs" role="tablist" aria-label="规则分类">
        {keys.map((key) => (
          <span
            key={key}
            className={activeKey === key ? "selected" : ""}
            role="tab"
            aria-selected={activeKey === key}
            tabIndex={activeKey === key ? 0 : -1}
            onClick={() => setActiveKey(key)}
            onKeyDown={(event) => {
              if (event.key === "Enter" || event.key === " ") {
                event.preventDefault();
                setActiveKey(key);
              }
            }}
          >
            {key}
          </span>
        ))}
      </div>
      {keys.map((key) => (
        <div
          key={key}
          className={"rule-reference-pane" + (activeKey === key ? " is-active" : "")}
          role="tabpanel"
          aria-hidden={activeKey !== key}
          hidden={activeKey !== key}
        >
          {panes[key]}
        </div>
      ))}
    </div>
  );
}

export function RuleInstructionsModal({ open, onClose, rules }: Props & { rules?: RuleSettings }) {
  const [modal, contextHolder] = Modal.useModal();
  const modalRef = useRef<{ destroy: () => void } | null>(null);
  const onCloseRef = useRef(onClose);
  onCloseRef.current = onClose;

  const currentPanes = useMemo<Record<string, ReactNode>>(
    () =>
      rules
        ? {
            基础玩法: <ConfiguredRules source={rules.basic || rules.content || ""} />,
            特殊打法: <ConfiguredRules source={rules.special || ""} />,
            总金额: <ConfiguredRules source={rules.amount || ""} />,
            文本规范: <ConfiguredRules source={rules.text || ""} />,
          }
        : panes,
    [rules],
  );

  useEffect(() => {
    if (!open) {
      modalRef.current?.destroy();
      modalRef.current = null;
      return;
    }

    const instance = modal.info({
      title: "规则说明",
      icon: <InfoCircleOutlined />,
      width: 1000,
      centered: true,
      className: "rule-reference-modal",
      content: <RuleInstructionsContent panes={currentPanes} />,
      okText: "关 闭",
      onOk: () => onCloseRef.current(),
      onCancel: () => onCloseRef.current(),
    });
    modalRef.current = instance;

    return () => {
      instance.destroy();
      if (modalRef.current === instance) modalRef.current = null;
    };
  }, [currentPanes, modal, open]);

  return contextHolder;
}
