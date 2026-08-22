import { useState } from "react";
import type { QuickEntryLine } from "../api/user";
import { Button, Modal } from "antd";
import { InfoCircleOutlined } from "@ant-design/icons";

type QuickResultTableProps = {
  lines: QuickEntryLine[];
  onChange?: (lines: QuickEntryLine[], reason: "structure" | "text") => void;
};

const formatAmount = (value: string) => value.replace(/\.00$/, "").replace(/(\.\d)0$/, "$1");

export function QuickResultTable({ lines, onChange }: QuickResultTableProps) {
  const [detailLine, setDetailLine] = useState<QuickEntryLine | null>(null);
  const renumber = (next: QuickEntryLine[]) => next.map((line, index) => ({ ...line, id: index + 1 }));
  const addLine = (line: QuickEntryLine) => {
    const index = lines.indexOf(line);
    const blankLine: QuickEntryLine = { id: 0, raw_text: "", status: "new", number_text: "", amount: "0", count: 0, category: null, reason: null };
    const next = [...lines];
    next.splice(index + 1, 0, blankLine);
    onChange?.(renumber(next), "structure");
  };
  const removeLine = (line: QuickEntryLine) => onChange?.(renumber(lines.filter((item) => item !== line)), "structure");
  const updateLineText = (line: QuickEntryLine, rawText: string) => {
    const next = lines.map((item) => item === line ? { ...item, raw_text: rawText } : item);
    onChange?.(next, "text");
  };
  const detailNumbers = detailLine ? (detailLine.number_text || "").split(/\s+/).filter(Boolean) : [];
  const detailSections = detailLine
    ? (detailLine.category === "福体" ? ["体", "福"] : [detailLine.category || "福"])
    : [];
  const unitAmount = detailLine && detailLine.count > 0 ? Number(detailLine.amount || 0) / detailLine.count : 0;
  const detailPlay = detailLine?.raw_text.match(/直选|组选|组六|组三|定位|复式|和值|跨度|胆拖|全包|飞|胆|直|组/)?.[0] || "直选";
  const detailRows = Array.from({ length: Math.ceil(detailNumbers.length / 8) }, (_, rowIndex) => {
    const row = detailNumbers.slice(rowIndex * 8, rowIndex * 8 + 8);
    return Array.from({ length: 8 }, (_, columnIndex) => row[columnIndex] || null);
  });

  return (
    <>
      <div className="quick-result" aria-live="polite">
      {lines.map((line) => (
        <div className={`quick-result-row ${line.status}`} key={line.id}>
          <div className="quick-result-main">
            <button type="button" className="quick-result-remove" aria-label={`删除第${line.id}条`} onClick={() => removeLine(line)} />
            <span className="quick-result-index">{line.id}</span>
            <button type="button" className="quick-result-add" aria-label={`新增第${line.id}条`} onClick={() => addLine(line)} />
            <span className="quick-result-detail-slot">
              {line.status === "success" && <button type="button" className="quick-result-more" aria-label={`查看第${line.id}条详情`} onClick={() => setDetailLine(line)} />}
            </span>
            <strong className={`quick-result-status ${line.status}`}>
              {line.status === "success" ? line.category || "成功" : line.status === "new" ? "新" : "失败"}
            </strong>
            <textarea className="quick-result-text" maxLength={5000} rows={1} value={line.raw_text} onChange={(event) => updateLineText(line, event.target.value)} />
          </div>
          {line.status === "success" ? (
            <div className="quick-result-detail">
              <span>笔数：</span><b>{line.count}</b>
              <span>金额：</span><b>{formatAmount(line.amount)}</b>
            </div>
          ) : line.status === "failed" ? (
            <div className="quick-result-detail quick-result-reason">
              <span>原因：</span><b>{line.reason || "语句存在问题，无法识别"}</b>
            </div>
          ) : null}
        </div>
      ))}
      </div>
      <Modal
        open={detailLine !== null}
        title={null}
        style={{ top: 50 }}
        closable={false}
        footer={<Button type="primary" onClick={() => setDetailLine(null)}>关 闭</Button>}
        onCancel={() => setDetailLine(null)}
        width={1000}
        className="quick-detail-modal"
      >
        <div className="quick-detail-summary"><InfoCircleOutlined className="quick-detail-info" />详情：总笔数 {detailLine?.count || 0}，总金额 {detailLine ? formatAmount(detailLine.amount) : "0"}</div>
        <div className="quick-detail-scroll">
          {detailSections.map((section) => (
            <section className="quick-detail-section" key={section}>
              <div className={`quick-detail-type ${section === "体" ? "ti" : "fu"}`}>{section}</div>
              <div className="quick-detail-play">{detailPlay}</div>
              <div className="quick-detail-table" role="table">
                <div className="quick-detail-header-row">
                  {Array.from({ length: 8 }, (_, index) => <span className="quick-detail-pair" key={`header-${section}-${index}`}><span>号码</span><span>金额</span></span>)}
                </div>
                {detailRows.map((row, rowIndex) => <div className="quick-detail-data-row" key={`${section}-row-${rowIndex}`}>
                  {row.map((number, index) => <span className={`quick-detail-pair${number ? "" : " is-empty"}`} key={`${section}-${rowIndex}-${index}`}><span>{number || "--"}</span><span>{number ? formatAmount(unitAmount.toFixed(2)) : "--"}</span></span>)}
                </div>)}
              </div>
            </section>
          ))}
        </div>
      </Modal>
    </>
  );
}
