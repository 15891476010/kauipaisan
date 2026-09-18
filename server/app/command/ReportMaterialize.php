<?php
declare(strict_types=1);

namespace app\command;

use app\service\ReportMaterializer;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;
use think\facade\Db;

/**
 * Rebuild the materialized report rows. Default mode refreshes only the
 * days changed since the last run (suitable for cron); --from/--to forces
 * a full rebuild of a date range.
 */
final class ReportMaterialize extends Command
{
    protected function configure(): void
    {
        $this->setName('report:materialize')->setDescription('刷新代理报表物化数据')
            ->addOption('site', null, Option::VALUE_REQUIRED, '站点ID（默认全部站点）')
            ->addOption('from', null, Option::VALUE_REQUIRED, '起始日期 Y-m-d，指定后执行全量重建')
            ->addOption('to', null, Option::VALUE_REQUIRED, '结束日期 Y-m-d');
    }

    protected function execute(Input $input, Output $output): int
    {
        $materializer = new ReportMaterializer();
        $siteOption = (int)$input->getOption('site');
        $sites = $siteOption > 0
            ? [$siteOption]
            : array_map('intval', Db::name('sites')->whereNull('deleted_at')->column('id'));
        $from = trim((string)$input->getOption('from'));
        $to = trim((string)$input->getOption('to')) ?: date('Y-m-d');

        foreach ($sites as $siteId) {
            $tenantId = (int)Db::name('sites')->where('id', $siteId)->value('tenant_id');
            if ($from !== '') {
                $result = $materializer->rebuild($siteId, $from, $to, $tenantId ?: 1);
            } else {
                $result = $materializer->refreshChangedDays($siteId, $tenantId ?: 1);
            }
            $rows = array_sum($result);
            $output->writeln("site {$siteId}: " . count($result) . " day(s), {$rows} row(s)");
        }
        return 0;
    }
}
