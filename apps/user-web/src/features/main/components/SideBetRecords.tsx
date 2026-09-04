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
    const raw = String(detail.play_type || detail.play_label || detail.category || "投注");
    if (raw === "直" || raw === "直选") return "直选";
    if (raw === "组" || raw === "组三" || raw === "组六" || raw === "组3" || raw === "组6") return "组选";
    return raw;
  };
  const playMark = (detail: BetDetail) => {
    const raw = String(detail.play_type || detail.play_label || "");
    if (raw === "直" || raw === "直选") return "直";
    if (raw === "组" || raw === "组三" || raw === "组六" || raw === "组3" || raw === "组6") return "组";
    if (raw.startsWith("组三")) return "组三";
    if (raw.startsWith("组六")) return "组六";
    return raw;
  };
  const displayDetailNumber = (detail: BetDetail) => {
    const source = detail.source_text || "";
    const play = playName(detail);
    const rawPlay = String(detail.play_type || detail.play_label || "");
    // 胆拖的内部号码保存为“胆1拖2345678”，标题已经携带具体的
    // 组六/组三语义；详情号码按用户约定显示为“六拖…”或“三拖…”。
    const dragFamily = play.match(/^(组六|组三)胆拖/u)?.[1];
    if (dragFamily) {
      const stored = detail.number_text || "";
      const drag = stored.match(/^胆\d{1,2}拖(\d{1,9})$/u)?.[1]
        || stored.match(/^[三六](\d{2,9})$/u)?.[1];
      if (drag) return `${dragFamily === "组六" ? "六" : "三"}拖${drag}`;
    }
    const sticky = source.match(/(\d{4,10})\s*(组三|组六)六码/u);
    if (sticky) return `${sticky[2] === "组三" ? "三" : "六"}${sticky[1]}`;
    if (rawPlay.includes("组3") || rawPlay.includes("组6") || rawPlay === "组" || rawPlay === "组三" || rawPlay === "组六") {
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
    // Preserve the three-character lottery expression, including a leading
    // zero, for direct and multi-position displays.
    return value || "-";
  };
  const groupedDetails = Array.from(details.reduce((lotteryMap, detail) => {
    const lottery = lotteryName(detail.lottery);
    if (!lotteryMap.has(lottery)) lotteryMap.set(lottery, new Map<string, BetDetail[]>());
    const play = playName(detail);
    const playMap = lotteryMap.get(lottery)!;
    if (!playMap.has(play)) playMap.set(play, []);
    playMap.get(play)!.push(detail);
    return lotteryMap;
  }, new Map<string, Map<string, BetDetail[]>>()));
  const numberGroups = Array.from(details.reduce((map, detail) => {
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
      await copyText(details.map((detail) => displayDetailNumber(detail)).join("\n"));
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
            <time>{record.placed_at}</time>
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
        width={detailMode === "numbers" ? 520 : 760}
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
                    {playDetails.map((detail, index) => (
                      <div className="record-detail-card" key={`${detail.id}-${index}`}>
                        <div className="record-detail-card-label">号码</div><div><span>{displayDetailNumber(detail)}</span><em className="record-detail-play-mark">{playMark(detail)}</em></div>
                        <div className="record-detail-card-label">金额</div><div className="amount">{detail.amount}</div>
                        <div className="record-detail-card-label">赔率</div><div className="odds">{detail.odds || "---"}</div>
                        <div className="record-detail-card-label">中奖</div><div>{Number(detail.win_amount || 0) > 0 ? detail.win_amount : "---"}</div>
                      </div>
                    ))}
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
