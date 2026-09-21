import assert from 'node:assert/strict';
import test from 'node:test';
import { NO_SHIPMENT, shipmentAdjustedTotal, hasShipmentBreakdown, readShipmentTotals } from '../src/features/reports/reportShipment.ts';

test('参考截图按真实减法计算出货调整', () => {
  assert.equal(shipmentAdjustedTotal('3792990.62', {amount:'48000',winAmount:'0'}), '3744990.62');
  assert.equal(shipmentAdjustedTotal('2468326.3', {amount:'48000',winAmount:'0'}), '2420326.3');
});
test('未知数据与有效零金额区分，不显示假合计', () => {
  assert.equal(shipmentAdjustedTotal('100'), null);
  assert.equal(shipmentAdjustedTotal('100', {amount:'',winAmount:'0'}), null);
  assert.equal(shipmentAdjustedTotal('100', {amount:'-1',winAmount:'0'}), null);
  assert.equal(shipmentAdjustedTotal('100', {amount:'0',winAmount:'0'}), '100');
});
test('小数精度、负值及大额计算不会丢失尾数', () => {
  assert.equal(shipmentAdjustedTotal('0.3', {amount:'0.1',winAmount:'0.2'}), '0.4');
  assert.equal(shipmentAdjustedTotal('-10.125', {amount:'20.125',winAmount:'5.0001'}), '-25.2499');
  assert.equal(shipmentAdjustedTotal('9007199254740993.12', {amount:'0.02',winAmount:'0'}), '9007199254740993.1');
  assert.equal(shipmentAdjustedTotal('0.1', {amount:'0.1',winAmount:'0'}), '0');
});

test('合计非零时显示公式，无出货按零参与计算', () => {
  assert.equal(hasShipmentBreakdown('0'), false);
  assert.equal(hasShipmentBreakdown('-0.0000'), false);
  assert.equal(hasShipmentBreakdown('123'), true);
  assert.equal(hasShipmentBreakdown('-535184.21'), true);
  assert.equal(hasShipmentBreakdown('123', NO_SHIPMENT), true);
  assert.equal(hasShipmentBreakdown('0', {amount:'48000',winAmount:'0'}), false);
  assert.equal(hasShipmentBreakdown('123', {amount:'12',winAmount:''}), false);
  assert.equal(shipmentAdjustedTotal('-535184.21', NO_SHIPMENT), '-535184.21');
});
test('参考站原始汇总适配到前端公式，不以缺失数据冒充零', () => {
  assert.deepEqual(readShipmentTotals({tba:48000,tpa:0}), {amount:'48000',winAmount:'0'});
  for (const value of [undefined, null, {}, {tba:48000}, {tba:'',tpa:0}, {tba:-1,tpa:0}])
    assert.equal(readShipmentTotals(value), undefined);
  assert.equal(shipmentAdjustedTotal('3792990.62', readShipmentTotals({tba:'48000',tpa:'0'})), '3744990.62');
});
