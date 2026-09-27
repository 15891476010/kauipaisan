<?php
declare(strict_types=1);
/**
 * KPS report-only regenerator (方案A 精简版 / 只灌报表数据).
 *
 * Per user's revised instruction: DO NOT generate 注单详情 (bet_records/bet_details/
 * user_stop_drops/raw ledger). Compute per-member-per-day-per-lottery aggregates and
 * write directly into report_member_issue (with a synthesized ledger_json snapshot).
 *
 * The summary report (AgentReport::materializedRows) reads report_member_issue for
 * 6/1..(today-1) and RECOMPUTES 占成/明水/庄家盈亏 from amount + win_amount + the
 * per-org snapshot in ledger_json. So a correct row needs only:
 *   amount, win_amount, and ledger_json = [{organization_id,level,share_rate(clamped %),rate_mode:'direct',line_org,booked}]
 *
 * Targets:
 *   - daily total turnover across robots ~= 15,000,000 (each robot ≤ its principal)
 *   - 明水 = turnover × 0.085 (falls out of the reader's water model on a fully-covered chain)
 *   - monthly 庄家盈亏 (Σamount − Σwin): 6=-3,000,000 ; 7=-500,000 ; 8=+500,000 ; 9(1..20)=+800,000
 *
 * Range: 2026-06-01 .. 2026-09-20 (materialized). 2026-09-21 (今天) is served live.
 *
 * Usage:
 *   php robot_report.php          # dry-run: compute & print monthly totals + sample, no writes
 *   php robot_report.php --go     # DESTRUCTIVE: clean stale rows, then insert report_member_issue
 */

use think\facade\Db;

ini_set('memory_limit', '3072M');
date_default_timezone_set('PRC'); // app is UTC+8

require __DIR__ . '/vendor/autoload.php';
$app = new think\App();
$app->initialize();

// ----------------------------------------------------------------------------
// Config
// ----------------------------------------------------------------------------
const SITE = 15;
const TENANT = 1;
const WATER_RATE = 0.085;
const SITE_CAP = 100.0;
const DAILY_TOTAL = 15000000.0;
const EPOCH = '2026-06-01';

// day ranges per month + dealer P&L target (Σamount − Σwin)
$MONTHS = [
    '2026-06' => ['d0' => 1, 'd1' => 30, 'dealer' => -3000000.0],
    '2026-07' => ['d0' => 1, 'd1' => 31, 'dealer' =>  -500000.0],
    '2026-08' => ['d0' => 1, 'd1' => 31, 'dealer' =>   500000.0],
    '2026-09' => ['d0' => 1, 'd1' => 20, 'dealer' =>   800000.0], // 9/1..9/20 (today=9/21 is live)
];

$GO = in_array('--go', $argv, true);

// ----------------------------------------------------------------------------
// Load robots
// ----------------------------------------------------------------------------
$robots = Db::query(
    "SELECT ra.user_id, su.organization_id AS org_id, su.site_id AS su_site,
            (su.balance + su.credit_balance) AS principal
     FROM robot_accounts ra JOIN site_users su ON su.id = ra.user_id
     WHERE ra.site_id = ? ORDER BY ra.user_id",
    [SITE]
);
if (!$robots) { fwrite(STDERR, "no robots found\n"); exit(1); }

$badSite = 0;
$sumPrincipal = 0.0;
foreach ($robots as $r) {
    $sumPrincipal += (float)$r['principal'];
    if ((int)$r['su_site'] !== SITE) $badSite++;
}
$F = DAILY_TOTAL / $sumPrincipal;
fprintf(STDOUT, "robots=%d  Σprincipal=%.2f  f=%.6f  (daily target %.0f)  site!=15:%d\n",
    count($robots), $sumPrincipal, $F, DAILY_TOTAL, $badSite);

// ----------------------------------------------------------------------------
// Org chain + snapshot (constant per org — rates do not change)
// ----------------------------------------------------------------------------
$CHAIN = [];
function getChain(int $orgId): array {
    global $CHAIN;
    if (isset($CHAIN[$orgId])) return $CHAIN[$orgId];
    $chain = [];
    $visited = [];
    $nid = $orgId;
    while ($nid > 0 && !in_array($nid, $visited, true)) {
        $visited[] = $nid;
        $node = Db::query("SELECT id, parent_id, level, balance FROM organization_nodes WHERE id=? AND deleted_at IS NULL", [$nid]);
        if (!$node) break;
        $node = $node[0];
        $share = Db::query(
            "SELECT share_rate FROM organization_profit_shares WHERE child_organization_id=? AND parent_organization_id=? AND status=1 LIMIT 1",
            [(int)$node['id'], (int)$node['parent_id']]
        );
        $chain[] = [
            'id'         => (int)$node['id'],
            'level'      => (string)$node['level'],
            'balance'    => (float)$node['balance'],
            'share_rate' => $share ? (float)$share[0]['share_rate'] : 0.0,
        ];
        $nid = (int)$node['parent_id'];
    }
    return $CHAIN[$orgId] = $chain;
}

/**
 * Build the per-org ledger_json snapshot: one entry per chain node with the
 * SEQUENTIALLY-CLAMPED direct share_rate (percent), matching what the reader's
 * shareRowMetrics expects for rate_mode='direct'. Constant per org.
 */
$SNAP = [];
function snapshotJson(int $orgId): string {
    global $SNAP;
    if (isset($SNAP[$orgId])) return $SNAP[$orgId];
    $chain = getChain($orgId);
    $covered = 0.0;
    $entries = [];
    foreach ($chain as $node) {
        $rate = max(0.0, min(SITE_CAP, min($node['share_rate'], (1.0 - $covered) * 100.0)));
        $covered += $rate / 100.0;
        $entries[] = [
            'organization_id' => $node['id'],
            'level'           => $node['level'],
            'share_rate'      => round($rate, 4),
            'rate_mode'       => 'direct',
            'line_org'        => $orgId,
            'booked'          => 0.0, // unused by the reader; reader recomputes money
        ];
    }
    return $SNAP[$orgId] = json_encode($entries, JSON_UNESCAPED_UNICODE);
}

// ----------------------------------------------------------------------------
// Deterministic helpers
// ----------------------------------------------------------------------------
function hash01(string $s): float { return (crc32($s) % 1000000) / 1000000.0; }
function daysSince(string $ymd): int { return (int)((strtotime($ymd) - strtotime(EPOCH)) / 86400); }
function pad3(int $n): string { return sprintf('%03d', $n); }

// ----------------------------------------------------------------------------
// Clean stale rows (orphans from aborted heavy run)
// ----------------------------------------------------------------------------
if ($GO) {
    echo "\n*** --go : cleaning stale rows ***\n";
    Db::execute("SET FOREIGN_KEY_CHECKS=0");
    foreach (['bet_records','bet_details','user_stop_drops','report_member_issue','bills'] as $t) {
        Db::execute("TRUNCATE TABLE `$t`");
        echo "  truncated $t\n";
    }
    $del = Db::execute("DELETE FROM organization_credit_ledger WHERE related_bet_record_id IS NOT NULL OR source_type IN ('settlement_share','bet')");
    echo "  deleted $del betting ledger rows (kept non-betting)\n";
    Db::execute("SET FOREIGN_KEY_CHECKS=1");
}

// ----------------------------------------------------------------------------
// Main: per month build rows -> distribute win -> insert report_member_issue
// ----------------------------------------------------------------------------
$grand = ['amount' => 0.0, 'win' => 0.0, 'rows' => 0];
$batch = [];
$now = date('Y-m-d H:i:s');

function flushBatch(array &$batch): void {
    if ($batch) { Db::name('report_member_issue')->insertAll($batch); $batch = []; }
}

$sampleShown = false;
foreach ($MONTHS as $ym => $mc) {
    [$yy, $mm] = array_map('intval', explode('-', $ym));
    $rows = [];
    for ($d = $mc['d0']; $d <= $mc['d1']; $d++) {
        $ymd = sprintf('%04d-%02d-%02d', $yy, $mm, $d);
        $off = daysSince($ymd);
        foreach ($robots as $rb) {
            $uid = (int)$rb['user_id'];
            $org = (int)$rb['org_id'];
            $turn = (float)$rb['principal'] * $F;
            // split 福/体 with mild deterministic variance (0.40..0.60)
            $fuFrac = 0.5 + ((crc32($uid . '-' . $off . 's') % 2001) - 1000) / 10000.0;
            foreach ([[0, $fuFrac], [1, 1.0 - $fuFrac]] as [$lot, $frac]) {
                $rows[] = [
                    'uid' => $uid, 'org' => $org, 'lot' => $lot, 'off' => $off, 'ymd' => $ymd,
                    'amount' => round($turn * $frac, 2),
                ];
            }
        }
    }

    // ---- win distribution: exact monthly Σ(amount-win)=dealer, with variance ----
    $sumAmt = 0.0;
    foreach ($rows as $r) $sumAmt += $r['amount'];
    $targetWin = $sumAmt - $mc['dealer'];

    $raw = 0.0;
    foreach ($rows as &$r) {
        $v = (hash01($r['uid'] . '-' . $r['off'] . '-' . $r['lot'] . 'w') - 0.5) * 2.0 * 0.6; // -0.6..+0.6
        $r['win'] = max(0.0, $r['amount'] * (1.0 + $v));
        $raw += $r['win'];
    }
    unset($r);
    $scale = $raw > 0 ? $targetWin / $raw : 0.0;

    $sumWin = 0.0; $maxIdx = -1; $maxA = -1.0;
    foreach ($rows as $i => &$r) {
        $r['win'] = round($r['win'] * $scale, 2);
        $sumWin += $r['win'];
        if ($r['amount'] > $maxA) { $maxA = $r['amount']; $maxIdx = $i; }
    }
    unset($r);
    // exact residual on the biggest-turnover row (invisible)
    $resid = round($targetWin - $sumWin, 2);
    if ($maxIdx >= 0) { $rows[$maxIdx]['win'] = round($rows[$maxIdx]['win'] + $resid, 2); $sumWin += $resid; }

    // ---- build + insert report rows ----
    foreach ($rows as $r) {
        $issue = $r['lot'] === 0 ? ('2026' . pad3(142 + $r['off'])) : ('26' . pad3(142 + $r['off']));
        $lotName = $r['lot'] === 0 ? '福彩3D' : '排列三';
        $dc = max(1, (int)round($r['amount'] / 2500.0));
        $nc = $dc * 8;
        $minute = crc32($r['uid'] . '-' . $r['off'] . '-' . $r['lot'] . 'm') % 1440;
        $placed = sprintf('%s %02d:%02d:00', $r['ymd'], intdiv($minute, 60), $minute % 60);

        if (!$sampleShown && !$GO) {
            fprintf(STDOUT, "sample uid=%d org=%d %s %s amount=%.2f win=%.2f ledger=%s\n",
                $r['uid'], $r['org'], $issue, $lotName, $r['amount'], $r['win'], snapshotJson($r['org']));
            $sampleShown = true;
        }

        if ($GO) {
            $batch[] = [
                'tenant_id' => TENANT, 'site_id' => SITE, 'user_id' => $r['uid'],
                'issue_no' => $issue, 'lottery_name' => $lotName, 'day' => $r['ymd'],
                'settled' => 1, 'detail_count' => $dc, 'number_count' => $nc,
                'amount' => $r['amount'], 'win_amount' => $r['win'], 'rebate' => 0, 'intercepted' => 0,
                'placed_at' => $placed, 'ledger_json' => snapshotJson($r['org']), 'updated_at' => $now,
            ];
            if (count($batch) >= 400) flushBatch($batch);
        }
    }
    if ($GO) flushBatch($batch);

    $dealer = $sumAmt - $sumWin;
    printf("%s (%02d..%02d)  rows=%d  Σamount=%.2f  Σwin=%.2f  dealerP&L=%+.2f (target %+.0f)\n",
        $ym, $mc['d0'], $mc['d1'], count($rows), $sumAmt, $sumWin, $dealer, $mc['dealer']);

    $grand['amount'] += $sumAmt; $grand['win'] += $sumWin; $grand['rows'] += count($rows);
    $rows = null; unset($rows); gc_collect_cycles();
}

printf("\n==== GRAND ====\n report_rows=%d  Σamount=%.2f  Σwin=%.2f  dealerP&L=%+.2f\n",
    $grand['rows'], $grand['amount'], $grand['win'], $grand['amount'] - $grand['win']);

if ($GO) {
    // reset materialize state so a manual incremental pass sees a clean frontier
    $maxLed = (int)Db::name('organization_credit_ledger')->max('id');
    Db::name('report_materialize_state')->where('site_id', SITE)->update([
        'last_bet_record_id' => 0, 'last_ledger_id' => $maxLed,
        'last_stop_drop_id' => 0, 'updated_at' => $now,
    ]);
    $cnt = (int)Db::name('report_member_issue')->where('site_id', SITE)->count();
    echo "\ninserted report_member_issue rows=$cnt  state reset (led=$maxLed)\n";
    echo "WARNING: do NOT run `php think report:materialize` — it force-refreshes today+yesterday from empty raw tables and would wipe 2026-09-20.\n";
    echo "DONE.\n";
} else {
    echo "\n(dry-run only — no writes. add --go to execute)\n";
}
