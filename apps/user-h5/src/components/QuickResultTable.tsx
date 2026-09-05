import { Fragment, memo, useLayoutEffect, useState } from "react";
import type { QuickEntryLine } from "../api/user";
import { Button, Modal } from "antd";
import { InfoCircleOutlined } from "@ant-design/icons";
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
  __physicalOnly?: boolean;
  __groupKey?: string;
  __groupEnd?: boolean;
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
  const sourcePhysical = _sourceText !== undefined ? _sourceText.split(/\r?\n/u) : [];
  const normalizeSource = (value: string) => value.replace(/\r/gu, "").trim();
  const sourceOf = (line: QuickEntryLine) => String(line.input_text || line.raw_text || "");
  const categoryFromRaw = (raw: string): string | null => {
    const hasFu = /福/u.test(raw);
    const hasTi = /体/u.test(raw);
    if (hasFu && hasTi) return "福体";
    if (hasFu) return "福";
    if (hasTi) return "体";
    return null;
  };
  const fallbackLine: QuickEntryLine = {
    id: 0,
    raw_text: "",
    status: "new",
    number_text: "",
    amount: "0",
    count: 0,
    category: null,
    reason: null,
  };
  const usedSemanticIds = new Set<number>();
  const buckets = new Map<string, QuickEntryLine[]>();
  lines.forEach((line) => {
    const key = normalizeSource(sourceOf(line));
    const bucket = buckets.get(key) || [];
    bucket.push(line);
    buckets.set(key, bucket);
  });
  const multilineCandidates = lines
    .map((line) => ({ line, parts: sourceOf(line).split(/\r?\n/u).map(normalizeSource).filter(Boolean) }))
    .filter(({ parts }) => parts.length > 1);
  const addPhysicalOnly = (raw: string, physicalIndex: number, related?: QuickEntryLine, inheritedCategory?: string | null, groupEnd = false) => {
    expandedLines.push({
      ...fallbackLine,
      id: physicalIndex + 1,
      raw_text: raw,
      input_text: raw,
      status: related?.status || fallbackLine.status,
      number_text: related?.number_text || "",
      play_type: related?.play_type,
      amount: related?.amount || "0",
      count: related?.count || 0,
      category: categoryFromRaw(raw) || inheritedCategory || related?.category || null,
      __physicalOnly: true,
      __sourceIds: related ? [related.id] : undefined,
      __detailLines: related ? [related] : undefined,
      __groupKey: related ? `source-${related.id}` : undefined,
      __groupEnd: groupEnd,
    });
  };
  const addSemantic = (raw: string, physicalIndex: number, members: QuickEntryLine[]) => {
    const semantic = members[members.length - 1];
    if (!semantic) {
      addPhysicalOnly(raw, physicalIndex);
      return;
    }
    members.forEach((member) => usedSemanticIds.add(member.id));
    expandedLines.push({
      ...semantic,
      id: physicalIndex + 1,
      raw_text: raw,
      input_text: raw,
      __sourceIds: members.map((member) => member.id),
      __detailLines: members,
      __groupKey: `source-${semantic.id}`,
    });
  };

  if (sourcePhysical.length > 0) {
    let previousCategory: string | null = null;
    let pendingAggregate: { line: QuickEntryLine; batchId: string } | null = null;
    let activeSemantic: QuickEntryLine | null = null;
    sourcePhysical.forEach((raw, physicalIndex) => {
      if (raw.trim() === "") {
        expandedLines.push({ ...fallbackLine, id: physicalIndex + 1, raw_text: raw, input_text: raw, __blank: true });
        return;
      }
      const key = normalizeSource(raw);
      const rawCategory = categoryFromRaw(raw);
      const aggregateText = /^\s*(?:共|合计|总)\s*\d+(?:\.\d+)?\s*(?:米|元)?\s*$/u.test(raw);
      if (rawCategory) previousCategory = rawCategory;

      // A combined multi-line ticket is represented by the reference as a
      // non-summary continuation row followed by a final aggregate row. The
      // parser often returns only the semantic line (the aggregate is just
      // source text), so synthesize that final physical row from the prior
      // semantic line while preserving its parsed details.
      if (aggregateText && pendingAggregate) {
        const sourceLine = pendingAggregate.line;
        expandedLines.push({
          ...sourceLine,
          id: physicalIndex + 1,
          raw_text: raw,
          input_text: raw,
          count: Number(sourceLine.batch_count ?? sourceLine.code_count ?? sourceLine.count ?? 0),
          amount: String(sourceLine.batch_amount ?? sourceLine.amount ?? "0"),
          batch_id: pendingAggregate.batchId,
          batch_end: true,
          batch_valid: true,
          batch_count: Number(sourceLine.batch_count ?? sourceLine.code_count ?? sourceLine.count ?? 0),
          batch_amount: String(sourceLine.batch_amount ?? sourceLine.amount ?? "0"),
          __sourceIds: [sourceLine.id],
          __detailLines: [sourceLine],
        });
        pendingAggregate = null;
        previousCategory = sourceLine.category || previousCategory;
        return;
      }
      const exactMembers = (buckets.get(key) || []).filter((line) => !usedSemanticIds.has(line.id));
      if (exactMembers.length > 0) {
        const semantic = exactMembers[exactMembers.length - 1];
        const isContinuation = Boolean(
          activeSemantic
          && activeSemantic.status === "success"
          && semantic.status === "new"
          && !Number(semantic.count || 0)
          && !Number(semantic.amount || 0)
          && !rawCategory,
        );
        if (isContinuation) {
          usedSemanticIds.add(semantic.id);
          addPhysicalOnly(raw, physicalIndex, activeSemantic || semantic, previousCategory, physicalIndex === sourcePhysical.length - 1);
          return;
        }
        const nextIsAggregate = /^\s*(?:共|合计|总)\s*\d+(?:\.\d+)?\s*(?:米|元)?\s*$/u.test(sourcePhysical[physicalIndex + 1] || "");
        if (nextIsAggregate) {
          const batchId = `physical-batch-${physicalIndex + 1}`;
          pendingAggregate = { line: semantic, batchId };
          addSemantic(raw, physicalIndex, [{
            ...semantic,
            count: 0,
            amount: "0",
            batch_id: batchId,
            batch_end: false,
            batch_valid: true,
          }]);
        } else {
          addSemantic(raw, physicalIndex, exactMembers);
        }
        activeSemantic = semantic;
        previousCategory = semantic.category || previousCategory;
        return;
      }

      const multiline = multilineCandidates.find(({ line, parts }) =>
        !usedSemanticIds.has(line.id) && parts.includes(key),
      );
      if (multiline) {
        const lastPartIndex = multiline.parts.length - 1;
        const currentPartIndex = multiline.parts.indexOf(key);
        if (currentPartIndex < lastPartIndex) {
          addPhysicalOnly(raw, physicalIndex, multiline.line, previousCategory, currentPartIndex === lastPartIndex - 1);
        } else {
          addSemantic(raw, physicalIndex, [multiline.line]);
          activeSemantic = multiline.line;
          previousCategory = multiline.line.category || previousCategory;
        }
        return;
      }

      const nextSemantic = lines.find((line) => !usedSemanticIds.has(line.id) && (!rawCategory || line.category === rawCategory))
        || lines.find((line) => !usedSemanticIds.has(line.id));
      if (nextSemantic && !sourceOf(nextSemantic).includes("\n")) {
        const hasAggregateAfter = /^\s*(?:共|合计|总)\s*\d+(?:\.\d+)?\s*(?:米|元)?\s*$/u.test(sourcePhysical[physicalIndex + 1] || "");
        const semanticForRow = rawCategory && nextSemantic.category !== rawCategory
          ? { ...nextSemantic, category: rawCategory }
          : nextSemantic;
        if (hasAggregateAfter && !rawCategory?.includes("福体")) {
          const batchId = `physical-batch-${physicalIndex + 1}`;
          pendingAggregate = { line: semanticForRow, batchId };
          addSemantic(raw, physicalIndex, [{
            ...semanticForRow,
            count: 0,
            amount: "0",
            batch_id: batchId,
            batch_end: false,
            batch_valid: true,
          }]);
          activeSemantic = semanticForRow;
        } else {
          addSemantic(raw, physicalIndex, [semanticForRow]);
          activeSemantic = semanticForRow;
        }
        previousCategory = semanticForRow.category || previousCategory;
      } else {
        const groupEnd = physicalIndex === sourcePhysical.length - 1;
        addPhysicalOnly(raw, physicalIndex, activeSemantic || nextSemantic, previousCategory, groupEnd);
      }
    });
    lines.forEach((line) => {
      if (!usedSemanticIds.has(line.id)) {
        expandedLines.push({ ...line, __sourceIds: [line.id], __detailLines: [line] });
      }
    });
  } else {
    lines.forEach((line) => {
      expandedLines.push({ ...line, __sourceIds: [line.id], __detailLines: [line] });
    });
  }
  const displayGroups: DisplayLine[][] = [];
  // The parser keeps the two settlement legs of a combined 福体 sentence as
  // separate semantic rows (组三 and 组六). They are still one user-entered
  // ticket, so group them for the editable preview whenever the category,
  // selected numbers and stake text match. Do not merge different selections
  // such as 12345 and 123456.
  const combinedPlay = (line: QuickEntryLine): "组三" | "组六" | null => {
    const play = String(line.play_type || "");
    if (play.startsWith("组三")) return "组三";
    if (play.startsWith("组六")) return "组六";
    const source = String(line.input_text || line.raw_text || "");
    if (/组三/u.test(source)) return "组三";
    if (/组六/u.test(source)) return "组六";
    if (/^\s*[三六]\s*(?=\d)/u.test(source)) return source.trimStart().startsWith("三") ? "组三" : "组六";
    return null;
  };
  const combinedSourceKey = (line: QuickEntryLine): string | null => {
    const play = combinedPlay(line);
    if (!play) return null;
    const source = String(line.input_text || line.raw_text || "").trim();
    const key = source
      .replace(/组三|组六/gu, "")
      .replace(/^\s*[三六](?=\s*\d)/u, "")
      .replace(/\s+/gu, "");
    return `${line.category || ""}|${key}`;
  };
  const displayTextForGroup = (group: DisplayLine[]): string => {
    const first = String(group[0]?.input_text || group[0]?.raw_text || "");
    if (group.length < 2 || !group.some((item) => combinedPlay(item) === "组三") || !group.some((item) => combinedPlay(item) === "组六")) return first;
    const original = String(_sourceText || "").trim();
    if (original && !original.includes("\n") && /组三/u.test(original) && /组六/u.test(original)) return original;
    if (/组三|组六/u.test(first)) return first.replace(/组三|组六/u, "组三组六");
    return first.replace(/^\s*[三六]\s*(?=\d)/u, "组三组六 ");
  };
  expandedLines.forEach((line) => {
    const previous = displayGroups[displayGroups.length - 1];
    if (
      previous &&
      line.status === "success" &&
      !line.batch_id &&
      previous.every((item) => item.status === "success" && !item.batch_id) &&
      ((previous[0].input_text || previous[0].raw_text) === (line.input_text || line.raw_text)
        || (previous.length === 1 && combinedSourceKey(previous[0]) !== null && combinedSourceKey(previous[0]) === combinedSourceKey(line)
          && combinedPlay(previous[0]) !== combinedPlay(line))) &&
      !previous[0].raw_text.includes("\n") &&
      !line.raw_text.includes("\n") &&
      previous[0].category === line.category &&
      previous.some((item) => item.play_type !== line.play_type || combinedPlay(item) !== combinedPlay(line))
    ) {
      previous.push(line);
    } else {
      displayGroups.push([line]);
    }
  });
  // The reference keeps each mobile source line to a single fixed-height row;
  // long number lists are clipped horizontally instead of growing the result
  // panel into dozens of wrapped lines.
  useLayoutEffect(() => {
    if (!window.matchMedia("(max-width: 599px)").matches) return;
    const resize = () => {
      document.querySelectorAll<HTMLTextAreaElement>(".entry > .quick-result .quick-result-text").forEach((textarea) => {
        textarea.style.setProperty("height", "3rem", "important");
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
    const play = String(line.play_type || "");
    const family = play.startsWith("组三") || play.startsWith("组3") ? "三" : play.startsWith("组六") || play.startsWith("组6") ? "六" : "";
    if (family && play !== "复式") {
      const selection = String(line.settlement_text || line.parse_text || line.input_text || line.raw_text || "")
        .match(/(?<!\d)(\d{4,10})\s*(?:组六|组三|组6|组3)(?:两|三|四|五|六|七|八|九|[2-9])?码?/u)?.[1];
      if (selection) return [`${family}${selection}`];
      const compact = String(line.number_text || line.display_number_text || "").replace(/\s+/gu, "");
      if (/^[三六]\d{4,10}$/u.test(compact)) return [compact];
    }
    // Detail dialogs follow the provider's display order. `batch_occurrence_text`
    // is an internal occurrence/settlement sequence and can start with a
    // different subset (for example 133,144,166 before 033,044,066).
    const tokens = (line.display_number_text || line.number_text || line.batch_occurrence_text || "")
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
    const play = String(line.play_type || "");
    const groupSource = `${line.settlement_text || ""} ${line.parse_text || ""} ${line.input_text || ""} ${line.raw_text || ""}`;
    const multiDigits = String(line.number_text || line.display_number_text || "").match(/\d{4,10}/u)?.[0]
      || groupSource.match(/(?:组三|组六|组3|组6)\s*(\d{4,10})/u)?.[1]
      || groupSource.match(/(\d{4,10})\s*(?:组三|组六|组3|组6)/u)?.[1];
    const multiFamily = /组六|组6/u.test(play) ? "组六" : /组三|组3/u.test(play) ? "组三" : "";
    if (multiDigits && multiFamily) {
      const words: Record<string, string> = { "4": "四", "5": "五", "6": "六", "7": "七", "8": "八", "9": "九" };
      return `${multiFamily}${words[String(multiDigits.length)] || multiDigits.length}码`;
    }
    const mixedGroup = /(?:组三|组3)[^\n]*(?:组六|组6)|(?:组六|组6)[^\n]*(?:组三|组3)/u.test(groupSource);
    if (mixedGroup && /(?:组三|组六|组3|组6)/u.test(play)) {
      const count = groupSource.match(/(?<!\d)(\d{3,10})\s*(?:组三|组六|组3|组6)([一二两三四五六七八九]|[2-9])?码?/u);
      const words: Record<string, string> = { "2": "二", "3": "三", "4": "四", "5": "五", "6": "六", "7": "七", "8": "八", "9": "九" };
      const size = count?.[2] || (count?.[1] ? words[String(count[1].length)] : "");
      return size ? `组${words[size] || size}码` : "组选";
    }
    if (line.play_type === "直") return "直选";
    if (line.play_type === "组" || line.play_type === "组三" || line.play_type === "组六") return "组选";
    if (line.play_type?.startsWith("和")) return "和值";
    if (line.play_type?.startsWith("跨")) return "跨度";
    return line.play_type || "直选";
  };
  const rawDetailSections = detailLines.flatMap((line) => {
    const occurrences = numberTokens(line);
    const isGroup = /^(?:组|组选|组三|组六|组3|组6)/u.test(String(line.play_type || ""));
    const frequency = occurrences.reduce<Record<string, number>>((frequencies, number) => {
      const displayNumber = isGroup ? normalizeGroupNumber(number) : number;
      frequencies[displayNumber] = (frequencies[displayNumber] || 0) + 1;
      return frequencies;
    }, {});
    // The reference preserves the provider/API number order in the detail
    // table. Sorting here changes the visual sequence (e.g. 033,044,066,133)
    // and makes otherwise identical detail dialogs look different.
    const numbers = Array.from(new Set(occurrences.map((number) => isGroup ? normalizeGroupNumber(number) : number)));
    const stakeCount = Number(line.stake_count ?? line.code_count ?? line.count ?? 0);
    const unitAmount = stakeCount > 0 ? Number(line.amount || 0) / stakeCount : 0;
    const detectedCategory = line.category || categoryFromSource(line.input_text || line.raw_text || "");
    const categorySections = detectedCategory === "福体" ? ["体", "福"] : [detectedCategory || "福"];
    const amounts = Object.fromEntries(numbers.map((number) => [number, unitAmount * (frequency[number] || 1)]));
    return categorySections.map((category) => ({ line, category, title: playLabel(line), numbers, frequency, unitAmount, amounts }));
  });
  const detailSections = Array.from(rawDetailSections.reduce((map, section) => {
    const key = `${section.category}|${section.title}|${section.numbers.join(",")}`;
    const previous = map.get(key);
    if (!previous) { map.set(key, section); return map; }
    section.numbers.forEach((number) => {
      previous.amounts[number] = (previous.amounts[number] || 0) + (section.amounts[number] || 0);
      previous.frequency[number] = (previous.frequency[number] || 0) + (section.frequency[number] || 0);
    });
    return map;
  }, new Map<string, typeof rawDetailSections[number]>()).values()).sort((left, right) => {
    const categoryRank = (category: string) => category === "体" ? 0 : category === "福" ? 1 : 2;
    const playRank = (title: string) => title.includes("组三") ? 0 : title.includes("组六") ? 1 : title === "直选" ? 0 : 2;
    return categoryRank(left.category) - categoryRank(right.category) || playRank(left.title) - playRank(right.title);
  });
  const detailCount = detailLines.reduce((sum, line) => sum + Number(line.batch_count ?? line.code_count ?? line.count ?? 0), 0);
  const detailAmount = detailLines.reduce((sum, line) => sum + Number(line.batch_amount ?? line.amount ?? 0), 0);
  const openDetails = (group: DisplayLine[]) => {
    const details = group[group.length - 1]?.__detailLines;
    const mismatch = group.some((item) => item.status === "failed" && item.suggested_amount);
    if (mismatch) {
      const source = group[0]?.input_text || group[0]?.raw_text || "";
      const related = lines.filter((item) => {
        const key = item.input_text || item.raw_text || "";
        return key === source || key.includes(source) || source.includes(key);
      }).filter((item) => item.status === "success");
      setDetailLines(related.length ? related : (details?.length ? details : group));
      return;
    }
    setDetailLines(details?.length ? details : group);
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
        const relationKey = line.__groupKey || groupKey;
        const isEditing = editingGroupKey === groupKey;
        const draftText = draftTexts[groupKey] ?? displayTextForGroup(group);
        const isBatch = Boolean(line.batch_id);
        const isBatchEnd = isBatch && line.batch_end === true && line.batch_valid !== false;
        const isBlank = line.__blank === true;
        const isPhysicalOnly = line.__physicalOnly === true;
        const groupCount = group.reduce((sum, item) => sum + Number(item.code_count ?? item.count ?? 0), 0);
        const groupAmount = group.reduce((sum, item) => sum + Number(item.amount || 0), 0);
        const sourceKey = line.input_text || line.raw_text || "";
        // A single source sentence may expand into several successful rows
        // plus one final mismatch row. Resolve the mismatch from the whole
        // display group instead of looking only at its first row.
        const mismatchLine = group.find((item) => isAmountMismatch(item));
        const amountMismatch = Boolean(mismatchLine);
        const hasParsedResult = (item: QuickEntryLine) => item.status === "success"
          || (item.status !== "failed" && (Number(item.count || 0) > 0 || Number(item.amount || 0) > 0 || Boolean(item.number_text || item.display_number_text)));
        const groupHasParsedResult = group.some(hasParsedResult);
        const mismatchGroup = amountMismatch || mismatchSources.has(sourceKey) || mismatchTexts.some((text) => text.includes(sourceKey) || sourceKey.includes(text));
        const hasLaterRelatedGroup = mismatchGroup
          ? displayGroups.slice(groupIndex + 1).some((candidate) => candidate[0]?.__groupKey === relationKey || mismatchTexts.some((text) => {
              const candidateSource = candidate[0]?.input_text || candidate[0]?.raw_text || "";
              return candidateSource !== "" && text.includes(candidateSource);
            }))
          : displayGroups.slice(groupIndex + 1).some((candidate) => candidate[0]?.__groupKey === relationKey || (() => {
              const candidateSource = candidate[0]?.input_text || candidate[0]?.raw_text || "";
              return candidateSource === sourceKey || candidateSource.includes(sourceKey) || sourceKey.includes(candidateSource);
            })());
        const isLastRelatedGroup = !hasLaterRelatedGroup;
        const showDetailButton = isLastRelatedGroup && ((groupHasParsedResult && (!isBatch || isBatchEnd)) || mismatchGroup) && (!isPhysicalOnly || line.__groupEnd === true);
        const displayCategory = line.category || lines.find((item) => {
          const itemSource = item.input_text || item.raw_text || "";
          return item.category && (itemSource === sourceKey || itemSource.includes(sourceKey) || sourceKey.includes(itemSource));
        })?.category || categoryFromSource(sourceKey) || (isPhysicalOnly ? "" : "福");
        const categoryTone = displayCategory === "福" ? "fu" : displayCategory === "体" ? "ti" : displayCategory === "福体" ? "futi" : "";
        const visualStatus = isBlank ? "blank" : isEditing ? "new" : mismatchGroup ? "mismatch" : hasParsedResult(line) ? "success" : line.status;
        const visualTone = isEditing ? "" : categoryTone;
        return (
          <Fragment key={group.map((item) => item.id).join("-")}>
          <div className={`quick-result-row ${visualStatus}${visualTone ? ` ${visualTone}` : ""}${isBatchEnd ? " batch-end" : ""}`}>
            <div className="quick-result-main">
              <button type="button" className="quick-result-remove" aria-label={`删除第${line.id}条`} onClick={() => removeGroup(group)} />
              <span className="quick-result-index">{line.id}</span>
              <button type="button" className="quick-result-add" aria-label={`新增第${line.id}条`} onClick={() => addLine(line)} />
              <strong className={`quick-result-status ${visualStatus}${visualTone ? ` ${visualTone}` : ""}`}>
                {isBlank ? "" : isPhysicalOnly ? displayCategory : isEditing ? "新" : mismatchGroup ? displayCategory : hasParsedResult(line) ? line.category || displayCategory : line.status === "new" ? "新" : "失败"}
              </strong>
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
                {!isPhysicalOnly && isLastRelatedGroup && (isBatchEnd || mismatchGroup) && <button type="button" className="quick-result-combine" aria-label={`查看第${line.id}条合并文本`} onClick={() => setMergedLine(line)}>合</button>}
                {showDetailButton && <button type="button" className="quick-result-more" aria-label={`查看第${line.id}条详情`} onClick={() => openDetails(group)} />}
                {!isPhysicalOnly && isLastRelatedGroup && amountMismatch && onConfirmMismatch && (
                  <button type="button" className="quick-result-confirm" aria-label={mismatchLine?.suggested_amount ? `确认按${mismatchLine.suggested_amount}元修正` : "人工确认金额后修改"} onClick={() => onConfirmMismatch(mismatchLine || line)}>✓</button>
                )}
              </span>
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
            {!isPhysicalOnly && line.status === "success" ? null : line.status === "failed" && amountMismatch ? (
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
          {hasParsedResult(line) && (!isBatch || isBatchEnd) && isLastRelatedGroup && (
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
            <div className="result-category-tabs">
              {Array.from(new Set(detailSections.map((section) => section.category))).map((category) => (
                <div key={`category-tab-${category}`} className={`ltype-wrapper ${category === "体" ? "is-ti" : category === "福" ? "is-fu" : "is-futi"}`}>{category}</div>
              ))}
            </div>
            {detailSections.map((section, sectionIndex) => {
              const previousSection = detailSections[sectionIndex - 1];
              const isNewCategory = !previousSection || previousSection.category !== section.category;
              return (
              <Fragment key={`${section.category}-${section.title}-${sectionIndex}`}>
                {isNewCategory && sectionIndex > 0 && <div className="sep" />}
                {isNewCategory && <div className={`ltype-wrapper ${section.category === "体" ? "is-ti" : "is-fu"}`}>{section.category}</div>}
                <div className="ltype-body">
                  <div className="game-type-wrapper">
                    <div className="game-type-title">{section.title}</div>
                    <div className="row-container row-header-container">
                      {Array.from({ length: 4 }, (_, index) => (
                        <div className="row-label-container" key={`header-${section}-${index}`}>
                          <span className="label-wrapper">号码</span>
                          <span className="label-wrapper">金额</span>
                        </div>
                      ))}
                    </div>
                    <div className="row-container">
                      {Array.from({ length: Math.ceil(section.numbers.length / 4) }, (_, rowIndex) => section.numbers.slice(rowIndex * 4, rowIndex * 4 + 4)).map((row, rowIndex) => (
                        <Fragment key={`${section}-row-${rowIndex}`}>
                          {Array.from({ length: 4 }, (_, index) => row[index] || null).map((number, index) => (
                            <div className={`row-label-container${number ? " has-amount" : ""}`} key={`${section.category}-${section.title}-${rowIndex}-${index}`}>
                              <span className="label-wrapper">{number || "--"}</span>
                              <span className="label-wrapper">{number ? formatAmount((section.amounts[number] ?? section.unitAmount * (section.frequency[number] || 1)).toFixed(2)) : "--"}</span>
                            </div>
                          ))}
                        </Fragment>
                      ))}
                    </div>
                  </div>
                </div>
              </Fragment>
              );
            })}
          </div>
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
