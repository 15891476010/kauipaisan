<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

/**
 * Maintains report_member_issue: one pre-grouped row per (member, issue,
 * lottery, settled). The report aggregates these rows instead of scanning
 * bet_details for every request, which keeps multi-month range queries
 * fast even as the betting volume grows.
 *
 * Refresh granularity is one day: a day rebuilds its whole group set, so
 * inserts, settlements, refunds and interception releases all converge to
 * the same persisted state.
 */
final class ReportMaterializer
{
    /**
     * Rebuild every day touched since the last run. Watermarks ride on the
     * monotonically increasing ids of the tables that signal a change:
     * bet_records (new bets, status edits via new rows where available),
     * organization_credit_ledger (settlements and refunds), and
     * agent_interceptions (held amounts). Today and yesterday are always
     * rebuilt because record updates do not carry a reliable timestamp.
     */
    public function refreshChangedDays(int $siteId, int $tenantId = 1): array
    {
        $state = Db::name('report_materialize_state')->where('site_id', $siteId)->find();
        $lastBet = (int)($state['last_bet_record_id'] ?? 0);
        $lastLedger = (int)($state['last_ledger_id'] ?? 0);
        $lastIntercept = (int)($state['last_interception_id'] ?? 0);

        $days = [];
        $maxBet = $lastBet;
        foreach (Db::name('bet_records')->where('site_id', $siteId)->where('id', '>', $lastBet)
            ->field('DISTINCT DATE(placed_at) AS d, MAX(id) AS m')->group('d')->select()->toArray() as $row) {
            if (!empty($row['d'])) $days[(string)$row['d']] = true;
            $maxBet = max($maxBet, (int)$row['m']);
        }
        $maxLedger = $lastLedger;
        foreach (Db::name('organization_credit_ledger')->alias('l')
            ->join('bet_records r', 'r.id=l.related_bet_record_id')
            ->where('l.site_id', $siteId)->where('l.id', '>', $lastLedger)
            ->field('DISTINCT DATE(r.placed_at) AS d, MAX(l.id) AS m')->group('d')->select()->toArray() as $row) {
            if (!empty($row['d'])) $days[(string)$row['d']] = true;
            $maxLedger = max($maxLedger, (int)$row['m']);
        }
        $maxIntercept = $lastIntercept;
        foreach (Db::name('agent_interceptions')->alias('i')
            ->join('bet_details d', 'd.id=i.bet_detail_id')
            ->where('d.site_id', $siteId)->where('i.id', '>', $lastIntercept)
            ->field('DISTINCT DATE(d.placed_at) AS d, MAX(i.id) AS m')->group('d')->select()->toArray() as $row) {
            if (!empty($row['d'])) $days[(string)$row['d']] = true;
            $maxIntercept = max($maxIntercept, (int)$row['m']);
        }
        // Released interceptions flip the released_at flag without a new id;
        // rebuilding the trailing window covers them plus refunded records.
        $days[date('Y-m-d')] = true;
        $days[date('Y-m-d', time() - 86400)] = true;

        ksort($days);
        $refreshed = [];
        foreach (array_keys($days) as $day) $refreshed[$day] = $this->refreshDay($siteId, (string)$day, $tenantId);

        Db::name('report_materialize_state')->where('site_id', $siteId)->delete();
        Db::name('report_materialize_state')->insert([
            'site_id' => $siteId,
            'last_bet_record_id' => $maxBet,
            'last_ledger_id' => $maxLedger,
            'last_interception_id' => $maxIntercept,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return $refreshed;
    }

    /** Rebuild the materialized groups of every day in [from, to]. */
    public function rebuild(int $siteId, string $from, string $to, int $tenantId = 1): array
    {
        $refreshed = [];
        for ($ts = strtotime($from); $ts <= strtotime($to); $ts += 86400) {
            $day = date('Y-m-d', $ts);
            $refreshed[$day] = $this->refreshDay($siteId, $day, $tenantId);
        }
        return $refreshed;
    }

    /**
     * Replace the persisted group set of a single day. Returns the number
     * of materialized rows written.
     */
    public function refreshDay(int $siteId, string $day, int $tenantId = 1): int
    {
        $groups = $this->groupedDay($siteId, $day);
        $intercepts = $this->interceptDay($siteId, $day);
        $ledgers = $this->ledgerDay($siteId, $day);

        Db::name('report_member_issue')->where('site_id', $siteId)->where('day', $day)->delete();
        $now = date('Y-m-d H:i:s');
        $batch = [];
        foreach ($groups as $group) {
            $key = (int)$group['user_id'] . '|' . (string)$group['issue_no'] . '|' . (string)($group['lottery_name'] ?? '');
            $ledger = $ledgers[$key] ?? null;
            $batch[] = [
                'tenant_id' => $tenantId,
                'site_id' => $siteId,
                'user_id' => (int)$group['user_id'],
                'issue_no' => (string)$group['issue_no'],
                'lottery_name' => (string)($group['lottery_name'] ?? ''),
                'day' => $day,
                'settled' => (int)$group['settled'],
                'detail_count' => (int)$group['detail_count'],
                'number_count' => (int)$group['number_count'],
                'amount' => (float)$group['amount'],
                'win_amount' => (float)$group['win_amount'],
                'rebate' => (float)$group['rebate'],
                'intercepted' => (float)($intercepts[$key] ?? 0),
                'placed_at' => $group['placed_at'],
                'ledger_json' => $ledger === null ? null : json_encode($ledger, JSON_UNESCAPED_UNICODE),
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($batch, 500) as $chunk) Db::name('report_member_issue')->insertAll($chunk);
        return count($batch);
    }

    /**
     * Same grouping the report performs on bet_details, but restricted to a
     * single day and split by lottery so a shared issue code across
     * 福彩3D/排列三 never mixes into one row.
     */
    private function groupedDay(int $siteId, string $day): array
    {
        return Db::name('bet_details')->alias('d')
            ->join('bet_records r', 'r.id=d.bet_record_id')
            ->join('site_users u', 'u.id=d.user_id')
            ->where('d.site_id', $siteId)->where('u.site_id', $siteId)->whereNull('u.deleted_at')
            ->where('d.placed_at', '>=', $day . ' 00:00:00')->where('d.placed_at', '<=', $day . ' 23:59:59')
            ->where('r.status', '<>', 'refunded')
            ->field(
                'd.user_id,d.issue_no,' .
                "COALESCE(NULLIF(d.lottery_name,''), r.lottery_name) AS lottery_name," .
                "CASE WHEN r.status IN ('won','unwon') THEN 1 ELSE 0 END AS settled," .
                'COUNT(d.id) AS detail_count,' .
                "SUM(LENGTH(d.number_text)-LENGTH(REPLACE(d.number_text,' ',''))+LENGTH(d.number_text)-LENGTH(REPLACE(REPLACE(d.number_text,',',''),'，',''))+1) AS number_count," .
                'SUM(d.amount) AS amount,SUM(d.win_amount) AS win_amount,SUM(d.rebate) AS rebate,' .
                'MAX(d.placed_at) AS placed_at'
            )->group('d.user_id,d.issue_no,lottery_name,settled')->select()->toArray();
    }

    /** @return array<string,float> intercepted amount per member|issue|lottery */
    private function interceptDay(int $siteId, string $day): array
    {
        $out = [];
        foreach (Db::name('agent_interceptions')->alias('i')
            ->join('bet_details d', 'd.id=i.bet_detail_id')
            ->join('bet_records r', 'r.id=d.bet_record_id')
            ->whereNull('i.released_at')->where('r.site_id', $siteId)->where('r.status', '<>', 'refunded')
            ->where('d.placed_at', '>=', $day . ' 00:00:00')->where('d.placed_at', '<=', $day . ' 23:59:59')
            ->field("r.user_id,r.issue_no,COALESCE(NULLIF(d.lottery_name,''), r.lottery_name) AS lottery_name,SUM(i.intercepted_amount) AS intercepted")
            ->group('r.user_id,r.issue_no,lottery_name')->select()->toArray() as $row) {
            $key = (int)$row['user_id'] . '|' . (string)$row['issue_no'] . '|' . (string)($row['lottery_name'] ?? '');
            $out[$key] = (float)$row['intercepted'];
        }
        return $out;
    }

    /** @return array<string,array<int,array<string,mixed>>> booked share entries per member|issue|lottery */
    private function ledgerDay(int $siteId, string $day): array
    {
        $out = [];
        foreach (Db::name('organization_credit_ledger')->alias('l')
            ->join('bet_records r', 'r.id=l.related_bet_record_id')
            ->where('l.site_id', $siteId)->where('l.source_type', 'settlement_share')->where('r.status', '<>', 'refunded')
            ->where('r.placed_at', '>=', $day . ' 00:00:00')->where('r.placed_at', '<=', $day . ' 23:59:59')
            ->field(
                'r.user_id,r.issue_no,r.lottery_name,l.organization_id,l.direction,SUM(l.amount) AS total,' .
                "JSON_UNQUOTE(JSON_EXTRACT(l.metadata,'$.organization_level')) AS lvl," .
                "JSON_UNQUOTE(JSON_EXTRACT(l.metadata,'$.share_rate')) AS rate," .
                "JSON_UNQUOTE(JSON_EXTRACT(l.metadata,'$.rate_mode')) AS rate_mode," .
                "MAX(JSON_UNQUOTE(JSON_EXTRACT(l.metadata,'$.line_organization_id'))) AS line_org"
            )->group('r.user_id,r.issue_no,r.lottery_name,l.organization_id,l.direction')->select()->toArray() as $row) {
            $key = (int)$row['user_id'] . '|' . (string)$row['issue_no'] . '|' . (string)($row['lottery_name'] ?? '');
            $mode = (string)($row['rate_mode'] ?? '');
            $out[$key][] = [
                'organization_id' => (int)$row['organization_id'],
                'level' => (string)($row['lvl'] ?? ''),
                'share_rate' => (float)($row['rate'] ?? 0),
                'rate_mode' => $mode !== '' ? $mode : 'edge',
                'line_org' => (int)($row['line_org'] ?? 0),
                'booked' => ((string)$row['direction'] === 'in' ? 1.0 : -1.0) * (float)$row['total'],
            ];
        }
        return $out;
    }
}
