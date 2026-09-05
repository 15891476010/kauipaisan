import { useEffect, useMemo, useState } from "react";
import { App as AntdApp, Empty, Modal, Switch } from "antd";
import { InfoCircleOutlined, SearchOutlined } from "@ant-design/icons";
import { getBetDetails, getBetRecords, type BetDetail, type BetRecord } from "../../../api/user";
import { apiErrorMessage } from "../../../utils/request";
import { RecordsPagination } from "../components/RecordsPagination";
import { displayAmount } from "../shared";

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

function detailLotteryName(value?: string) {
  if (value === "排列三" || value === "体") return "排列三";
  return "福彩3D";
}

function detailPlayLabel(detail: BetDetail) {
  const raw = String(detail.play_label || detail.play_type || detail.category || "投注");
  const source = `${raw} ${detail.number_text || ""} ${detail.source_text || ""} ${detail.original_source_text || ""} ${detail.record_source || ""}`;
  const family = /组六|组6/u.test(source) ? "组六" : /组三|组3/u.test(source) ? "组三" : "";
  const digits = source.match(/(?<!\d)\d{4,10}(?!\d)/u)?.[0];
  if (family && digits && !/全包|胆拖/u.test(source)) return `${family}多码`;
  if (/胆拖/u.test(source)) return /组六|组6|六组/u.test(source) ? "组六胆拖" : /组三|组3|三组/u.test(source) ? "组三胆拖" : raw;
  if (/^[口Xx]{2}[Xx]$/u.test(raw) || raw === "口口X") return "二码定位";
  if (/^[口Xx][Xx]{2}$/u.test(raw)) return "一码定位";
  if (raw === "直" || raw === "直选" || raw.startsWith("直")) return "直选";
  if (["组", "组三", "组六", "组3", "组6", "组选"].includes(raw)) return "组选";
  return raw;
}

function groupDetails(details: BetDetail[]) {
  const groups = new Map<string, BetDetail[]>();
  details.forEach((detail) => {
    const name = detailLotteryName(detail.lottery);
    groups.set(name, [...(groups.get(name) || []), detail]);
  });
  return Array.from(groups.entries());
}

function groupPlayDetails(details: BetDetail[]) {
  const groups = new Map<string, BetDetail[]>();
  details.forEach((detail) => {
    const name = detailPlayLabel(detail);
    groups.set(name, [...(groups.get(name) || []), detail]);
  });
  return Array.from(groups.entries());
}

function normalizeDetailRows(input: BetDetail[]): BetDetail[] {
  const explicitLeopards = new Set<string>();
  input.forEach((detail) => {
    const source = String(detail.source_text || "");
    if (!source.includes("豹子") || source.includes("豹子全包")) return;
    String(detail.number_text || "").split(/[\s,，、]+/u).forEach((token) => {
      const match = token.match(/^(\d{3})/u);
      if (match && new Set(match[1]).size === 1) explicitLeopards.add(match[1]);
    });
  });
  const result: BetDetail[] = [];
  input.forEach((detail) => {
    const source = String(detail.source_text || "");
    const tokens = String(detail.number_text || "").split(/[\s,，、]+/u).filter(Boolean);
    const rawPlay = String(detail.play_type || detail.play_label || "");
    const isDirect = rawPlay === "直" && tokens.every((token) => /^\d{3}直$/u.test(token));
    const isExplicitLeopard = source.includes("豹子") && !source.includes("豹子全包");
    if (isExplicitLeopard) { result.push({ ...detail, odds: "800" }); return; }
    if (!isDirect || tokens.length < 2) { result.push(detail); return; }
    const leopard = tokens.filter((token) => {
      const number = token.slice(0, 3);
      return new Set(number).size === 1 && (explicitLeopards.size === 0 || explicitLeopards.has(number));
    });
    const normal = tokens.filter((token) => !leopard.includes(token));
    if (leopard.length === 0) { result.push(detail); return; }
    const perAmount = Number(detail.amount || 0) / tokens.length;
    if (normal.length > 0) result.push({ ...detail, number_text: normal.join(" "), amount: (perAmount * normal.length).toFixed(2) });
    if (explicitLeopards.size === 0) result.push({ ...detail, number_text: leopard.join(" "), amount: (perAmount * leopard.length).toFixed(2), odds: "800", source_text: `${leopard.map((token) => token.slice(0, 3)).join(" ")}直豹子各${perAmount}元` });
  });
  return result;
}

function displayDetailNumber(detail: BetDetail, play: string) {
  const source = String(detail.source_text || "");
  const raw = String(detail.play_type || detail.play_label || "");
  const combined = `${detail.number_text || ""} ${source}`.replace(/\s+/gu, "");
  const context = `${play}${combined}`;
  const isDantuo = /胆拖/u.test(context);
  const isPack = /(组三|组六|组3|组6)包/u.test(context);
  if (isDantuo) {
    const family = /(组六|组6)/u.test(context) ? "六" : "三";
    const match = combined.match(/胆?([0-9]+)拖([0-9]+)/u);
    if (match) return `${family}${match[1]}拖${match[2]}`;
  }
  if (isPack) return /(组六|组6)/u.test(context) ? "六包" : "三包";
  const sticky = source.match(/(\d{4,10})\s*(组三|组六)六码/u);
  if (sticky) return `${sticky[2] === "组三" ? "三" : "六"}${sticky[1]}`;
  const sourceContext = `${source} ${detail.original_source_text || ""} ${detail.record_source || ""}`;
  const genericGroup = /(?:^|\s)组(?:各|每|共|合计|计|$)/u.test(sourceContext)
    && !/(组三|组六|组3|组6)/u.test(sourceContext);
  if (genericGroup) {
    return String(detail.number_text || "")
      .replace(/^[三六]/u, "")
      .replace(/(?:组三|组六|组3|组6)组?$|组$/u, "") || "-";
  }
  let value = String(detail.number_text || "");
  if (/复式/u.test(`${play} ${raw}`)) {
    value = value.replace(/复式(?:[一二三四五六七八九\d]+码|多码)?$/u, "");
    return value || "-";
  }
  if (/组3|组6|组三|组六|组选/u.test(raw)) {
    // Keep a meaningful `组三`/`组六` suffix and drop only a duplicated
    // trailing `组` (the red marker is rendered separately).
    value = value.replace(/^[三六]/u, "").replace(/组$/u, "");
  }
  if (/直/u.test(raw) || /码定位$/u.test(play)) value = value.replace(/直+$/u, "");
  if (/^独胆$/u.test(play) || /^独胆$/u.test(raw)) value = value.replace(/胆$/u, "");
  if (play.endsWith("码定位")) value = value.replace(/(?:口口X|口X口|X口口)$/iu, "");
  return value || "-";
}

function detailPlayMark(detail: BetDetail, play: string) {
  const raw = String(detail.play_type || detail.play_label || "");
  if (/码定位/u.test(`${play} ${raw} ${detail.play_label || ""} ${detail.category || ""}`)) return "";
  const context = `${raw} ${detail.number_text || ""} ${detail.source_text || ""} ${detail.original_source_text || ""}`;
  if (/复式/u.test(`${play} ${context}`)) return "";
  if (/胆拖|和值|豹子|包/u.test(context) || /\d{4,10}/u.test(context) && /(?:组三|组六|组3|组6)/u.test(context)) return "";
  if (/口|X/i.test(raw) && !raw.includes("直")) return "";
  if (/直/u.test(raw)) return "直";
  if (/组三|组六|组3|组6|组选|组/u.test(raw)) return "组";
  return play === "独胆" ? "独胆" : raw;
}

function displayDetailOdds(detail: BetDetail): string {
  const context = `${detail.play_type || ""} ${detail.play_label || ""} ${detail.source_text || ""} ${detail.original_source_text || ""}`;
  if (/豹子全包/u.test(context)) return detail.odds || "---";
  const leopard = String(detail.number_text || "").split(/[\s,，、]+/u).some((token) => {
    const number = token.match(/^(\d{3})/)?.[1];
    return Boolean(number && new Set(number).size === 1);
  });
  if (leopard || /豹子/u.test(context)) return "800";
  return detail.odds || "---";
}

export function RecordsPage() {
  const { message } = AntdApp.useApp();
  const dates = useMemo(() => dateOptions(), []);
  const [records, setRecords] = useState<BetRecord[]>([]);
  const [source, setSource] = useState("");
  const [from, setFrom] = useState(dates[0]);
  const [to, setTo] = useState(dates[0]);
  const [status, setStatus] = useState("all");
  const [page, setPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [amountTotal, setAmountTotal] = useState("0.00");
  const [winAmountTotal, setWinAmountTotal] = useState("0.00");
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState("");
  const [detailRecord, setDetailRecord] = useState<BetRecord | null>(null);
  const [details, setDetails] = useState<BetDetail[]>([]);
  const [detailLoading, setDetailLoading] = useState(false);
  const [detailWinningOnly, setDetailWinningOnly] = useState(false);
  const [textRecord, setTextRecord] = useState<BetRecord | null>(null);
  const [textMode, setTextMode] = useState<"original" | "formatted">("original");

  const loadRecords = (
    nextPage = 1,
    range?: { from: string; to: string },
  ) => {
    const queryFrom = range?.from ?? from;
    const queryTo = range?.to ?? to;
    if (queryFrom > queryTo) {
      setError("起始时间不能晚于结束时间");
      message.error("起始时间不能晚于结束时间");
      return;
    }
    const tableWrap = document.querySelector<HTMLElement>(".records-page-table-wrap");
    if (tableWrap) tableWrap.scrollTop = 0;
    setLoading(true);
    setError("");
    getBetRecords({
      source,
      from: queryFrom,
      to: queryTo,
      status: status === "all" ? undefined : status,
      page: nextPage,
      page_size: PAGE_SIZE,
    })
      .then((response) => {
        const data = response.data?.data;
        const nextRecords = data?.list || [];
        const pageAmount = nextRecords.reduce(
          (sum, record) =>
            sum + (record.status === "refunded" ? 0 : Number(record.amount || 0)),
          0,
        );
        const pageWinAmount = nextRecords.reduce(
          (sum, record) =>
            sum + (record.status === "refunded" ? 0 : Number(record.win_amount || 0)),
          0,
        );
        setRecords(nextRecords);
        setTotal(Number(data?.total || 0));
        setAmountTotal(pageAmount.toFixed(2));
        setWinAmountTotal(pageWinAmount.toFixed(2));
        setPage(nextPage);
      })
      .catch((reason) => {
        setRecords([]);
        setTotal(0);
        setAmountTotal("0.00");
        setWinAmountTotal("0.00");
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

  const showTextDetails = (record: BetRecord) => {
    setTextRecord(record);
    setTextMode("original");
  };

  const showDetails = (record: BetRecord, winningOnly = false) => {
    setDetailRecord(record);
    setDetailWinningOnly(winningOnly);
    setDetailLoading(true);
    setDetails([]);
    const detailQuery = {
      submission_id: record.submission_id ?? record.id,
      page_size: 100,
    };
    getBetDetails({ ...detailQuery, page: 1 })
      .then(async (response) => {
        const data = response.data?.data;
        const firstPage = data?.list || [];
        const total = Number(data?.total || firstPage.length);
        const pageSize = Math.max(1, Number(data?.page_size || detailQuery.page_size));
        const pageCount = Math.ceil(total / pageSize);
        if (pageCount <= 1 || firstPage.length >= total) {
          setDetails(normalizeDetailRows(firstPage.slice(0, total)));
          return;
        }
        const remainingPages = await Promise.all(
          Array.from({ length: pageCount - 1 }, (_, index) =>
            getBetDetails({ ...detailQuery, page: index + 2 }),
          ),
        );
        const allDetails = [
          ...firstPage,
          ...remainingPages.flatMap((pageResponse) => pageResponse.data?.data?.list || []),
        ];
        setDetails(normalizeDetailRows(allDetails.slice(0, total)));
      })
      .catch(() => setDetails([]))
      .finally(() => setDetailLoading(false));
  };

  const visibleDetails = detailWinningOnly
    ? details.filter((detail) => Number(detail.win_amount || 0) > 0)
    : details;

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
            onChange={(event) => {
              const nextFrom = event.target.value;
              setFrom(nextFrom);
              loadRecords(1, { from: nextFrom, to });
            }}
          >
            {dates.map((date) => <option key={`from-${date}`} value={date}>{date}</option>)}
          </select>
          <span>至</span>
          <select
            id="records-to"
            value={to}
            onChange={(event) => {
              const nextTo = event.target.value;
              setTo(nextTo);
              loadRecords(1, { from, to: nextTo });
            }}
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
          <button type="submit" disabled={loading} aria-busy={loading}>
            <SearchOutlined /> <span>{loading ? "搜索中" : "搜索"}</span>
          </button>
        </div>
      </form>

      <RecordsPagination page={page} total={total} loading={loading} onPage={changePage} />

      <div className="records-page-table-wrap">
        <div className="records-page-table" role="table" aria-label="投注记录">
          <div className="records-page-head" role="row">
            <span role="columnheader">期号</span>
            <span role="columnheader">金额<br />中奖</span>
            <span role="columnheader">文本</span>
            <span role="columnheader">投注时间</span>
          </div>
          {records.length > 0 ? records.map((record) => {
            const refunded = record.status === "refunded";
            const amount = refunded ? "0" : record.amount || "0";
            const winAmount = refunded ? "0" : record.win_amount || "0";
            return (
            <div className={`records-page-row-data${Number(winAmount) > 0 ? " records-page-row-with-win" : ""}`} role="row" key={record.id}>
              <span className="records-page-issues" role="cell">
                {(!record.lottery || record.lottery.includes("福") || record.lottery === "福彩3D") ? (
                  <span className="records-issue-badge records-issue-fu">
                    <b>福</b><em>{record.issue_no || "-"}</em>
                  </span>
                ) : null}
                {record.lottery?.includes("体") || record.lottery === "排列三" ? (
                  <span className="records-issue-badge records-issue-ti">
                    <b>体</b><em>{record.issue_no || "-"}</em>
                  </span>
                ) : null}
              </span>
              <span className="records-page-amount" role="cell">
                <span className="records-page-amount-line records-page-bet-line">
                  <b>{displayAmount(amount)}</b>
                  <button type="button" className="records-row-detail" onClick={() => showDetails(record)}>详 情</button>
                </span>
                {Number(winAmount) > 0 ? (
                  <span className="records-page-amount-line records-page-win-line">
                    <b>{displayAmount(winAmount)}</b>
                    <button type="button" className="records-row-detail records-row-detail-win" onClick={() => showDetails(record, true)}>详 情</button>
                  </span>
                ) : null}
              </span>
              <span className="records-page-text" role="cell" title={record.source_text || ""}>
                <button type="button" className="records-row-more" onClick={() => showTextDetails(record)}>更 多</button>
              </span>
              <span role="cell">{record.placed_at ? record.placed_at.slice(0, 10) : "-"}</span>
            </div>
            );
          }) : (
            <div className="records-page-empty">
              {loading ? <span className="records-page-spinner" aria-label="加载中" /> : (
                <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description={error || "暂无数据"} />
              )}
            </div>
          )}
          {records.length > 0 ? (
            <div className={`records-page-total${Number(winAmountTotal || 0) > 0 ? " records-page-total-with-win" : ""}`} role="row">
              <span role="cell">合计：</span>
              <span className="records-page-total-amount" role="cell">
                <span className="records-page-total-line records-page-total-bet">
                  <b>{displayAmount(amountTotal === "0.00" ? "0" : amountTotal)}</b>
                </span>
                {Number(winAmountTotal || 0) > 0 ? (
                  <span className="records-page-total-line records-page-total-win">
                    <b>{displayAmount(winAmountTotal)}</b>
                  </span>
                ) : null}
              </span>
              <span role="cell" />
              <span role="cell" />
            </div>
          ) : null}
        </div>
      </div>

      <Modal
        className="records-detail-modal"
        wrapClassName="records-detail-wrap"
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
              <label className="record-detail-winning-label">仅中奖?</label>
              <Switch
                checked={detailWinningOnly}
                checkedChildren="是"
                unCheckedChildren="否"
                className="record-detail-winning-switch"
                onChange={setDetailWinningOnly}
              />
              {groupDetails(visibleDetails).map(([name]) => (
                <span className="selected" key={name}>{name}</span>
              ))}
            </div>
            <div className="record-detail-code-body">
              {groupDetails(visibleDetails).map(([lotteryName, lotteryDetails]) => (
                <section className="record-detail-lottery" key={lotteryName}>
                  <h5 className={lotteryName === "福彩3D" ? "lt-4" : "lt-3"}>{lotteryName}</h5>
                  {groupPlayDetails(lotteryDetails).map(([playName, playDetails]) => (
                    <div className="record-detail-play-group" key={playName}>
                      <label>{playName}</label>
                      <div className="record-detail-data">
                        <div className="record-detail-sub-header">
                          <span>号码</span><span>金额</span><span>赔率</span><span>中奖</span>
                        </div>
                        {playDetails.map((detail, index) => (
                          <div className="record-detail-data-row" key={detail.id + "-" + index}>
                            <span>
                              <span className="record-detail-number">
                                <label>{displayDetailNumber(detail, playName)}</label>
                                {detailPlayMark(detail, playName) ? <em>{detailPlayMark(detail, playName)}</em> : null}
                              </span>
                            </span>
                            <span>{detail.amount || "0"}</span>
                            <span>{displayDetailOdds(detail)}</span>
                            <span>{Number(detail.win_amount || 0) > 0 ? detail.win_amount : "---"}</span>
                          </div>
                        ))}
                      </div>
                    </div>
                  ))}
                </section>
              ))}
            </div>
          </div>
        ) : (
          <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="暂无详情" />
        )}
      </Modal>

      <Modal
        className="records-text-modal"
        wrapClassName="records-text-wrap"
        open={Boolean(textRecord)}
        title={null}
        width={416}
        footer={null}
        onCancel={() => setTextRecord(null)}
      >
        <div className="records-text-title">
          <InfoCircleOutlined />
          <span>文本详情</span>
        </div>
        <div className="records-text-tabs" role="tablist" aria-label="文本类型">
          <span
            className={textMode === "original" ? "selected" : ""}
            role="tab"
            aria-selected={textMode === "original"}
            tabIndex={0}
            onClick={() => setTextMode("original")}
            onKeyDown={(event) => {
              if (event.key === "Enter" || event.key === " ") {
                event.preventDefault();
                setTextMode("original");
              }
            }}
          >原始文本</span>
          <span
            className={textMode === "formatted" ? "selected" : ""}
            role="tab"
            aria-selected={textMode === "formatted"}
            tabIndex={0}
            onClick={() => setTextMode("formatted")}
            onKeyDown={(event) => {
              if (event.key === "Enter" || event.key === " ") {
                event.preventDefault();
                setTextMode("formatted");
              }
            }}
          >文本</span>
        </div>
        <pre>{(textMode === "formatted" ? textRecord?.formatted_text : textRecord?.source_text) || textRecord?.source_text || "-"}</pre>
        <button type="button" className="records-modal-close" onClick={() => setTextRecord(null)}>关 闭</button>
      </Modal>

      <RecordsPagination page={page} total={total} loading={loading} onPage={changePage} />
    </section>
  );
}
