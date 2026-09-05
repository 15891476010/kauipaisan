import { useEffect, useState } from "react";
import { App as AntdApp, Empty, Modal } from "antd";
import { QuestionCircleOutlined } from "@ant-design/icons";
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
import { useReferenceSuccessMessage } from "../../../components/ReferenceSuccessMessage";

export function SideBetRecords({
  onMore,
  onNumbers,
  panelRight,
  onToggleSide,
  disabled = false,
}: {
  onMore: () => void;
  onNumbers: (record: BetRecord) => void;
  panelRight: boolean;
  onToggleSide: () => void;
  disabled?: boolean;
}) {
  const { message, modal } = AntdApp.useApp();
  const { holder: refundSuccessHolder, show: showRefundSuccess } = useReferenceSuccessMessage();
  const [records, setRecords] = useState<BetRecord[]>([]);
  const [amountTotal, setAmountTotal] = useState("0.00");
  const [loading, setLoading] = useState(false);
  const [details, setDetails] = useState<BetDetail[]>([]);
  const [detailRecord, setDetailRecord] = useState<BetRecord>();
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
  const showRecord = async (record: BetRecord) => {
    setDetailRecord(record);
    setDetailLoading(true);
    try {
      const detailQuery = {
        submission_id: record.id,
        page_size: 100,
      };
      const firstResult = await getBetDetails({ ...detailQuery, page: 1 });
      const firstPage = firstResult.data?.data?.list || [];
      const total = Number(firstResult.data?.data?.total || firstPage.length);
      const pageSize = Math.max(1, Number(firstResult.data?.data?.page_size || detailQuery.page_size));
      const pageCount = Math.ceil(total / pageSize);
      if (pageCount <= 1 || firstPage.length >= total) {
        setDetails(normalizeDetailRows(firstPage.slice(0, total)));
        return;
      }
      const remainingPages = await Promise.all(
        Array.from({ length: pageCount - 1 }, (_, index) =>
          getBetDetails({ ...detailQuery, page: index + 2 }),
        ),
      );
      const allDetails = [
        ...firstPage,
        ...remainingPages.flatMap((pageResponse) => pageResponse.data?.data?.list || []),
      ];
      setDetails(normalizeDetailRows(allDetails.slice(0, total)));
    } catch (error) {
      setDetails([]);
      message.error(apiErrorMessage(error, "注单详情加载失败"));
    } finally {
      setDetailLoading(false);
    }
  };
  const refund = (record: BetRecord) => {
    modal.confirm({
      className: "refund-confirm-modal",
      rootClassName: "refund-confirm-root",
      centered: true,
      width: "92vw",
      icon: <QuestionCircleOutlined />,
      title: "确认退码吗？",
      okText: "我确定",
      cancelText: "取消",
      onOk: async () => {
        try {
          await refundBetRecord(record.id);
          showRefundSuccess(`${lotteryName(record.lottery)}成功退单`);
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
    const text = `${detail.play_label || ""} ${detail.play_type || ""} ${detail.source_text || ""} ${detail.original_source_text || ""} ${detail.record_source || ""}`;
    if (/(组六\s*(?:全包|包)|组6\s*(?:全包|包))/u.test(text)) return "组六全包";
    if (/(组三\s*(?:全包|包)|组3\s*(?:全包|包))/u.test(text)) return "组三全包";
    return "";
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
  const playName = (detail: BetDetail) => {
    const raw = String(detail.play_label || detail.play_type || detail.category || "投注");
    const packageType = packagePlay(detail);
    if (packageType) return packageType;
    const multiSource = `${raw} ${detail.number_text || ""} ${detail.source_text || ""} ${detail.original_source_text || ""} ${detail.record_source || ""}`;
    if (/胆拖/u.test(multiSource)) {
      if (/(组六|组6|六组)/u.test(multiSource)) return "组六胆拖";
      if (/(组三|组3|三组)/u.test(multiSource)) return "组三胆拖";
    }
    const multiFamily = /组六|组6/u.test(multiSource) ? "组六" : /组三|组3/u.test(multiSource) ? "组三" : "";
    const multiDigits = multiSource.match(/(?<!\d)\d{4,10}(?!\d)/u)?.[0];
    if (multiFamily && multiDigits && !/全包|胆拖/u.test(multiSource)) return `${multiFamily}多码`;
    const genericGroupSource = /(?:^|\s)组(?:各|每|共|合计|计|$)/u.test(multiSource)
      && !/(组三|组六|组3|组6)/u.test(multiSource);
    const genericLeopard = genericGroupSource && String(detail.number_text || "").split(/[\s,，、]+/u).some((token) => {
      const number = token.match(/^(\d{3})/)?.[1];
      return Boolean(number && new Set(number).size === 1);
    });
    if (genericLeopard) return "直选";
    if (/^[口Xx]{2}[Xx]$/u.test(raw) || raw === "口口X") return "二码定位";
    if (/^[口Xx][Xx]{2}$/u.test(raw)) return "一码定位";
    if (raw === "直" || raw === "直选" || raw.startsWith("直")) return "直选";
    if (["组", "组三", "组六", "组3", "组6", "组选"].includes(raw)) return "组选";
    return raw;
  };
  const compactDetailNumber = (detail: BetDetail, play: string, source: string) => {
    const combined = `${detail.number_text || ""} ${source}`.replace(/\s+/gu, "");
    const context = `${play}${combined}`;
    const isDantuo = /胆拖/u.test(context);
    const isPack = /(组三|组六|组3|组6)包/u.test(context);
    if (!isDantuo && !isPack) return "";
    const kind = /(组六|组6)/u.test(context) ? "六" : "三";
    if (isDantuo) {
      const match = combined.match(/胆?([0-9]+)拖([0-9]+)/u);
      if (match) return `${kind}${match[1]}拖${match[2]}`;
      const fallback = combined.replace(/(?:组三|组六|组3|组6)胆拖/gu, "").replace(/^胆/u, "");
      return `${kind}${fallback}`;
    }
    return `${kind}包`;
  };
  const displayDetailNumber = (detail: BetDetail) => {
    const source = detail.source_text || "";
    const play = playName(detail);
    const rawPlay = String(detail.play_type || detail.play_label || detail.category || "");
    const compact = compactDetailNumber(detail, play, source);
    if (compact) return compact;
    const sticky = source.match(/(\d{4,10})\s*(组三|组六)六码/u);
    if (sticky) return `${sticky[2] === "组三" ? "三" : "六"}${sticky[1]}`;
    const sourceContext = `${source} ${detail.original_source_text || ""} ${detail.record_source || ""}`;
    const genericGroup = /(?:^|\s)组(?:各|每|共|合计|计|$)/u.test(sourceContext)
      && !/(组三|组六|组3|组6)/u.test(sourceContext);
    if (genericGroup) {
      return (detail.number_text || "")
        .replace(/^[三六]/u, "")
        .replace(/(?:组三|组六|组3|组6)组?$|组$/u, "");
    }
    if (/(组3|组6|组三|组六|组选)/u.test(rawPlay) || /^(组|组三|组六|组选)$/u.test(play)) {
      // Provider detail rows can repeat the semantic marker in the number
      // field (for example `123组六组`). Keep the meaningful family suffix
      // (`组六`/`组三`) and remove only the duplicated trailing `组`; the
      // separate red marker column renders the final `组`.
      return (detail.number_text || "")
        .replace(/^[三六]/u, "")
        .replace(/组$/u, "");
    }
    if (play.includes("双飞") || source.includes("对子")) {
      const number = (detail.number_text || "")
        .replace(/^0(?=\d{2}(?:飞)?$)/, "")
        .replace(/飞$/, "");
      return number ? `${number}飞` : "双飞";
    }
    if (detail.number_text === "000" && play.startsWith("跨度")) return `跨${play.slice(2)}`;
    if (detail.number_text === "000" && play.startsWith("和值")) return play;
    let value = detail.number_text || "";
    if (/复式/u.test(`${play} ${rawPlay}`)) {
      value = value.replace(/复式(?:[一二三四五六七八九\d]+码|多码)?$/u, "");
      return value || "-";
    }
    // 独胆的“胆”是玩法语义，号码列只显示数字；红色标记单独显示“独胆”。
    if (play === "独胆" || /^独胆$/u.test(String(detail.play_type || detail.play_label || ""))) {
      value = value.replace(/胆$/u, "");
    }
    if (play.endsWith("码定位")) value = value.replace(/(?:口口X|口X口|X口口)$/iu, "");
    if (play === "直" || play.startsWith("直") || play.endsWith("码定位")) value = value.replace(/直+$/u, "");
    // Preserve the three-character lottery expression, including a leading
    // zero, for direct and multi-position displays.
    return value || "-";
  };
  const displayDetailOdds = (detail: BetDetail) => {
    const context = `${detail.play_type || ""} ${detail.play_label || ""} ${detail.source_text || ""} ${detail.original_source_text || ""}`;
    if (/豹子全包/u.test(context)) return detail.odds || "---";
    const leopard = String(detail.number_text || "").split(/[\s,，、]+/u).some((token) => {
      const number = token.match(/^(\d{3})/)?.[1];
      return Boolean(number && new Set(number).size === 1);
    });
    if (leopard || /豹子/u.test(context)) return "800";
    return detail.odds || "---";
  };
  const playMark = (detail: BetDetail) => {
    const raw = String(detail.play_type || detail.play_label || "");
    if (/码定位/u.test(`${raw} ${detail.play_label || ""} ${detail.category || ""}`)) return "";
    const context = `${raw} ${detail.number_text || ""} ${detail.source_text || ""} ${detail.original_source_text || ""}`;
    if (playName(detail) === "直选" && String(detail.number_text || "").match(/\d{3}/u) && new Set(String(detail.number_text || "").match(/\d{3}/u)![0]).size === 1) return "直";
    if (/复式/u.test(`${playName(detail)} ${context}`)) return "";
    if (/胆拖|和值|豹子|包/u.test(context) || /\d{4,10}/u.test(context) && /(?:组三|组六|组3|组6)/u.test(context)) return "";
    if (/口|X/i.test(raw) && !raw.includes("直")) return "";
    if (/直/u.test(raw)) return "直";
    if (/组三|组六|组3|组6|组选|组/u.test(raw)) return "组";
    if (/^独胆$/u.test(raw)) return "独胆";
    return raw;
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

  const copyRecordText = (record: BetRecord) => {
    if (record.status === "refunded" || !record.source_text) return;
    void copyText(record.source_text)
      .then(() => message.success("原始文本已复制"))
      .catch(() => message.error("复制原始文本失败"));
  };
  return (
    <>
      {refundSuccessHolder}
      <div className="side-total">
        <span>
          总金额: <b>{amountTotal}</b>
        </span>
        <div className="side-actions">
          <button type="button" disabled={disabled} onClick={onMore}>
            <span className="side-action-label">更多</span>
          </button>
          <button className="side-right" type="button" disabled={disabled} onClick={onToggleSide}>
            <img
              className={panelRight ? "is-left" : ""}
              src={arrowRightIcon}
              alt=""
              aria-hidden="true"
            />
            <span className="side-action-label">{panelRight ? "居左" : "居右"}</span>
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
              <span className={"side-record-board pk-" + (record.board_code || "1")}>
                {record.board_name || (record.board_code || "A") + "盘"}
              </span>
              时间：{record.placed_at}
            </time>
            <div className="side-record-text">
              <b className="side-record-issue">
                {record.lottery ? record.lottery + " " : ""}第 {record.issue_no || "--"} 期
              </b>
              <p
                className={record.lottery === "排列三" || record.lottery === "体" ? "pl3" : record.lottery && record.lottery !== "福彩3D" && record.lottery !== "福" ? "all" : "fc3"}
                title={record.source_text || "-"}
                onClick={record.status === "refunded" ? undefined : () => copyRecordText(record)}
              >
                {record.source_text || "-"}
              </p>            </div>
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
                    aria-label="详情"
                    disabled={disabled}
                    onClick={() => showRecord(record)}
                  >
                    <svg
                      className="side-record-detail-icon"
                      viewBox="0 0 16 16"
                      aria-hidden="true"
                      focusable="false"
                    >
                      <path d="M3.25 1.75h6l3.5 3.5v9H3.25z" />
                      <path d="M9.25 1.75v3.5h3.5M5.5 8h5M5.5 10.5h5" />
                    </svg>
                  </button>
                  <button
                    type="button"
                    className="side-record-action numbers"
                    style={{ backgroundColor: "#ffe5cf", color: "#80502d" }}
                    disabled={disabled}
                    onClick={() => onNumbers(record)}
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
        className="records-detail-modal"
        wrapClassName="records-detail-wrap"
        open={Boolean(detailRecord)}
        title="下注详情"
        footer={(
          <button type="button" className="records-modal-close" onClick={() => setDetailRecord(undefined)}>
            关 闭
          </button>
        )}
        onCancel={() => setDetailRecord(undefined)}
        width={760}
      >
        {detailLoading ? <div className="record-detail-loading">加载中...</div> : details.length ? (
          <div className="record-detail-content">
            <div className="record-detail-tabs">
              {groupedDetails.map(([lottery]) => <span className="selected" key={lottery}>{lottery}</span>)}
            </div>
            <div className="record-detail-code-body">
              {groupedDetails.map(([lottery, plays]) => (
                <section className="record-detail-lottery" key={lottery}>
                  <h5 className={lottery === "福彩3D" ? "lt-4" : "lt-3"}>{lottery}</h5>
                  {Array.from(plays).map(([play, playDetails]) => (
                    <div className="record-detail-play-group" key={lottery + "-" + play}>
                      <label>{play}</label>
                      <div className="record-detail-data">
                        <div className="record-detail-sub-header">
                          <span>号码</span>
                          <span>金额</span>
                          <span>赔率</span>
                          <span>中奖</span>
                        </div>
                        {playDetails.map((detail, index) => (
                          <div className="record-detail-data-row" key={detail.id + "-" + index}>
                            <span>
                              <span className="record-detail-number">
                                <label>{displayDetailNumber(detail)}</label>
                                {playMark(detail) ? <em>{playMark(detail)}</em> : null}
                              </span>
                            </span>
                            <span>{detail.amount || "0"}</span>
                            <span>{displayDetailOdds(detail)}</span>
                            <span>{Number(detail.win_amount || 0) > 0 ? detail.win_amount : "---"}</span>
                          </div>
                        ))}
                      </div>
                    </div>
                  ))}
                </section>
              ))}
            </div>
          </div>
        ) : <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="暂无数据" />}
      </Modal>
    </>
  );
}
