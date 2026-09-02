import { useEffect, useRef, useState, type CSSProperties } from "react";
import { App as AntdApp, Input, Modal, Switch, Tooltip } from "antd";
import {
  QuestionCircleOutlined,
  RestOutlined,
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
  const [replaceOpen, setReplaceOpen] = useState(false);
  const [replaceUndoText, setReplaceUndoText] = useState<string | null>(null);
  const [tagOpen, setTagOpen] = useState(false);
  const [tagName, setTagName] = useState("");
  const [tags, setTags] = useState<QuickTag[]>([]);
  const [tagDeleting, setTagDeleting] = useState(false);
  const [tab, setTab] = useState("快速录入");
  const [rulesOpen, setRulesOpen] = useState(false);
  const [ruleSettings, setRuleSettings] = useState<RuleSettings>();
  const [lottery, setLottery] = useState("福彩3D");
  const [boardCode, setBoardCode] = useState("A");
  const [now, setNow] = useState(Date.now());
  useEffect(() => {
    const timer = window.setInterval(() => setNow(Date.now()), 1_000);
    return () => window.clearInterval(timer);
  }, []);
  const currentQuickLottery =
    lotteries.find((item) => item.name === lottery) || selectedLottery;
  const timing = lotteryTiming(currentQuickLottery, now);
  const boardOptions = currentQuickLottery?.boards || [{ code: currentQuickLottery?.board_code || "A", name: `${currentQuickLottery?.board_code || "A"}盘` }];
  const activeBoard = boardOptions.find((item) => item.code === boardCode) || boardOptions[0];
  useEffect(() => { if (selectedLottery?.board_code) setBoardCode(selectedLottery.board_code); else if (!boardOptions.some((item) => item.code === boardCode)) setBoardCode(boardOptions[0]?.code || "A"); }, [selectedLottery?.id, selectedLottery?.board_code, boardOptions.length]);
  const showMask = tab === "快速录入" && timing.mask;
  const [generatedLines, setGeneratedLines] = useState<QuickEntryLine[]>([]);
  const [generatedTotal, setGeneratedTotal] = useState({
    count: 0,
    codeCount: 0,
    amount: "0.00",
  });
  const [resultHeight, setResultHeight] = useState(0);
  const [generating, setGenerating] = useState(false);
  const previewRequestId = useRef(0);
  const quickSettingsLoaded = useRef(false);
  const defaultLotteryFallbackNotice = useRef("");
  useEffect(() => {
    if (generatedLines.length === 0) {
      setResultHeight(0);
      return;
    }
    const result = document.querySelector<HTMLElement>('.entry > .quick-result');
    if (!result) return;
    const update = () => setResultHeight(result.getBoundingClientRect().height);
    update();
    const observer = typeof ResizeObserver === "undefined" ? null : new ResizeObserver(update);
    observer?.observe(result);
    return () => observer?.disconnect();
  }, [generatedLines.length]);
  const splitTicketBlocks = (source: string) => {
    const blocks: string[] = []; let current: string[] = []; let blankRun = 0;
    const flush = () => { const value = current.join("\n").trim(); if (value) blocks.push(value); current = []; };
    for (const line of source.split(/\r?\n/)) {
      if (!line.trim()) { blankRun++; if (blankRun >= 2) { flush(); blankRun = 0; } else current.push(line); continue; }
      blankRun = 0;
      current.push(line);
      if (/(?:合计|🈴|^\s*合)\s*\d+(?:\.\d+)?\s*$/u.test(line.trim()) || /(?:一元|两元|二元|\d+元)?\s*单\s*[,，]?\s*(?:一元|两元|二元|\d+元)?\s*组\s*\d+/u.test(line)) flush();
    }
    flush(); return blocks;
  };
  const [warningAmount, setWarningAmount] = useState("");
  const suppressResultRecognition = useRef(false);
  useEffect(() => {
    getRules()
      .then((response) => {
        if (response.data?.data) setRuleSettings(response.data.data);
      })
      .catch(() => setRuleSettings(undefined));
  }, []);
  useEffect(() => {
    if (quickSettingsLoaded.current) return;
    getQuickSettings()
      .then((response) => {
        const data = response.data?.data;
        if (!data) return;
        setTags(data.tags || []);
        const p = data.preferences || {};
        setLottery(String(p.lottery || lotteries.find((item) => item.name === "福彩3D")?.name || lotteries[0]?.name || "福彩3D"));
        const savedWarning = String(p.warningAmount ?? "").trim();
        setWarningAmount(savedWarning === "0" ? "" : savedWarning);
        setOptions([
          p.autoBet !== false,
          p.recognize === true,
          p.copyTicket === true,
          p.copyHeader === true,
          p.textMode === true,
        ]);
        quickSettingsLoaded.current = true;
      })
      .catch(() => { quickSettingsLoaded.current = true; });
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

  useEffect(() => {
    if (!quickSettingsLoaded.current || !lotteries.length) return;
    const current = lotteries.find((item) => item.name === lottery);
    if (!current) return;

    const currentTiming = lotteryTiming(current, now);
    if (currentTiming.canBet) {
      defaultLotteryFallbackNotice.current = "";
      return;
    }

    // The reference only falls back from 福彩3D to 体彩. Once体彩 is
    // selected, keep it selected even while it is about to open so the
    // locked state and status remain visible instead of oscillating back.
    if (current.name !== "福彩3D") return;
    const fallback =
      lotteries.find((item) => item.name === "排列三") ||
      lotteries.find((item) => item.id !== current.id);
    if (!fallback) return;

    const noticeKey = String(current.id) + ":" + String(fallback.id);
    if (defaultLotteryFallbackNotice.current === noticeKey) return;
    defaultLotteryFallbackNotice.current = noticeKey;

    const shortName = (item: Lottery) =>
      item.name === "排列三" ? "体彩" : item.name === "福彩3D" ? "福彩" : item.name;
    setLottery(fallback.name);
    persistPreferences(options, fallback.name, warningAmount);
    message.info(
      shortName(current) + "已经关盘，默认彩种变更为" + shortName(fallback),
    );
  }, [lotteries, lottery, now, options, warningAmount]);

  const generateText = async (
    sourceText: string,
    showMessage = true,
  ): Promise<QuickPreview | null> => {
    if (!sourceText.trim()) {
      if (showMessage) message.warning("请输入投注文本");
      return null;
    }
    setGenerating(true);
    const requestId = ++previewRequestId.current;
    try {
      const response = await previewQuickEntry({ text: sourceText, lottery, board_code: boardCode });
      const data = response.data?.data || null;
      if (requestId !== previewRequestId.current) return data;
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
      modal.error({ title: "生成失败", content: apiErrorMessage(error, "生成失败"), okText: "确认" });
      return null;
    } finally {
      if (requestId === previewRequestId.current) setGenerating(false);
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
  }, [text, options[1], lottery, boardCode]);
  useEffect(() => {
    if (text.trim()) return;
    previewRequestId.current += 1;
    setGeneratedLines((current) => current.length ? [] : current);
    setGeneratedTotal((current) => current.count || current.codeCount || current.amount !== "0.00"
      ? { count: 0, codeCount: 0, amount: "0.00" }
      : current);
  }, [text]);
  const copyTicket = async (
    sourceText: string,
    lines: QuickEntryLine[],
    includeHeader: boolean,
  ) => {
    const body = lines
      .filter((line) => line.status === "success")
      .map(
        (line) => `${line.display_number_text || line.number_text} ${line.category || ""}各${line.amount}`,
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
    if (!timing.canBet) {
      message.warning(timing.status || "当前时间段不可下注");
      return false;
    }
    const mismatched = preview.lines.some((line) => line.status === "failed" && Boolean(
      line.suggested_amount || /总金额|金额需确认|金额单位不完整|不一致|对不上/.test(line.reason || ""),
    ));
    if (mismatched) {
      if (showSuccess) message.warning("金额不一致，请先点击注单行中的对号进行人工确认");
      return false;
    }
    const valid = preview.lines.filter((line) => line.status === "success");
    if (!valid.length) {
      if (showSuccess) message.warning("请先生成有效投注内容");
      return false;
    }
    try {
      const response = await placeQuickEntry({
        text: sourceText,
        lottery,
        board_code: boardCode,
        confirmed: true,
      });
      if (showSuccess)
        message.success(response.data?.message || "下注提交成功");
      if (options[2]) await copyTicket(sourceText, valid, options[3]);
      // A successful submission starts a fresh ticket. Keep the generated
      // result and input area in sync by clearing the editor after the ticket
      // has been accepted (including auto-bet/paste submissions).
      setText("");
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
      modal.error({ title: "下注失败", content: apiErrorMessage(error, "下注失败"), okText: "确认" });
      return false;
    }
  };
  const place = () => {
    if (!timing.canBet) {
      message.warning(timing.status || "当前时间段不可下注");
      return;
    }
    const blocks = splitTicketBlocks(text);
    if (blocks.length > 1) {
      modal.confirm({
        title: "确认分单下注",
        content: `检测到 ${blocks.length} 张注单（空行分隔），将分别提交，确认吗？`,
        okText: "确认下注",
        cancelText: "取消",
        onOk: async () => {
          for (const block of blocks) {
            const blockPreview = await generateText(block, false);
            if (!blockPreview || !blockPreview.lines.some((line) => line.status === "success")) return;
            if (!(await submitBet(block, blockPreview))) return;
          }
        },
      });
      return;
    }
    const preview: QuickPreview = {
      lines: generatedLines,
      count: generatedTotal.count,
      amount: generatedTotal.amount,
      formatted_text: text,
    };
    if (preview.lines.some((line) => line.status === "failed" && Boolean(
      line.suggested_amount || /总金额|金额需确认|金额单位不完整|不一致|对不上/.test(line.reason || ""),
    ))) {
      modal.warning({ title: "需要人工确认", content: "检测到整张注单金额与识别结果不一致，请先点击对应注单行的对号确认修正金额。", okText: "确认" });
      return;
    }
    if (!preview.lines.some((line) => line.status === "success")) {
      modal.warning({ title: "无法下注", content: "请先生成有效投注内容", okText: "确认" });
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
    <div
      className={`entry${showMask ? " entry-locked" : ""}${generatedLines.length ? " has-results" : ""}`}
      style={{ "--quick-result-height": `${resultHeight}px` } as CSSProperties}
    >
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
          <div className="quick-board-indicator">
            <span>{activeBoard?.code || boardCode} - {activeBoard?.code || boardCode}</span>
          </div>
          <div className="replace">
            <button
              type="button"
              onClick={() => setReplaceOpen(true)}
            >
              <span>替换文本</span>
            </button>
            <b className="replace-separator" aria-hidden="true" />
            {replaceUndoText !== null && (
              <button
                className="undo"
                type="button"
                onClick={() => {
                  modal.confirm({
                    title: "确定要撤销至最近一次替换操作之前的文本吗？",
                    icon: <QuestionCircleOutlined />,
                    okText: "确 定",
                    cancelText: "取 消",
                    onOk: () => {
                      setText(replaceUndoText);
                      setReplaceUndoText(null);
                    },
                  });
                }}
              >
                <span>撤 销</span>
              </button>
            )}
            <button
              className="new"
              type="button"
              onClick={() => setTagOpen(true)}
            >
              ＋ 新标签
            </button>
            <b className="replace-separator" aria-hidden="true" />
            <button
              type="button"
              className="rule-action"
              onClick={() => setRulesOpen(true)}
            >
              规则
            </button>
            <b className="replace-separator" aria-hidden="true" />
            <div className="warning-label">
              <label htmlFor="warning-amount">大金额预警</label>
              <input
                id="warning-amount"
                className="warning-input"
                value={warningAmount}
                onFocus={(event) => event.currentTarget.select()}
                onChange={(event) => {
                  const value = event.target.value.replace(/[^\d.]/g, "");
                  setWarningAmount(value);
                  persistPreferences(options, lottery, value);
                }}
                inputMode="decimal"
              />
            </div>
            {tags.length > 0 && (
              <div className={`tag-list${tagDeleting ? " deleting" : ""}`}>
                <button
                  type="button"
                  className="tag-delete-toggle"
                  onClick={() => setTagDeleting((value) => !value)}
                >
                  {tagDeleting ? "取 消" : "删 除"}
                </button>
                {tags.map((tag) => (
                  <div className="tag-item" key={tag.id}>
                    <button
                      type="button"
                      className="tag-name"
                      onClick={() => {
                        if (!tagDeleting) setText(tag.name);
                      }}
                    >
                      {tag.name}
                    </button>
                    {tagDeleting && (
                      <button
                        type="button"
                        className="tag-item-delete"
                        aria-label={`删除标签 ${tag.name}`}
                        onClick={() => {
                          modal.confirm({
                            title: "确定要删除吗？",
                            icon: <QuestionCircleOutlined />,
                            okText: "确 定",
                            cancelText: "取 消",
                            onOk: async () => {
                              try {
                                await deleteQuickTag(tag.id);
                                setTags((current) =>
                                  current.filter((item) => item.id !== tag.id),
                                );
                                setTagDeleting(false);
                                message.success("删除成功");
                              } catch (error) {
                                message.error(apiErrorMessage(error, "标签删除失败"));
                              }
                            },
                          });
                        }}
                      >
                        X
                      </button>
                    )}
                  </div>
                ))}
              </div>
            )}
          </div>
          <div
            className={`text-entry ${lottery === "排列三" ? "lottery-ti" : "lottery-fu"}`}
          >
            <textarea
              value={text}
              onChange={(e) => {
                setReplaceUndoText(null);
                setText(e.target.value.slice(0, 10000));
              }}
              onPaste={(event) => {
                const pasted = event.clipboardData
                  .getData("text")
                  .slice(0, 10000);
                event.preventDefault();
                // A paste is an explicit entry action. Recognize it immediately
                // on mobile even when “立即识别” is off; that switch controls
                // recognition while editing, not whether paste works.
                suppressResultRecognition.current = true;
                setText(pasted);
                window.setTimeout(() => {
                  void generateText(pasted, options[0] && timing.canBet).then((preview) => {
                    if (preview && options[0] && timing.canBet)
                      void submitBet(pasted, preview);
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
                <RestOutlined /><span>清空</span>
              </button>
              <span>{text.length.toLocaleString()}/10,000</span>
              <div className="mobile-entry-actions" aria-label="录入操作">
                <button type="button" className="mobile-identify" onClick={generate} disabled={generating}>
                  {generating ? "识别中" : "识 别"}
                </button>
                <button type="button" className="mobile-place" onClick={place} disabled={!timing.canBet}>
                  下 注
                </button>
                <span className="mobile-entry-total">共 ¥ <b>{displayAmount(generatedTotal.amount)}</b></span>
              </div>
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
              {(() => {
              const current = lotteries.find((item) => item.name === lottery) || lotteries[0];
                if (!current) return null;
                const tone = current.name === "排列三" ? "ti" : "fu";
                const shortLabel = current.name === "排列三" ? "体" : "福";
                const primary = lotteries.find((item) => item.name === "福彩3D") || lotteries[0] || current;
                const next = lotteries.find((item) => item.id !== current.id) || current;
                return (
                  <Switch
                    checked={current.id === primary.id}
                    checkedChildren={shortLabel}
                    unCheckedChildren={shortLabel}
                    aria-label={`默认彩种${shortLabel}`}
                    className={`lottery-default-switch lottery-default-switch-${tone}`}
                    onChange={(checked) => {
                      const target = checked ? primary : next;
                      if (target.id === current.id) return;
                      if (!lotteryTiming(target, now).canBet) {
                        message.error(target.name + "当前已封盘");
                        return;
                      }
                      setLottery(target.name);
                      persistPreferences(options, target.name);
                    }}
                  />
                );
              })()}
            </label>
          </div>
          <div className="actions">
            <button type="button" className="desktop-entry-action" onClick={place} disabled={!timing.canBet}>
              {timing.canBet ? "下 注" : timing.status || "暂不可下注"}
            </button>
            <button
              type="button"
              className="gold desktop-entry-action"
              onClick={generate}
              disabled={generating}
            >
              {generating ? "识 别 中" : "识 别"}
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
          </div>
          <QuickResultTable
            lines={generatedLines}
            sourceText={text}
            onConfirmMismatch={(line) => {
              const source = line.input_text || line.raw_text || text;
              let corrected = line.corrected_text;
              // Older parser branches returned only suggested_amount (or
              // echoed the original text). Build the safe total replacement
              // on the client so the confirmation button is never a no-op.
              if ((!corrected || corrected === source) && line.suggested_amount) {
                corrected = source.replace(
                  /((?:合计|共计|总计|合|共|计)\s*)\d+(?:\.\d+)?(?=\s*(?:元|米|块|角|毛|快)?\s*$)/u,
                  `$1${line.suggested_amount}`,
                );
              }
              if (!corrected || corrected === source) {
                modal.warning({
                  title: "需要人工确认",
                  content: "识别金额与原文不一致，当前无法自动改写。请直接修改金额后重新生成。",
                  okText: "确认",
                });
                return;
              }
              suppressResultRecognition.current = true;
              setText(corrected);
              void generateText(corrected);
            }}
            onChange={(lines, reason) => {
              if (reason === "structure")
                suppressResultRecognition.current = true;
              // One source sentence can produce several internal rows (for
              // example 福体/组三/组六). Keep adjacent duplicate source text
              // only once, otherwise editing a displayed row would append
              // the same ticket and generate an extra result.
              const sourceLines = lines.reduce<string[]>((result, line) => {
            const raw = line.input_text || line.raw_text || "";
                if (raw !== "" && result[result.length - 1] !== raw) result.push(raw);
                return result;
              }, []);
              const nextText = sourceLines.join("\n");
              setText(nextText);
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
              // Text edits are committed on blur by QuickResultTable. Parse
              // that single source sentence immediately so the original row
              // is replaced with the new result instead of adding another row.
              if (reason === "text" && nextText.trim() && !options[1]) {
                void generateText(nextText, false);
              }
            }}
          />
          <RuleInstructionsModal
            open={rulesOpen}
            onClose={() => setRulesOpen(false)}
            rules={ruleSettings}
          />
          <Modal
            open={replaceOpen}
            wrapClassName="replace-modal-wrap"
            title="替换文本"
            okText="确认替换"
            cancelText="关 闭"
            onCancel={() => {
              setReplaceOpen(false);
            }}
            onOk={() => {
              if (!text.trim()) {
                message.warning("空文本无可替换");
                return;
              }
              if (replaceFrom) {
                const nextText = text.split(replaceFrom).join(replaceTo);
                if (nextText !== text) {
                  setReplaceUndoText(text);
                  setText(nextText);
                }
              }
              setReplaceOpen(false);
              setReplaceFrom("");
              setReplaceTo("");
            }}
            width={500}
            className="replace-modal"
          >
            <div className="replace-form">
              <div className="replace-form-row">
                <label htmlFor="replace-from">将：</label>
                <Input
                  id="replace-from"
                  value={replaceFrom}
                  onChange={(event) => setReplaceFrom(event.target.value)}
                  maxLength={20}
                />
              </div>
              <div className="replace-form-row">
                <label htmlFor="replace-to">替换为：</label>
                <Input
                  id="replace-to"
                  value={replaceTo}
                  onChange={(event) => setReplaceTo(event.target.value)}
                  maxLength={20}
                />
              </div>
            </div>
          </Modal>
          <Modal
            open={tagOpen}
            wrapClassName="tag-modal-wrap"
            title="添加新标签"
            okText="确认"
            cancelText="关闭"
            onCancel={() => {
              setTagOpen(false);
              setTagName("");
            }}
            onOk={async () => {
              const value = tagName.trim();
              if (!value) {
                message.warning("请输入标签名称");
                return;
              }
              try {
                const response = await createQuickTag(value);
                const created = response.data?.data;
                setTags((current) => [
                  ...current,
                  created || { id: Date.now(), name: value },
                ]);
                setTagOpen(false);
                setTagName("");
                setTagDeleting(false);
                message.success("标签添加成功");
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
              placeholder="请输入新标签"
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
