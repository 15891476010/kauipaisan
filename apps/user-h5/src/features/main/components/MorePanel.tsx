import { useEffect, useState } from "react";
import { App as AntdApp, Modal, Switch } from "antd";
import { FileTextOutlined, LeftOutlined, SearchOutlined } from "@ant-design/icons";
import dayjs from "dayjs";
import { getBetDetails, getBetRecords, type BetDetail, type BetRecord, type Lottery } from "../../../api/user";
import { apiErrorMessage } from "../../../utils/request";
import { displayIssueCode } from "../shared";

function displayTotalAmount(value: unknown): string {
  const numeric = Number(value);
  if (!Number.isFinite(numeric)) return "0";
  return Number.isInteger(numeric) ? String(numeric) : String(Number(numeric.toFixed(2)));
}

export function MorePanel({
  onBack,
  lotteries,
}: {
  onBack: () => void;
  lotteries: Lottery[];
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
                    <button type="button" className="more-card-action numbers" aria-label="查看号码" onClick={() => void openDetails(record)}>号</button>
                  </div>
                )}
              </div>
            </article>
          );
        })}</div>}
        {!loading && records.length === 0 && <div className="more-empty">暂无数据</div>}
        {total > pageSize && <div className="more-pagination"><span>{page} / {Math.max(1, Math.ceil(total / pageSize))}</span><button type="button" disabled={page <= 1 || loading} onClick={() => setPage((value) => value - 1)}>上页</button><button type="button" disabled={page >= Math.ceil(total / pageSize) || loading} onClick={() => setPage((value) => value + 1)}>下页</button></div>}
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
            ) : (
              <div className="more-detail-empty">暂无详情</div>
            )}
          </div>
        )}
      </Modal>
    </section>
  );
}
