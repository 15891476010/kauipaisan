<?php
declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;

/**
 * Convert organization_profit_shares.share_rate from edge rates (a fraction
 * of the residual book arriving at the node) into direct fractions of the
 * member turnover.
 *
 * direct(node) = edge(node) × Π(1 − edge(d)) for d over the chain below the
 * node on the member path — numerically identical accounting, but the stored
 * value now reads as the node's true slice (e.g. 15 instead of 15.7895).
 *
 * Historical ledger snapshots keep their edge rates and are converted at
 * read time (entries without rate_mode='direct' are treated as legacy).
 *
 * Safe to dry-run (default) and idempotent (--apply twice is a no-op).
 */
class ShareToDirect extends Command
{
    protected function configure(): void
    {
        $this->setName('share:to-direct')
            ->addOption('site', null, Option::VALUE_OPTIONAL, '只处理指定站点 id')
            ->addOption('apply', null, Option::VALUE_NONE, '实际写入（默认干跑预览）');
    }

    protected function execute(Input $input, Output $output): int
    {
        $apply = (bool)$input->getOption('apply');
        $siteOption = $input->getOption('site');

        $siteQuery = Db::name('sites')->whereNull('deleted_at');
        if ($siteOption !== null && $siteOption !== '') $siteQuery->where('id', (int)$siteOption);
        $siteIds = array_map('intval', $siteQuery->column('id'));

        foreach ($siteIds as $siteId) {
            $this->convertSite((int)$siteId, $apply, $output);
        }
        return 0;
    }

    private function convertSite(int $siteId, bool $apply, Output $output): void
    {
        $nodes = [];
        foreach (Db::name('organization_nodes')->where('site_id', $siteId)->whereNull('deleted_at')->field('id,parent_id,level,name')->select()->toArray() as $n) {
            $nodes[(int)$n['id']] = $n;
        }
        $shares = [];
        foreach (Db::name('organization_profit_shares')->where('site_id', $siteId)->where('status', 1)->select()->toArray() as $s) {
            $shares[(int)$s['child_organization_id']] = $s;
        }
        if ($nodes === []) { $output->writeln("site {$siteId}: 无组织节点，跳过"); return; }

        // Residual fraction arriving at each node along member-bearing paths:
        // walk leaf→root for every node that owns members.
        $residual = []; // node id => product of (1−edge) over the chain below it
        $memberOrgs = Db::name('site_users')->where('site_id', $siteId)->whereNull('deleted_at')
            ->where('organization_id', '>', 0)->group('organization_id')->column('organization_id');
        foreach ($memberOrgs as $orgId) {
            $arrive = 1.0;
            $current = (int)$orgId;
            $guard = 0;
            while ($current > 0 && isset($nodes[$current]) && $guard++ < 64) {
                if (!isset($residual[$current])) $residual[$current] = $arrive;
                $edge = isset($shares[$current]) ? (float)$shares[$current]['share_rate'] / 100 : 0.0;
                $arrive *= (1.0 - $edge);
                $current = (int)$nodes[$current]['parent_id'];
            }
        }
        // Nodes not on any member path: fall back to walking down the longest
        // descendant chain so org-only branches still convert.
        foreach ($nodes as $id => $node) {
            if (isset($residual[$id])) continue;
            $arrive = 1.0;
            $down = (int)$id;
            $guard = 0;
            while ($guard++ < 64) {
                $child = null;
                foreach ($nodes as $cand) {
                    if ((int)$cand['parent_id'] === $down) { $child = $cand; break; }
                }
                if ($child === null) break;
                $down = (int)$child['id'];
                $edge = isset($shares[$down]) ? (float)$shares[$down]['share_rate'] / 100 : 0.0;
                if ($down !== (int)$id) $arrive *= (1.0 - $edge); // edges strictly below the node
            }
            $residual[$id] = $arrive;
        }

        $updated = 0;
        foreach ($shares as $childId => $share) {
            $edge = (float)$share['share_rate'];
            $factor = $residual[$childId] ?? 1.0;
            $direct = round($edge * $factor, 4);
            if (abs($direct - $edge) < 0.0001) continue;
            $name = (string)($nodes[$childId]['name'] ?? ('#'.$childId));
            $output->writeln(sprintf('site %d: %s（%s）%s%% → %s%%', $siteId, $name, (string)($nodes[$childId]['level'] ?? ''), number_format($edge, 4, '.', ''), number_format($direct, 4, '.', '')));
            if ($apply) {
                Db::name('organization_profit_shares')->where('id', (int)$share['id'])
                    ->update(['share_rate' => number_format($direct, 4, '.', ''), 'updated_at' => date('Y-m-d H:i:s')]);
            }
            $updated++;
        }
        $output->writeln("site {$siteId}: {$updated} 条占成已" . ($apply ? '换算为直比并写入' : '标记待换算（预览）'));
    }
}
