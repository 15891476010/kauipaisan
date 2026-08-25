import { useEffect, useState } from "react";
import { App as AntdApp, Empty, Modal } from "antd";
import dayjs from "dayjs";
import arrowRightIcon from "../../../assets/arrow-right.svg";
import {
  getBetDetails,
  getBetRecords,
  refundBetRecord,
  type BetDetail,
  type BetRecord,
} from "../../../api/user";
import { apiErrorMessage } from "../../../utils/request";
import { displayAmount } from "../shared";

export function SideBetRecords({
  onMore,
  panelRight,
  onToggleSide,
}: {
  onMore: () => void;
  panelRight: boolean;
  onToggleSide: () => void;
}) {
  const { message, modal } = AntdApp.useApp();
  const [records, setRecords] = useState<BetRecord[]>([]);
  const [amountTotal, setAmountTotal] = useState("0.00");
  const [loading, setLoading] = useState(false);
  const [details, setDetails] = useState<BetDetail[]>([]);
  const [detailRecord, setDetailRecord] = useState<BetRecord>();
  const [detailMode, setDetailMode] = useState<"detail" | "numbers">("detail");
  const [detailLoading, setDetailLoading] = useState(false);
  const load = () => {
    const today = dayjs().format("YYYY-MM-DD");
    setLoading(true);
    getBetRecords({ from: today, to: today, page: 1, page_size: 100 })
      .then((response) => {
        setRecords(response.data?.data?.list || []);
        setAmountTotal(response.data?.data?.amount_total || "0.00");
      })
      .catch((error) => {
        setRecords([]);
        setAmountTotal("0.00");
        message.error(apiErrorMessage(error, "下单记录加载失败"));
      })
      .finally(() => setLoading(false));
  };
  useEffect(() => {
    load();
    const refresh = () => load();
    window.addEventListener("bet-records-updated", refresh);
    return () => window.removeEventListener("bet-records-updated", refresh);
  }, []);
  const showRecord = async (record: BetRecord, mode: "detail" | "numbers") => {
    setDetailRecord(record);
    setDetailMode(mode);
    setDetailLoading(true);
    try {
      const result = await getBetDetails({
        submission_id: record.id,
        page: 1,
        page_size: 100,
      });
      setDetails(result.data?.data?.list || []);
    } catch (error) {
      setDetails([]);
      message.error(apiErrorMessage(error, "注单详情加载失败"));
    } finally {
      setDetailLoading(false);
    }
  };
  const refund = (record: BetRecord) => {
    modal.confirm({
      title: "确认退单",
      content: `确定退回该注单，金额 ¥ ${displayAmount(record.amount)} 吗？`,
      okText: "确认退单",
      cancelText: "取消",
      okButtonProps: { danger: true },
      onOk: async () => {
        try {
          await refundBetRecord(record.id);
          message.success("退单成功");
          setDetailRecord(undefined);
          await load();
          window.dispatchEvent(new Event("profile-updated"));
        } catch (error) {
          message.error(apiErrorMessage(error, "退单失败"));
        }
      },
    });
  };
  return (
    <>
      <div className="side-total">
        <span>
          总金额: <b>{amountTotal}</b>
        </span>
        <div className="side-actions">
          <button type="button" onClick={onMore}>
            更多
          </button>
          <button className="side-right" type="button" onClick={onToggleSide}>
            <img
              className={panelRight ? "is-left" : ""}
              src={arrowRightIcon}
              alt=""
              aria-hidden="true"
            />
            {panelRight ? "居左" : "居右"}
          </button>
        </div>
      </div>
      <div className="side-record-list">
        {records.map((record) => (
          <article
            className={`side-record-item${record.status === "refunded" ? " refunded" : ""}`}
            key={record.id}
          >
            <time>{record.placed_at}</time>
            <div className="side-record-text">
              <p title={record.source_text || "-"}>{record.source_text || "-"}</p>
            </div>
            <footer>
              <strong>
                {record.status === "refunded" ? "0.00" : record.amount}
              </strong>
              {record.status !== "refunded" ? (
                <div>
                  <button
                    type="button"
                    className="side-record-action detail"
                    onClick={() => showRecord(record, "detail")}
                  >
                    详
                  </button>
                  <button
                    type="button"
                    className="side-record-action numbers"
                    onClick={() => showRecord(record, "numbers")}
                  >
                    号
                  </button>
                  <button
                    type="button"
                    className="side-record-action refund"
                    disabled={!record.can_refund || loading}
                    title={
                      record.can_refund
                        ? `开奖时间 ${record.open_time}`
                        : "仅开奖前可以退单"
                    }
                    onClick={() => refund(record)}
                  >
                    退
                  </button>
                </div>
              ) : null}
            </footer>
          </article>
        ))}
      </div>
      <Modal
        className="record-detail-modal"
        open={Boolean(detailRecord)}
        title={
          detailRecord
            ? `${detailMode === "detail" ? "注单详情" : "号码"} · ${detailRecord.issue_no}`
            : ""
        }
        footer={null}
        onCancel={() => setDetailRecord(undefined)}
        width={760}
      >
        {detailLoading ? (
          <div className="record-detail-loading">加载中...</div>
        ) : details.length ? (
          <div className="record-detail-list">
            {details.map((detail) => (
              <div className="record-detail-line" key={detail.id}>
                <span className={detail.lottery === "福彩3D" ? "fu" : "ti"}>
                  {detail.lottery === "福彩3D"
                    ? "福"
                    : detail.lottery === "排列三"
                      ? "体"
                      : detail.lottery || "-"}
                </span>
                {detailMode === "detail" ? (
                  <>
                    <b>{detail.category || detail.play_type || "投注"}</b>
                    <p>{detail.source_text || detail.number_text}</p>
                    <strong>¥ {detail.amount}</strong>
                  </>
                ) : (
                  <div className="record-number-chips">
                    {detail.number_text
                      .split(/[\s,，]+/)
                      .filter(Boolean)
                      .map((number, index) => (
                        <i key={`${number}-${index}`}>{number}</i>
                      ))}
                  </div>
                )}
              </div>
            ))}
          </div>
        ) : (
          <Empty image={Empty.PRESENTED_IMAGE_SIMPLE} description="暂无数据" />
        )}
      </Modal>
    </>
  );
}
