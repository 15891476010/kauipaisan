import { Fragment, useCallback, useEffect, useState } from "react";
import { App as AntdApp, Modal, Switch } from "antd";
import { ArrowLeftOutlined, FileTextOutlined, LeftOutlined, SearchOutlined } from "@ant-design/icons";
import dayjs from "dayjs";
import { getBetDetails, getBetRecords, type BetDetail, type BetRecord, type Lottery } from "../../../api/user";
import { apiErrorMessage } from "../../../utils/request";
import { displayIssueCode } from "../shared";
import { RecordsPagination } from "./RecordsPagination";

function displayTotalAmount(value: unknown): string {
  const numeric = Number(value);
  if (!Number.isFinite(numeric)) return "0";
  return Number.isInteger(numeric) ? String(numeric) : String(Number(numeric.toFixed(2)));
}

async function writeClipboardText(value: string): Promise<boolean> {
  if (navigator.clipboard && window.isSecureContext) {
    try {
      await navigator.clipboard.writeText(value);
      return true;
    } catch {
      // HTTP deployments and older WebViews need the selection fallback below.
    }
  }
  const textarea = document.createElement("textarea");
  textarea.value = value;
  textarea.setAttribute("readonly", "");
  textarea.style.position = "fixed";
  textarea.style.left = "-9999px";
  textarea.style.top = "0";
  textarea.style.opacity = "0";
  document.body.appendChild(textarea);
  textarea.focus();
  textarea.select();
  textarea.setSelectionRange(0, textarea.value.length);
  try {
    return document.execCommand("copy");
  } catch {
    return false;
  } finally {
    textarea.remove();
  }
}

type TicketPdfPage = {
  data: Uint8Array;
  width: number;
  height: number;
};

function concatBytes(parts: Uint8Array[]): Uint8Array {
  const size = parts.reduce((total, part) => total + part.length, 0);
  const result = new Uint8Array(size);
  let offset = 0;
  parts.forEach((part) => {
    result.set(part, offset);
    offset += part.length;
  });
  return result;
}

function createPdfBlob(pages: TicketPdfPage[]): Blob {
  const encoder = new TextEncoder();
  const chunks: Uint8Array[] = [];
  const offsets: number[] = [0];
  let size = 0;
  const append = (part: Uint8Array | string) => {
    const bytes = typeof part === "string" ? encoder.encode(part) : part;
    chunks.push(bytes);
    size += bytes.length;
  };
  const appendObject = (number: number, body: string | Uint8Array[]) => {
    offsets[number] = size;
    append(`${number} 0 obj\n`);
    if (typeof body === "string") append(body);
    else body.forEach(append);
    append("\nendobj\n");
  };

  append("%PDF-1.4\n%");
  append(new Uint8Array([0xe2, 0xe3, 0xcf, 0xd3]));
  append("\n");
  appendObject(1, "<< /Type /Catalog /Pages 2 0 R >>");
  const pageNumbers = pages.map((_, index) => 3 + index * 3);
  appendObject(2, `<< /Type /Pages /Kids [${pageNumbers.map((number) => `${number} 0 R`).join(" ")}] /Count ${pages.length} >>`);

  pages.forEach((page, index) => {
    const pageNumber = 3 + index * 3;
    const imageNumber = pageNumber + 1;
    const contentNumber = pageNumber + 2;
    const pdfWidth = 595.28;
    const pdfHeight = 841.89;
    appendObject(pageNumber, `<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ${pdfWidth} ${pdfHeight}] /Resources << /XObject << /Im0 ${imageNumber} 0 R >> >> /Contents ${contentNumber} 0 R >>`);
    appendObject(imageNumber, [
      encoder.encode(`<< /Type /XObject /Subtype /Image /Width ${page.width} /Height ${page.height} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length ${page.data.length} >>\nstream\n`),
      page.data,
      encoder.encode("\nendstream"),
    ]);
    const content = `q ${pdfWidth} 0 0 ${pdfHeight} 0 0 cm /Im0 Do Q`;
    appendObject(contentNumber, `<< /Length ${encoder.encode(content).length} >>\nstream\n${content}\nendstream`);
  });

  const xrefOffset = size;
  append(`xref\n0 ${offsets.length}\n`);
  append("0000000000 65535 f \n");
  for (let number = 1; number < offsets.length; number += 1) {
    append(`${String(offsets[number]).padStart(10, "0")} 00000 n \n`);
  }
  append(`trailer\n<< /Size ${offsets.length} /Root 1 0 R >>\nstartxref\n${xrefOffset}\n%%EOF`);
  const pdfBytes = concatBytes(chunks);
  return new Blob([Uint8Array.from(pdfBytes).buffer], { type: "application/pdf" });
}

async function renderTicketPdf(
  record: BetRecord,
  lines: BetDetail[],
  memberName?: string,
): Promise<Blob> {
  const groups = Array.from(lines.reduce((result, line) => {
    const key = String(line.lottery || record.lottery || "福") + "|" + String(line.issue_no || record.issue_no || "--");
    if (!result.has(key)) result.set(key, []);
    result.get(key)!.push(line);
    return result;
  }, new Map<string, BetDetail[]>()));
  if (!groups.length) throw new Error("empty numbers");

  const width = 1240;
  const height = 1754;
  const left = 118;
  const paperWidth = 946;
  const firstColumnWidth = Math.round(paperWidth * 0.6875);
  const metaHeight = 124;
  const headerHeight = 41;
  const groupHeight = 41;
  const rowHeight = 41;
  const footerHeight = 41;
  const bottom = height - 125;
  const placedAt = dayjs(record.placed_at);
  const placedTime = placedAt.isValid() ? placedAt.format("YYYY-MM-DD HH:mm") : String(record.placed_at || "");
  const generatedTime = dayjs().format("YYYY-MM-DD HH:mm");
  const pageImages: string[] = [];
  let canvas: HTMLCanvasElement | undefined;
  let context!: CanvasRenderingContext2D;
  let y = 0;

  const setFont = (weight: 400 | 600 | 700, pixels: number) => {
    context.font = `${weight} ${pixels}px "Songti SC", STSong, SimSun, serif`;
    context.textBaseline = "middle";
  };
  const drawBorder = (top: number, boxHeight: number) => {
    context.strokeStyle = "#555";
    context.lineWidth = 1.5;
    context.strokeRect(left, top, paperWidth, boxHeight);
  };
  const drawTableHeader = () => {
    context.fillStyle = "#fff";
    context.fillRect(left, y, paperWidth, headerHeight);
    drawBorder(y, headerHeight);
    context.beginPath();
    context.moveTo(left + firstColumnWidth, y);
    context.lineTo(left + firstColumnWidth, y + headerHeight);
    context.stroke();
    context.fillStyle = "#111";
    setFont(400, 24);
    context.textAlign = "center";
    context.fillText("号码", left + firstColumnWidth / 2, y + headerHeight / 2);
    context.fillText("金额", left + firstColumnWidth + (paperWidth - firstColumnWidth) / 2, y + headerHeight / 2);
    y += headerHeight;
  };
  const finishPage = () => {
    if (!canvas) return;
    pageImages.push(canvas.toDataURL("image/jpeg", 0.92));
    canvas.width = 1;
    canvas.height = 1;
    canvas = undefined;
  };
  const startPage = () => {
    finishPage();
    canvas = document.createElement("canvas");
    canvas.width = width;
    canvas.height = height;
    context = canvas.getContext("2d", { alpha: false })!;
    context.fillStyle = "#fff";
    context.fillRect(0, 0, width, height);
    y = 100;
    drawBorder(y, metaHeight);
    context.fillStyle = "#111";
    setFont(400, 24);
    context.textAlign = "left";
    context.fillText(`下注时间: ${placedTime}`, left + 8, y + 25);
    context.fillText(`全体时间: ${generatedTime}`, left + 8, y + 62);
    context.fillText(`用户: ${memberName || "-"}`, left + 8, y + 99);
    y += metaHeight;
    drawTableHeader();
  };
  const drawGroupHeader = (text: string) => {
    context.fillStyle = "#7d7d7d";
    context.fillRect(left, y, paperWidth, groupHeight);
    drawBorder(y, groupHeight);
    context.fillStyle = "#fff";
    setFont(400, 24);
    context.textAlign = "center";
    context.fillText(text, left + paperWidth / 2, y + groupHeight / 2);
    y += groupHeight;
  };
  const drawNumberRow = (line: BetDetail) => {
    drawBorder(y, rowHeight);
    context.beginPath();
    context.moveTo(left + firstColumnWidth, y);
    context.lineTo(left + firstColumnWidth, y + rowHeight);
    context.stroke();
    context.fillStyle = "#111";
    setFont(400, 24);
    context.textAlign = "center";
    context.fillText(line.number_text || "-", left + firstColumnWidth / 2, y + rowHeight / 2);
    context.fillText(displayTotalAmount(line.amount), left + firstColumnWidth + (paperWidth - firstColumnWidth) / 2, y + rowHeight / 2);
    y += rowHeight;
  };

  startPage();
  groups.forEach(([key, groupLines]) => {
    const [lottery, issue] = key.split("|");
    const lotteryLabel = lottery === "体" || lottery === "排列三" ? "体" : "福";
    const amount = groupLines.reduce((total, line) => total + Number(line.amount || 0), 0);
    const groupTitle = `${lotteryLabel} 第${issue}期 共${displayTotalAmount(amount)}米`;
    if (y + groupHeight + rowHeight > bottom) startPage();
    drawGroupHeader(groupTitle);
    groupLines.slice().sort((leftLine, rightLine) =>
      String(leftLine.number_text || "").localeCompare(String(rightLine.number_text || ""), "zh-CN", { numeric: true, sensitivity: "base" }),
    ).forEach((line) => {
      if (y + rowHeight > bottom) {
        startPage();
      }
      drawNumberRow(line);
    });
  });
  if (y + footerHeight > bottom) startPage();
  setFont(400, 24);
  context.textAlign = "left";
  let summaryX = left + 8;
  const drawSummary = (text: string, color: string) => {
    context.fillStyle = color;
    context.fillText(text, summaryX, y + footerHeight / 2);
    summaryX += context.measureText(text).width;
  };
  drawSummary("总笔数 ", "#111");
  drawSummary(String(lines.length), "#ff0000");
  drawSummary(" 总金额 ", "#111");
  drawSummary(displayTotalAmount(record.amount), "#ff0000");
  finishPage();

  const jpegPages = pageImages.map((dataUrl) => {
    const binary = window.atob(dataUrl.slice(dataUrl.indexOf(",") + 1));
    const data = new Uint8Array(binary.length);
    for (let index = 0; index < binary.length; index += 1) data[index] = binary.charCodeAt(index);
    return { data, width, height };
  });
  return createPdfBlob(jpegPages);
}

export function MorePanel({
  onBack,
  lotteries,
  memberName,
  initialNumberRecord,
}: {
  onBack: () => void;
  lotteries: Lottery[];
  memberName?: string;
  initialNumberRecord?: BetRecord;
}) {
  const { message } = AntdApp.useApp();
  const today = new Intl.DateTimeFormat("sv-SE", { timeZone: "Asia/Shanghai" }).format(new Date());
  const dateOptions = Array.from(
    new Map([
      ...lotteries.map((lottery) => {
        const recent = lottery.recent_issues || [];
        const latestText = String(recent[0]?.draw_day || "");
        const match = latestText.match(/^(\d{4})[-/](\d{1,2})[-/](\d{1,2})$/u);
        const nextDay = match
          ? dayjs(new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]) + 1)).format("YYYY-MM-DD")
          : today;
        const inferred = recent[0]?.code
          ? String(Number(recent[0].code) + 1)
          : "";
        const configured = String(lottery.next_code || "");
        const latestNumber = Number(recent[0]?.code || lottery.latest_code || 0);
        const nextCode = configured && Number(configured) > latestNumber
          ? configured
          : inferred;
        return [
          nextDay,
          { day: nextDay, code: nextCode },
        ] as const;
      }),
      ...lotteries.flatMap((lottery) =>
        (lottery.recent_issues || []).map((issue) => [
          issue.draw_day || issue.code,
          { day: issue.draw_day || today, code: issue.code },
        ] as const),
      ),
    ]).values(),
  ).slice(0, 30);
  const [selectedDay, setSelectedDay] = useState(dateOptions[0]?.day || today);
  const [source, setSource] = useState("");
  const [board, setBoard] = useState("all");
  const boardOptions = Array.from(new Map(lotteries.flatMap((lottery) => lottery.boards || []).map((item) => [item.code, item] as const)).values());
  const [onlyRefunded, setOnlyRefunded] = useState(false);
  const [records, setRecords] = useState<BetRecord[]>([]);
  const [amountTotal, setAmountTotal] = useState("0");
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const pageSize = 20;
  const [loading, setLoading] = useState(false);
  const [detailRecord, setDetailRecord] = useState<BetRecord>();
  const [detailLines, setDetailLines] = useState<BetDetail[]>([]);
  const [detailLoading, setDetailLoading] = useState(false);
  const [numberRecord, setNumberRecord] = useState<BetRecord | undefined>(initialNumberRecord);
  const [numberLines, setNumberLines] = useState<BetDetail[]>([]);
  const [numberPage, setNumberPage] = useState(1);
  const [numberTotal, setNumberTotal] = useState(0);
  const [numberLoading, setNumberLoading] = useState(Boolean(initialNumberRecord));
  const [numberActionLoading, setNumberActionLoading] = useState(false);
  useEffect(() => {
    if (
      dateOptions.length &&
      !dateOptions.some((item) => item.day === selectedDay)
    )
      setSelectedDay(dateOptions[0].day);
  }, [lotteries, dateOptions.length, dateOptions[0]?.day]);
  const search = () => {
    setLoading(true);
    getBetRecords({
      from: selectedDay,
      to: selectedDay,
      source: source.trim() || undefined,
      page,
      page_size: pageSize,
      board: board === "all" ? undefined : board,
    })
      .then((response) => {
        const data = response.data?.data;
        const list = data?.list || [];
        setRecords(onlyRefunded ? list.filter((item) => item.status === "refunded") : list);
        setAmountTotal(displayTotalAmount(data?.amount_total));
        setTotal(Number(data?.total || list.length));
      })
      .catch((error) => {
        setRecords([]);
        setAmountTotal("0");
        message.error(apiErrorMessage(error, "投注记录加载失败"));
      })
      .finally(() => setLoading(false));
  };
  useEffect(() => {
    if (dateOptions.length) search();
  }, [selectedDay, page, board, onlyRefunded]);
  const runSearch = () => {
    setPage(1);
    if (page === 1) search();
  };
  const copyRecordText = (record: BetRecord) => {
    const value = record.source_text || record.formatted_text || "";
    if (!value) return;
    const textarea = document.createElement("textarea");
    textarea.value = value;
    textarea.setAttribute("readonly", "");
    textarea.style.position = "fixed";
    textarea.style.left = "-9999px";
    textarea.style.top = "0";
    textarea.style.opacity = "0";
    document.body.appendChild(textarea);
    textarea.focus();
    textarea.select();
    textarea.setSelectionRange(0, textarea.value.length);
    let copied = false;
    try {
      copied = document.execCommand("copy");
    } finally {
      textarea.remove();
    }
    if (copied) {
      message.success("原始文本已复制");
      return;
    }
    const promise = navigator.clipboard?.writeText(value);
    if (promise) {
      void promise
        .then(() => message.success("原始文本已复制"))
        .catch(() => message.error("复制原始文本失败"));
    } else {
      message.error("复制原始文本失败");
    }
  };
  const loadAllNumberLines = async (): Promise<BetDetail[]> => {
    if (!numberRecord) return [];
    const pageCount = Math.max(1, Math.ceil(numberTotal / 100));
    const responses = await Promise.all(
      Array.from({ length: pageCount }, (_, index) =>
        getBetDetails({
          submission_id: numberRecord.id,
          page: index + 1,
          page_size: 100,
        }),
      ),
    );
    return responses.flatMap((response) => response.data?.data?.list || []);
  };
  const buildNumbersText = (lines: BetDetail[]): string => {
    if (!numberRecord || !lines.length) return "";
    const groups = Array.from(lines.reduce((result, line) => {
      const key = String(line.lottery || numberRecord.lottery || "福") + "|" + String(line.issue_no || numberRecord.issue_no || "--");
      if (!result.has(key)) result.set(key, []);
      result.get(key)!.push(line);
      return result;
    }, new Map<string, BetDetail[]>()));
    const placedAt = dayjs(numberRecord.placed_at);
    const rows = [placedAt.isValid() ? placedAt.format("YYYY-MM-DD HH:mm:ss") : String(numberRecord.placed_at || "")];
    groups.forEach(([key, groupLines]) => {
      const [lottery, issue] = key.split("|");
      const lotteryLabel = lottery === "体" || lottery === "排列三" ? "体" : "福";
      const groupAmount = groupLines.reduce((sum, line) => sum + Number(line.amount || 0), 0);
      rows.push("-----------------------");
      rows.push(`${lotteryLabel} 第 ${issue} 期 共${displayTotalAmount(groupAmount)}米`);
      groupLines.forEach((line) => {
        rows.push(`${line.number_text || "-"} - ${displayTotalAmount(line.amount)}米`);
      });
    });
    rows.push("-----------------------");
    rows.push("请核对 一切以小票为准");
    rows.push(`共${displayTotalAmount(numberRecord.amount)}米`);
    return rows.join("\n");
  };
  const copyNumbers = async () => {
    if (!numberRecord || numberActionLoading) return;
    setNumberActionLoading(true);
    try {
      const value = buildNumbersText(await loadAllNumberLines());
      if (!value || !(await writeClipboardText(value))) throw new Error("copy failed");
      message.success("已复制号码");
    } catch {
      message.error("复制号码失败");
    } finally {
      setNumberActionLoading(false);
    }
  };
  const downloadNumbers = async () => {
    if (!numberRecord || numberActionLoading) return;
    // Match the reference site: open the browser PDF surface immediately from
    // the click, then navigate that window after the full ticket is rendered.
    // Opening it before the async work prevents mobile browsers from treating
    // the preview as an unsolicited popup.
    const previewWindow = window.open("", "numberPdfPreview", "toolbar=no,location=no");
    if (!previewWindow) {
      message.error("PDF 预览被浏览器拦截，请允许弹出窗口后重试");
      return;
    }
    previewWindow.document.open();
    previewWindow.document.write("<!doctype html><html><head><meta charset=\"utf-8\"><title>正在生成 PDF</title><style>body{margin:0;display:grid;place-items:center;height:100vh;font:16px sans-serif;color:#444}</style></head><body>正在生成号码明细 PDF…</body></html>");
    previewWindow.document.close();
    setNumberActionLoading(true);
    try {
      const lines = await loadAllNumberLines();
      const blob = await renderTicketPdf(numberRecord, lines, memberName);
      const issue = String(numberRecord.issue_no || "号码明细").replace(/[^0-9A-Za-z\u4e00-\u9fff_-]/gu, "-");
      const filename = `号码明细-${issue}.pdf`;
      const file = new File([blob], filename, { type: "application/pdf" });
      const url = URL.createObjectURL(file);
      previewWindow.location.replace(url);
      window.setTimeout(() => URL.revokeObjectURL(url), 10 * 60_000);
      message.success("PDF 预览已打开，请使用浏览器的下载或打印按钮");
    } catch {
      previewWindow.close();
      message.error("号码明细下载失败");
    } finally {
      setNumberActionLoading(false);
    }
  };
  const openDetails = async (record: BetRecord) => {
    setDetailRecord(record);
    setDetailLines([]);
    setDetailLoading(true);
    try {
      const response = await getBetDetails({
        submission_id: record.id,
        page: 1,
        page_size: 100,
      });
      setDetailLines(response.data?.data?.list || []);
    } catch (error) {
      message.error(apiErrorMessage(error, "下注详情加载失败"));
    } finally {
      setDetailLoading(false);
    }
  };
  const openNumbers = useCallback(async (record: BetRecord, page = 1) => {
    setNumberRecord(record);
    setNumberPage(page);
    setNumberLoading(true);
    try {
      const response = await getBetDetails({
        submission_id: record.id,
        page,
        page_size: 100,
      });
      const data = response.data?.data;
      setNumberLines(data?.list || []);
      setNumberTotal(Number(data?.total || data?.list?.length || 0));
    } catch (error) {
      setNumberLines([]);
      setNumberTotal(0);
      message.error(apiErrorMessage(error, "号码明细加载失败"));
    } finally {
      setNumberLoading(false);
    }
  }, [message]);
  useEffect(() => {
    if (initialNumberRecord) void openNumbers(initialNumberRecord);
  }, [initialNumberRecord, openNumbers]);
  const numberGroups = Array.from(numberLines.reduce((groups, line) => {
    const key = String(line.lottery || numberRecord?.lottery || "福") + "|" + String(line.issue_no || numberRecord?.issue_no || "--");
    if (!groups.has(key)) groups.set(key, []);
    groups.get(key)!.push(line);
    return groups;
  }, new Map<string, BetDetail[]>()));
  const numberPageCount = Math.max(1, Math.ceil(numberTotal / 100));
  if (numberRecord) {
    const time = String(numberRecord.placed_at || "").slice(0, 16);
    return (
      <section className="more-number-page">
        <div className="number-page-toolbar">
          <span className="number-page-back" role="button" tabIndex={0} aria-label="返回查看更多" onClick={() => setNumberRecord(undefined)} onKeyDown={(event) => { if (event.key === "Enter" || event.key === " ") { event.preventDefault(); setNumberRecord(undefined); } }}>
            <ArrowLeftOutlined />
          </span>
          <div className="number-page-indicator"><span>第<b>{numberPage}/{numberPageCount}</b>页</span></div>
          <div className="number-page-actions">
            <button type="button" disabled={numberPage <= 1 || numberLoading} onClick={() => void openNumbers(numberRecord, numberPage - 1)}>上页</button>
            <button type="button" disabled={numberPage >= numberPageCount || numberLoading} onClick={() => void openNumbers(numberRecord, numberPage + 1)}>下页</button>
            <button type="button" disabled={numberActionLoading} onClick={() => void downloadNumbers()}>下载</button>
            <button type="button" disabled={numberActionLoading} onClick={() => void copyNumbers()}>复制号码</button>
          </div>
        </div>
        <div className="number-page-body">
          <div className="number-paper">
            <div className="number-unit">单位：元</div>
            <div className="number-meta"><div>时间: {time}</div><div>会员: {memberName || "-"}</div></div>
            <div className="number-table-head"><span>号码</span><span>金额</span></div>
            {numberGroups.map(([key, lines]) => {
              const [lottery, issue] = key.split("|");
              const amount = lines.reduce((sum, line) => sum + Number(line.amount || 0), 0);
              const lotteryLabel = lottery === "体" || lottery === "排列三" ? "体" : "福";
              return (
                <Fragment key={key}>
                  <div className="number-group-header"><b>{lotteryLabel}</b><span>第</span><b>{issue}</b><span>期，共</span><b>{amount.toFixed(2).replace(/\.00$/, "")}</b></div>
                  {lines.map((line, index) => <div className="number-row" key={line.id || index}><span>{line.number_text || "-"}</span><span>{line.amount || "0"}</span></div>)}
                </Fragment>
              );
            })}
            {!numberLoading && numberLines.length === 0 && <div className="number-empty">暂无号码</div>}
            <div className="number-note">
              请核对 一切以小票为准<br />
              总笔数:{numberTotal} 总金额:{numberRecord.amount}
            </div>
          </div>
        </div>
        {(numberLoading || numberActionLoading) && <div className="page-local-loading" role="status" aria-label="加载中" />}
      </section>
    );
  }
  return (
    <section className="more-panel">
      <div className="more-search">
        <div className="more-row more-date-row">
          <div className="more-row-inner">
            <button className="more-back-button" type="button" onClick={onBack}><LeftOutlined />返回</button>
            <div className="more-range-search-wrapper">
              <select
                value={selectedDay}
                onChange={(event) => setSelectedDay(event.target.value)}
              >
                {dateOptions.length ? (
                  dateOptions.map((item) => (
                    <option key={item.day} value={item.day}>
                      {dayjs(item.day).format("M-D")} (
                      {lotteries
                        .map(
                          (lottery) =>
                            String(lottery.name === "排列三" ? "体" : "福") + "-" + displayIssueCode(item.code),
                        )
                        .join(" ")}
                      )
                    </option>
                  ))
                ) : (
                  <option value={today}>{dayjs(today).format("M-D")}</option>
                )}
              </select>
            </div>
          </div>
        </div>
        <div className="more-row more-board-row">
          <div className="more-row-inner">
            <label className="more-board-field">
              <span>盘口</span>
              <select value={board} onChange={(event) => { setBoard(event.target.value); setPage(1); }}>
                <option value="all">全部</option>{boardOptions.map((item) => <option key={item.code} value={item.code}>{item.name} - {item.code}</option>)}
              </select>
            </label>
          </div>
        </div>
        <div className="more-row more-text-row">
          <div className="more-row-inner">
            <div className="more-text-field">
              <textarea
                className="ant-input"
                rows={8}
                value={source}
                onChange={(event) => setSource(event.target.value)}
                placeholder="原始文本搜索"
                aria-label="原始文本搜索"
                onKeyDown={(event) => {
                  if (event.key === "Enter" && !event.shiftKey) runSearch();
                }}
              />
            </div>
          </div>
        </div>
        <div className="more-search-wrapper">
          <label className="more-refund-toggle">
            <span>仅退码</span>
            <Switch
              className="record-detail-winning-switch"
              checked={onlyRefunded}
              checkedChildren="是"
              unCheckedChildren="否"
              onChange={(checked) => {
                setOnlyRefunded(checked);
                setPage(1);
              }}
            />
          </label>
          <button
            className="more-search-button"
            type="button"
            onClick={runSearch}
            disabled={loading}
          >
            <SearchOutlined /><span>搜索</span>
          </button>
          <label className="more-total">总金额：</label>
          <span className="more-total-amount">{amountTotal}</span>
        </div>
      </div>
      <div className="more-results">
        {total > 0 && (
          <RecordsPagination
            page={page}
            pageSize={pageSize}
            total={total}
            loading={loading}
            onPage={setPage}
          />
        )}
        {records.length > 0 && <div className="more-card-list">{records.map((record) => {
          const refunded = record.status === "refunded";
          const boardCode = String(record.board_code || "A").toUpperCase();
          const lotteryClass = record.lottery === "体" || record.lottery === "排列三" ? "pl3" : "fc3";
          return (
            <article className={"more-card" + (refunded ? " is-refunded" : "")} key={record.id} title={refunded ? "已退码" : "点击复制文本"}>
              <label className="more-card-meta">
                <span className={"more-card-board pk-" + boardCode}>{record.board_name || boardCode + "盘"}</span>
                时间：{record.placed_at}
              </label>
              <p className={lotteryClass} onClick={refunded ? undefined : () => copyRecordText(record)}>{record.source_text || record.formatted_text || "-"}</p>
              <div className="more-card-footer">
                <span>{refunded ? "0.00" : record.amount}</span>
                {!refunded && (
                  <div className="more-card-actions">
                    <button type="button" className="more-card-action copy" aria-label="查看下注详情" onClick={() => void openDetails(record)}>
                      <FileTextOutlined aria-hidden="true" />
                    </button>
                    <button type="button" className="more-card-action numbers" aria-label="查看号码" onClick={() => void openNumbers(record)}>号</button>
                  </div>
                )}
              </div>
            </article>
          );
        })}</div>}
        {!loading && records.length === 0 && <div className="more-empty">暂无数据</div>}
        {total > 0 && (
          <RecordsPagination
            page={page}
            pageSize={pageSize}
            total={total}
            loading={loading}
            onPage={setPage}
          />
        )}
        {loading && (
          <div
            className="page-local-loading"
            role="status"
            aria-label="加载中"
          />
        )}
      </div>
      <Modal
        className="more-detail-modal"
        open={Boolean(detailRecord)}
        title="下注详情"
        footer={<button type="button" className="more-detail-close" onClick={() => setDetailRecord(undefined)}>关 闭</button>}
        onCancel={() => setDetailRecord(undefined)}
        width={760}
      >
        {detailLoading ? (
          <div className="more-detail-loading">加载中...</div>
        ) : (
          <div className="more-detail-content">
            <div className="more-detail-lottery">
              {detailRecord?.lottery === "体" || detailRecord?.lottery === "排列三" ? "排列三" : "福彩3D"}
            </div>
            {detailLines.length ? (
              <>
              <h5 className="more-detail-section-title">
                {detailRecord?.lottery === "体" || detailRecord?.lottery === "排列三" ? "排列三" : "福彩3D"}
              </h5>
              <div className="more-detail-play-label">
                {detailLines[0]?.play_label || detailLines[0]?.play_type || "投注"}
              </div>
              <div className="more-detail-lines">
                <div className="more-detail-line more-detail-header">
                  <span>号码</span>
                  <span>金额</span>
                  <span>赔率</span>
                  <span>中奖</span>
                </div>
                {detailLines.map((line, index) => (
                  <div className="more-detail-line" key={line.id || index}>
                    <span>{line.number_text || "-"}</span>
                    <span>{line.amount || "0"}</span>
                    <span>{line.odds || "---"}</span>
                    <span>{Number(line.win_amount || 0) > 0 ? line.win_amount : "---"}</span>
                  </div>
                ))}
              </div>
              </>
            ) : (
              <div className="more-detail-empty">暂无详情</div>
            )}
          </div>
        )}
      </Modal>
    </section>
  );
}
