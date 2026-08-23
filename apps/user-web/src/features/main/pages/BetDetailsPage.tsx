import { useEffect, useState } from "react";
import { App as AntdApp, Empty, Modal } from "antd";
import dayjs from "dayjs";
import { SearchOutlined } from "@ant-design/icons";
import {
  getBetDetails,
  type BetDetail,
  type Lottery,
} from "../../../api/user";
import { apiErrorMessage } from "../../../utils/request";

export function BetDetailsPage({
  lotteries,
  selectedLotteryId,
}: {
  lotteries: Lottery[];
  selectedLotteryId: number | null;
}) {
  const { message } = AntdApp.useApp();
  const categories = [
    "所有",
    "一码定位",
    "口XX",
    "X口X",
    "XX口",
    "二码定位",
    "口口X",
    "口X口",
    "X口口",
    "直选",
    "独胆",
    "双飞",
    "组选",
    "组三多码",
    "组三二码",
    "组三三码",
    "组三四码",
    "组三五码",
    "组三六码",
    "组三七码",
    "组三八码",
    "组三九码",
    "组三全包",
    "组六多码",
    "组六四码",
    "组六五码",
    "组六六码",
    "组六七码",
    "组六八码",
    "组六九码",
    "组六全包",
    "复式多码",
    "跨度",
    "和值",
    "大小单双",
    "豹子全包",
    "对子全包",
  ];
  const [rows, setRows] = useState<BetDetail[]>([]);
  const [previewRow, setPreviewRow] = useState<BetDetail>();
  const [previewMode, setPreviewMode] = useState<"text" | "numbers">("text");
  const [number, setNumber] = useState("");
  const [metric, setMetric] = useState("odds");
  const [min, setMin] = useState("");
  const [max, setMax] = useState("");
  const [category, setCategory] = useState("所有");
  const [sort, setSort] = useState("desc");
  const [winning, setWinning] = useState(false);
  const [loading, setLoading] = useState(false);
  const [issue, setIssue] = useState("");
  const [issues, setIssues] = useState<
    Array<{ code: string; draw_day: string | null }>
  >([]);
  const selectedLottery =
    lotteries.find((item) => item.id === selectedLotteryId) || lotteries[0];
  const load = (overrides: Record<string, unknown> = {}) => {
    setLoading(true);
    getBetDetails({
      number,
      metric,
      min,
      max,
      category,
      sort,
      lottery: selectedLottery?.name,
      issue_no: issue || undefined,
      winning: winning ? 1 : undefined,
      page: 1,
      page_size: 50,
      ...overrides,
    })
      .then((response) => setRows(response.data?.data?.list || []))
      .catch((error) => {
        setRows([]);
        message.error(apiErrorMessage(error, "下注明细加载失败"));
      })
      .finally(() => setLoading(false));
  };
  useEffect(() => {
    if (!selectedLottery) return;
    const recent = (selectedLottery.recent_issues || []).slice(0, 10);
    setIssues(recent);
    const nextIssue = recent[0]?.code || "";
    setIssue(nextIssue);
    load({ lottery: selectedLottery.name, issue_no: nextIssue || undefined });
  }, [selectedLottery?.id]);
  return (
    <div className="bet-detail-page">
      <div className="bet-detail-filter">
        <button
          type="button"
          className={winning ? "bet-winning active" : "bet-winning"}
          onClick={() => {
            const next = !winning;
            setWinning(next);
            load({ winning: next ? 1 : undefined });
          }}
        >
          查看中奖
        </button>
        <label className="bet-filter-number">
          <span>查号码</span>
          <input
            value={number}
            onChange={(event) => setNumber(event.target.value)}
            placeholder="查号码"
          />
        </label>
        <label className="bet-filter-range">
          <span>列出</span>
          <select
            value={metric}
            onChange={(event) => setMetric(event.target.value)}
          >
            <option value="odds">赔率</option>
            <option value="amount">金额</option>
          </select>
          <input
            value={min}
            inputMode="decimal"
            onChange={(event) =>
              setMin(event.target.value.replace(/[^\d.]/g, ""))
            }
          />
          <em>至</em>
          <input
            value={max}
            inputMode="decimal"
            onChange={(event) =>
              setMax(event.target.value.replace(/[^\d.]/g, ""))
            }
          />
        </label>
        <label className="bet-filter-category">
          <span>分类</span>
          <select
            value={category}
            onChange={(event) => setCategory(event.target.value)}
          >
            {categories.map((item) => (
              <option key={item}>{item}</option>
            ))}
          </select>
        </label>
        <button
          type="button"
          className="bet-filter-search"
          onClick={() => load()}
          disabled={loading}
        >
          <SearchOutlined /> 搜索
        </button>
      </div>
      <div className="bet-detail-results">
        <div className="bet-detail-sort">
          <strong>总货明细(红色为退码)</strong>
          <span>按下注时间排序:</span>
          <label>
            <input
              type="radio"
              name="detail-sort"
              checked={sort === "desc"}
              onChange={() => {
                setSort("desc");
                load({ sort: "desc" });
              }}
            />{" "}
            倒序
          </label>
          <label>
            <input
              type="radio"
              name="detail-sort"
              checked={sort === "asc"}
              onChange={() => {
                setSort("asc");
                load({ sort: "asc" });
              }}
            />{" "}
            正序
          </label>
          <select
            aria-label="期号"
            value={issue}
            onChange={(event) => {
              const next = event.target.value;
              setIssue(next);
              load({ issue_no: next });
            }}
          >
            {issues.length ? (
              issues.map((item) => (
                <option key={item.code} value={item.code}>
                  {item.draw_day ? dayjs(item.draw_day).format("M-D") : "--"}(
                  {item.code})
                </option>
              ))
            ) : (
              <option value="">暂无期号</option>
            )}
          </select>
        </div>
        <div className="bet-detail-table">
          <div className="bet-detail-head">
            <span>注单编号</span>
            <span>下单时间</span>
            <span>号码</span>
            <span>金额</span>
            <span>赔率</span>
            <span>中奖</span>
            <span>回水</span>
            <span>离线回水</span>
            <span>盈亏</span>
            <span>状态</span>
            <span>查看文本</span>
          </div>
          {rows.length ? (
            rows.map((row) => (
              <div className="bet-detail-row" key={row.id}>
                <span>{row.id}</span>
                <span>{row.placed_at}</span>
                <button
                  type="button"
                  className="bet-number-link"
                  onClick={() => {
                    setPreviewRow(row);
                    setPreviewMode("numbers");
                  }}
                >
                  {row.number_text || "-"}
                </button>
                <span>{row.amount}</span>
                <span>{row.odds || "-"}</span>
                <span>{row.win_amount}</span>
                <span>{row.rebate}</span>
                <span>-</span>
                <span>
                  {(
                    Number(row.win_amount) -
                    Number(row.amount) +
                    Number(row.rebate)
                  ).toFixed(2)}
                </span>
                <span>
                  {(
                    {
                      pending: "未结算",
                      won: "中奖",
                      unwon: "未中奖",
                      refunded: "已退码",
                      cancelled: "已取消",
                      failed: "失败",
                    } as Record<string, string>
                  )[row.status] || "未知状态"}
                </span>
                <button
                  type="button"
                  className="bet-text-link"
                  disabled={!row.source_text}
                  onClick={() => {
                    setPreviewRow(row);
                    setPreviewMode("text");
                  }}
                >
                  {row.source_text ? "查看" : "-"}
                </button>
              </div>
            ))
          ) : (
            !loading && (
              <div className="bet-detail-empty">
                <Empty
                  image={Empty.PRESENTED_IMAGE_SIMPLE}
                  description="暂无数据"
                />
              </div>
            )
          )}
          {loading && (
            <div
              className="page-local-loading"
              role="status"
              aria-label="加载中"
            />
          )}
        </div>
        <Modal
          className="record-detail-modal"
          open={Boolean(previewRow)}
          title={
            previewRow
              ? `${previewMode === "text" ? "原始投注文本" : "投注号码"} · 注单 ${previewRow.id}`
              : ""
          }
          footer={null}
          onCancel={() => setPreviewRow(undefined)}
          width={760}
        >
          {previewRow && previewMode === "text" ? (
            <pre className="bet-text-preview">
              {previewRow.source_text || "暂无原始文本"}
            </pre>
          ) : (
            <div className="bet-number-preview">
              {(previewRow?.number_text || "")
                .split(/[\s,，]+/)
                .filter(Boolean)
                .map((number, index) => (
                  <i key={`${number}-${index}`}>{number}</i>
                ))}
            </div>
          )}
        </Modal>
      </div>
    </div>
  );
}
