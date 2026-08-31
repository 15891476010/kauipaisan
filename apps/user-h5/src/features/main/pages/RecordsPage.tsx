import { useEffect, useMemo, useState } from "react";
import { Empty, Modal } from "antd";
import { SearchOutlined } from "@ant-design/icons";
import { getBetDetails, getBetRecords, type BetDetail, type BetRecord } from "../../../api/user";
import { apiErrorMessage } from "../../../utils/request";

const PAGE_SIZE = 20;

function dateOptions(today = new Date()) {
  return Array.from({ length: 30 }, (_, index) => {
    const date = new Date(today);
    date.setDate(date.getDate() - index);
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, "0");
    const day = String(date.getDate()).padStart(2, "0");
    return `${year}-${month}-${day}`;
  });
}

export function RecordsPage() {
  const dates = useMemo(() => dateOptions(), []);
  const [records, setRecords] = useState<BetRecord[]>([]);
  const [source, setSource] = useState("");
  const [from, setFrom] = useState(dates[0]);
  const [to, setTo] = useState(dates[0]);
  const [status, setStatus] = useState("all");
  const [page, setPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [detailRecord, setDetailRecord] = useState<BetRecord | null>(null);
  const [details, setDetails] = useState<BetDetail[]>([]);
  const [detailLoading, setDetailLoading] = useState(false);
  const [textRecord, setTextRecord] = useState<BetRecord | null>(null);

  const loadRecords = (nextPage = 1) => {
    setLoading(true);
    setError("");
    getBetRecords({
      source,
      from,
      to,
      status: status === "all" ? undefined : status,
      page: nextPage,
      page_size: PAGE_SIZE,
    })
      .then((response) => {
        const data = response.data?.data;
        setRecords(data?.list || []);
        setTotal(Number(data?.total || 0));
        setPage(nextPage);
      })
      .catch((reason) => {
        setRecords([]);
        setTotal(0);
        setError(apiErrorMessage(reason, "记录加载失败"));
      })
      .finally(() => setLoading(false));
  };

  useEffect(() => {
    loadRecords(1);
    // The initial query intentionally runs once; subsequent changes submit with 搜索.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const pageCount = Math.max(1, Math.ceil(total / PAGE_SIZE));
  const changePage = (nextPage: number) => {
    const target = Math.min(pageCount, Math.max(1, nextPage));
    if (target !== page) loadRecords(target);
  };

  const showDetails = (record: BetRecord) => {
    setDetailRecord(record);
    setDetailLoading(true);
    setDetails([]);
    getBetDetails({
      submission_id: record.submission_id ?? record.id,
      page: 1,
      page_size: 100,
    })
      .then((response) => setDetails(response.data?.data?.list || []))
      .catch(() => setDetails([]))
      .finally(() => setDetailLoading(false));
  };

  const detailLottery = (value?: string) => {
    if (value === "排列三" || value === "体") return "排列三";
    return "福彩3D";
  };

  const displayDetailNumber = (detail: BetDetail) => {
    const value = detail.number_text || "";
    return /^\\d{3}$/.test(value) ? String(Number(value)) : value || "-";
  };

  return (
    <section className="records-page" aria-label="记录">
      <form
        className="records-page-filter"
        onSubmit={(event) => {
          event.preventDefault();
          loadRecords(1);
        }}
      >
        <div className="records-page-row records-page-source">
          <label htmlFor="records-source">文本搜索</label>
          <input
            id="records-source"
            value={source}
            maxLength={200}
            placeholder="输入文本"
            onChange={(event) => setSource(event.target.value)}
          />
        </div>
        <div className="records-page-row records-page-dates">
          <label htmlFor="records-from">投注时间</label>
          <select
            id="records-from"
            value={from}
            onChange={(event) => setFrom(event.target.value)}
          >
            {dates.map((date) => <option key={`from-${date}`} value={date}>{date}</option>)}
          </select>
          <span>至</span>
          <select
            id="records-to"
            value={to}
            onChange={(event) => setTo(event.target.value)}
          >
            {dates.map((date) => <option key={`to-${date}`} value={date}>{date}</option>)}
          </select>
        </div>
        <div className="records-page-row records-page-actions">
          <label htmlFor="records-status">中奖</label>
          <select
            id="records-status"
            value={status}
            onChange={(event) => setStatus(event.target.value)}
          >
            <option value="all">全部</option>
            <option value="won">仅中奖</option>
            <option value="unwon">未中奖</option>
          </select>
          <button type="submit" disabled={loading}>
            <SearchOutlined /> 搜索
          </button>
        </div>
      </form>

      <RecordsPagination page={page} pageCount={pageCount} total={total} onPage={changePage} />

      <div className="records-page-table-wrap">
        <div className="records-page-table" role="table" aria-label="投注记录">
          <div className="records-page-head" role="row">
            <span role="columnheader">期号</span>
            <span role="columnheader">金额<br />中奖</span>
            <span role="columnheader">文本</span>
            <span role="columnheader">投注时间</span>
          </div>
          {records.length > 0 ? records.map((record) => (
            <div className="records-page-row-data" role="row" key={record.id}>
              <span className="records-page-issues" role="cell">
                <span className="records-issue-badge records-issue-fu">
                  <b>福</b><em>{record.issue_no || "-"}</em>
                </span>
                {record.lottery === "排列三" || record.lottery === "体" ? (
                  <span className="records-issue-badge records-issue-ti">
                    <b>体</b><em>{record.issue_no || "-"}</em>
                  </span>
                ) : null}
              </span>
              <span className="records-page-amount" role="cell">
                <b>{record.amount || "0"}</b>
                <em>{record.win_amount || "0"}</em>
                <button type="button" className="records-row-detail" onClick={() => showDetails(record)}>详 情</button>
              </span>
              <span className="records-page-text" role="cell" title={record.source_text || ""}>
                <button type="button" className="records-row-more" onClick={() => setTextRecord(record)}>更 多</button>
              </span>
              <span role="cell">{record.placed_at ? record.placed_at.slice(0, 10) : "-"}</span>
            </div>
          )) : (
            <div className="records-page-empty">
              {loading ? <span className="records-page-spinner" aria-label="加载中" /> : (
                <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description={error || "暂无数据"} />
              )}
            </div>
          )}
        </div>
      </div>

      <Modal
        className="records-detail-modal"
        open={Boolean(detailRecord)}
        title="下注详情"
        width={760}
        footer={<button type="button" className="records-modal-close" onClick={() => setDetailRecord(null)}>关 闭</button>}
        onCancel={() => setDetailRecord(null)}
      >
        {detailLoading ? (
          <div className="record-detail-loading">加载中...</div>
        ) : details.length > 0 ? (
          <div className="record-detail-content">
            <div className="record-detail-tabs">
              {Array.from(new Set(details.map((detail) => detailLottery(detail.lottery)))).map((name) => (
                <span key={name}>{name}</span>
              ))}
            </div>
            {details.map((detail, index) => (
              <section className="record-detail-lottery" key={`${detail.id}-${index}`}>
                <h2>{detailLottery(detail.lottery)}</h2>
                <h3>{detail.play_label || detail.play_type || detail.category || "投注"}</h3>
                <div className="record-detail-card">
                  <div className="record-detail-card-label">号码</div>
                  <div>{displayDetailNumber(detail)}</div>
                  <div className="record-detail-card-label">金额</div>
                  <div className="amount">{detail.amount || "0"}</div>
                  <div className="record-detail-card-label">赔率</div>
                  <div className="odds">{detail.odds || "---"}</div>
                  <div className="record-detail-card-label">中奖</div>
                  <div>{Number(detail.win_amount || 0) > 0 ? detail.win_amount : "---"}</div>
                </div>
              </section>
            ))}
          </div>
        ) : (
          <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="暂无详情" />
        )}
      </Modal>

      <Modal
        className="records-text-modal"
        open={Boolean(textRecord)}
        title="文本详情"
        width={416}
        footer={<button type="button" className="records-modal-close" onClick={() => setTextRecord(null)}>关 闭</button>}
        onCancel={() => setTextRecord(null)}
      >
        <div className="records-text-tabs">
          <span className="selected">原始文本</span>
          <span>文本</span>
        </div>
        <pre>{textRecord?.source_text || "-"}</pre>
      </Modal>

      <RecordsPagination page={page} pageCount={pageCount} total={total} onPage={changePage} />
    </section>
  );
}

function RecordsPagination({
  page,
  pageCount,
  total,
  onPage,
}: {
  page: number;
  pageCount: number;
  total: number;
  onPage: (page: number) => void;
}) {
  return (
    <div className="records-page-pagination" aria-label="记录分页">
      <span>第</span>
      <button type="button" className="records-page-number" onClick={() => onPage(page)}>{total ? page : 0}</button>
      <span>页</span>
      {pageCount > 1 ? (
        <span className="records-page-nav">
          <button type="button" disabled={page <= 1} onClick={() => onPage(page - 1)}>‹</button>
          <button type="button" disabled={page >= pageCount} onClick={() => onPage(page + 1)}>›</button>
        </span>
      ) : null}
    </div>
  );
}
