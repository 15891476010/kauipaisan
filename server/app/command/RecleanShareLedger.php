<?php
declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;
use think\facade\Db;

/**
 * Recompute historical settlement_share ledger rows under the reference
 * accounting model: a node's booked share is its share P/L minus the site
 * water charged on the occupied amount (edge rate × arriving residual
 * fraction × record turnover). The rows are bookkeeping-only — settlement
 * never moved organization balances — so rewriting them realigns the stored
 * record without touching money. A backup table is created before any
 * update; run without --apply for a dry-run summary.
 */
final class RecleanShareLedger extends Command
{
    protected function configure(): void
    {
        $this->setName('ledger:reclean-share')->setDescription('按新口径重算历史占成入账（扣除水钱）')
            ->addOption('site', null, Option::VALUE_REQUIRED, '站点ID（默认全部站点）')
            ->addOption('apply', null, Option::VALUE_NONE, '实际写入（默认仅预览）');
    }

    protected function execute(Input $input, Output $output): int
    {
        $apply = (bool)$input->getOption('apply');
        $siteOption = (int)$input->getOption('site');
        $sites = $siteOption > 0
            ? [$siteOption]
            : array_map('intval', Db::name('sites')->whereNull('deleted_at')->column('id'));

        foreach ($sites as $siteId) {
            $settings = Db::name('sites')->where('id', $siteId)->value('settings');
            $settings = is_string($settings) ? json_decode($settings, true) : (is_array($settings) ? $settings : []);
            $waterRate = max(0, min(1, (float)($settings['water_rate'] ?? $settings['dark_water_rate'] ?? 0.085)));

            $rows = Db::name('organization_credit_ledger')->alias('l')
                ->join('bet_records r', 'r.id=l.related_bet_record_id')
                ->leftJoin('bet_details d', 'd.bet_record_id=r.id')
                ->where('l.site_id', $siteId)->where('l.source_type', 'settlement_share')
                ->field('l.id,l.organization_id,l.direction,l.amount,l.metadata,r.amount AS turnover,r.win_amount,SUM(d.rebate) AS rebate')
                ->group('l.id')
                ->select()->toArray();

            $changed = 0; $skipped = 0; $totalWater = 0.0; $updates = [];
            foreach ($rows as $row) {
                $meta = is_string($row['metadata'] ?? null) ? (json_decode((string)$row['metadata'], true) ?: []) : [];
                $incoming = (float)($meta['incoming_amount'] ?? 0);
                $houseProfit = (float)$row['turnover'] - (float)$row['win_amount'] - (float)$row['rebate'];
                if (abs($houseProfit) < 0.000001 || abs($incoming) < 0.000001) { $skipped++; continue; }
                $arriveRatio = $incoming / $houseProfit;
                $occupied = ((float)($meta['share_rate'] ?? 0)) / 100 * $arriveRatio * (float)$row['turnover'];
                $water = round($waterRate * $occupied, 2);
                if (abs($water) < 0.005) { $skipped++; continue; }
                $signed = ((string)$row['direction'] === 'in' ? 1.0 : -1.0) * (float)$row['amount'];
                $new = round($signed - $water, 2);
                $meta['occupied_amount'] = $occupied;
                $meta['water_cost'] = $water;
                $updates[] = [
                    'id' => (int)$row['id'],
                    'direction' => $new >= 0 ? 'in' : 'out',
                    'amount' => number_format(abs($new), 2, '.', ''),
                    'metadata' => json_encode($meta, JSON_UNESCAPED_UNICODE),
                ];
                $totalWater += $water;
                $changed++;
            }

            $output->writeln("site {$siteId}: {$changed} 条需调整，{$skipped} 条跳过，合计扣水 {$totalWater}（水钱率 {$waterRate}）");
            if (!$apply || $updates === []) continue;

            $backup = 'organization_credit_ledger_bak_' . date('YmdHis');
            Db::execute("CREATE TABLE `{$backup}` AS SELECT * FROM `organization_credit_ledger` WHERE site_id=? AND source_type='settlement_share'", [$siteId]);
            $output->writeln("已备份到 {$backup}");

            Db::startTrans();
            try {
                foreach ($updates as $u) {
                    Db::name('organization_credit_ledger')->where('id', $u['id'])->update([
                        'direction' => $u['direction'],
                        'amount' => $u['amount'],
                        'metadata' => $u['metadata'],
                    ]);
                }
                Db::commit();
            } catch (\Throwable $e) {
                Db::rollback();
                $output->writeln("site {$siteId} 更新失败已回滚: " . $e->getMessage());
                return 1;
            }
            $output->writeln("site {$siteId}: 已更新 {$changed} 条");
        }
        return 0;
    }
}
