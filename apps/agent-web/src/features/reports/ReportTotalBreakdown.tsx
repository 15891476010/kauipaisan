import { InfoCircleOutlined } from '@ant-design/icons';
import { Modal } from 'antd';
import { useState } from 'react';
import { reportNumber } from './reportNumber';
import { NO_SHIPMENT, hasShipmentBreakdown, shipmentAdjustedTotal, type ShipmentTotals } from './reportShipment';
import './ReportTotalBreakdown.css';

type Props = { title: string; base: string | number; shipment?: ShipmentTotals };

function Amount({ value, prefix = '' }: { value: string | number | null; prefix?: string }) {
  const valid = value !== null && String(value).trim() !== '' && Number.isFinite(Number(value));
  return <span className={valid ? (Number(value) > 0 ? 'report-breakdown-positive' : 'report-breakdown-nonpositive') : 'report-breakdown-missing'}>
    {prefix}{valid ? reportNumber(value!) : '—'}
  </span>;
}

function boxClass(value: string | number | null) {
  return Number(value) < 0 ? 'report-breakdown-box report-breakdown-negative' : 'report-breakdown-box';
}

export function ReportTotalBreakdown({ title, base, shipment = NO_SHIPMENT }: Props) {
  const [open, setOpen] = useState(false);
  const label = title === '占成盈亏' ? '占成输赢' : title;
  const adjusted = shipmentAdjustedTotal(base, shipment);
  const amount = shipment?.amount ?? null;
  const win = shipment?.winAmount ?? null;
  if (!hasShipmentBreakdown(base, shipment)) return <>{reportNumber(base)}</>;
  return <>
    <button type="button" className="report-total-breakdown" aria-label={`查看${title}出货明细`} onClick={() => setOpen(true)}>
      <span title={label}><Amount value={base}/></span>
      <span title="减去出货总金额"><Amount value={amount} prefix="−"/></span>
      <span title="加上出货总输赢"><Amount value={win} prefix={win !== null && Number(win) < 0 ? '' : '+'}/></span>
      <span className="report-breakdown-equals">=</span>
      <span title={adjusted === null ? '出货数据未提供，暂不能计算调整后合计' : `${label}（不含出货）`}><Amount value={adjusted}/></span>
    </button>
    <Modal className="report-breakdown-modal" title={<span className="report-breakdown-title"><InfoCircleOutlined/>查看{label}</span>} closable={false} open={open} onCancel={() => setOpen(false)} onOk={() => setOpen(false)} cancelButtonProps={{ style: { display: 'none' } }} okText="知道了" width={1000}>
      <div className="report-breakdown-detail">
        <div className={boxClass(base)}><label>{label}</label><Amount value={base}/></div>
        <span>-</span>
        <div className={boxClass(amount)}><label>出货总金额</label><Amount value={amount}/></div>
        <span>+</span>
        <div className={boxClass(win)}><label>出货总输赢</label><Amount value={win}/></div>
        <span>=</span>
        <div className={boxClass(adjusted)}><label>{label}（不含出货）</label><Amount value={adjusted}/></div>
      </div>
      {adjusted === null && <p className="report-breakdown-note">出货数据尚未提供，“—”表示未知，不代表 0。原报表金额保留在第一行。</p>}
    </Modal>
  </>;
}
