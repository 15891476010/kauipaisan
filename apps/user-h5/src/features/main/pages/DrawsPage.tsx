import { useEffect, useRef, useState, type CSSProperties } from "react";
import { Empty } from "antd";
import { getDraws, waitDraws, type Draw, type Lottery } from "../../../api/user";
import { displayIssueCode, lotteryTiming } from "../shared";

function formatDrawTime(value?: string | null) {
  const raw = String(value || "");
  if (!raw.trim()) return "---";
  const match = raw.trim().match(/(?:\d{4}[-/]?)?(\d{1,2})[-/](\d{1,2})\s+(\d{1,2}:\d{2}(?::\d{2})?)/u);
  if (!match) return raw.trim();
  return match[1].padStart(2, "0") + "-" + match[2].padStart(2, "0") + " " + match[3];
}

export function DrawsPage({ selectedLottery }: { selectedLottery?: Lottery }) {
  const [rows, setRows] = useState<Draw[]>([]);
  const [loading, setLoading] = useState(false);
  const [now, setNow] = useState(Date.now());
  const drawRequestId = useRef(0);
  const drawSignature = useRef("");
  const showNextIssue = lotteryTiming(selectedLottery, now).showNextIssue;
  const nextIssue = selectedLottery?.header_next_code || selectedLottery?.next_code;
  const rowsWithPending = nextIssue && !rows.some((row) => String(row.issue_no) === String(nextIssue))
    ? [{
        lottery: selectedLottery?.name || "",
        issue_no: nextIssue,
        draw_date: "",
        draw_time: null,
        numbers: "",
        pending: 1,
      } as Draw, ...rows]
    : rows;
  // The reference always reserves the first row for the next issue while it is
  // waiting for the draw, even when the API only returns historical rows.
  const visibleRows = rowsWithPending.slice(0, 64);
  const issueDigits = Math.max(
    2,
    ...rows.map((row) => displayIssueCode(row.issue_no).trim().length),
  );
  const drawTableStyle = {
    "--draw-issue-width": "calc(" + issueDigits + "ch + 10px)",
  } as CSSProperties;
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
        <div className="draw-table" style={drawTableStyle}>
          <div className="draw-head">
            <span>开奖时间</span>
            <span>期号</span>
            <span>佰</span>
            <span>拾</span>
            <span>个</span>
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
                    <time>{pending ? "---" : formatDrawTime(row.draw_time || row.draw_date)}</time>
                    <strong>{displayIssueCode(row.issue_no)}</strong>
                    {[0, 1, 2].map((index) => (
                      <span
                        className={`draw-ball${pending ? " pending" : ""}`}
                        data-number={numbers[index] || ""}
                        key={index}
                      >
                        {numbers[index] || ""}
                      </span>
                    ))}

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
