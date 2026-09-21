import assert from 'node:assert/strict';
import test from 'node:test';
import { renderToStaticMarkup } from 'react-dom/server';
import { reportNumber } from '../src/features/reports/reportNumber.ts';

test('only the decimal point and fraction receive the decimal class', () => {
  for (const [value, expected] of [
    ['1234.56', '1234<span class="report-decimal">.56</span>'],
    ['-1234.56', '-1234<span class="report-decimal">.56</span>'],
    ['0.05', '0<span class="report-decimal">.05</span>'],
    ['-0.01', '-0<span class="report-decimal">.01</span>'],
    ['12.50', '12<span class="report-decimal">.5</span>'],
    [12.34, '12<span class="report-decimal">.34</span>'],
  ] as const) assert.equal(renderToStaticMarkup(reportNumber(value)), expected);
});

test('integers, zero, trailing-zero normalization and invalid fallback stay unchanged', () => {
  for (const value of [0, '0.00', '-0.00', 42, '42.00', -42, '', 'invalid', Infinity, NaN]) {
    const expected = Number.isFinite(Number(value)) ? String(Number(value)) : '0';
    assert.equal(renderToStaticMarkup(reportNumber(value)), expected);
  }
});
