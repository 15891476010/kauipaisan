import { useEffect, useRef, useState } from "react";
import { App as AntdApp, Input, Modal, Switch, Tooltip } from "antd";
import {
  DeleteOutlined,
} from "@ant-design/icons";
import {
  createQuickTag,
  deleteQuickTag,
  getQuickSettings,
  getRules,
  placeQuickEntry,
  previewQuickEntry,
  saveQuickSettings,
  type Lottery,
  type QuickEntryLine,
  type QuickPreview,
  type QuickTag,
  type RuleSettings,
} from "../../../api/user";
import { apiErrorMessage } from "../../../utils/request";
import { QuickResultTable } from "../../../components/QuickResultTable";
import { RuleInstructionsModal } from "../../../components/RuleInstructionsModal";
import { displayAmount, lotteryTiming } from "../shared";
import { BettingRecordsPage } from "./BettingRecordsPage";
import { StopDropPage } from "./StopDropPage";

export function QuickEntryPage({
  lotteries,
  selectedLottery,
}: {
  lotteries: Lottery[];
  selectedLottery?: Lottery;
}) {
  const { message, modal } = AntdApp.useApp();
  const [text, setText] = useState("");
  const [replaceFrom, setReplaceFrom] = useState("");
  const [replaceTo, setReplaceTo] = useState("");
  const [tagOpen, setTagOpen] = useState(false);
  const [tagName, setTagName] = useState("");
  const [tags, setTags] = useState<QuickTag[]>([]);
  const [tab, setTab] = useState("快速录入");
  const [rulesOpen, setRulesOpen] = useState(false);
  const [ruleSettings, setRuleSettings] = useState<RuleSettings>();
  const [lottery, setLottery] = useState("福彩3D");
  const [now, setNow] = useState(Date.now());
  useEffect(() => {
    const timer = window.setInterval(() => setNow(Date.now()), 1_000);
    return () => window.clearInterval(timer);
  }, []);
  const timing = lotteryTiming(selectedLottery, now);
  const locked = tab === "快速录入" && timing.locked;
  const showMask = locked && timing.mask;
  const [generatedLines, setGeneratedLines] = useState<QuickEntryLine[]>([]);
  const [generatedTotal, setGeneratedTotal] = useState({
    count: 0,
    codeCount: 0,
    amount: "0.00",
  });
  const [generating, setGenerating] = useState(false);
  const [warningAmount, setWarningAmount] = useState("0");
  const suppressResultRecognition = useRef(false);
  useEffect(() => {
    getRules()
      .then((response) => {
        if (response.data?.data) setRuleSettings(response.data.data);
      })
      .catch(() => setRuleSettings(undefined));
  }, []);
  useEffect(() => {
    getQuickSettings()
      .then((response) => {
        const data = response.data?.data;
        if (!data) return;
        setTags(data.tags || []);
        const p = data.preferences || {};
        setLottery(String(p.lottery || lotteries[0]?.name || "福彩3D"));
        setWarningAmount(String(p.warningAmount || "0"));
        setOptions([
          p.autoBet !== false,
          p.recognize === true,
          p.copyTicket === true,
          p.copyHeader === true,
          p.textMode === true,
        ]);
      })
      .catch(() => undefined);
  }, [lotteries]);
  const [options, setOptions] = useState([true, false, false, false, false]);
  const optionTips = [
    "",
    "当文本被编辑后是否立即识别",
    "下注成功后是否自动复制小票信息",
    "复制小票信息时，是否带上时间和总金额",
    "切换复制文本或者号码",
  ];
  const optionNames = [
    "粘贴后自动下注",
    "立即识别",
    "自动复制小票",
    "复制小票头尾",
    "文本或号码",
  ];
  const persistPreferences = (
    nextOptions: boolean[],
    nextLottery = lottery,
    nextWarning = warningAmount,
  ) => {
    setOptions(nextOptions);
    void saveQuickSettings({
      autoBet: nextOptions[0],
      recognize: nextOptions[1],
      copyTicket: nextOptions[2],
      copyHeader: nextOptions[3],
      textMode: nextOptions[4],
      lottery: nextLottery,
      warningAmount: nextWarning,
    }).catch((error) => message.error(apiErrorMessage(error, "设置保存失败")));
  };
  const generateText = async (
    sourceText: string,
    showMessage = true,
  ): Promise<QuickPreview | null> => {
    if (!sourceText.trim()) {
      if (showMessage) message.warning("请输入投注文本");
      return null;
    }
    setGenerating(true);
    try {
      const response = await previewQuickEntry({ text: sourceText, lottery });
      const data = response.data?.data || null;
      setGeneratedLines(data?.lines || []);
      setGeneratedTotal({
        count: data?.count || 0,
        codeCount: data?.code_count ?? data?.count ?? 0,
        amount: data?.amount || "0.00",
      });
      const warning = Number(warningAmount);
      if (warning > 0 && Number(data?.amount || 0) >= warning)
        message.warning(
          `总金额已达到预警金额 ¥${displayAmount(warningAmount)}`,
        );
      return data;
    } catch (error) {
      setGeneratedLines([]);
      setGeneratedTotal({ count: 0, codeCount: 0, amount: "0.00" });
      message.error(apiErrorMessage(error, "生成失败"));
      return null;
    } finally {
      setGenerating(false);
    }
  };
  const generate = () => void generateText(text);
  useEffect(() => {
    if (suppressResultRecognition.current) {
      suppressResultRecognition.current = false;
      return;
    }
    if (!options[1] || !text.trim()) return;
    const timer = window.setTimeout(() => {
      void generateText(text, false);
    }, 450);
    return () => window.clearTimeout(timer);
  }, [text, options[1], lottery]);
  const copyTicket = async (
    sourceText: string,
    lines: QuickEntryLine[],
    includeHeader: boolean,
  ) => {
    const body = lines
      .filter((line) => line.status === "success")
      .map(
        (line) => `${line.number_text} ${line.category || ""}各${line.amount}`,
      )
      .join("\n");
    const ticket = options[4] ? sourceText : body || sourceText;
    const header = includeHeader
      ? `快排小票\n${new Date().toLocaleString()}\n`
      : "";
    try {
      await navigator.clipboard.writeText(`${header}${ticket}`);
      message.success("小票已复制");
    } catch {
      message.error("复制失败，请检查浏览器剪贴板权限");
    }
  };
  const submitBet = async (
    sourceText: string,
    preview: QuickPreview,
    showSuccess = true,
  ) => {
    const valid = preview.lines.filter((line) => line.status === "success");
    if (!valid.length) {
      if (showSuccess) message.warning("请先生成有效投注内容");
      return false;
    }
    try {
      const response = await placeQuickEntry({
        text: sourceText,
        lottery,
        confirmed: true,
      });
      if (showSuccess)
        message.success(response.data?.message || "下注提交成功");
      if (options[2]) await copyTicket(sourceText, valid, options[3]);
      setGeneratedLines([]);
      setGeneratedTotal({ count: 0, codeCount: 0, amount: "0.00" });
      window.dispatchEvent(new Event("bet-records-updated"));
      window.dispatchEvent(
        new CustomEvent("profile-updated", {
          detail: { amount: response.data?.data?.amount || preview.amount },
        }),
      );
      return true;
    } catch (error) {
      message.error(apiErrorMessage(error, "下注失败"));
      return false;
    }
  };
  const place = () => {
    const preview: QuickPreview = {
      lines: generatedLines,
      count: generatedTotal.count,
      amount: generatedTotal.amount,
      formatted_text: text,
    };
    if (!preview.lines.some((line) => line.status === "success")) {
      message.warning("请先生成有效投注内容");
      return;
    }
    modal.confirm({
      title: "确认下注",
      content: `共 ${generatedTotal.codeCount} 码，共 ¥ ${preview.amount}，确认提交吗？`,
      okText: "确认下注",
      cancelText: "取消",
      onOk: () => submitBet(text, preview),
    });
  };
  return (
    <div className={`entry${showMask ? " entry-locked" : ""}`}>
      {showMask && (
        <div className="entry-lock-overlay" aria-label="当前不可下注" />
      )}
      <div className="tabs">
        {["快速录入", "投注记录", "停押降水"].map((x) => (
          <button
            className={tab === x ? "active" : ""}
            onClick={() => setTab(x)}
            key={x}
          >
            {x}
          </button>
        ))}
      </div>
      {tab === "快速录入" ? (
        <>
          <div className="replace">
            将：
            <input
              value={replaceFrom}
              onChange={(event) => setReplaceFrom(event.target.value)}
            />
            替换为：
            <input
              value={replaceTo}
              onChange={(event) => setReplaceTo(event.target.value)}
            />
            <button
              type="button"
              onClick={() => {
                if (replaceFrom)
                  setText((value) => value.split(replaceFrom).join(replaceTo));
              }}
            >
              替换
            </button>
            <button
              className="new"
              type="button"
              onClick={() => setTagOpen(true)}
            >
              ＋ 新标签
            </button>
            {tags.length > 0 && (
              <div className="tag-list">
                {tags.map((tag) => (
                  <span key={tag.id}>
                    <button type="button" onClick={() => setText(tag.name)}>
                      {tag.name}
                    </button>
                    <button
                      type="button"
                      aria-label={`删除标签 ${tag.name}`}
                      onClick={async () => {
                        try {
                          await deleteQuickTag(tag.id);
                          setTags((current) =>
                            current.filter((item) => item.id !== tag.id),
                          );
                        } catch (error) {
                          message.error(apiErrorMessage(error, "标签删除失败"));
                        }
                      }}
                    >
                      ×
                    </button>
                  </span>
                ))}
              </div>
            )}
          </div>
          <div
            className={`text-entry ${lottery === "排列三" ? "lottery-ti" : "lottery-fu"}`}
          >
            <textarea
              value={text}
              onChange={(e) => setText(e.target.value.slice(0, 10000))}
              onPaste={(event) => {
                const pasted = event.clipboardData
                  .getData("text")
                  .slice(0, 10000);
                event.preventDefault();
                if (options[0] && options[1])
                  suppressResultRecognition.current = true;
                setText(pasted);
                if (options[0])
                  window.setTimeout(() => {
                    void generateText(pasted).then((preview) => {
                      if (preview) void submitBet(pasted, preview);
                    });
                  }, 0);
              }}
              placeholder="请复制文本"
              maxLength={10000}
            />
            <div className="text-entry-footer">
              <button
                type="button"
                className="clear-text"
                disabled={!text}
                onClick={() => setText("")}
              >
                <DeleteOutlined /> 清空
              </button>
              <span>{text.length.toLocaleString()}/10,000</span>
            </div>
          </div>
          <div className="options">
            {optionNames.map((name, index) => (
              <label className={`option-card option-card-${index}`} key={name}>
                <Tooltip title={optionTips[index] || undefined} placement="top">
                  <span className={`option-label${index === 0 ? " info" : ""}`}>
                    {index > 0 && <span className="option-hint">?</span>}
                    {name}
                  </span>
                </Tooltip>
                <Switch
                  checked={options[index]}
                  checkedChildren={index === 4 ? "文" : "是"}
                  unCheckedChildren={index === 4 ? "号" : "否"}
                  onChange={(checked) => {
                    const next = options.map((value, item) =>
                      item === index ? checked : value,
                    );
                    persistPreferences(next);
                  }}
                />
              </label>
            ))}
            <label className="option-card option-card-lottery">
              <span className="option-label">默认彩种</span>
              {lotteries.map((item) => {
                const tone = item.name === "排列三" ? "ti" : "fu";
                return (
                  <b
                    key={item.id}
                    className={`lottery-choice lottery-choice-${tone}${lottery === item.name ? " selected" : ""}`}
                    onClick={() => {
                      setLottery(item.name);
                      persistPreferences(options, item.name);
                    }}
                  >
                    {item.name}
                  </b>
                );
              })}
            </label>
          </div>
          <div className="actions">
            <button type="button" onClick={place}>
              下 注
            </button>
            <button
              type="button"
              className="gold"
              onClick={generate}
              disabled={generating}
            >
              {generating ? "生成中" : "生 成"}
            </button>
            <button
              type="button"
              className="rule-action"
              onClick={() => setRulesOpen(true)}
            >
              规则说明
            </button>
            <span className="action-separator" aria-hidden="true" />
            <span className="action-total">
              <span>
                <i>共</i>
                <b>{generatedTotal.codeCount}</b>
                <i>码</i>
              </span>
              <span>
                <i>共 ¥</i>
                <b>{displayAmount(generatedTotal.amount)}</b>
              </span>
            </span>
            <span className="action-separator" aria-hidden="true" />
            <div className="warning-label">
              <label htmlFor="warning-amount">大金额预警：</label>
            </div>
            <input
              id="warning-amount"
              className="warning-input"
              value={warningAmount}
              onFocus={(event) => event.currentTarget.select()}
              onChange={(event) => {
                const value = event.target.value.replace(/[^\d.]/g, "") || "0";
                setWarningAmount(value);
                persistPreferences(options, lottery, value);
              }}
              inputMode="decimal"
            />
          </div>
          <QuickResultTable
            lines={generatedLines}
            onChange={(lines, reason) => {
              if (reason === "structure")
                suppressResultRecognition.current = true;
              setText(lines.map((line) => line.raw_text).join("\n"));
              setGeneratedLines(lines);
              const successful = lines.filter(
                (line) => line.status === "success",
              );
              setGeneratedTotal({
                count: successful.reduce(
                  (total, line) => total + line.count,
                  0,
                ),
                codeCount: successful.reduce(
                  (total, line) => total + (line.code_count ?? line.count),
                  0,
                ),
                amount: successful
                  .reduce((total, line) => total + Number(line.amount || 0), 0)
                  .toFixed(2),
              });
            }}
          />
          <RuleInstructionsModal
            open={rulesOpen}
            onClose={() => setRulesOpen(false)}
            rules={ruleSettings}
          />
          <Modal
            open={tagOpen}
            title="添加新标签"
            okText="确认"
            cancelText="关闭"
            onCancel={() => {
              setTagOpen(false);
              setTagName("");
            }}
            onOk={async () => {
              const value = tagName.trim();
              if (!value) return;
              try {
                const response = await createQuickTag(value);
                const created = response.data?.data;
                setTags((current) => [
                  ...current,
                  created || { id: Date.now(), name: value },
                ]);
                setTagOpen(false);
                setTagName("");
              } catch (error) {
                message.error(apiErrorMessage(error, "标签添加失败"));
              }
            }}
            width={500}
            className="tag-modal"
          >
            <Input
              value={tagName}
              onChange={(event) => setTagName(event.target.value)}
              maxLength={30}
              autoFocus
            />
          </Modal>
        </>
      ) : tab === "投注记录" ? (
        <BettingRecordsPage />
      ) : (
        <StopDropPage />
      )}
    </div>
  );
}
