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
 * record without touching money. Rows stream in id-ordered chunks so the
 * command survives production volumes; a backup table is created before any
 * update. Run without --apply for a dry-run summary.
 */
final class RecleanShareLedger extends Command
{
    private const CHUNK = 500;

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

            $changed = 0; $skipped = 0; $totalWater = 0.0; $scanned = 0; $lastId = 0; $backupDone = false;
            while (true) {
                $rows = Db::name('organization_credit_ledger')
                    ->where('site_id', $siteId)->where('source_type', 'settlement_share')
                    ->where('id', '>', $lastId)->order('id')->limit(self::CHUNK)
                    ->field('id,related_bet_record_id,organization_id,direction,amount,metadata')
                    ->select()->toArray();
                if ($rows === []) break;

                $recordIds = array_values(array_unique(array_map(static fn(array $r): int => (int)$r['related_bet_record_id'], $rows)));
                $records = Db::name('bet_records')->whereIn('id', $recordIds)->field('id,amount,win_amount')->select()->toArray();
                $recordMap = [];
                foreach ($records as $rec) $recordMap[(int)$rec['id']] = $rec;
                $rebates = [];
                foreach (Db::name('bet_details')->whereIn('bet_record_id', $recordIds)
                    ->field('bet_record_id,SUM(rebate) AS rb')->group('bet_record_id')->select()->toArray() as $rb) {
                    $rebates[(int)$rb['bet_record_id']] = (float)$rb['rb'];
                }

                $updates = [];
                foreach ($rows as $row) {
                    $lastId = (int)$row['id'];
                    $scanned++;
                    $record = $recordMap[(int)$row['related_bet_record_id']] ?? null;
                    if ($record === null) { $skipped++; continue; }
                    $meta = is_string($row['metadata'] ?? null) ? (json_decode((string)$row['metadata'], true) ?: []) : [];
                    if (isset($meta['water_cost'])) { $skipped++; continue; }
                    $incoming = (float)($meta['incoming_amount'] ?? 0);
                    $houseProfit = (float)$record['amount'] - (float)$record['win_amount'] - (float)($rebates[(int)$row['related_bet_record_id']] ?? 0);
                    if (abs($houseProfit) < 0.000001 || abs($incoming) < 0.000001) { $skipped++; continue; }
                    $arriveRatio = $incoming / $houseProfit;
                    $occupied = ((float)($meta['share_rate'] ?? 0)) / 100 * $arriveRatio * (float)$record['amount'];
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

                if ($apply && $updates !== []) {
                    if (!$backupDone) {
                        $backup = 'organization_credit_ledger_bak_' . date('YmdHis');
                        Db::execute("CREATE TABLE `{$backup}` AS SELECT * FROM `organization_credit_ledger` WHERE site_id=? AND source_type='settlement_share'", [$siteId]);
                        $output->writeln("site {$siteId}: 已备份到 {$backup}");
                        $backupDone = true;
                    }
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
                        $output->writeln("site {$siteId} 更新失败已回滚（本条 chunk 内）: " . $e->getMessage());
                        return 1;
                    }
                }
                if ($scanned % 50000 === 0) $output->writeln("site {$siteId}: 已扫描 {$scanned}…");
            }

            $output->writeln("site {$siteId}: 扫描 {$scanned} 条，{$changed} 条需调整，{$skipped} 条跳过，合计扣水 " . round($totalWater, 2) . "（水钱率 {$waterRate}）" . ($apply ? "，已写入" : "，预览未写入"));
        }
        return 0;
    }
}
