import { Fragment, useState } from "react";
import type { QuickEntryLine } from "../api/user";
import { Button, Modal } from "antd";
import { InfoCircleOutlined } from "@ant-design/icons";
import "./QuickResultTable.css";

type QuickResultTableProps = {
  lines: QuickEntryLine[];
  onChange?: (lines: QuickEntryLine[], reason: "structure" | "text") => void;
};

const formatAmount = (value: string) => value.replace(/\.00$/, "").replace(/(\.\d)0$/, "$1");
const batchMetadataKeys = [
  "batch_id",
  "batch_index",
  "batch_size",
  "batch_end",
  "batch_valid",
  "batch_count",
  "batch_stake_count",
  "batch_amount",
  "batch_number_text",
  "batch_occurrence_text",
  "batch_merged_text",
  "batch_has_duplicates",
  "batch_duplicate_numbers",
  "batch_declared_stake_count",
  "batch_count_mismatch",
] as const;

/**
 * A row's batch role/statistics are derived from the complete source text.
 * Any local insertion, deletion, or edit invalidates that derived metadata;
 * retaining it would leave a stale last-row summary visible until the next
 * server preview. Individual parse values remain available while editing
 * with "立即识别" disabled.
 */
const clearBatchMetadata = (line: QuickEntryLine): QuickEntryLine => {
  const next = { ...line };
  for (const key of batchMetadataKeys) delete next[key];
  return next;
};

export function QuickResultTable({ lines, onChange }: QuickResultTableProps) {
  const [detailLines, setDetailLines] = useState<QuickEntryLine[]>([]);
  const [mergedLine, setMergedLine] = useState<QuickEntryLine | null>(null);
  const renumber = (next: QuickEntryLine[]) => next.map((line, index) => ({ ...line, id: index + 1 }));
  const addLine = (line: QuickEntryLine) => {
    const index = lines.indexOf(line);
    const blankLine: QuickEntryLine = { id: 0, raw_text: "", status: "new", number_text: "", amount: "0", count: 0, category: null, reason: null };
    const next = [...lines];
    next.splice(index + 1, 0, blankLine);
    onChange?.(renumber(next.map(clearBatchMetadata)), "structure");
  };
  const displayGroups: QuickEntryLine[][] = [];
  lines.forEach((line) => {
    const previous = displayGroups[displayGroups.length - 1];
    // A single quick-entry sentence can produce several internal rows (for
    // example 直选 and 组选). Keep those rows for submission/settlement, but
    // present them as one source row in the preview.
    if (
      previous &&
      line.status === "success" &&
      !line.batch_id &&
      previous.every((item) => item.status === "success" && !item.batch_id) &&
      previous[0].raw_text === line.raw_text &&
      previous[0].category === line.category &&
      previous.some((item) => item.play_type !== line.play_type)
    ) {
      previous.push(line);
    } else {
      displayGroups.push([line]);
    }
  });

  const numberTokens = (line: QuickEntryLine) => {
    // Multi-code plays such as “组六六码/组三六码” are represented internally
    // by their expanded settlement combinations, while the detail dialog
    // should show the original six-digit selection as one item.
    if (line.play_type?.endsWith("六码")) {
      const selection = (line.settlement_text || "").match(/([0-9]{4,10})\s+(?:组六|组三)六码/)?.[1];
      if (selection) return [`${line.play_type.startsWith("组三") ? "三" : "六"}${selection}`];
    }
    const tokens = (line.batch_occurrence_text || line.display_number_text || line.number_text || "")
      .split(/\s+/)
      .map((token) => token.trim())
      .filter(Boolean);
    if (tokens.length === 1 && tokens[0] === "000" && line.play_type) {
      if (line.play_type.startsWith("跨度")) return [`跨${line.play_type.slice(2)}`];
      return [line.play_type];
    }
    return tokens;
  };
  const normalizeGroupNumber = (value: string) =>
    value.length === 3 && /^\d{3}$/.test(value) ? value.split("").sort().join("") : value;
  const playLabel = (line: QuickEntryLine) => {
    if (line.play_type === "直") return "直选";
    if (line.play_type === "组" || line.play_type === "组三" || line.play_type === "组六") return "组选";
    if (line.play_type?.startsWith("和")) return "和值";
    if (line.play_type?.startsWith("跨")) return "跨度";
    return line.play_type || "直选";
  };
  const detailSections = detailLines.flatMap((line) => {
    const occurrences = numberTokens(line);
    const isGroup = line.play_type === "组" || line.play_type === "组三" || line.play_type === "组六";
    const frequency = occurrences.reduce<Record<string, number>>((frequencies, number) => {
      const displayNumber = isGroup ? normalizeGroupNumber(number) : number;
      frequencies[displayNumber] = (frequencies[displayNumber] || 0) + 1;
      return frequencies;
    }, {});
    const numbers = Array.from(new Set(occurrences.map((number) => isGroup ? normalizeGroupNumber(number) : number))).sort();
    const stakeCount = Number(line.stake_count ?? line.code_count ?? line.count ?? 0);
    const unitAmount = stakeCount > 0 ? Number(line.amount || 0) / stakeCount : 0;
    const categorySections = line.category === "福体" ? ["体", "福"] : [line.category || "福"];
    return categorySections.map((category) => ({ line, category, title: playLabel(line), numbers, frequency, unitAmount }));
  }).sort((left, right) => {
    const categoryRank = (category: string) => category === "体" ? 0 : category === "福" ? 1 : 2;
    const playRank = (title: string) => title.includes("组三") ? 0 : title.includes("组六") ? 1 : title === "直选" ? 0 : 2;
    return categoryRank(left.category) - categoryRank(right.category) || playRank(left.title) - playRank(right.title);
  });
  const detailCount = detailLines.reduce((sum, line) => sum + Number(line.batch_count ?? line.code_count ?? line.count ?? 0), 0);
  const detailAmount = detailLines.reduce((sum, line) => sum + Number(line.batch_amount ?? line.amount ?? 0), 0);
  const openDetails = (group: QuickEntryLine[]) => setDetailLines(group);
  const removeGroup = (group: QuickEntryLine[]) => {
    const members = new Set(group);
    onChange?.(renumber(lines.filter((item) => !members.has(item)).map(clearBatchMetadata)), "structure");
  };
  const updateGroupText = (group: QuickEntryLine[], rawText: string) => {
    const members = new Set(group);
    const next = lines.map((item) => members.has(item) ? { ...item, raw_text: rawText } : item).map(clearBatchMetadata);
    onChange?.(next, "text");
  };

  return (
    <>
      <div className="quick-result" aria-live="polite">
      {displayGroups.map((group) => {
        const line = group[0];
        const isBatch = Boolean(line.batch_id);
        const isBatchEnd = isBatch && line.batch_end === true && line.batch_valid !== false;
        const groupCount = group.reduce((sum, item) => sum + Number(item.code_count ?? item.count ?? 0), 0);
        const groupAmount = group.reduce((sum, item) => sum + Number(item.amount || 0), 0);
        const showDetailButton = group.some((item) => item.status === "success") && (!isBatch || isBatchEnd);
        const categoryTone = line.category === "福" ? "fu" : line.category === "体" ? "ti" : line.category === "福体" ? "futi" : "";
        return (
          <Fragment key={group.map((item) => item.id).join("-")}>
          <div className={`quick-result-row ${line.status}${isBatchEnd ? " batch-end" : ""}`}>
            <div className="quick-result-main">
              <button type="button" className="quick-result-remove" aria-label={`删除第${line.id}条`} onClick={() => removeGroup(group)} />
              <span className="quick-result-index">{line.id}</span>
              <button type="button" className="quick-result-add" aria-label={`新增第${line.id}条`} onClick={() => addLine(line)} />
              <span className="quick-result-detail-slot">
                {isBatchEnd && (line.batch_has_duplicates || line.batch_count_mismatch) && (
                  <span
                    className="quick-result-warning"
                    title={[
                      line.batch_has_duplicates ? `重复号码：${(line.batch_duplicate_numbers || []).join("、")}` : "",
                      line.batch_count_mismatch ? `声明${line.batch_declared_stake_count ?? 0}注，实际${line.batch_stake_count ?? 0}注` : "",
                    ].filter(Boolean).join("；")}
                  >!
                  </span>
                )}
                {isBatchEnd && <button type="button" className="quick-result-combine" aria-label={`查看第${line.id}条合并文本`} onClick={() => setMergedLine(line)}>合</button>}
                {showDetailButton && <button type="button" className="quick-result-more" aria-label={`查看第${line.id}条详情`} onClick={() => openDetails(group)} />}
              </span>
              <strong className={`quick-result-status ${line.status}${categoryTone ? ` ${categoryTone}` : ""}`}>
                {line.status === "success" ? line.category || "成功" : line.status === "new" ? "新" : "失败"}
              </strong>
              <textarea className="quick-result-text" maxLength={5000} rows={1} value={line.raw_text} onChange={(event) => updateGroupText(group, event.target.value)} />
            </div>
            {line.status === "success" ? (
              !isBatch ? (
                <div className="quick-result-detail">
                  <span>笔数：</span><b>{groupCount}</b>
                  <span>金额：</span><b>{formatAmount(groupAmount.toFixed(2))}</b>
                </div>
              ) : null
            ) : line.status === "failed" ? (
              <div className="quick-result-detail quick-result-reason">
                <span>原因：</span><b>{line.reason || "语句存在问题，无法识别"}</b>
              </div>
            ) : null}
          </div>
          {isBatchEnd && line.status === "success" && (
            <div className="quick-result-summary">
              <span>笔数：</span><b>{groupCount}</b>
              <span>金额：</span><b>{formatAmount(groupAmount.toFixed(2))}</b>
            </div>
          )}
          </Fragment>
        );
      })}
      </div>
      <Modal
        open={mergedLine !== null}
        title="查看合并后的文本"
        footer={<Button type="primary" onClick={() => setMergedLine(null)}>关 闭</Button>}
        onCancel={() => setMergedLine(null)}
        width={900}
        className="quick-merge-modal"
      >
        <textarea className="quick-merge-text" readOnly rows={8} value={mergedLine?.batch_merged_text || ""} />
      </Modal>
      <Modal
        open={detailLines.length > 0}
        title={null}
        style={{ top: 100 }}
        closable={false}
        footer={<Button type="primary" onClick={() => setDetailLines([])}>关 闭</Button>}
        onCancel={() => setDetailLines([])}
        width={1000}
        className="quick-detail-modal"
      >
        <div className="quick-detail-summary"><InfoCircleOutlined className="quick-detail-info" />详情：总笔数 {detailCount}，总金额 {formatAmount(detailAmount.toFixed(2))}</div>
        <div className="quick-detail-scroll">
          <div className="result-table">
            {detailSections.map((section, sectionIndex) => (
              <Fragment key={`${section.category}-${section.title}-${sectionIndex}`}>
                {sectionIndex > 0 && <div className="sep" />}
                <div className={`ltype-wrapper ${section.category === "体" ? "is-ti" : "is-fu"}`}>{section.category}</div>
                <div className="ltype-body">
                  <div className="game-type-wrapper">
                    <div className="game-type-title">{section.title}</div>
                    <div className="row-container row-header-container">
                      {Array.from({ length: 8 }, (_, index) => (
                        <div className="row-label-container" key={`header-${section}-${index}`}>
                          <span className="label-wrapper">号码</span>
                          <span className="label-wrapper">金额</span>
                        </div>
                      ))}
                    </div>
                    <div className="row-container">
                      {Array.from({ length: Math.ceil(section.numbers.length / 8) }, (_, rowIndex) => section.numbers.slice(rowIndex * 8, rowIndex * 8 + 8)).map((row, rowIndex) => (
                        <Fragment key={`${section}-row-${rowIndex}`}>
                          {Array.from({ length: 8 }, (_, index) => row[index] || null).map((number, index) => (
                            <div className={`row-label-container${number ? " has-amount" : ""}`} key={`${section.category}-${section.title}-${rowIndex}-${index}`}>
                              <span className="label-wrapper">{number || "--"}</span>
                              <span className="label-wrapper">{number ? formatAmount((section.unitAmount * (section.frequency[number] || 1)).toFixed(2)) : "--"}</span>
                            </div>
                          ))}
                        </Fragment>
                      ))}
                    </div>
                  </div>
                </div>
              </Fragment>
            ))}
          </div>
        </div>
      </Modal>
    </>
  );
}
