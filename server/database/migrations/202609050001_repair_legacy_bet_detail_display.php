<?php
declare(strict_types=1);

use think\facade\Db;
use think\migration\Migrator;

/**
 * Repair historical detail rows that were persisted as settlement-expanded
 * numbers before the parser began storing the user's play-level selection.
 *
 * This is deliberately data-only: amounts, odds, status and settlement fields
 * are untouched. Every changed value is journaled so the migration can be
 * rolled back safely.
 */
final class RepairLegacyBetDetailDisplay extends Migrator
{
    private const KEY = '202609050001';

    public function up(): void
    {
        $this->execute("CREATE TABLE IF NOT EXISTS `bet_detail_display_backups` (
            `migration_key` VARCHAR(32) NOT NULL,
            `detail_id` BIGINT UNSIGNED NOT NULL,
            `original_detail_number_text` TEXT NOT NULL,
            `original_stop_number_text` TEXT NULL,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`migration_key`, `detail_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $rows = Db::name('bet_details')->alias('d')
            ->join('bet_records r', 'r.id=d.bet_record_id')
            ->leftJoin('user_stop_drops s', 's.bet_detail_id=d.id')
            ->field('d.id,d.number_text,d.source_text AS detail_source,s.number_text AS stop_number_text,s.play_type,s.source_text AS stop_source,r.source_text AS record_source,r.formatted_text AS formatted_source')
            ->order('d.id asc')->select()->toArray();

        foreach ($rows as $row) {
            $detailId = (int)($row['id'] ?? 0);
            if ($detailId < 1) continue;
            $oldDetail = trim((string)($row['number_text'] ?? ''));
            $oldStop = trim((string)($row['stop_number_text'] ?? ''));
            $play = trim((string)($row['play_type'] ?? ''));
            $replacement = $this->replacement($play, $oldDetail, [
                (string)($row['record_source'] ?? ''),
                (string)($row['formatted_source'] ?? ''),
                (string)($row['detail_source'] ?? ''),
                (string)($row['stop_source'] ?? ''),
            ]);
            if ($replacement === null) continue;
            [$detailNumber, $stopNumber] = $replacement;
            if ($detailNumber === $oldDetail && ($stopNumber === null || $stopNumber === $oldStop)) continue;

            Db::transaction(function () use ($detailId, $oldDetail, $oldStop, $detailNumber, $stopNumber): void {
                $current = Db::name('bet_details')->where('id', $detailId)->lock(true)->find();
                if (!is_array($current)) return;
                $backup = Db::name('bet_detail_display_backups')
                    ->where('migration_key', self::KEY)->where('detail_id', $detailId)->find();
                if (!$backup) {
                    Db::name('bet_detail_display_backups')->insert([
                        'migration_key' => self::KEY,
                        'detail_id' => $detailId,
                        'original_detail_number_text' => (string)($current['number_text'] ?? $oldDetail),
                        'original_stop_number_text' => $oldStop !== '' ? $oldStop : null,
                        'created_at' => date('Y-m-d H:i:s'),
                    ]);
                }
                Db::name('bet_details')->where('id', $detailId)->update(['number_text' => $detailNumber]);
                if ($stopNumber !== null) {
                    Db::name('user_stop_drops')->where('bet_detail_id', $detailId)->update(['number_text' => $stopNumber]);
                }
            });
        }
    }

    /** @return array{0:string,1:?string}|null */
    private function replacement(string $play, string $detail, array $sources): ?array
    {
        // 独胆 was historically stored as plain digits. Keep one token per
        // selected digit and make the display explicit without changing stake.
        if ($play === '独胆') {
            $tokens = preg_split('/[\s,，、]+/u', $detail, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if ($tokens === [] || count(array_filter($tokens, static fn(string $v): bool => preg_match('/^\d$/', $v) !== 1)) > 0) return null;
            $next = implode(' ', array_map(static fn(string $v): string => $v . '胆', $tokens));
            return [$next, $next];
        }

        if (preg_match('/^(组三|组六)([一二两三四五六七八九])码$/u', $play, $match) !== 1) return null;
        $lengths = ['一'=>1,'二'=>2,'两'=>2,'三'=>3,'四'=>4,'五'=>5,'六'=>6,'七'=>7,'八'=>8,'九'=>9];
        $length = (int)($lengths[$match[2]] ?? 0);
        if ($length < 2) return null;
        $tokens = preg_split('/\s+/u', $detail, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($tokens) < 2) return null;
        $union = [];
        foreach ($tokens as $token) {
            if (preg_match('/^\d{3}$/', $token) !== 1) return null;
            $digits = array_values(array_unique(str_split($token)));
            if (count($digits) !== ($match[1] === '组三' ? 2 : 3)) return null;
            foreach ($digits as $digit) $union[$digit] = true;
        }
        if (count($union) !== $length) return null;

        foreach ($sources as $source) {
            $source = trim($source);
            if ($source === '') continue;
            $pattern = '/(?<!\d)([0-9]{' . $length . '})(?!\d)/u';
            if (preg_match_all($pattern, $source, $found) === false) continue;
            foreach ((array)($found[1] ?? []) as $candidate) {
                $selected = array_values(array_unique(str_split((string)$candidate)));
                if (count($selected) !== $length) continue;
                if (array_diff($selected, array_keys($union)) !== [] || array_diff(array_keys($union), $selected) !== []) continue;
                $prefix = $match[1] === '组三' ? '三' : '六';
                $next = $prefix . $candidate;
                return [$next, $next];
            }
        }
        return null;
    }

    public function down(): void
    {
        $rows = Db::name('bet_detail_display_backups')->where('migration_key', self::KEY)->select()->toArray();
        foreach ($rows as $row) {
            $detailId = (int)($row['detail_id'] ?? 0);
            if ($detailId < 1) continue;
            Db::name('bet_details')->where('id', $detailId)->update(['number_text' => (string)$row['original_detail_number_text']]);
            $stop = $row['original_stop_number_text'];
            if ($stop !== null) Db::name('user_stop_drops')->where('bet_detail_id', $detailId)->update(['number_text' => (string)$stop]);
        }
        $this->execute("DROP TABLE IF EXISTS `bet_detail_display_backups`");
    }
}
