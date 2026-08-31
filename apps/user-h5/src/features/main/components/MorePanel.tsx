import { useEffect, useState } from "react";
import { App as AntdApp } from "antd";
import dayjs from "dayjs";
import { getBetRecords, type BetRecord, type Lottery } from "../../../api/user";
import { apiErrorMessage } from "../../../utils/request";
import { lotteryTiming } from "../shared";

export function MorePanel({
  onBack,
  lotteries,
}: {
  onBack: () => void;
  lotteries: Lottery[];
}) {
  const { message } = AntdApp.useApp();
  const today = dayjs().format("YYYY-MM-DD");
  const [now, setNow] = useState(Date.now());
  useEffect(() => {
    const timer = window.setInterval(() => setNow(Date.now()), 1_000);
    return () => window.clearInterval(timer);
  }, []);
  const dateOptions = Array.from(
    new Map(
      lotteries.flatMap((lottery) =>
        (lottery.recent_issues || [])
          .filter((issue) => lotteryTiming(lottery, now).showNextIssue || lottery.next_code === lottery.latest_code || issue.code !== lottery.next_code)
          .map((issue) => [
          issue.draw_day || issue.code,
          { day: issue.draw_day || today, code: issue.code },
        ]),
      ),
    ).values(),
  ).slice(0, 30);
  const [selectedDay, setSelectedDay] = useState(dateOptions[0]?.day || today);
  const [source, setSource] = useState("");
  const [board, setBoard] = useState("all");
  const [onlyRefunded, setOnlyRefunded] = useState(false);
  const [records, setRecords] = useState<BetRecord[]>([]);
  const [amountTotal, setAmountTotal] = useState("0.00");
  const [total, setTotal] = useState(0);
  const [page, setPage] = useState(1);
  const pageSize = 20;
  const [loading, setLoading] = useState(false);
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
        setAmountTotal(data?.amount_total || "0.00");
        setTotal(Number(data?.total || list.length));
      })
      .catch((error) => {
        setRecords([]);
        setAmountTotal("0.00");
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
  return (
    <section className="more-panel">
      <div className="more-search">
        <label className="more-field more-date-field">
          <span>日期</span>
          <select
            value={selectedDay}
            onChange={(event) => setSelectedDay(event.target.value)}
          >
            {dateOptions.length ? (
              dateOptions.map((item) => (
                <option key={`${item.day}-${item.code}`} value={item.day}>
                  {dayjs(item.day).format("M-D")} (
                  {lotteries
                    .map(
                      (lottery) =>
                        `${lottery.name === "排列三" ? "体" : "福"}-${item.code}`,
                    )
                    .join(" ")}
                  )
                </option>
              ))
            ) : (
              <option value={today}>{dayjs(today).format("M-D")}</option>
            )}
          </select>
        </label>
        <label className="more-field more-board-field">
          <span>盘口</span>
          <select value={board} onChange={(event) => { setBoard(event.target.value); setPage(1); }}>
            <option value="all">全部</option><option value="A">A盘 - A</option><option value="B">B盘 - B</option><option value="C">C盘 - C</option><option value="D">D盘 - D</option>
          </select>
        </label>
        <label className="more-field more-text-field">
          <span>原始文本搜索</span>
          <input
            value={source}
            onChange={(event) => setSource(event.target.value)}
            placeholder="输入文本"
            onKeyDown={(event) => {
              if (event.key === "Enter") runSearch();
            }}
          />
        </label>
        <button
          className="more-search-button"
          type="button"
          onClick={runSearch}
          disabled={loading}
        >
          ⌕ 搜索
        </button>
        <label className="more-refund-toggle"><span>仅退码</span><input type="checkbox" checked={onlyRefunded} onChange={(event) => { setOnlyRefunded(event.target.checked); setPage(1); }} /><i>{onlyRefunded ? "是" : "否"}</i></label>
        <button className="more-back-button" type="button" onClick={onBack}>返回</button>
      </div>
      <div className="more-total">
        总金额: <b>{amountTotal}</b>
      </div>
      <div className="more-results">
        {records.length > 0 && <div className="more-card-list">{records.map((record) => <article className={`more-card${record.status === "refunded" ? " is-refunded" : ""}`} key={record.id}>
          <div className="more-card-meta"><b>{record.lottery === "体" ? "体" : "福"}盘</b><span>时间：{record.placed_at}</span></div>
          <p>{record.source_text || record.formatted_text || "-"}</p>
          <footer><strong>{record.status === "refunded" ? "0.00" : record.amount}</strong><span>{record.status === "refunded" ? "已退码" : record.status === "won" ? "中奖" : record.status === "unwon" ? "未中奖" : "未结算"}</span></footer>
        </article>)}</div>}
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
    </section>
  );
}
