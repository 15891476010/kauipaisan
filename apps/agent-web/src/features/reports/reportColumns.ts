import type { AgentReportLevel, AgentReportMetrics } from '../../api/user';

type MetricKey = Exclude<keyof AgentReportMetrics, 'levels'>;
export type ReportColumn = {
  key: string;
  title: string;
  value: (metrics: AgentReportMetrics) => string | number;
};
export type ReportColumnGroup = {
  key: string;
  label: string;
  className: string;
  relation: AgentReportLevel['relation'] | 'member';
  columns: ReportColumn[];
};

function metric(title: string, key: MetricKey): ReportColumn {
  return { key, title, value: (metrics) => metrics[key] };
}

function levelMetric(title: string, level: string, key: 'water' | 'profit' | 'amount'): ReportColumn {
  return { key, title, value: (metrics) => metrics.levels?.[level]?.[key] ?? '0' };
}

const labelFor = (level: AgentReportLevel) => level.key === 'small_shareholder' ? '股东' : level.label;

// 列组、表头和取值共用此配置，避免下钻或隐藏列后表头与金额错位。
export function reportColumnGroups(reportLevels: AgentReportLevel[]): ReportColumnGroup[] {
  const self = reportLevels.find((level) => level.relation === 'self')
    ?? { key: 'agent', label: '代理', relation: 'self' as const };
  const downline = reportLevels.filter((level) => level.relation === 'downline').at(-1);
  const upline = reportLevels.find((level) => level.relation === 'upline');
  const memberColumns = [
    metric('笔数', 'bet_count'), metric('总投', 'amount'), metric('总中', 'win_amount'),
    ...(self.key === 'agent' ? [metric('总赚水', 'water')] : []),
    metric('盈亏', 'member_profit'),
  ];
  const groups: ReportColumnGroup[] = [
    { key: 'member', label: '会员', relation: 'member', className: 'member-group', columns: memberColumns },
  ];
  if (downline) groups.push({
    key: downline.key, label: labelFor(downline), relation: 'downline', className: 'platform-group',
    columns: [
      // 参考站下级组的总投为扣除下级占成后交到本级的金额。
      metric('总投', 'viewer_amount'),
      levelMetric('总赚水', downline.key, 'water'),
      levelMetric('盈亏', downline.key, 'profit'),
    ],
  });
  const shareColumns = [metric('占成金额', 'share_amount'), metric('占成盈亏', 'share_profit')];
  const waterColumns = self.key === 'director'
    ? [metric('承担赚水', 'agent_water')]
    : self.key === 'agent'
      ? [metric('离线反水', 'offline_water'), metric('总赚水', 'agent_water')]
      : [metric('总赚水', 'agent_water'), metric('离线反水', 'offline_water')];
  groups.push({
    key: self.key, label: labelFor(self), relation: 'self', className: 'agent-group',
    columns: [...shareColumns, ...waterColumns, metric('总盈亏', 'agent_profit')],
  });
  if (upline) groups.push({
    key: upline.key, label: labelFor(upline), relation: 'upline', className: 'platform-group',
    columns: [levelMetric('总投', upline.key, 'amount'), levelMetric('盈亏', upline.key, 'profit')],
  });
  return groups;
}
