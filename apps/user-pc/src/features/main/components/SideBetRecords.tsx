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
        page_size: 100,
      });
      setDetails(result.data?.data?.list || []);
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
  const playName = (detail: BetDetail) => {
    const raw = String(detail.play_label || detail.play_type || detail.category || "投注");
    // The API may return 直/直选 (or a direct-play subtype) for the same
    // catalog play. Keep them in one section so the detail table has one
    // heading instead of splitting the same direct bets into separate blocks.
    if (raw === "直" || raw.startsWith("直")) return "直";
    if (raw === "组" || raw === "组选") return "组选";
    return raw;
  };
  const displayDetailNumber = (detail: BetDetail) => {
    const source = detail.source_text || "";
    const play = playName(detail);
    const sticky = source.match(/(\d{4,10})\s*(组三|组六)六码/u);
    if (sticky) return `${sticky[2] === "组三" ? "三" : "六"}${sticky[1]}`;
    if (play.includes("组3") || play.includes("组6") || play.includes("组选")) {
      return (detail.number_text || "").replace(/^[三六]/u, "");
    }
    if (play.includes("双飞") || source.includes("对子")) {
      const number = (detail.number_text || "")
        .replace(/^0(?=\d{2}(?:飞)?$)/, "")
        .replace(/飞$/, "");
      return number ? `${number}飞` : "双飞";
    }
    if (detail.number_text === "000" && play.startsWith("跨度")) return `跨${play.slice(2)}`;
    if (detail.number_text === "000" && play.startsWith("和值")) return play;
    const value = detail.number_text || "";
    return /^\d{3}$/.test(value) ? String(Number(value)) : value || "-";
  };
  const orderedDetails = [...details].sort((left, right) => {
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
                          {labelCell("号码")}{cells.map((detail, index) => <div className={`record-detail-grid-value number${detail ? "" : " placeholder"}`} key={`number-${detail?.id || "empty"}-${index}`}>{detail ? <><span>{displayDetailNumber(detail)}</span><em>{play}</em></> : null}</div>)}
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
