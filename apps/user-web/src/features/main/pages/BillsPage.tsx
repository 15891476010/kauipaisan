import { useEffect, useState } from "react";
import { DatePicker, Empty } from "antd";
import dayjs from "dayjs";
import { getBills, type Bill } from "../../../api/user";

export function BillsPage() {
  const [rows, setRows] = useState<Bill[]>([]);
  const today = dayjs();
  const [from, setFrom] = useState(today.startOf("month"));
  const [to, setTo] = useState(today);
  const [period, setPeriod] = useState("month");
  const [lotteries, setLotteries] = useState({ fu: true, ti: true });
  const [total, setTotal] = useState({
    bet_count: 0,
    amount: "0.00",
    rebate: "0.00",
    offline_rebate: "0.00",
    win_amount: "0.00",
    profit: "0.00",
  });
  const [loading, setLoading] = useState(false);
  const lotteryFilter = lotteries.fu === lotteries.ti ? "" : lotteries.fu ? "福彩3D" : lotteries.ti ? "排列三" : "__none__";
  const setRange = (
    nextFrom: dayjs.Dayjs,
    nextTo: dayjs.Dayjs,
    nextPeriod: string,
  ) => {
    setFrom(nextFrom.startOf("day"));
    setTo(nextTo.startOf("day"));
    setPeriod(nextPeriod);
  };
  const applyPeriod = (next: string) => {
    const now = dayjs();
    if (next === "today") return setRange(now, now, next);
    if (next === "yesterday") {
      const day = now.subtract(1, "day");
      return setRange(day, day, next);
    }
    if (next === "week") return setRange(now.startOf("week"), now, next);
    if (next === "last-week")
      return setRange(
        now.subtract(1, "week").startOf("week"),
        now.subtract(1, "week").endOf("week"),
        next,
      );
    setRange(now.startOf("month"), now, "month");
  };
  useEffect(() => {
    setLoading(true);
    getBills({ from: from.format("YYYY-MM-DD"), to: to.format("YYYY-MM-DD"), ...(lotteryFilter ? { lottery: lotteryFilter } : {}) })
      .then((response) => {
        const data = response.data?.data;
        setRows(data?.list || []);
        setTotal(
          (data?.total as typeof total) || {
            bet_count: 0,
            amount: "0.00",
            rebate: "0.00",
            offline_rebate: "0.00",
            win_amount: "0.00",
            profit: "0.00",
          },
        );
      })
      .catch(() => {
        setRows([]);
        setTotal({
          bet_count: 0,
          amount: "0.00",
          rebate: "0.00",
          offline_rebate: "0.00",
          win_amount: "0.00",
          profit: "0.00",
        });
      })
      .finally(() => setLoading(false));
  }, [from, to, lotteryFilter]);
  return (
    <div className="business-page">
      <div className="business-toolbar bill-toolbar">
        <fieldset className="bill-lottery-filter">
          <legend>彩种</legend>
          <label>
            <input
              type="checkbox"
              checked={lotteries.fu}
              onChange={(event) =>
                setLotteries((value) => ({
                  ...value,
                  fu: event.target.checked,
                }))
              }
            />{" "}
            福
          </label>
          <label>
            <input
              type="checkbox"
              checked={lotteries.ti}
              onChange={(event) =>
                setLotteries((value) => ({
                  ...value,
                  ti: event.target.checked,
                }))
              }
            />{" "}
            体
          </label>
        </fieldset>
        <div className="bill-date-range">
          <span>日期</span>
          <DatePicker
            className="bill-date-picker"
            value={from}
            format="YYYY-MM-DD"
            allowClear={false}
            onChange={(value) =>
              value &&
              setRange(value, to.isBefore(value, "day") ? to : value, "custom")
            }
          />
          <em>至</em>
          <DatePicker
            className="bill-date-picker"
            value={to}
            format="YYYY-MM-DD"
            allowClear={false}
            onChange={(value) => value && setRange(from, value, "custom")}
          />
        </div>
      </div>
      <div className="bill-subbar">
        <b>历史账单</b>
        <strong
          className={period === "month" ? "month-selected" : ""}
          role="button"
          tabIndex={0}
          onClick={() => applyPeriod("month")}
        >
          {today.format("YYYY年MM月")}
        </strong>
        <button
          type="button"
          className={period === "today" ? "selected" : ""}
          onClick={() => applyPeriod("today")}
        >
          今天
        </button>
        <button
          type="button"
          className={period === "yesterday" ? "selected" : ""}
          onClick={() => applyPeriod("yesterday")}
        >
          昨天
        </button>
        <button
          type="button"
          className={period === "week" ? "selected" : ""}
          onClick={() => applyPeriod("week")}
        >
          本周
        </button>
        <button
          type="button"
          className={period === "last-week" ? "selected" : ""}
          onClick={() => applyPeriod("last-week")}
        >
          上周
        </button>
      </div>
      <div className="business-table bill-table">
        <div className="business-head">
          <span>日期</span>
          <span>笔数</span>
          <span>金额</span>
          <span>总回水</span>
          <span>离线回水</span>
          <span>中奖</span>
          <span>盈亏</span>
        </div>
        {rows.length ? (
          rows.map((row) => (
            <div className="business-row" key={row.bill_date}>
              <span>{row.bill_date}</span>
              <span>{row.bet_count}</span>
              <span>{row.amount}</span>
              <span>{row.rebate}</span>
              <span>{row.offline_rebate}</span>
              <span>{row.win_amount}</span>
              <span>{row.profit}</span>
            </div>
          ))
        ) : (
          <div className="business-empty">
            <Empty
              image={Empty.PRESENTED_IMAGE_SIMPLE}
              description="暂无数据"
            />
          </div>
        )}
        <div className="bill-total">
          <span>合计</span>
          <span>{total.bet_count}</span>
          <span>{total.amount}</span>
          <span>{total.rebate}</span>
          <span>{total.offline_rebate}</span>
          <span>{total.win_amount}</span>
          <span>{total.profit}</span>
        </div>
        {loading && (
          <div
            className="page-local-loading"
            role="status"
            aria-label="加载中"
          />
        )}
      </div>
    </div>
  );
}
