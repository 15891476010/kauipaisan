import { useEffect, useRef, useState } from "react";
import { Empty } from "antd";
import { getDraws, waitDraws, type Draw, type Lottery } from "../../../api/user";
import { lotteryTiming } from "../shared";

export function DrawsPage({ selectedLottery }: { selectedLottery?: Lottery }) {
  const [rows, setRows] = useState<Draw[]>([]);
  const [loading, setLoading] = useState(false);
  const [now, setNow] = useState(Date.now());
  const drawRequestId = useRef(0);
  const drawSignature = useRef("");
  const showNextIssue = lotteryTiming(selectedLottery, now).showNextIssue;
  const visibleRows = showNextIssue
    ? rows
    : rows.filter((row) => row.pending !== 1 && row.numbers.trim() !== "");
  useEffect(() => {
    const timer = window.setInterval(() => setNow(Date.now()), 1_000);
    return () => window.clearInterval(timer);
  }, []);
  useEffect(() => {
    if (!selectedLottery) return;
    const requestId = ++drawRequestId.current;
    drawSignature.current = "";
    setRows([]);
    setLoading(true);
    window.setTimeout(() => {
      getDraws({ lottery: selectedLottery.name, _t: Date.now() })
        .then((response) => {
          if (requestId === drawRequestId.current)
            setRows(response.data?.data?.list || []);
        })
        .catch(() => {
          if (requestId === drawRequestId.current) setRows([]);
        })
        .finally(() => {
          if (requestId === drawRequestId.current) setLoading(false);
        });
    }, 0);
    let active = true;
    const watch = async () => {
      try {
        const response = await waitDraws({
          lottery: selectedLottery.name,
          since: drawSignature.current,
        });
        if (!active || requestId !== drawRequestId.current) return;
        const data = response.data?.data;
        if (data?.changed) {
          drawSignature.current = data.signature || "";
          const refreshed = await getDraws({
            lottery: selectedLottery.name,
            _t: Date.now(),
          });
          if (active && requestId === drawRequestId.current)
            setRows(refreshed.data?.data?.list || []);
        } else if (data?.signature) drawSignature.current = data.signature;
      } catch {}
      if (active && requestId === drawRequestId.current) void watch();
    };
    void watch();
    return () => {
      active = false;
    };
  }, [selectedLottery?.id, selectedLottery?.next_code, showNextIssue]);
  return (
    <div className="draw-page">
      {loading ? (
        <div className="draw-loading-only" role="status" aria-label="加载中" />
      ) : (
        <div className="draw-table">
          <div className="draw-head">
            <span>期号</span>
            <span>开奖时间</span>
            <span>佰</span>
            <span>拾</span>
            <span>个</span>
            <span>和值</span>
            <span>跨度</span>
          </div>
          <div className="draw-body">
            {visibleRows.length ? (
              visibleRows.map((row) => {
                const numbers = row.numbers.split(/[,，\s]+/).filter(Boolean);
                const pending = numbers.length < 3;
                return (
                  <div
                    className="draw-row"
                    key={`${row.lottery}-${row.issue_no}`}
                  >
                    <strong>{row.issue_no}</strong>
                    <time>{row.draw_time || row.draw_date || "---"}</time>
                    {[0, 1, 2].map((index) => (
                      <span
                        className={`draw-ball${pending ? " pending" : ""}`}
                        key={index}
                      >
                        {numbers[index] || ""}
                      </span>
                    ))}
                    <span className="draw-sum">
                      {pending || row.sum_value == null
                        ? "---"
                        : `${row.sum_value} / ${row.size} / ${row.parity}`}
                    </span>
                    <b className={`draw-span${pending ? " pending" : ""}`}>
                      {pending || row.span_value == null ? "---" : row.span_value}
                    </b>
                  </div>
                );
              })
            ) : (
              <div className="draw-empty">
                <Empty
                  image={Empty.PRESENTED_IMAGE_SIMPLE}
                  description="暂无数据"
                />
              </div>
            )}
          </div>
        </div>
      )}
    </div>
  );
}
