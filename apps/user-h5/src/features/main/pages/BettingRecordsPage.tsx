import { useEffect, useState } from "react";
import { App as AntdApp, DatePicker, Empty } from "antd";
import zhCN from "antd/es/date-picker/locale/zh_CN";
import dayjs from "dayjs";
import { ExportOutlined, SearchOutlined } from "@ant-design/icons";
import { apiErrorMessage } from "../../../utils/request";
import { getBetRecords, refundBetRecord, type BetRecord } from "../../../api/user";

export function BettingRecordsPage() {
  const { message, modal } = AntdApp.useApp();
  const today = dayjs().format("YYYY-MM-DD");
  const [records, setRecords] = useState<BetRecord[]>([]);
  const [status, setStatus] = useState("all");
  const [from, setFrom] = useState(today);
  const [to, setTo] = useState(today);
  const [source, setSource] = useState("");
  const [page, setPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(false);
  const loadRecords = (nextPage = page) => {
    setLoading(true);
    getBetRecords({
      status: status === "all" ? undefined : status,
      from,
      to,
      source,
      page: nextPage,
      page_size: 20,
    })
      .then((response) => {
        setRecords(response.data?.data?.list || []);
        setTotal(Number(response.data?.data?.total || 0));
        setPage(nextPage);
      })
      .catch((error) => {
        setRecords([]);
        setTotal(0);
        message.error(apiErrorMessage(error, "投注记录加载失败"));
      })
      .finally(() => setLoading(false));
  };
  useEffect(() => {
    loadRecords(1);
  }, []);
  const exportRecords = () => {
    if (!records.length) {
      message.info("当前没有可导出的记录");
      return;
    }
    const csv = [
      "期号,笔数/金额,中奖金额,原始文本,封盘情况,投注时间",
      ...records.map((r) =>
        [
          r.issue_no,
          `${r.bet_count}/${r.amount}`,
          r.win_amount,
          `"${(r.source_text || "").replaceAll('"', '""')}"`,
          r.sealed ? "已封盘" : "-",
          r.placed_at,
        ].join(","),
      ),
    ].join("\n");
    const url = URL.createObjectURL(
      new Blob([`\ufeff${csv}`], { type: "text/csv;charset=utf-8" }),
    );
    const a = document.createElement("a");
    a.href = url;
    a.download = `投注记录-${from}-${to}.csv`;
    a.click();
    URL.revokeObjectURL(url);
  };
  const refund = (record: BetRecord) => {
    modal.confirm({
      title: "确认退单",
      content: `确定退回该注单，金额 ¥ ${record.amount} 吗？`,
      okText: "确认退单",
      cancelText: "取消",
      okButtonProps: { danger: true },
      onOk: async () => {
        try {
          await refundBetRecord(record.id);
          message.success("退单成功");
          await loadRecords(1);
          window.dispatchEvent(new Event("profile-updated"));
          window.dispatchEvent(new Event("bet-records-updated"));
        } catch (error) {
          message.error(apiErrorMessage(error, "退单失败"));
        }
      },
    });
  };
  return (
    <div className="records-panel">
      <div className="records-filter">
        <label className="records-field records-prize">
          <span>中奖</span>
          <select
            value={status}
            onChange={(event) => setStatus(event.target.value)}
          >
            <option value="all">全部</option>
            <option value="won">仅中奖</option>
            <option value="unwon">未中奖</option>
          </select>
        </label>
        <label className="records-field records-source">
          <span>原始文本搜索</span>
          <textarea
            rows={1}
            maxLength={200}
            value={source}
            onChange={(event) => setSource(event.target.value)}
            placeholder="输入文本"
          />
        </label>
        <div className="records-time-range">
          <span>投注时间</span>
          <DatePicker
            locale={zhCN}
            allowClear={false}
            value={dayjs(from)}
            format="YYYY-MM-DD"
            onChange={(value) => value && setFrom(value.format("YYYY-MM-DD"))}
          />
          <em>至</em>
          <DatePicker
            locale={zhCN}
            allowClear={false}
            value={dayjs(to)}
            format="YYYY-MM-DD"
            onChange={(value) => value && setTo(value.format("YYYY-MM-DD"))}
          />
        </div>
        <div className="records-buttons">
          <button
            type="button"
            className="records-search"
            onClick={() => loadRecords(1)}
            disabled={loading}
          >
            <SearchOutlined /> 搜索
          </button>
          <button
            type="button"
            className="records-export"
            onClick={exportRecords}
          >
            <ExportOutlined /> 导出金额
          </button>
        </div>
      </div>
      <div className="records-table">
        <div className="records-head">
          <span>期号</span>
          <span>笔数/金额</span>
          <span>中奖笔数/金额</span>
          <span>文本</span>
          <span>ⓘ 封盘情况</span>
          <span>投注时间</span>
          <span>退码</span>
        </div>
        {records.length ? (
          records.map((record) => (
            <div className="records-row" key={record.id}>
              <span>{record.issue_no}</span>
              <span>
                {record.bet_count}/{record.amount}
              </span>
              <span>{record.win_amount}</span>
              <span>{record.source_text || "-"}</span>
              <span>{record.sealed ? "已封盘" : "-"}</span>
              <span>{record.placed_at}</span>
              <span>
                {record.status === "refunded" ? "已退" : record.can_refund ? (
                  <button type="button" className="records-refund" disabled={loading} onClick={() => refund(record)}>
                    退
                  </button>
                ) : "-"}
              </span>
            </div>
          ))
        ) : (
          !loading && (
            <div className="records-empty">
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
      <div className="records-pagination">
        <span>共 {total} 条</span>
        <button
          type="button"
          disabled={page <= 1 || loading}
          onClick={() => loadRecords(page - 1)}
        >
          上一页
        </button>
        <span>第 {page} 页</span>
        <button
          type="button"
          disabled={page * 20 >= total || loading}
          onClick={() => loadRecords(page + 1)}
        >
          下一页
        </button>
      </div>
    </div>
  );
}
