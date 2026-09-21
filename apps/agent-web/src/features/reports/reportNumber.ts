import { createElement, Fragment } from 'react';

export function reportNumber(value: string | number) {
  const text = Number.isFinite(Number(value)) ? String(Number(value)) : '0';
  const point = text.indexOf('.');
  return point < 0 ? text : createElement(Fragment, null,
    text.slice(0, point),
    createElement('span', { className: 'report-decimal' }, text.slice(point)),
  );
}
