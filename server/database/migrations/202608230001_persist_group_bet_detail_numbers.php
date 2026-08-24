<?php
declare(strict_types=1);

use app\service\QuickEntryRules;
use think\facade\Db;
use think\migration\Migrator;

/**
 * Replace the legacy 000 placeholder for persisted multi-code group tickets.
 *
 * The detail amount, odds, status and settlement totals are deliberately left
 * untouched.  A small rollback journal makes this data-only migration
 * reversible without relying on the original source text remaining present.
 */
final class PersistGroupBetDetailNumbers extends Migrator
{
    private const KEY = '202608230001';

    public function up(): void
    {
        $this->execute("CREATE TABLE IF NOT EXISTS `bet_detail_number_backups` (
            `migration_key` VARCHAR(32) NOT NULL,
            `detail_id` BIGINT UNSIGNED NOT NULL,
            `original_number_text` TEXT NOT NULL,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`migration_key`,`detail_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->execute("CREATE TABLE IF NOT EXISTS `bet_detail_stop_number_backups` (
            `migration_key` VARCHAR(32) NOT NULL,
            `stop_id` BIGINT UNSIGNED NOT NULL,
            `original_number_text` TEXT NOT NULL,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`migration_key`,`stop_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $rows = Db::name('bet_details')->alias('d')
            ->leftJoin('user_stop_drops s', 's.bet_detail_id=d.id')
            ->leftJoin('bet_records r', 'r.id=d.bet_record_id')
            ->where('d.number_text', '000')
            ->field('d.id,d.number_text,d.source_text AS detail_source,s.source_text AS stop_source,r.source_text AS record_source')
            ->select()->toArray();
        $seen = [];
        foreach ($rows as $row) {
            $detailId = (int)($row['id'] ?? 0);
            if ($detailId < 1 || isset($seen[$detailId])) continue;
            $seen[$detailId] = true;
            $source = trim((string)($row['detail_source'] ?? ''));
            if ($source === '') $source = trim((string)($row['stop_source'] ?? ''));
            if ($source === '') $source = trim((string)($row['record_source'] ?? ''));
            $tokens = $this->groupTokens($source);
            if ($tokens === []) continue;

            Db::transaction(function () use ($detailId, $tokens): void {
                $now = date('Y-m-d H:i:s');
                $detail = Db::name('bet_details')->where('id', $detailId)->lock(true)->find();
                if (!$detail || trim((string)($detail['number_text'] ?? '')) !== '000') return;
                $already = Db::name('bet_detail_number_backups')
                    ->where('migration_key', self::KEY)->where('detail_id', $detailId)->find();
                if (!$already) {
                    Db::name('bet_detail_number_backups')->insert([
                        'migration_key' => self::KEY,
                        'detail_id' => $detailId,
                        'original_number_text' => (string)$detail['number_text'],
                        'created_at' => $now,
                    ]);
                }
                $stopRows = Db::name('user_stop_drops')->where('bet_detail_id', $detailId)->lock(true)->select()->toArray();
                foreach ($stopRows as $stop) {
                    $stopId = (int)$stop['id'];
                    $stopBackup = Db::name('bet_detail_stop_number_backups')
                        ->where('migration_key', self::KEY)->where('stop_id', $stopId)->find();
                    if (!$stopBackup) {
                        Db::name('bet_detail_stop_number_backups')->insert([
                            'migration_key' => self::KEY,
                            'stop_id' => $stopId,
                            'original_number_text' => (string)$stop['number_text'],
                            'created_at' => $now,
                        ]);
                    }
                    Db::name('user_stop_drops')->where('id', $stopId)->update(['number_text' => implode(' ', $tokens)]);
                }
                Db::name('bet_details')->where('id', $detailId)->update(['number_text' => implode(' ', $tokens)]);
            });
        }
    }

    /** @return array<int,string> */
    private function groupTokens(string $source): array
    {
        $source = (new QuickEntryRules())->normalize($source);
        if (!preg_match('/(?<!\d)(\d{3,10})\s*(组三|组六)\s*([一二两三四五六七八九1-9])码/u', $source, $match)) return [];
        $digits = [];
        foreach (str_split((string)$match[1]) as $digit) if (!in_array($digit, $digits, true)) $digits[] = $digit;
        $family = (string)$match[2];
        if ($family === '组六') {
            if (count($digits) < 4) return [];
            return $this->groupSix($digits);
        }
        if (count($digits) < 3) return [];
        return $this->groupThree($digits);
    }

    /** @param array<int,string> $digits @return array<int,string> */
    private function groupSix(array $digits): array
    {
        $result = [];
        for ($a = 0, $n = count($digits); $a < $n - 2; $a++) {
            for ($b = $a + 1; $b < $n - 1; $b++) {
                for ($c = $b + 1; $c < $n; $c++) $result[] = $digits[$a].$digits[$b].$digits[$c];
            }
        }
        return $result;
    }

    /** @param array<int,string> $digits @return array<int,string> */
    private function groupThree(array $digits): array
    {
        $result = [];
        foreach ($digits as $pair) foreach ($digits as $single) if ($pair !== $single) $result[] = $pair.$pair.$single;
        return $result;
    }

    public function down(): void
    {
        $details = Db::name('bet_detail_number_backups')->where('migration_key', self::KEY)->select()->toArray();
        foreach ($details as $row) Db::name('bet_details')->where('id', (int)$row['detail_id'])->update(['number_text' => $row['original_number_text']]);
        $stops = Db::name('bet_detail_stop_number_backups')->where('migration_key', self::KEY)->select()->toArray();
        foreach ($stops as $row) Db::name('user_stop_drops')->where('id', (int)$row['stop_id'])->update(['number_text' => $row['original_number_text']]);
        $this->execute("DROP TABLE IF EXISTS `bet_detail_stop_number_backups`");
        $this->execute("DROP TABLE IF EXISTS `bet_detail_number_backups`");
    }
}
