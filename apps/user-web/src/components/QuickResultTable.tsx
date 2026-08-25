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
  const [detailLine, setDetailLine] = useState<QuickEntryLine | null>(null);
  const [mergedLine, setMergedLine] = useState<QuickEntryLine | null>(null);
  const renumber = (next: QuickEntryLine[]) => next.map((line, index) => ({ ...line, id: index + 1 }));
  const addLine = (line: QuickEntryLine) => {
    const index = lines.indexOf(line);
    const blankLine: QuickEntryLine = { id: 0, raw_text: "", status: "new", number_text: "", amount: "0", count: 0, category: null, reason: null };
    const next = [...lines];
    next.splice(index + 1, 0, blankLine);
    onChange?.(renumber(next.map(clearBatchMetadata)), "structure");
  };
  const removeLine = (line: QuickEntryLine) => onChange?.(renumber(lines.filter((item) => item !== line).map(clearBatchMetadata)), "structure");
  const updateLineText = (line: QuickEntryLine, rawText: string) => {
    const next = lines.map((item) => item === line ? { ...item, raw_text: rawText } : item).map(clearBatchMetadata);
    onChange?.(next, "text");
  };
  const detailOccurrences = detailLine ? (detailLine.batch_occurrence_text || detailLine.display_number_text || detailLine.number_text || "").split(/\s+/).filter(Boolean) : [];
  const detailFrequency = detailOccurrences.reduce<Record<string, number>>((frequencies, number) => {
    frequencies[number] = (frequencies[number] || 0) + 1;
    return frequencies;
  }, {});
  const detailNumbers = Array.from(new Set(detailOccurrences));
  const detailSections = detailLine
    ? (detailLine.category === "福体" ? ["体", "福"] : [detailLine.category || "福"])
    : [];
  const detailCount = detailLine ? (detailLine.batch_count ?? detailLine.code_count ?? detailLine.count) : 0;
  const detailStakeCount = detailLine ? (detailLine.batch_stake_count ?? detailLine.stake_count ?? detailLine.count) : 0;
  const detailAmount = detailLine ? (detailLine.batch_amount ?? detailLine.amount) : "0";
  const unitAmount = detailStakeCount > 0 ? Number(detailAmount || 0) / detailStakeCount : 0;
  const detailPlayMatch = detailLine?.raw_text.match(/直选|组选|组六|组三|定位|复式|和值|跨度|胆拖|全包|飞|胆|直|组/)?.[0];
  const detailPlay = detailLine?.play_type || (detailPlayMatch === "直" ? "直选" : detailPlayMatch || "直选");
  const detailRows = Array.from({ length: Math.ceil(detailNumbers.length / 8) }, (_, rowIndex) => {
    const row = detailNumbers.slice(rowIndex * 8, rowIndex * 8 + 8);
    return Array.from({ length: 8 }, (_, columnIndex) => row[columnIndex] || null);
  });

  return (
    <>
      <div className="quick-result" aria-live="polite">
      {lines.map((line) => {
        const isBatch = Boolean(line.batch_id);
        const isBatchEnd = isBatch && line.batch_end === true && line.batch_valid !== false;
        const showDetailButton = line.status === "success" && (!isBatch || isBatchEnd);
        const categoryTone = line.category === "福" ? "fu" : line.category === "体" ? "ti" : line.category === "福体" ? "futi" : "";
        return (
          <Fragment key={line.id}>
          <div className={`quick-result-row ${line.status}${isBatchEnd ? " batch-end" : ""}`}>
            <div className="quick-result-main">
              <button type="button" className="quick-result-remove" aria-label={`删除第${line.id}条`} onClick={() => removeLine(line)} />
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
                {showDetailButton && <button type="button" className="quick-result-more" aria-label={`查看第${line.id}条详情`} onClick={() => setDetailLine(line)} />}
              </span>
              <strong className={`quick-result-status ${line.status}${categoryTone ? ` ${categoryTone}` : ""}`}>
                {line.status === "success" ? line.category || "成功" : line.status === "new" ? "新" : "失败"}
              </strong>
              <textarea className="quick-result-text" maxLength={5000} rows={1} value={line.raw_text} onChange={(event) => updateLineText(line, event.target.value)} />
            </div>
            {line.status === "success" ? (
              !isBatch ? (
                <div className="quick-result-detail">
                  <span>笔数：</span><b>{line.code_count ?? line.count}</b>
                  <span>金额：</span><b>{formatAmount(line.amount)}</b>
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
              <span>笔数：</span><b>{line.batch_count ?? line.code_count ?? line.count}</b>
              <span>金额：</span><b>{formatAmount(line.batch_amount ?? line.amount)}</b>
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
        open={detailLine !== null}
        title={null}
        style={{ top: 100 }}
        closable={false}
        footer={<Button type="primary" onClick={() => setDetailLine(null)}>关 闭</Button>}
        onCancel={() => setDetailLine(null)}
        width={1000}
        className="quick-detail-modal"
      >
        <div className="quick-detail-summary"><InfoCircleOutlined className="quick-detail-info" />详情：总笔数 {detailCount}，总金额 {detailLine ? formatAmount(detailAmount) : "0"}</div>
        <div className="quick-detail-scroll">
          <div className="result-table">
            {detailSections.map((section, sectionIndex) => (
              <Fragment key={section}>
                {sectionIndex > 0 && <div className="sep" />}
                <div className={`ltype-wrapper ${section === "体" ? "is-ti" : "is-fu"}`}>{section}</div>
                <div className="ltype-body">
                  <div className="game-type-wrapper">
                    <div className="game-type-title">{detailPlay}</div>
                    <div className="row-container row-header-container">
                      {Array.from({ length: 8 }, (_, index) => (
                        <div className="row-label-container" key={`header-${section}-${index}`}>
                          <span className="label-wrapper">号码</span>
                          <span className="label-wrapper">金额</span>
                        </div>
                      ))}
                    </div>
                    <div className="row-container">
                      {detailRows.map((row, rowIndex) => (
                        <Fragment key={`${section}-row-${rowIndex}`}>
                          {row.map((number, index) => (
                            <div className={`row-label-container${number ? " has-amount" : ""}`} key={`${section}-${rowIndex}-${index}`}>
                              <span className="label-wrapper">{number || "--"}</span>
                              <span className="label-wrapper">{number ? formatAmount((unitAmount * (detailFrequency[number] || 1)).toFixed(2)) : "--"}</span>
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
