import { useEffect, useState } from "react";
import { App as AntdApp } from "antd";
import dayjs from "dayjs";
import { getBetRecords, type BetRecord, type Lottery } from "../../../api/user";
import { apiErrorMessage } from "../../../utils/request";

export function MorePanel({
  onBack,
  lotteries,
}: {
  onBack: () => void;
  lotteries: Lottery[];
}) {
  const { message } = AntdApp.useApp();
  const today = dayjs().format("YYYY-MM-DD");
  const dateOptions = Array.from(
    new Map(
      lotteries.flatMap((lottery) =>
        (lottery.recent_issues || []).map((issue) => [
          issue.draw_day || issue.code,
          { day: issue.draw_day || today, code: issue.code },
        ]),
      ),
    ).values(),
  ).slice(0, 30);
  const [selectedDay, setSelectedDay] = useState(dateOptions[0]?.day || today);
  const [source, setSource] = useState("");
  const [records, setRecords] = useState<BetRecord[]>([]);
  const [amountTotal, setAmountTotal] = useState("0.00");
  const [loading, setLoading] = useState(false);
  useEffect(() => {
    if (
      dateOptions.length &&
      !dateOptions.some((item) => item.day === selectedDay)
    )
      setSelectedDay(dateOptions[0].day);
  }, [lotteries]);
  const search = () => {
    setLoading(true);
    getBetRecords({
      from: selectedDay,
      to: selectedDay,
      source: source.trim() || undefined,
      page: 1,
      page_size: 100,
    })
      .then((response) => {
        const data = response.data?.data;
        setRecords(data?.list || []);
        setAmountTotal(data?.amount_total || "0.00");
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
  }, [selectedDay]);
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
        <label className="more-field more-text-field">
          <span>原始文本搜索：</span>
          <input
            value={source}
            onChange={(event) => setSource(event.target.value)}
            placeholder="输入文本"
            onKeyDown={(event) => {
              if (event.key === "Enter") search();
            }}
          />
        </label>
        <button
          className="more-search-button"
          type="button"
          onClick={search}
          disabled={loading}
        >
          ⌕ 搜索
        </button>
        <button className="more-back-button" type="button" onClick={onBack}>
          返回
        </button>
      </div>
      <div className="more-total">
        总金额: <b>{amountTotal}</b>
      </div>
      <div className="more-results">
        {records.length > 0 && (
          <div className="more-table">
            <div className="more-table-head">
              <span>期号</span>
              <span>笔数/金额</span>
              <span>中奖金额</span>
              <span>原始文本</span>
              <span>投注时间</span>
              <span>状态</span>
            </div>
            {records.map((record) => (
              <div className="more-table-row" key={record.id}>
                <span>{record.issue_no}</span>
                <span>
                  {record.bet_count}/{record.amount}
                </span>
                <span>{record.win_amount}</span>
                <span>{record.source_text || "-"}</span>
                <span>{record.placed_at}</span>
                <span>
                  {record.status === "refunded"
                    ? "已退"
                    : record.status === "won"
                      ? "中奖"
                      : record.status === "unwon"
                        ? "未中奖"
                        : "未结算"}
                </span>
              </div>
            ))}
          </div>
        )}
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
