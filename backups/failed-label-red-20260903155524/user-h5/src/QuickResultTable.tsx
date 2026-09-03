import { Fragment, memo, useLayoutEffect, useState } from "react";
import type { QuickEntryLine } from "../api/user";
import { Button, Modal } from "antd";
import "./QuickResultTable.css";

type QuickResultTableProps = {
  lines: QuickEntryLine[];
  sourceText?: string;
  onChange?: (lines: QuickEntryLine[], reason: "structure" | "text") => void;
  onConfirmMismatch?: (line: QuickEntryLine) => void;
};

const formatAmount = (value: string) => value.replace(/\.00$/, "").replace(/(\.\d)0$/, "$1");
const isAmountMismatch = (line: QuickEntryLine) => line.status === "failed" && Boolean(
  line.suggested_amount || /总金额|金额需确认|不一致|对不上/.test(line.reason || ""),
);
const categoryFromSource = (source: string): string | undefined => /福体/u.test(source) ? "福体" : (/^\s*体/u.test(source) ? "体" : (/^\s*福/u.test(source) ? "福" : undefined));
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
  "ticket_group_id",
  "ticket_group_count",
  "ticket_group_numbers",
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

type DisplayLine = QuickEntryLine & {
  __sourceIds?: number[];
  __detailLines?: QuickEntryLine[];
  __blank?: boolean;
};

function QuickResultTableInner({ lines, sourceText: _sourceText, onChange, onConfirmMismatch }: QuickResultTableProps) {
  const [detailLines, setDetailLines] = useState<QuickEntryLine[]>([]);
  const [mergedLine, setMergedLine] = useState<QuickEntryLine | null>(null);
  const [draftTexts, setDraftTexts] = useState<Record<string, string>>({});
  const [editingGroupKey, setEditingGroupKey] = useState<string | null>(null);
  const renumber = (next: QuickEntryLine[]) => next.map((line, index) => ({ ...line, id: index + 1 }));
  const addLine = (line: DisplayLine) => {
    const sourceId = line.__sourceIds?.[line.__sourceIds.length - 1] ?? line.id;
    const index = lines.findIndex((item) => item.id === sourceId);
    const blankLine: QuickEntryLine = { id: 0, raw_text: "", status: "new", number_text: "", amount: "0", count: 0, category: null, reason: null };
    const next = [...lines];
    next.splice(index + 1, 0, blankLine);
    onChange?.(renumber(next.map(clearBatchMetadata)), "structure");
  };
  const expandedLines: DisplayLine[] = [];
  const sourcePhysical = _sourceText ? _sourceText.split(/\r?\n/u) : [];
  const hasPhysicalBlank = sourcePhysical.some((raw) => raw.trim() === "");
  const physicalExpanded = sourcePhysical.length > lines.length && lines.length > 0;
  // The preview API omits empty input lines. Rebuild those physical rows in
  // the table so the user's original layout remains visible, while mapping
  // each non-empty row to the next parsed result instead of turning it into
  // a misleading “新” row.
  if (hasPhysicalBlank) {
    const fallbackBlank: QuickEntryLine = { id: 0, raw_text: "", status: "new", number_text: "", amount: "0", count: 0, category: null, reason: null };
    let semanticIndex = 0;
    sourcePhysical.forEach((raw, physicalIndex) => {
      const blank = raw.trim() === "";
      if (blank) {
        expandedLines.push({ ...fallbackBlank, id: physicalIndex + 1, raw_text: raw, input_text: raw, __blank: true });
        return;
      }
      const source = raw.trim();
      const start = semanticIndex;
      while (semanticIndex < lines.length) {
        const candidate = lines[semanticIndex];
        const candidateSource = (candidate.input_text || candidate.raw_text || "").trim();
        if (semanticIndex > start && candidateSource !== source) break;
        if (candidateSource !== source && semanticIndex === start) {
          // Keep rendering even if the parser normalized the source text.
          // The sequential fallback still preserves the user's line order.
        }
        expandedLines.push({ ...candidate, id: physicalIndex + 1, raw_text: raw, input_text: raw, __sourceIds: [candidate.id], __detailLines: [candidate] });
        semanticIndex += 1;
        if (candidateSource !== source) break;
      }
    });
    while (semanticIndex < lines.length) {
      const candidate = lines[semanticIndex++];
      expandedLines.push({ ...candidate, __sourceIds: [candidate.id], __detailLines: [candidate] });
    }
  } else if (physicalExpanded) {
    sourcePhysical.forEach((raw, physicalIndex) => {
      const last = physicalIndex === sourcePhysical.length - 1;
      const semantic = lines[Math.min(physicalIndex, lines.length - 1)];
      expandedLines.push({
        ...semantic,
        id: physicalIndex + 1,
        raw_text: raw,
        input_text: raw,
        __blank: raw.trim() === "",
        status: last ? semantic.status : raw.trim() ? "new" : "new",
        amount: last ? lines.reduce((sum, item) => sum + Number(item.amount || 0), 0).toFixed(2) : "0.00",
        count: last ? lines.reduce((sum, item) => sum + Number(item.count || 0), 0) : 0,
        code_count: last ? lines.reduce((sum, item) => sum + Number(item.code_count ?? item.count ?? 0), 0) : 0,
        stake_count: last ? lines.reduce((sum, item) => sum + Number(item.stake_count ?? item.count ?? 0), 0) : 0,
        __sourceIds: lines.map((item) => item.id),
        __detailLines: lines,
      });
    });
  }
  for (let index = hasPhysicalBlank || physicalExpanded ? lines.length : 0; index < lines.length; ) {
    const source = lines[index].input_text || lines[index].raw_text || "";
    const members: QuickEntryLine[] = [];
    while (index < lines.length && (lines[index].input_text || lines[index].raw_text || "") === source) {
      members.push(lines[index]);
      index++;
    }
    const physical = source.includes("\n") ? source.split(/\r?\n/u) : [source];
    if (physical.length <= 1 || members.length <= 1) {
      expandedLines.push(...members.map((member) => ({ ...member, __sourceIds: [member.id] })));
      continue;
    }
    physical.forEach((raw, physicalIndex) => {
      const last = physicalIndex === physical.length - 1;
      const semantic = members[physicalIndex] || members[0];
      expandedLines.push({
        ...semantic,
        raw_text: raw,
        input_text: raw,
        __blank: raw.trim() === "",
        status: last ? semantic.status : "new",
        amount: last ? members.reduce((sum, item) => sum + Number(item.amount || 0), 0).toFixed(2) : "0.00",
        count: last ? members.reduce((sum, item) => sum + Number(item.count || 0), 0) : 0,
        code_count: last ? members.reduce((sum, item) => sum + Number(item.code_count ?? item.count ?? 0), 0) : 0,
        stake_count: last ? members.reduce((sum, item) => sum + Number(item.stake_count ?? item.count ?? 0), 0) : 0,
        __sourceIds: members.map((item) => item.id),
        __detailLines: members,
      });
    });
  }
  const displayGroups: DisplayLine[][] = [];
  expandedLines.forEach((line) => {
    const previous = displayGroups[displayGroups.length - 1];
    if (
      previous &&
      line.status === "success" &&
      !line.batch_id &&
      previous.every((item) => item.status === "success" && !item.batch_id) &&
      (previous[0].input_text || previous[0].raw_text) === (line.input_text || line.raw_text) &&
      !previous[0].raw_text.includes("\n") &&
      !line.raw_text.includes("\n") &&
      previous[0].category === line.category &&
      previous.some((item) => item.play_type !== line.play_type)
    ) {
      previous.push(line);
    } else {
      displayGroups.push([line]);
    }
  });
  // Textareas size themselves from their `rows` attribute on first paint,
  // which can overestimate wrapped text on narrow phones and leave a large
  // empty block below the visible content. Measure the rendered width and
  // apply the actual scroll height after every preview/edit change.
  useLayoutEffect(() => {
    if (!window.matchMedia("(max-width: 599px)").matches) return;
    const resize = () => {
      document.querySelectorAll<HTMLTextAreaElement>(".entry > .quick-result .quick-result-text").forEach((textarea) => {
        textarea.style.setProperty("height", "auto", "important");
        textarea.style.setProperty("height", `${textarea.scrollHeight}px`, "important");
      });
    };
    resize();
    const container = document.querySelector<HTMLElement>(".entry > .quick-result");
    if (!container || typeof ResizeObserver === "undefined") return;
    const observer = new ResizeObserver(resize);
    observer.observe(container);
    return () => observer.disconnect();
  }, [displayGroups, draftTexts, editingGroupKey]);
  const numberTokens = (line: QuickEntryLine) => {
    // Multi-code plays such as “组六六码/组三六码” are represented internally
    // by their expanded settlement combinations, while the detail dialog
    // should show the original six-digit selection as one item.
    if (line.play_type?.includes("码") && line.play_type !== "复式") {
      const selection = (line.settlement_text || "").match(/([0-9]{3,10})\s+(?:组六|组三)(?:两|三|四|五|六|七|八|九)?码/)?.[1];
      if (selection) return [`${line.play_type.startsWith("组三") ? "三" : "六"}${selection}`];
    }
    // Group rows are displayed once per unordered combination, but their
    // amount is based on every entered permutation. Prefer the compiler's
    // original occurrence list so 189 and 198 render as one 189 cell with
    // the combined amount (rather than silently showing only one stake).
    const occurrenceText = (line as QuickEntryLine & { occurrence_number_text?: string }).occurrence_number_text;
    const tokens = (occurrenceText || line.batch_occurrence_text || line.display_number_text || line.number_text || "")
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
    const type = String(line.play_type || "");
    if (type === "直" || type.startsWith("直")) return "直选";
    if (type === "组" || type === "组选" || type.startsWith("组三") || type.startsWith("组六")) return "组选";
    if (line.play_type?.startsWith("和")) return "和值";
    if (line.play_type?.startsWith("跨")) return "跨度";
    return line.play_type || "直选";
  };
  const detailSectionsRaw = detailLines.flatMap((line) => {
    const occurrences = numberTokens(line);
    const isGroup = String(line.play_type || "").startsWith("组");
    const stakeCount = Number(line.stake_count ?? line.code_count ?? line.count ?? 0);
    // Count-prefix forms such as “二单一组” keep two direct stakes in one
    // play row.  Scale the occurrence frequency by the row's stake count so
    // each displayed number shows its real amount (4元直选 + 2元组选), rather
    // than looking like a single 2元 direct entry.
    const occurrenceWeight = occurrences.length > 0 && stakeCount > 0
      ? stakeCount / occurrences.length
      : 1;
    const unitAmount = stakeCount > 0 ? Number(line.amount || 0) / stakeCount : 0;
    const amounts = occurrences.reduce<Record<string, number>>((values, number) => {
      const displayNumber = isGroup ? normalizeGroupNumber(number) : number;
      values[displayNumber] = (values[displayNumber] || 0) + unitAmount * occurrenceWeight;
      return values;
    }, {});
    const numbers = Array.from(new Set(occurrences.map((number) => isGroup ? normalizeGroupNumber(number) : number))).sort();
    const detectedCategory = line.category || categoryFromSource(line.input_text || line.raw_text || "");
    const categorySections = detectedCategory === "福体" ? ["体", "福"] : [detectedCategory || "福"];
    return categorySections.map((category) => ({ line, category, title: playLabel(line), numbers, amounts }));
  }).sort((left, right) => {
    const categoryRank = (category: string) => category === "体" ? 0 : category === "福" ? 1 : 2;
    const playRank = (title: string) => title.includes("组三") ? 0 : title.includes("组六") ? 1 : title === "直选" ? 0 : 2;
    return categoryRank(left.category) - categoryRank(right.category) || playRank(left.title) - playRank(right.title);
  });
  // Several physical selections can use the same catalog play (for example
  // 12467 and 23457 are both 组三五码). Merge those sections so the detail
  // table has one 组三五码 block and one 组六五码 block, while retaining the
  // individual numbers and their amounts.
  const detailSectionsPrepared = detailSectionsRaw.map((section) => ({ ...section, numbers: [...section.numbers], amounts: { ...section.amounts } }));
  const directSections = detailSectionsPrepared.filter((section) => section.title === "直选");
  const groupSections = detailSectionsPrepared.filter((section) => section.title === "组选");
  const isLeopard = (value: string) => {
    const number = value.replace(/\D/g, "");
    return /^\d{3}$/.test(number) && new Set(number.split("")).size === 1;
  };
  // Only豹子 is combined into the direct display. Ordinary 组三/组六
  // selections remain in the separate 组选 table; equivalent permutations
  // (such as 849/948) continue to be merged there by normalizeGroupNumber.
  directSections.forEach((direct) => {
    const group = groupSections.find((candidate) => candidate.category === direct.category);
    if (!group) return;
    group.numbers.filter(isLeopard).forEach((number) => {
      const amount = group.amounts[number];
      if (amount == null) return;
      direct.amounts[number] = (direct.amounts[number] || 0) + amount;
      group.numbers = group.numbers.filter((item) => item !== number);
      delete group.amounts[number];
    });
  });
  const detailSections = detailSectionsPrepared.filter((section) => section.title !== "组选" || section.numbers.length > 0).reduce<typeof detailSectionsRaw>((merged, section) => {
    const key = `${section.category}|${section.title}`;
    const existing = merged.find((item) => `${item.category}|${item.title}` === key);
    if (!existing) {
      merged.push({ ...section, numbers: [...section.numbers], amounts: { ...section.amounts } });
      return merged;
    }
    existing.numbers = Array.from(new Set([...existing.numbers, ...section.numbers])).sort();
    Object.entries(section.amounts).forEach(([number, amount]) => {
      existing.amounts[number] = (existing.amounts[number] || 0) + amount;
    });
    return merged;
  }, []);
  const displayCount = (items: QuickEntryLine[]) => {
    let groupOpen = "";
    return items.reduce((sum, line) => {
      const groupId = line.ticket_group_id?.trim();
      if (groupId) {
        if (groupId === groupOpen) return sum;
        groupOpen = groupId;
        return sum + Number(line.ticket_group_count ?? line.count ?? 0);
      }
      groupOpen = "";
      return sum + Number(line.batch_count ?? line.code_count ?? line.count ?? 0);
    }, 0);
  };
  const openDetails = (group: DisplayLine[]) => {
    const details = group[group.length - 1]?.__detailLines;
    const mismatch = group.some((item) => item.status === "failed" && item.suggested_amount);
    const source = group[0]?.input_text || group[0]?.raw_text || "";
    const ticketGroupId = group.find((item) => item.ticket_group_id)?.ticket_group_id?.trim();
    // A composite sentence (for example “二单一组”) is represented by
    // separate direct/group rows for odds matching.  The details dialog must
    // show all of those rows together, otherwise opening it from the last
    // displayed row hides the direct leg and its per-number amount.
    const related = lines
      .filter((item) => ticketGroupId
        ? item.ticket_group_id?.trim() === ticketGroupId
        : (item.input_text || item.raw_text || "") === source)
      .filter((item) => item.status === "success");
    if (mismatch) {
      setDetailLines(related.length ? related : (details?.length ? details : group));
      return;
    }
    setDetailLines(related.length ? related : (details?.length ? details : group));
  };
  const removeGroup = (group: DisplayLine[]) => {
    const ids = new Set(group.flatMap((item) => item.__sourceIds || [item.id]));
    onChange?.(renumber(lines.filter((item) => !ids.has(item.id)).map(clearBatchMetadata)), "structure");
  };
  const mismatchSources = new Set(
    lines
      .filter((item) => isAmountMismatch(item))
      .map((item) => item.input_text || item.raw_text || ""),
  );
  const mismatchTexts = Array.from(mismatchSources).filter(Boolean);
  const updateGroupText = (group: DisplayLine[], rawText: string) => {
    const ids = new Set(group.flatMap((item) => item.__sourceIds || [item.id]));
    const next = lines.map((item) => ids.has(item.id) ? { ...item, raw_text: rawText, input_text: rawText } : item).map(clearBatchMetadata);
    onChange?.(next, "text");
  };

  return (
    <>
      <div className="quick-result" aria-live="polite">
      {displayGroups.map((group, groupIndex) => {
        const line = group[0];
        const groupKey = group.map((item) => item.id).join("-");
        const isEditing = editingGroupKey === groupKey;
        const draftText = draftTexts[groupKey] ?? line.input_text ?? line.raw_text;
        const isBatch = Boolean(line.batch_id);
        const isBatchEnd = isBatch && line.batch_end === true && line.batch_valid !== false;
        const isBlank = line.__blank === true;
        const groupCount = displayCount(group);
        const groupAmount = group.reduce((sum, item) => sum + Number(item.amount || 0), 0);
        const sourceKey = line.input_text || line.raw_text || "";
        // A single source sentence may expand into several successful rows
        // plus one final mismatch row. Resolve the mismatch from the whole
        // display group instead of looking only at its first row.
        const mismatchLine = group.find((item) => isAmountMismatch(item));
        const amountMismatch = Boolean(mismatchLine);
        const mismatchGroup = amountMismatch || mismatchSources.has(sourceKey) || mismatchTexts.some((text) => text.includes(sourceKey) || sourceKey.includes(text));
        const hasLaterRelatedGroup = mismatchGroup
          ? displayGroups.slice(groupIndex + 1).some((candidate) => mismatchTexts.some((text) => {
              const candidateSource = candidate[0]?.input_text || candidate[0]?.raw_text || "";
              return candidateSource !== "" && text.includes(candidateSource);
            }))
          : displayGroups.slice(groupIndex + 1).some((candidate) => {
              const candidateSource = candidate[0]?.input_text || candidate[0]?.raw_text || "";
              return candidateSource === sourceKey || candidateSource.includes(sourceKey) || sourceKey.includes(candidateSource);
            });
        const isLastRelatedGroup = !hasLaterRelatedGroup;
        const showDetailButton = isLastRelatedGroup && ((group.some((item) => item.status === "success") && (!isBatch || isBatchEnd)) || mismatchGroup);
        const displayCategory = line.category || lines.find((item) => {
          const itemSource = item.input_text || item.raw_text || "";
          return item.category && (itemSource === sourceKey || itemSource.includes(sourceKey) || sourceKey.includes(itemSource));
        })?.category || categoryFromSource(sourceKey) || "福";
        const categoryTone = displayCategory === "福" ? "fu" : displayCategory === "体" ? "ti" : displayCategory === "福体" ? "futi" : "";
        const visualStatus = isBlank ? "blank" : isEditing ? "new" : mismatchGroup ? "mismatch" : line.status;
        const visualTone = isEditing ? "" : categoryTone;
        return (
          <Fragment key={group.map((item) => item.id).join("-")}>
          <div className={`quick-result-row ${visualStatus}${isBatchEnd ? " batch-end" : ""}`}>
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
                {isLastRelatedGroup && (isBatchEnd || mismatchGroup) && <button type="button" className="quick-result-combine" aria-label={`查看第${line.id}条合并文本`} onClick={() => setMergedLine(line)}>合</button>}
                {showDetailButton && <button type="button" className="quick-result-more" aria-label={`查看第${line.id}条详情`} onClick={() => openDetails(group)} />}
                {isLastRelatedGroup && amountMismatch && onConfirmMismatch && (
                  <button type="button" className="quick-result-confirm" aria-label={mismatchLine?.suggested_amount ? `确认按${mismatchLine.suggested_amount}元修正` : "人工确认金额后修改"} onClick={() => onConfirmMismatch(mismatchLine || line)}>✓</button>
                )}
              </span>
              <strong className={`quick-result-status ${visualStatus}${visualTone ? ` ${visualTone}` : ""}`}>
                {isBlank ? "" : isEditing ? "新" : mismatchGroup ? displayCategory : line.status === "success" ? line.category || "成功" : line.status === "new" ? "新" : "失败"}
              </strong>
            </div>
            <textarea
              className="quick-result-text"
              maxLength={5000}
              rows={1}
              value={draftText}
              onInput={(event) => {
                const target = event.currentTarget;
                target.style.height = "auto";
                target.style.height = `${target.scrollHeight}px`;
              }}
              onFocus={() => setEditingGroupKey(groupKey)}
              onChange={(event) => setDraftTexts((current) => ({ ...current, [groupKey]: event.target.value }))}
              onBlur={() => {
                const nextText = draftTexts[groupKey] ?? line.raw_text;
                setEditingGroupKey((current) => current === groupKey ? null : current);
                setDraftTexts((current) => {
                  const next = { ...current };
                  delete next[groupKey];
                  return next;
                });
                if (nextText !== line.raw_text) updateGroupText(group, nextText);
              }}
            />
            {line.status === "success" ? (
              !isBatch ? (
                <div className="quick-result-detail">
                  <span>笔数：</span><b>{groupCount}</b>
                  <span>金额：</span><b>{formatAmount(groupAmount.toFixed(2))}</b>
                </div>
              ) : null
            ) : line.status === "failed" && amountMismatch ? (
              <div className="quick-result-detail quick-result-mismatch-detail">
                <span>金额需确认：</span><b>{line.suggested_amount ? `识别金额 ${formatAmount(line.suggested_amount)} 元` : "请点击对号人工确认"}</b>
              </div>
            ) : line.status === "failed" ? (
              <div className="quick-result-detail quick-result-reason">
                <span>原因：</span><b>{line.reason || "语句存在问题，无法识别"}</b>
                {amountMismatch && (
                  <span className="quick-result-manual-warning">
                    {line.suggested_amount
                      ? `检测到金额与识别结果不一致，请点击“✓”人工确认（确认后按 ${formatAmount(line.suggested_amount)} 元生成）`
                      : "检测到金额与识别结果不一致，请人工修改金额后重新生成"}
                  </span>
                )}
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
        <textarea className="quick-merge-text" readOnly rows={8} value={mergedLine?.batch_merged_text || mergedLine?.input_text || mergedLine?.raw_text || ""} />
      </Modal>
      <Modal
        className="more-detail-modal quick-entry-detail-modal"
        centered
        open={detailLines.length > 0}
        title="下注详情"
        footer={
          <button type="button" className="more-detail-close" onClick={() => setDetailLines([])}>
            关 闭
          </button>
        }
        onCancel={() => setDetailLines([])}
        width={760}
      >
        <div className="more-detail-content">
          {detailSections.length ? (
            <>
              <div className="more-detail-lottery">
                {detailSections[0]?.category === "体" ? "排列三" : "福彩3D"}
              </div>
              {detailSections.map((section, sectionIndex) => {
                const previousSection = detailSections[sectionIndex - 1];
                const isNewCategory = !previousSection || previousSection.category !== section.category;
                const line = section.line as QuickEntryLine & {
                  odds?: string | number;
                  win_amount?: string | number;
                };
                const odds = String(line.odds ?? (section.title === "直选" ? "900" : "---"));
                const winning = Number(line.win_amount || 0) > 0 ? String(line.win_amount) : "---";
                return (
                  <Fragment key={`${section.category}-${section.title}-${sectionIndex}`}>
                    {isNewCategory && sectionIndex > 0 && (
                      <div className="more-detail-lottery">
                        {section.category === "体" ? "排列三" : "福彩3D"}
                      </div>
                    )}
                    {isNewCategory && (
                      <h5 className={`more-detail-section-title ${section.category === "体" ? "lt-3" : "lt-4"}`}>
                        {section.category === "体" ? "排列三" : "福彩3D"}
                      </h5>
                    )}
                    <div className="more-detail-play-label">{section.title}</div>
                    <div className="more-detail-lines">
                      <div className="more-detail-line more-detail-header">
                        <span>号码</span>
                        <span>金额</span>
                        <span>赔率</span>
                        <span>中奖</span>
                      </div>
                      {section.numbers.map((number, numberIndex) => {
                        const match = number.match(/^(\\d+)(.*)$/);
                        return (
                          <div
                            className="more-detail-line"
                            key={`${section.category}-${section.title}-${number}-${numberIndex}`}
                          >
                            <span>
                              {match ? (
                                <div className="cMfHlm">
                                  <label>{match[1]}</label>
                                  {match[2] ? <span>{match[2]}</span> : null}
                                </div>
                              ) : (
                                number
                              )}
                            </span>
                            <span>{formatAmount((section.amounts[number] || 0).toFixed(2))}</span>
                            <span>{odds}</span>
                            <span>{winning}</span>
                          </div>
                        );
                      })}
                    </div>
                  </Fragment>
                );
              })}
            </>
          ) : (
            <div className="more-detail-empty">暂无详情</div>
          )}
        </div>
      </Modal>
    </>
  );
}

// The parent updates its countdown every second. Keep a long result table
// mounted between those ticks so mobile scroll position stays stable and
// rendering work is limited to actual text/result changes.
export const QuickResultTable = memo(
  QuickResultTableInner,
  (previous, next) =>
    previous.lines === next.lines && previous.sourceText === next.sourceText,
);
