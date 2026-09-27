<?php
declare(strict_types=1);
/**
 * KPS betting-robot report regenerator (方案A / 全量重算).
 *
 * Faithfully reproduces how the robots bet (直选, odds 900, win = unit*900),
 * writes bet_records / bet_details / user_stop_drops / bills and replicates the
 * organization profit-share ledger EXACTLY like BetSettlement::allocateOrganizationProfit,
 * then materializes report_member_issue.
 *
 * Targets (per user's formula):
 *   - total daily turnover across all robots ~= 15,000,000
 *   - each robot daily turnover <= its principal (balance + credit_balance)
 *   - water (明水) = turnover * 0.085 booked in the org-ledger layer
 *   - monthly dealer P&L (庄家盈亏 = Σamount - Σwin):
 *       2026-06 = -3,000,000 ; 2026-07 = -500,000 ; 2026-08 = +500,000 ; 2026-09 = +800,000
 *
 * Usage:
 *   php robot_regen.php            # dry-run: compute & print projected monthly totals, no writes
 *   php robot_regen.php --go       # DESTRUCTIVE: wipe betting tables & regenerate + materialize
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
const ODDS = 900.0;
const DAILY_TOTAL = 15000000.0;

$MONTHS = [
    '2026-06' => ['days' => 30, 'dealer' => -3000000.0],
    '2026-07' => ['days' => 31, 'dealer' =>  -500000.0],
    '2026-08' => ['days' => 31, 'dealer' =>   500000.0],
    '2026-09' => ['days' => 21, 'dealer' =>   800000.0], // 9/1 .. 9/21 (今天)
];
const EPOCH = '2026-06-01';

$GO = in_array('--go', $argv, true);

$UNITS = [50, 60, 70, 80, 90, 100];

// ----------------------------------------------------------------------------
// Load robots: user_id, organization_id, principal
// ----------------------------------------------------------------------------
$robots = Db::query(
    "SELECT ra.user_id, su.organization_id AS org_id, (su.balance + su.credit_balance) AS principal
     FROM robot_accounts ra JOIN site_users su ON su.id = ra.user_id
     WHERE ra.site_id = ? ORDER BY ra.user_id",
    [SITE]
);
if (!$robots) { fwrite(STDERR, "no robots found\n"); exit(1); }
$sumPrincipal = 0.0;
foreach ($robots as $r) $sumPrincipal += (float)$r['principal'];
$F = DAILY_TOTAL / $sumPrincipal; // daily turnover fraction of principal

fprintf(STDOUT, "robots=%d  Σprincipal=%.2f  f=%.6f  (daily target %.0f)\n",
    count($robots), $sumPrincipal, $F, DAILY_TOTAL);

// ----------------------------------------------------------------------------
// Org chain cache: leaf->root list of [id, level, balance, share_rate]
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

/** Replicate SequentialProfitShare::allocate + allocateOrganizationProfit ledger rows. */
function ledgerRows(int $orgId, float $houseProfit, float $turnover): array {
    if (abs($houseProfit) < 0.000001) return [];
    $chain = getChain($orgId);
    if (!$chain) return [];
    $covered = 0.0;
    $rows = [];
    foreach ($chain as $node) {
        $rate = max(0.0, min(SITE_CAP, min($node['share_rate'], (1.0 - $covered) * 100.0)));
        $incoming = round($houseProfit * (1.0 - $covered), 2);
        $shareAmt = round($houseProfit * $rate / 100.0, 2);
        $covered += $rate / 100.0;
        $remaining = round($houseProfit * max(0.0, 1.0 - $covered), 2);
        $occupied = $rate / 100.0 * $turnover;
        $water = round(WATER_RATE * $occupied, 2);
        $amount = round($shareAmt - $water, 2);
        if (abs($amount) < 0.005) continue;
        $rows[] = [
            'org' => $node['id'], 'level' => $node['level'], 'balance' => $node['balance'],
            'rate' => $rate, 'incoming' => $incoming, 'share' => $shareAmt,
            'occupied' => $occupied, 'water' => $water, 'remaining' => $remaining,
            'amount' => $amount,
        ];
    }
    return $rows;
}

// ----------------------------------------------------------------------------
// Deterministic helpers
// ----------------------------------------------------------------------------
function hash01(string $s): float { return (crc32($s) % 1000000) / 1000000.0; }
function daysSince(string $ymd): int {
    return (int)((strtotime($ymd) - strtotime(EPOCH)) / 86400);
}
function pad3(int $n): string { return sprintf('%03d', $n); }

/** Generate K distinct 3-digit numbers deterministically. */
function pickNumbers(int $seed, int $k): array {
    mt_srand($seed);
    $set = [];
    while (count($set) < $k) $set[mt_rand(0, 999)] = true;
    return array_map(fn($n) => pad3($n), array_keys($set));
}

/** Build a robot-day's records: list of [uid,org,lot,unit,K,amount,minute]. */
function genDay(array $robot, float $turnover, int $dayOff): array {
    $uid = (int)$robot['user_id'];
    $org = (int)$robot['org_id'];
    global $UNITS;
    $recs = [];
    $remaining = $turnover;
    $i = 0;
    while ($remaining >= 300.0) {
        $h = "$uid-$dayOff-$i";
        $u = $UNITS[crc32($h . 'u') % count($UNITS)];
        $desired = 1500 + (crc32($h . 'a') % 3500); // 1500..5000
        $k = (int)max(2, min(55, (int)round($desired / $u)));
        $amt = $k * $u;
        if ($amt > $remaining) { $k = (int)floor($remaining / $u); if ($k < 2) break; $amt = $k * $u; }
        $lot = (crc32($h . 'l') % 2); // 0=福,1=体 (weight_fu==weight_ti)
        $minute = crc32($h . 'm') % 1440;
        $recs[] = ['uid' => $uid, 'org' => $org, 'lot' => $lot, 'unit' => $u, 'k' => $k,
                   'amount' => (float)$amt, 'minute' => $minute, 'off' => $dayOff, 'i' => $i];
        $remaining -= $amt;
        $i++;
    }
    return $recs;
}

// ----------------------------------------------------------------------------
// Main: per-month generate -> winner-select -> (dry-run sum | go insert)
// ----------------------------------------------------------------------------
$grand = ['amount' => 0.0, 'win' => 0.0, 'water' => 0.0, 'recs' => 0, 'ledger' => 0];

$recId = 0; $detId = 0; $stopId = 0; $billId = 0;

if ($GO) {
    echo "\n*** --go : WIPING betting tables ***\n";
    Db::execute("SET FOREIGN_KEY_CHECKS=0");
    foreach (['bet_records','bet_details','user_stop_drops','report_member_issue','bills'] as $t) {
        Db::execute("TRUNCATE TABLE `$t`");
        echo "  truncated $t\n";
    }
    $del = Db::execute("DELETE FROM organization_credit_ledger WHERE related_bet_record_id IS NOT NULL OR source_type IN ('settlement_share','bet')");
    echo "  deleted $del betting ledger rows (kept non-betting)\n";
    Db::execute("SET FOREIGN_KEY_CHECKS=1");
}

foreach ($MONTHS as $ym => $mc) {
    [$yy, $mm] = array_map('intval', explode('-', $ym));
    $records = [];
    for ($d = 1; $d <= $mc['days']; $d++) {
        $ymd = sprintf('%04d-%02d-%02d', $yy, $mm, $d);
        $off = daysSince($ymd);
        foreach ($robots as $robot) {
            $turnover = round((float)$robot['principal'] * $F, 2);
            foreach (genDay($robot, $turnover, $off) as $rec) {
                $rec['ymd'] = $ymd;
                $records[] = $rec;
            }
        }
    }

    // ---- winner selection to hit monthly dealer P&L exactly ----
    $sumAmt = 0.0; $potential = 0.0;
    foreach ($records as $rec) { $sumAmt += $rec['amount']; $potential += ODDS * $rec['unit']; }
    $targetWin = $sumAmt - $mc['dealer'];
    $p = $potential > 0 ? max(0.0, min(1.0, $targetWin / $potential)) : 0.0;

    $sumClean = 0.0; $maxWinIdx = -1; $maxWinVal = -1.0;
    foreach ($records as $idx => &$rec) {
        $rec['won'] = hash01($rec['uid'] . '-' . $rec['off'] . '-' . $rec['i'] . 'w') < $p;
        $rec['win'] = 0.0;
        if ($rec['won']) {
            $rec['win'] = round(ODDS * $rec['unit'], 2);
            $sumClean += $rec['win'];
            if ($rec['win'] > $maxWinVal) { $maxWinVal = $rec['win']; $maxWinIdx = $idx; }
        }
    }
    unset($rec);
    // exact residual on the biggest winner (invisible; keeps Σwin exact)
    $residual = round($targetWin - $sumClean, 2);
    if ($maxWinIdx >= 0) {
        $records[$maxWinIdx]['win'] = round($records[$maxWinIdx]['win'] + $residual, 2);
        if ($records[$maxWinIdx]['win'] < 0) {
            // spread deficit across winners (rare)
            $need = -$records[$maxWinIdx]['win'];
            $records[$maxWinIdx]['win'] = 0.0;
            $records[$maxWinIdx]['won'] = false;
            foreach ($records as &$rec) {
                if ($need <= 0) break;
                if ($rec['won'] && $rec['win'] > 0) {
                    $take = min($rec['win'], $need);
                    $rec['win'] = round($rec['win'] - $take, 2);
                    if ($rec['win'] <= 0) { $rec['win'] = 0.0; $rec['won'] = false; }
                    $need -= $take;
                }
            }
            unset($rec);
        }
    }

    $sumWin = 0.0;
    foreach ($records as $rec) $sumWin += $rec['win'];

    // ---- water total (exact, via ledger replication) + optional inserts ----
    $mWater = 0.0; $mLedger = 0;
    $recBatch = []; $detBatch = []; $stopBatch = []; $ledBatch = [];
    $bills = []; // key uid|ymd => [count,amount,win]

    foreach ($records as $rec) {
        $uid = $rec['uid']; $org = $rec['org']; $unit = (float)$rec['unit']; $k = $rec['k'];
        $amount = $rec['amount']; $win = $rec['win']; $won = $rec['won'] && $win > 0;
        $house = $amount - $win;
        $rows = ledgerRows($org, $house, $amount);
        foreach ($rows as $lr) $mWater += $lr['water'];
        $mLedger += count($rows);

        if ($GO) {
            $ymd = $rec['ymd'];
            $issue = $rec['lot'] === 0 ? ('2026' . pad3(142 + $rec['off'])) : ('26' . pad3(142 + $rec['off']));
            $cat = $rec['lot'] === 0 ? '福' : '体';
            $lotName = $rec['lot'] === 0 ? '福彩3D' : '排列三';
            $nums = pickNumbers((int)crc32($uid . '-' . $rec['off'] . '-' . $rec['i'] . 'n'), $k);
            $numJoin = implode(' ', $nums);
            $numText = implode(' ', array_map(fn($n) => $n . '直', $nums));
            $unitInt = rtrim(rtrim(number_format($unit, 2, '.', ''), '0'), '.');
            $recSource = $cat . $numJoin . '直各' . $unitInt . '元';
            $detSource = $numJoin . ' 直各' . number_format($unit, 2, '.', '') . '元 ' . $cat;
            $placedAt = sprintf('%s %02d:%02d:00', $ymd, intdiv($rec['minute'], 60), $rec['minute'] % 60);
            $status = $won ? 'won' : 'unwon';
            $matched = $won ? 1 : 0;

            $recId++; $detId++; $stopId++;
            $fp = hash('sha256', $uid . $issue . $rec['off'] . $rec['i']);

            $recBatch[] = [
                'id' => $recId, 'submission_id' => null, 'tenant_id' => TENANT, 'site_id' => SITE,
                'user_id' => $uid, 'issue_no' => $issue, 'source_text' => $recSource, 'formatted_text' => $recSource,
                'submission_fingerprint' => $fp, 'bet_count' => $k, 'amount' => $amount, 'win_amount' => $win,
                'status' => $status, 'sealed' => 0, 'placed_at' => $placedAt, 'created_at' => $placedAt,
                'board_code' => 'A', 'lottery_name' => null,
            ];
            $detBatch[] = [
                'id' => $detId, 'tenant_id' => TENANT, 'site_id' => SITE, 'user_id' => $uid, 'bet_record_id' => $recId,
                'issue_no' => $issue, 'number_text' => $numText, 'category' => $cat, 'amount' => $amount,
                'odds' => ODDS, 'win_amount' => $win, 'matched_count' => $matched, 'rebate' => 0,
                'status' => $status, 'placed_at' => $placedAt, 'source_text' => $detSource, 'board_code' => 'A',
                'lottery_name' => $lotName,
            ];
            $stopBatch[] = [
                'id' => $stopId, 'tenant_id' => TENANT, 'site_id' => SITE, 'user_id' => $uid, 'bet_detail_id' => $detId,
                'lottery' => $lotName, 'issue_no' => $issue, 'number_text' => $numText, 'play_type' => '直',
                'stop_type' => 'none', 'original_amount' => $amount, 'actual_amount' => $amount, 'stop_amount' => 0,
                'original_odds' => ODDS, 'actual_odds' => ODDS, 'drop_odds' => 0, 'source_text' => $detSource,
                'placed_at' => $placedAt, 'created_at' => $placedAt, 'board_code' => 'A',
            ];
            foreach ($rows as $lr) {
                $ledBatch[] = [
                    'transaction_no' => 'LG' . date('YmdHis') . strtoupper(bin2hex(random_bytes(5))),
                    'tenant_id' => TENANT, 'site_id' => SITE, 'organization_id' => $lr['org'],
                    'account_type' => 'organization', 'account_id' => $lr['org'], 'related_user_id' => $uid,
                    'related_bet_record_id' => $recId, 'related_bet_detail_id' => null, 'issue_no' => $issue,
                    'direction' => $lr['amount'] >= 0 ? 'in' : 'out', 'amount' => abs(round($lr['amount'], 2)),
                    'balance_before' => $lr['balance'], 'balance_after' => $lr['balance'],
                    'reason' => $lr['amount'] >= 0 ? '本期投注盈利占成' : '本期投注亏损承担',
                    'source_type' => 'settlement_share', 'category' => 'settlement',
                    'metadata' => json_encode([
                        'allocation_method' => 'direct_share', 'rate_mode' => 'direct',
                        'line_organization_id' => $org, 'organization_level' => $lr['level'],
                        'incoming_amount' => $lr['incoming'], 'share_rate' => $lr['rate'],
                        'share_amount' => $lr['share'], 'occupied_amount' => $lr['occupied'],
                        'water_cost' => $lr['water'], 'remaining_amount' => $lr['remaining'],
                    ], JSON_UNESCAPED_UNICODE),
                    'created_at' => date('Y-m-d H:i:s'),
                ];
            }
            $bk = $uid . '|' . $ymd;
            if (!isset($bills[$bk])) $bills[$bk] = ['uid' => $uid, 'ymd' => $ymd, 'c' => 0, 'a' => 0.0, 'w' => 0.0];
            $bills[$bk]['c'] += $k; $bills[$bk]['a'] += $amount; $bills[$bk]['w'] += $win;

            if (count($recBatch) >= 2000) { flushBatches($recBatch, $detBatch, $stopBatch, $ledBatch); }
        }
    }
    if ($GO) {
        flushBatches($recBatch, $detBatch, $stopBatch, $ledBatch);
        // bills
        $billRows = [];
        foreach ($bills as $b) {
            $billId++;
            $billRows[] = [
                'id' => $billId, 'tenant_id' => TENANT, 'site_id' => SITE, 'user_id' => $b['uid'],
                'bill_date' => $b['ymd'], 'bet_count' => $b['c'], 'amount' => round($b['a'], 2), 'rebate' => 0,
                'offline_rebate' => 0, 'win_amount' => round($b['w'], 2), 'profit' => round($b['w'] - $b['a'], 2),
                'created_at' => date('Y-m-d H:i:s'),
            ];
            if (count($billRows) >= 2000) { Db::name('bills')->insertAll($billRows); $billRows = []; }
        }
        if ($billRows) Db::name('bills')->insertAll($billRows);
    }

    $dealer = $sumAmt - $sumWin;
    printf("%s  recs=%d  Σamount=%.2f  Σwin=%.2f  dealerP&L=%+.2f (target %+.0f)  water=%.2f  ledger=%d\n",
        $ym, count($records), $sumAmt, $sumWin, $dealer, $mc['dealer'], $mWater, $mLedger);

    $grand['amount'] += $sumAmt; $grand['win'] += $sumWin; $grand['water'] += $mWater;
    $grand['recs'] += count($records); $grand['ledger'] += $mLedger;
    $records = null; unset($records);
    gc_collect_cycles();
}

function flushBatches(array &$rec, array &$det, array &$stop, array &$led): void {
    if ($rec)  { Db::name('bet_records')->insertAll($rec);  $rec = []; }
    if ($det)  { Db::name('bet_details')->insertAll($det);  $det = []; }
    if ($stop) { Db::name('user_stop_drops')->insertAll($stop); $stop = []; }
    if ($led)  { Db::name('organization_credit_ledger')->insertAll($led); $led = []; }
}

printf("\n==== GRAND ====\n recs=%d  Σamount=%.2f  Σwin=%.2f  dealerP&L=%+.2f  water=%.2f  ledgerRows=%d\n",
    $grand['recs'], $grand['amount'], $grand['win'], $grand['amount'] - $grand['win'], $grand['water'], $grand['ledger']);

if ($GO) {
    echo "\n*** materializing report_member_issue 2026-06-01..2026-09-21 ***\n";
    (new \app\service\ReportMaterializer())->rebuild(SITE, '2026-06-01', '2026-09-21', TENANT);
    $maxRec = (int)Db::name('bet_records')->max('id');
    $maxLed = (int)Db::name('organization_credit_ledger')->max('id');
    $maxStop = (int)Db::name('user_stop_drops')->max('id');
    Db::name('report_materialize_state')->where('site_id', SITE)->update([
        'last_bet_record_id' => $maxRec, 'last_ledger_id' => $maxLed,
        'last_stop_drop_id' => $maxStop, 'updated_at' => date('Y-m-d H:i:s'),
    ]);
    echo "  materialized; state -> rec=$maxRec led=$maxLed stop=$maxStop\n";
    echo "DONE.\n";
} else {
    echo "\n(dry-run only — no writes. add --go to execute)\n";
}
