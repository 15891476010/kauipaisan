import { useEffect, useState } from "react";
import dayjs, { type Dayjs } from "dayjs";
import { getBills, type Bill } from "../../../api/user";

const emptyTotal = {
  bet_count: 0,
  amount: "0.00",
  rebate: "0.00",
  offline_rebate: "0.00",
  win_amount: "0.00",
  profit: "0.00",
};

export function BillsPage() {
  const today = dayjs();
  const [rows, setRows] = useState<Bill[]>([]);
  const [from, setFrom] = useState(today);
  const [to, setTo] = useState(today);
  const [period, setPeriod] = useState("today");
  const [lotteries, setLotteries] = useState({ fu: true, ti: true });
  const [total, setTotal] = useState(emptyTotal);
  const [loading, setLoading] = useState(false);
  const lotteryFilter = lotteries.fu === lotteries.ti
    ? ""
    : lotteries.fu
      ? "福彩3D"
      : lotteries.ti
        ? "排列三"
        : "__none__";
  const dateOptions = Array.from({ length: 42 }, (_, index) =>
    today.subtract(index, "day"),
  );

  const setRange = (nextFrom: Dayjs, nextTo: Dayjs, nextPeriod: string) => {
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
    if (next === "last-week") {
      const day = now.subtract(1, "week");
      return setRange(day.startOf("week"), day.endOf("week"), next);
    }
    setRange(now.startOf("month"), now, "month");
  };
  const selectDate = (value: string, side: "from" | "to") => {
    const date = dayjs(value);
    if (!date.isValid()) return;
    if (side === "from") setRange(date, to.isBefore(date, "day") ? date : to, "custom");
    else setRange(from, date.isBefore(from, "day") ? from : date, "custom");
  };

  useEffect(() => {
    setLoading(true);
    getBills({
      from: from.format("YYYY-MM-DD"),
      to: to.format("YYYY-MM-DD"),
      ...(lotteryFilter ? { lottery: lotteryFilter } : {}),
    })
      .then((response) => {
        const data = response.data?.data;
        setRows(data?.list || []);
        setTotal((data?.total as typeof total) || emptyTotal);
      })
      .catch(() => {
        setRows([]);
        setTotal(emptyTotal);
      })
      .finally(() => setLoading(false));
  }, [from, to, lotteryFilter]);

  return (
    <div className="business-page bill-page">
      <div className="business-toolbar bill-toolbar">
        <div className="lottery-selector" aria-label="彩种">
          <span
            className={lotteries.fu ? "selected" : ""}
            role="button"
            tabIndex={0}
            onClick={() => setLotteries((value) => ({ ...value, fu: !value.fu }))}
            onKeyDown={(event) => {
              if (event.key === "Enter" || event.key === " ")
                setLotteries((value) => ({ ...value, fu: !value.fu }));
            }}
          >福</span>
          <span
            className={lotteries.ti ? "selected" : ""}
            role="button"
            tabIndex={0}
            onClick={() => setLotteries((value) => ({ ...value, ti: !value.ti }))}
            onKeyDown={(event) => {
              if (event.key === "Enter" || event.key === " ")
                setLotteries((value) => ({ ...value, ti: !value.ti }));
            }}
          >体</span>
        </div>
        <div className="bill-date-selectors">
          <select aria-label="开始日期" value={from.format("YYYY-MM-DD")} onChange={(event) => selectDate(event.target.value, "from")}>
            {dateOptions.map((date) => <option key={date.format("YYYY-MM-DD")} value={date.format("YYYY-MM-DD")}>{date.format("YYYY-MM-DD")}</option>)}
          </select>
          <span>至</span>
          <select aria-label="结束日期" value={to.format("YYYY-MM-DD")} onChange={(event) => selectDate(event.target.value, "to")}>
            {dateOptions.map((date) => <option key={date.format("YYYY-MM-DD")} value={date.format("YYYY-MM-DD")}>{date.format("YYYY-MM-DD")}</option>)}
          </select>
        </div>
        <div className="bill-period-selector">
          <span className="link-button"><span>{today.format("YYYY年MM月")}</span></span>
          {[
            ["today", "今天"],
            ["yesterday", "昨天"],
            ["week", "本周"],
            ["last-week", "上周"],
          ].map(([value, label]) => (
            <span key={value} className={`link-button ${period === value ? "selected" : ""}`} role="button" tabIndex={0} onClick={() => applyPeriod(value)} onKeyDown={(event) => { if (event.key === "Enter" || event.key === " ") applyPeriod(value); }}>
              <span>{label}</span>
            </span>
          ))}
        </div>
      </div>
      <div className="business-table bill-table">
        <div className="business-head">
          <span>日期</span><span>金额</span><span>回水</span><span>离线水</span><span>中奖</span><span>盈亏</span>
        </div>
        {rows.map((row) => (
          <div className="business-row" key={row.bill_date}>
            <span>{row.bill_date}</span><span>{row.amount}</span><span>{row.rebate}</span><span>{row.offline_rebate}</span><span>{row.win_amount}</span><span>{row.profit}</span>
          </div>
        ))}
        <div className="bill-total">
          <span>合计</span><span>{total.amount === "0.00" ? "0" : total.amount}</span><span>{total.rebate === "0.00" ? "0" : total.rebate}</span><span>{total.offline_rebate === "0.00" ? "0" : total.offline_rebate}</span><span>{total.win_amount === "0.00" ? "0" : total.win_amount}</span><span>{total.profit === "0.00" ? "0" : total.profit}</span>
        </div>
        {loading && <div className="page-local-loading" role="status" aria-label="加载中" />}
      </div>
    </div>
  );
}
