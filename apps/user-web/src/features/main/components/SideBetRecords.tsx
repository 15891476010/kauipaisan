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
  const playName = (detail: BetDetail) => detail.play_label || detail.play_type || detail.category || "投注";
  const displayDetailNumber = (detail: BetDetail) => {
    const source = detail.source_text || "";
    const play = playName(detail);
    const sticky = source.match(/(\d{4,10})\s*(组三|组六)六码/u);
    if (sticky) return `${sticky[2] === "组三" ? "三" : "六"}${sticky[1]}`;
    if (play.includes("双飞") || source.includes("对子")) {
      const number = (detail.number_text || "").replace(/^0(?=\d{2}$)/, "");
      return number ? `${number}飞` : "双飞";
    }
    if (detail.number_text === "000" && play.startsWith("跨度")) return `跨${play.slice(2)}`;
    if (detail.number_text === "000" && play.startsWith("和值")) return play;
    return detail.number_text || "-";
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
  const copyNumbers = async () => {
    try {
      await navigator.clipboard.writeText(details.map((detail) => displayDetailNumber(detail)).join("\n"));
      message.success("号码已复制");
    } catch {
      message.error("复制号码失败，请检查浏览器权限");
    }
  };
  const printNumbers = () => {
    const printWindow = window.open("", "_blank", "noopener,noreferrer,width=620,height=760");
    if (!printWindow) return message.error("无法打开打印窗口");
    const rows = numberGroups.map(([key, group]) => `<tr><th colspan="2">${key.replace("|", " 第 ")} 期，共 ${group.reduce((sum, item) => sum + Number(item.amount || 0), 0).toFixed(2)}</th></tr>${group.map((item) => `<tr><td>${displayDetailNumber(item)}</td><td>${item.amount}</td></tr>`).join("")}`).join("");
    printWindow.document.write(`<html><head><title>号码小票</title><style>body{font:14px Arial;padding:20px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #777;padding:7px;text-align:center}th{background:#666;color:#fff}</style></head><body><p>时间：${detailRecord?.placed_at || ""}</p><p>会员：-</p><table>${rows}</table><p>总笔数：${details.length} 总金额：${details.reduce((sum, item) => sum + Number(item.amount || 0), 0).toFixed(2)}</p></body></html>`);
    printWindow.document.close();
    printWindow.focus();
    printWindow.print();
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
                        <div className="record-detail-card-label">号码</div><div>{displayDetailNumber(detail)}</div>
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
