export type ShipmentTotals = { amount: string; winAmount: string };

// 当前报表未启用出货时使用零值展示公式，不生成或写入出货记录。
export const NO_SHIPMENT: ShipmentTotals = { amount: '0', winAmount: '0' };

// 金额先转为万分之一单位，避免浮点加减产生尾差；缺失数据不能当作零。
function units(value: string | number): bigint | null {
  const match = /^(-?)(\d+)(?:\.(\d{1,4}))?$/.exec(String(value));
  if (!match) return null;
  const amount = BigInt(match[2]) * 10000n + BigInt((match[3] ?? '').padEnd(4, '0'));
  return match[1] ? -amount : amount;
}

export function shipmentAdjustedTotal(base: string | number, shipment?: ShipmentTotals): string | null {
  if (!shipment) return null;
  const original = units(base);
  const amount = units(shipment.amount);
  const winnings = units(shipment.winAmount);
  if (original === null || amount === null || winnings === null || amount < 0n) return null;
  const result = original - amount + winnings;
  const absolute = result < 0n ? -result : result;
  const fraction = String(absolute % 10000n).padStart(4, '0').replace(/0+$/, '');
  return `${result < 0n ? '-' : ''}${absolute / 10000n}${fraction ? '.' + fraction : ''}`;
}

export function hasShipmentBreakdown(base: string | number, shipment: ShipmentTotals = NO_SHIPMENT): boolean {
  const original = units(base);
  return original !== null && original !== 0n && shipmentAdjustedTotal(base, shipment) !== null;
}

// 参考站的独立出货汇总为 chuhuoList.tba/tpa；不从占成或拦货金额反推。
export function readShipmentTotals(value: unknown): ShipmentTotals | undefined {
  if (!value || typeof value !== 'object' || !('tba' in value) || !('tpa' in value)) return undefined;
  if ((typeof value.tba !== 'string' && typeof value.tba !== 'number') ||
      (typeof value.tpa !== 'string' && typeof value.tpa !== 'number')) return undefined;
  const shipment = { amount: String(value.tba), winAmount: String(value.tpa) };
  return shipmentAdjustedTotal('0', shipment) === null ? undefined : shipment;
}
