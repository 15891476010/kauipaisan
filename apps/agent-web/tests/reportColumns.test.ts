import assert from 'node:assert/strict';
import test from 'node:test';
import { reportColumnGroups } from '../src/features/reports/reportColumns.ts';
import type { AgentReportMetrics } from '../src/api/user.ts';

test('总监表头与参考站一致：只取紧邻下级，共十二列', () => {
  const groups = reportColumnGroups([
    { key: 'small_shareholder', label: '小股东', relation: 'downline' },
    { key: 'major_shareholder', label: '大股东', relation: 'downline' },
    { key: 'director', label: '总监', relation: 'self' },
  ]);
  assert.deepEqual(groups.map(g => [g.label, g.columns.map(c => c.title)]), [
    ['会员', ['笔数','总投','总中','盈亏']],
    ['大股东', ['总投','总赚水','盈亏']],
    ['总监', ['占成金额','占成盈亏','承担赚水','总盈亏']],
  ]);
  assert.equal(1 + groups.reduce((n,g) => n + g.columns.length, 0), 12);
});

test('大股东下钻为十五列，股东总投和上级总监总投均保留', () => {
  const groups = reportColumnGroups([
    { key: 'small_shareholder', label: '小股东', relation: 'downline' },
    { key: 'major_shareholder', label: '大股东', relation: 'self' },
    { key: 'director', label: '总监', relation: 'upline' },
  ]);
  assert.deepEqual(groups.map(g => [g.label, g.columns.map(c => c.title)]), [
    ['会员', ['笔数','总投','总中','盈亏']],
    ['股东', ['总投','总赚水','盈亏']],
    ['大股东', ['占成金额','占成盈亏','总赚水','离线反水','总盈亏']],
    ['总监', ['总投','盈亏']],
  ]);
});

test('代理视图会员赚水及本级两种水钱次序保持独立', () => {
  const groups = reportColumnGroups([
    { key: 'agent', label: '代理', relation: 'self' },
    { key: 'general_agent', label: '总代理', relation: 'upline' },
  ]);
  assert.deepEqual(groups[0].columns.map(c => c.title), ['笔数','总投','总中','总赚水','盈亏']);
  assert.deepEqual(groups[1].columns.map(c => c.title), ['占成金额','占成盈亏','离线反水','总赚水','总盈亏']);
  const metrics: AgentReportMetrics = {
    bet_count: 3, amount: '1000', win_amount: '20', water: '12.50', member_profit: '-967.50',
    viewer_amount: '950', share_amount: '50', share_profit: '40.50',
    offline_water: '10', agent_water: '15', agent_profit: '65.50',
    platform_amount: '900', platform_profit: '800',
    levels: { general_agent: { amount: '900', water: '0', profit: '800', share_amount: '0', share_profit: '0' } },
  };
  assert.deepEqual(groups[0].columns.map(c => c.value(metrics)), [3,'1000','20','12.50','-967.50']);
  assert.deepEqual(groups[1].columns.map(c => c.value(metrics)), ['50','40.50','10','15','65.50']);
  assert.deepEqual(groups[2].columns.map(c => c.value(metrics)), ['900','800']);
});

test('下级总投绑定上交本级金额，不混入下级占成前的承接额', () => {
  const groups = reportColumnGroups([
    { key: 'major_shareholder', label: '大股东', relation: 'downline' },
    { key: 'director', label: '总监', relation: 'self' },
  ]);
  const metrics: AgentReportMetrics = {
    bet_count: 1, amount: '1000', win_amount: '0', water: '0', member_profit: '-1000',
    viewer_amount: '550', share_amount: '550', share_profit: '503.25',
    offline_water: '0', agent_water: '46.75', agent_profit: '778.75',
    platform_amount: '0', platform_profit: '0',
    levels: { major_shareholder: { amount: '800', water: '68', profit: '-778.75', share_amount: '250', share_profit: '228.75' } },
  };
  assert.deepEqual(groups[1].columns.map(c => c.value(metrics)), ['550','68','-778.75']);
  assert.deepEqual(groups[2].columns.map(c => c.value(metrics)), ['550','503.25','46.75','778.75']);
});
