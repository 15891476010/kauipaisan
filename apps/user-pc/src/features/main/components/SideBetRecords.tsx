import { Fragment, useEffect, useState } from "react";
import { App as AntdApp, Button, Empty, Modal } from "antd";
import dayjs from "dayjs";
import arrowRightIcon from "../../../assets/arrow-right.svg";
import {
  getBetDetails,
  getBetRecords,
  refundBetRecord,
  type BetDetail,
  type BetRecord,
} from "../../../api/user";
import { apiErrorMessage } from "../../../utils/request";
import { displayAmount } from "../shared";

export function SideBetRecords({
  onMore,
  panelRight,
  onToggleSide,
  disabled = false,
}: {
  onMore: () => void;
  panelRight: boolean;
  onToggleSide: () => void;
  disabled?: boolean;
}) {
  const { message, modal } = AntdApp.useApp();
  const [records, setRecords] = useState<BetRecord[]>([]);
  const [amountTotal, setAmountTotal] = useState("0.00");
  const [loading, setLoading] = useState(false);
  const [details, setDetails] = useState<BetDetail[]>([]);
  const [detailRecord, setDetailRecord] = useState<BetRecord>();
  const [detailMode, setDetailMode] = useState<"detail" | "numbers">("detail");
  const [detailLoading, setDetailLoading] = useState(false);
  const load = () => {
    if (disabled) return;
    const today = dayjs().format("YYYY-MM-DD");
    setLoading(true);
    getBetRecords({ from: today, to: today, page: 1, page_size: 100 })
      .then((response) => {
        setRecords(response.data?.data?.list || []);
        setAmountTotal(response.data?.data?.amount_total || "0.00");
      })
      .catch((error) => {
        setRecords([]);
        setAmountTotal("0.00");
        message.error(apiErrorMessage(error, "下单记录加载失败"));
      })
      .finally(() => setLoading(false));
  };
  useEffect(() => {
    load();
    const refresh = () => load();
    window.addEventListener("bet-records-updated", refresh);
    return () => window.removeEventListener("bet-records-updated", refresh);
  }, [disabled]);
  const showRecord = async (record: BetRecord, mode: "detail" | "numbers") => {
    setDetailRecord(record);
    setDetailMode(mode);
    setDetailLoading(true);
    try {
      const result = await getBetDetails({
        submission_id: record.id,
        page: 1,
        // Details can contain hundreds of numbers (for example a 181-line
        // direct ticket). Load the complete record so the popup never hides
        // the first/last entries behind the API page limit.
        page_size: 2000,
      });
      setDetails(normalizeDetailRows(result.data?.data?.list || []));
    } catch (error) {
      setDetails([]);
      message.error(apiErrorMessage(error, "注单详情加载失败"));
    } finally {
      setDetailLoading(false);
    }
  };
  const refund = (record: BetRecord) => {
    modal.confirm({
      title: "确认退单",
      content: `确定退回该注单，金额 ¥ ${displayAmount(record.amount)} 吗？`,
      okText: "确认退单",
      cancelText: "取消",
      okButtonProps: { danger: true },
      onOk: async () => {
        try {
          await refundBetRecord(record.id);
          message.success("退单成功");
          setDetailRecord(undefined);
          await load();
          window.dispatchEvent(new Event("profile-updated"));
        } catch (error) {
          message.error(apiErrorMessage(error, "退单失败"));
        }
      },
    });
  };
  const lotteryName = (value?: string) => {
    if (value === "福") return "福彩3D";
    if (value === "体") return "排列三";
    return value || "福彩3D";
  };
  const packagePlay = (detail: BetDetail) => {
    // The detail row can contain only the provider's expanded atoms (for
    // example `三 组三 3 组三`).  The parent record keeps the authoritative
    // original wording, so include it when resolving package plays.
    const text = `${detail.play_label || ""} ${detail.play_type || ""} ${detail.source_text || ""} ${detail.original_source_text || ""} ${detail.record_source || ""}`;
    if (/(组六\s*(?:全包|包)|组6\s*(?:全包|包))/u.test(text)) return "组六全包";
    if (/(组三\s*(?:全包|包)|组3\s*(?:全包|包))/u.test(text)) return "组三全包";
    return "";
  };
  const playName = (detail: BetDetail) => {
    const raw = String(detail.play_label || detail.play_type || detail.category || "投注");
    const packageType = packagePlay(detail);
    if (packageType) return packageType;
    // 多码/沾边赖在接口中可能是 `组三 + 三 23456`，也可能是
    // `23456组三五码`。两种写法都归到参考站的同一表头。
    const multiSource = `${detail.source_text || ""} ${detail.original_source_text || ""} ${detail.record_source || ""}`;
    const multiDigits = String(detail.number_text || "").match(/\d{4,10}/u)?.[0]
      || multiSource.match(/(?:组三|组六|组3|组6)\s*(\d{4,10})/u)?.[1]
      || multiSource.match(/(\d{4,10})\s*(?:组三|组六|组3|组6)/u)?.[1];
    const multiFamily = /组六|组6/u.test(raw) ? "组六" : /组三|组3/u.test(raw) ? "组三" : /组六|组6/u.test(multiSource) ? "组六" : /组三|组3/u.test(multiSource) ? "组三" : "";
    if (multiDigits && multiFamily) {
      const words: Record<string, string> = { "4": "四", "5": "五", "6": "六", "7": "七", "8": "八", "9": "九" };
      return `${multiFamily}${words[String(multiDigits.length)] || multiDigits.length}${/赖/u.test(multiSource) ? "赖" : "码"}`;
    }
    const dragSource = `${raw} ${detail.number_text || ""} ${detail.source_text || ""}`;
    if (/胆拖/u.test(dragSource)) {
      if (/(组六|组6|六组)/u.test(dragSource)) return "组六胆拖";
      if (/(组三|组3|三组)/u.test(dragSource)) return "组三胆拖";
    }
    // 定位玩法的 X/口 是内部占位符，不作为用户端玩法名称显示。
    if (/^[口Xx]{2}[Xx]$/.test(raw) || raw === "口口X") return "二码定位";
    if (/^[口Xx][Xx]{2}$/.test(raw)) return "一码定位";
    // A mixed sentence may be expanded by the parser into separate 组三/组六
    // rows. Keep those rows in one 组选 section; the number cell retains the
    // 三/六 prefix so the original meaning is still visible.
    const groupSource = `${detail.source_text || ""} ${detail.original_source_text || ""} ${detail.record_source || ""}`;
    const mixedGroup = /(?:组三|组3)[^\n]*(?:组六|组6)|(?:组六|组6)[^\n]*(?:组三|组3)/u.test(groupSource);
    if (mixedGroup && /(?:组三|组六|组3|组6)/u.test(raw)) {
      const count = groupSource.match(/(?<!\d)(\d{3,10})\s*(?:组三|组六|组3|组6)([一二两三四五六七八九]|[2-9])?码?/u);
      const words: Record<string, string> = { "2": "二", "3": "三", "4": "四", "5": "五", "6": "六", "7": "七", "8": "八", "9": "九" };
      const size = count?.[2] || (count?.[1] ? words[String(count[1].length)] : "");
      const suffix = /赖/u.test(groupSource) ? "赖" : "码";
      return size ? `组${words[size] || size}${suffix}` : "组选";
    }
    // The API may return 直/直选 (or a direct-play subtype) for the same
    // catalog play. Keep them in one section so the detail table has one
    // heading instead of splitting the same direct bets into separate blocks.
    if (raw === "直" || raw === "直选" || raw.startsWith("直")) return "直选";
    if (raw === "组" || raw === "组三" || raw === "组六" || raw === "组3" || raw === "组6" || raw === "组选") return "组选";
    return raw;
  };
  const playMark = (detail: BetDetail) => {
    const raw = String(detail.play_type || detail.play_label || "");
    if (/胆拖/u.test(`${raw} ${detail.number_text || ""} ${detail.source_text || ""}`)) return "";
    if (/^和/u.test(raw) || /^和值/u.test(raw)) return "";
    if (/豹子/u.test(`${raw} ${detail.number_text || ""} ${detail.source_text || ""}`)) return "";
    if (packagePlay(detail)) return "";
    if (/口|X/i.test(raw) && !raw.includes("直")) return "";
    // Multi-code and 沾边赖 rows already carry 三/六 in the number text;
    // appending a second red play marker makes the table look split.
    if (/\d{4,10}/u.test(String(detail.number_text || "")) && /(?:组三|组六|组3|组6)/u.test(`${raw} ${detail.source_text || ""}`)) return "";
    if (/(?:组三|组六|组3|组6).*(?:码|赖)|(?:码|赖).*(?:组三|组六|组3|组6)/u.test(raw)) return "";
    if (raw === "直" || raw === "直选" || raw.startsWith("直")) return "直";
    if (raw === "组" || raw === "组三" || raw === "组六" || raw === "组3" || raw === "组6" || raw === "组选") return "组";
    if (raw.startsWith("组三")) return "组三";
    if (raw.startsWith("组六")) return "组六";
    return raw;
  };
  const normalizeDetailRows = (input: BetDetail[]): BetDetail[] => {
    const explicitLeopards = new Set<string>();
    input.forEach((detail) => {
      const source = String(detail.source_text || "");
      if (!source.includes("豹子") || source.includes("豹子全包")) return;
      String(detail.number_text || "").split(/[\s,，、]+/u).forEach((token) => {
        const match = token.match(/^(\d{3})/u);
        if (match && new Set(match[1]).size === 1) explicitLeopards.add(match[1]);
      });
    });
    const result: BetDetail[] = [];
    input.forEach((detail) => {
      const source = String(detail.source_text || "");
      const tokens = String(detail.number_text || "").split(/[\s,，、]+/u).filter(Boolean);
      const rawPlay = String(detail.play_type || detail.play_label || "");
      const isDirect = rawPlay === "直" && tokens.every((token) => /^\d{3}直$/u.test(token));
      const isExplicitLeopard = source.includes("豹子") && !source.includes("豹子全包");
      if (isExplicitLeopard) {
        result.push({ ...detail, odds: "800" });
        return;
      }
      if (!isDirect || tokens.length < 2) { result.push(detail); return; }
      const leopard = tokens.filter((token) => {
        const number = token.slice(0, 3);
        return new Set(number).size === 1 && (explicitLeopards.size === 0 || explicitLeopards.has(number));
      });
      const normal = tokens.filter((token) => !leopard.includes(token));
      if (leopard.length === 0) { result.push(detail); return; }
      const perAmount = Number(detail.amount || 0) / tokens.length;
      if (normal.length > 0) result.push({ ...detail, number_text: normal.join(" "), amount: (perAmount * normal.length).toFixed(2) });
      if (explicitLeopards.size === 0) result.push({ ...detail, number_text: leopard.join(" "), amount: (perAmount * leopard.length).toFixed(2), odds: "800", source_text: `${leopard.map((token) => token.slice(0, 3)).join(" ")}直豹子各${perAmount}元` });
    });
    return result;
  };
  const displayDetailNumber = (detail: BetDetail) => {
    const source = detail.source_text || "";
    const play = playName(detail);
    const rawPlay = String(detail.play_type || detail.play_label || "");
    // Multi-code 组三/组六 selections are one catalogue item. Older provider
    // responses may expose the selection as `三 23456` or split it into
    // expanded combinations; restore the compact reference-site form.
    const multiCodeText = `${source} ${detail.original_source_text || ""} ${detail.record_source || ""} ${rawPlay}`;
    const multiCode = multiCodeText.match(/(?<!\d)(\d{3,10})\s*(组三|组六|组3|组6)(?:[一二两三四五六七八九]|[2-9])?(?:码|赖)?/u)
      || multiCodeText.match(/(组三|组六|组3|组6)\s*(\d{3,10})/u);
    if (multiCode) {
      const family = multiCode[1].startsWith("组") ? multiCode[1] : multiCode[2];
      const digits = multiCode[1].startsWith("组") ? multiCode[2] : multiCode[1];
      return `${family === "组六" || family === "组6" ? "六" : "三"}${digits}`;
    }
    const compactStored = String(detail.number_text || "").replace(/\s+/gu, "");
    if (/^(组三|组六|组3|组6)/u.test(rawPlay) && /^[三六]\d{4,10}$/u.test(compactStored)) return compactStored;
    // 胆拖的内部号码保存为“胆1拖2345678”，标题已经携带具体的
    // 组六/组三语义；详情号码按用户约定显示为“六拖…”或“三拖…”。
    const dragFamily = play.match(/^(组六|组三)胆拖/u)?.[1];
    if (dragFamily) {
      const stored = (detail.number_text || "").replace(/\s+/gu, "");
      const countedDrag = stored.match(/^胆(\d{1,2})拖(\d{1,9})(?:(?:组三|组六|组3|组6)胆拖)?$/u);
      if (countedDrag) return `${dragFamily === "组六" ? "六" : "三"} ${countedDrag[1]} 拖 ${countedDrag[2]}`;
      const drag = stored.match(/^[三六](\d{2,9})(?:(?:组三|组六|组3|组6)胆拖)?$/u)?.[1];
      if (drag) return `${dragFamily === "组六" ? "六" : "三"} 拖 ${drag}`;
    }
    const sticky = source.match(/(\d{4,10})\s*(组三|组六)六码/u);
    if (sticky) return `${sticky[2] === "组三" ? "三" : "六"}${sticky[1]}`;
    if (rawPlay.includes("组3") || rawPlay.includes("组6") || rawPlay === "组" || rawPlay === "组三" || rawPlay === "组六" || rawPlay === "组选") {
      return (detail.number_text || "").replace(/^[三六]/u, "").replace(/(直|组|组三|组六)$/u, "");
    }
    if (rawPlay.includes("直")) return (detail.number_text || "").replace(/(直|组|组三|组六)$/u, "");
    if (play.includes("双飞") || source.includes("对子")) {
      const number = (detail.number_text || "")
        .replace(/^0(?=\d{2}(?:飞)?$)/, "")
        .replace(/飞$/, "");
      return number ? `${number}飞` : "双飞";
    }
    if (detail.number_text === "000" && play.startsWith("跨度")) return `跨${play.slice(2)}`;
    if (detail.number_text === "000" && play.startsWith("和值")) return play;
    const value = detail.number_text || "";
    const packageType = packagePlay(detail);
    if (packageType) return packageType.startsWith("组六") ? "六包" : "三包";
    // 独胆的玩法标记单独显示为红色“独胆”，号码单元格只保留数字。
    if (/^独胆$/u.test(play) || /^独胆$/u.test(rawPlay)) return value.replace(/胆$/u, "");
    // 定位号码中的 X 表示未指定的位置，需保留在号码本身（例如 12X）。
    // 仅将内部玩法标签“口口X/X口口”规范化为“二码定位”，不改写号码。
    if (play.endsWith("码定位")) return value;
    // Preserve the three-character lottery expression, including a leading
    // zero, for direct and multi-position displays.
    return value || "-";
  };
  const orderedDetails = [...details].sort((left, right) => {
    const playRank = (detail: BetDetail) => {
      const play = playName(detail);
      if (play === "直选") return 0;
      if (play === "组选") return 1;
      return 2;
    };
    const leftRank = playRank(left);
    const rightRank = playRank(right);
    if (leftRank !== rightRank) return leftRank - rightRank;
    const leftNumber = Number(displayDetailNumber(left).replace(/\D/g, ""));
    const rightNumber = Number(displayDetailNumber(right).replace(/\D/g, ""));
    if (Number.isFinite(leftNumber) && Number.isFinite(rightNumber) && leftNumber !== rightNumber) return leftNumber - rightNumber;
    return displayDetailNumber(left).localeCompare(displayDetailNumber(right), "zh-CN");
  });
  const groupedDetails = Array.from(orderedDetails.reduce((lotteryMap, detail) => {
    const lottery = lotteryName(detail.lottery);
    if (!lotteryMap.has(lottery)) lotteryMap.set(lottery, new Map<string, BetDetail[]>());
    const play = playName(detail);
    const playMap = lotteryMap.get(lottery)!;
    if (!playMap.has(play)) playMap.set(play, []);
    playMap.get(play)!.push(detail);
    return lotteryMap;
  }, new Map<string, Map<string, BetDetail[]>>()));
  const numberGroups = Array.from(orderedDetails.reduce((map, detail) => {
    const key = `${lotteryName(detail.lottery)}|${detail.issue_no}`;
    if (!map.has(key)) map.set(key, []);
    map.get(key)!.push(detail);
    return map;
  }, new Map<string, BetDetail[]>()));
  const copyText = async (text: string) => {
    const textarea = document.createElement("textarea");
    textarea.value = text;
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
    if (copied) return;

    // 旧方案不可用时，再尝试现代剪贴板 API（部分浏览器要求安全上下文）。
    if (navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(text);
      return;
    }
    throw new Error("copy failed");
  };
  const copyRecordSource = async (record: BetRecord) => {
    const source = String(record.source_text || "");
    if (!source) return;
    try {
      await copyText(source);
      message.success("原始文本已复制");
    } catch {
      message.error("复制原始文本失败，请长按文本手动复制");
    }
  };
  const copyNumbers = async () => {
    try {
      await copyText(orderedDetails.map((detail) => displayDetailNumber(detail)).join("\n"));
      message.success("号码已复制");
    } catch {
      message.error("复制号码失败，请长按号码手动复制");
    }
  };
  const escapeHtml = (value: unknown) => String(value ?? "")
    .replace(/&/g, "&amp;")
    .replace(/</g, "&lt;")
    .replace(/>/g, "&gt;")
    .replace(/"/g, "&quot;")
    .replace(/'/g, "&#039;");
  const printNumbers = () => {
    const rows = numberGroups.map(([key, group]) => `<tr><th colspan="2">${escapeHtml(key.replace("|", " 第 "))} 期，共 ${group.reduce((sum, item) => sum + Number(item.amount || 0), 0).toFixed(2)}</th></tr>${group.map((item) => `<tr><td>${escapeHtml(displayDetailNumber(item))}</td><td>${escapeHtml(item.amount)}</td></tr>`).join("")}`).join("");
    const paper = `<div class="bet-number-print-paper"><p>时间：${escapeHtml(detailRecord?.placed_at || "")}</p><p>会员：-</p><table>${rows}</table><p>请核对一切以小票为准<br>总笔数：${details.length} 总金额：${details.reduce((sum, item) => sum + Number(item.amount || 0), 0).toFixed(2)}</p></div>`;
    const printCss = "body{font:14px Arial,sans-serif;padding:20px;color:#111}.bet-number-print-paper{max-width:580px;margin:0 auto}table{border-collapse:collapse;width:100%}th,td{border:1px solid #777;padding:7px;text-align:center}th{background:#666!important;color:#fff!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}";

    // 必须在点击事件内同步打开窗口，否则容易被浏览器当作广告弹窗拦截。
    const printWindow = window.open("", "_blank", "width=620,height=760");
    if (printWindow) {
      printWindow.document.open();
      printWindow.document.write(`<html><head><meta charset="utf-8"><title>号码小票</title><style>${printCss}</style></head><body>${paper}</body></html>`);
      printWindow.document.close();
      printWindow.focus();
      printWindow.print();
      return;
    }

    // 新窗口被拦截时，使用当前页面的隐藏打印区域，不再直接报错。
    const printRoot = document.createElement("div");
    const printStyle = document.createElement("style");
    printRoot.id = "bet-number-print-root";
    printRoot.innerHTML = paper;
    printStyle.textContent = `@media print{body>*:not(#bet-number-print-root){display:none!important}#bet-number-print-root{display:block!important;position:static!important}${printCss}}`;
    document.body.append(printStyle, printRoot);
    let cleaned = false;
    const cleanup = () => {
      if (cleaned) return;
      cleaned = true;
      printRoot.remove();
      printStyle.remove();
      window.removeEventListener("afterprint", cleanup);
    };
    window.addEventListener("afterprint", cleanup);
    window.print();
    window.setTimeout(cleanup, 1500);
  };
  return (
    <>
      <div className="side-total">
        <span>
          总金额: <b>{amountTotal}</b>
        </span>
        <div className="side-actions">
          <button type="button" disabled={disabled} onClick={onMore}>
            更多
          </button>
          <button className="side-right" type="button" disabled={disabled} onClick={onToggleSide}>
            <img
              className={panelRight ? "is-left" : ""}
              src={arrowRightIcon}
              alt=""
              aria-hidden="true"
            />
            {panelRight ? "居左" : "居右"}
          </button>
        </div>
      </div>
      <div className="side-record-list">
        {records.map((record) => (
          <article
            className={`side-record-item${record.status === "refunded" ? " refunded" : ""}`}
            key={record.id}
            role="button"
            tabIndex={0}
            title="点击复制完整原始文本"
            onClick={(event) => {
              if ((event.target as HTMLElement).closest("button")) return;
              void copyRecordSource(record);
            }}
            onKeyDown={(event) => {
              if ((event.key === "Enter" || event.key === " ") && event.target === event.currentTarget) {
                event.preventDefault();
                void copyRecordSource(record);
              }
            }}
          >
            <time>
              <span className="side-record-board">
                {record.board_name || `${record.board_code || "A"}盘`}
              </span>
              时间：{record.placed_at}
            </time>
            <div className="side-record-text">
              <b className="side-record-issue">
                {record.lottery ? `${record.lottery} ` : ""}第 {record.issue_no || "--"} 期
              </b>
              <p title={record.source_text || "-"}>{record.source_text || "-"}</p>
            </div>
            <footer>
              <strong>
                {record.status === "refunded" ? "0.00" : record.amount}
              </strong>
              {record.status !== "refunded" ? (
                <div>
                  <button
                    type="button"
                    className="side-record-action detail"
                    style={{ backgroundColor: "#087e0b", color: "#fff" }}
                    disabled={disabled}
                    onClick={() => showRecord(record, "detail")}
                  >
                    详
                  </button>
                  <button
                    type="button"
                    className="side-record-action numbers"
                    style={{ backgroundColor: "#ffe5cf", color: "#80502d" }}
                    disabled={disabled}
                    onClick={() => showRecord(record, "numbers")}
                  >
                    号
                  </button>
                  {record.can_refund ? (
                    <button
                      type="button"
                      className="side-record-action refund"
                      style={{ backgroundColor: "#c2212b", color: "#fff" }}
                      disabled={disabled || loading}
                      title={`开奖时间 ${record.open_time || "待定"}`}
                      onClick={() => refund(record)}
                    >
                      退
                    </button>
                  ) : null}
                </div>
              ) : null}
            </footer>
          </article>
        ))}
      </div>
      <Modal
        className={detailMode === "numbers" ? "record-number-modal" : "record-detail-modal"}
        open={Boolean(detailRecord)}
        title={detailMode === "detail" ? "下注详情" : null}
        footer={detailMode === "detail" ? <Button onClick={() => setDetailRecord(undefined)}>关 闭</Button> : null}
        onCancel={() => setDetailRecord(undefined)}
        width={detailMode === "numbers" ? 520 : 900}
      >
        {detailLoading ? <div className="record-detail-loading">加载中...</div> : details.length && detailMode === "detail" ? (
          <div className="record-detail-content">
            <div className="record-detail-tabs">
              {groupedDetails.map(([lottery]) => <span key={lottery}>{lottery}</span>)}
            </div>
            {groupedDetails.map(([lottery, plays]) => (
              <section className="record-detail-lottery" key={lottery}>
                <h2>{lottery}</h2>
                {Array.from(plays).map(([play, playDetails]) => (
                  <div className="record-detail-play" key={`${lottery}-${play}`}>
                    <h3>{play}</h3>
                    <div className="record-detail-grid-list">
                      {(() => {
                        const chunks: BetDetail[][] = [playDetails.slice(0, 5)];
                        for (let offset = 5; offset < playDetails.length; offset += 6) chunks.push(playDetails.slice(offset, offset + 6));
                        return chunks.map((chunk, chunkIndex) => {
                        const showLabels = chunkIndex === 0;
                        const cells = Array.from({ length: showLabels ? 5 : 6 }, (_, index) => chunk[index] || null);
                        const labelCell = (label: string) => showLabels ? <div className="record-detail-grid-label">{label}</div> : null;
                        return <div className={`record-detail-grid${showLabels ? "" : " continuation"}`} key={`${lottery}-${play}-${chunkIndex}`} style={{ gridTemplateColumns: "repeat(6, minmax(112px, 1fr))" }}>
                          {labelCell("号码")}{cells.map((detail, index) => <div className={`record-detail-grid-value number${detail ? "" : " placeholder"}`} key={`number-${detail?.id || "empty"}-${index}`}>{detail ? <><span>{displayDetailNumber(detail)}</span><em>{playMark(detail)}</em></> : null}</div>)}
                          {labelCell("金额")}{cells.map((detail, index) => <div className={`record-detail-grid-value amount${detail ? "" : " placeholder"}`} key={`amount-${detail?.id || "empty"}-${index}`}>{detail?.amount || null}</div>)}
                          {labelCell("赔率")}{cells.map((detail, index) => <div className={`record-detail-grid-value odds${detail ? "" : " placeholder"}`} key={`odds-${detail?.id || "empty"}-${index}`}>{detail?.odds || null}</div>)}
                          {labelCell("中奖")}{cells.map((detail, index) => <div className={`record-detail-grid-value win${detail ? "" : " placeholder"}`} key={`win-${detail?.id || "empty"}-${index}`}>{detail && Number(detail.win_amount || 0) > 0 ? detail.win_amount : null}</div>)}
                        </div>;
                        });
                      })()}
                    </div>
                  </div>
                ))}
              </section>
            ))}
          </div>
        ) : details.length ? (
          <div className="record-number-content">
            <div className="record-number-toolbar"><button type="button" onClick={() => setDetailRecord(undefined)}>←</button><span>第1/1页</span><button type="button" disabled>上页</button><button type="button" disabled>下页</button><button type="button" className="download" onClick={printNumbers}>下载PDF</button><button type="button" className="copy" onClick={() => void copyNumbers()}>复制号码</button></div>
            <div className="record-number-paper"><p>时间:{detailRecord?.placed_at || ""}</p><p>会员:-</p><table><thead><tr><th>号码</th><th>全额</th></tr></thead><tbody>{numberGroups.map(([key, group]) => <Fragment key={key}><tr className="group"><th colSpan={2}>{key.replace("|", " 第 ")} 期，共 {group.reduce((sum, item) => sum + Number(item.amount || 0), 0).toFixed(2)}</th></tr>{group.map((detail, index) => <tr key={`${detail.id}-${index}`}><td>{displayDetailNumber(detail)}</td><td>{detail.amount}</td></tr>)}</Fragment>)}</tbody></table><p>请核对一切以小票为准<br />总笔数:{details.length} 总金额:{details.reduce((sum, item) => sum + Number(item.amount || 0), 0).toFixed(2)}</p></div>
          </div>
        ) : <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="暂无数据" />}
      </Modal>
    </>
  );
}
