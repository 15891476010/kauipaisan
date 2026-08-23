<?php
declare(strict_types=1);
use think\facade\Db;
use think\migration\Migrator;

final class CreateBetSubmissions extends Migrator
{
    public function up(): void
    {
        $this->execute("CREATE TABLE IF NOT EXISTS `bet_submissions` (`id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,`tenant_id` BIGINT UNSIGNED NOT NULL,`site_id` BIGINT UNSIGNED NOT NULL,`user_id` BIGINT UNSIGNED NOT NULL,`issue_no` VARCHAR(40) NOT NULL,`source_text` TEXT NULL,`formatted_text` TEXT NULL,`submission_fingerprint` CHAR(64) NULL,`bet_count` INT NOT NULL DEFAULT 0,`amount` DECIMAL(12,2) NOT NULL DEFAULT 0,`win_amount` DECIMAL(12,2) NOT NULL DEFAULT 0,`status` VARCHAR(20) NOT NULL DEFAULT 'pending',`sealed` TINYINT NOT NULL DEFAULT 0,`placed_at` DATETIME NOT NULL,`refunded_at` DATETIME NULL,`created_at` DATETIME NOT NULL,INDEX `idx_submission_user_time` (`site_id`,`user_id`,`placed_at`),INDEX `idx_submission_issue` (`site_id`,`issue_no`),INDEX `idx_submission_fingerprint` (`site_id`,`user_id`,`submission_fingerprint`,`created_at`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        if (!$this->table('bet_records')->hasColumn('submission_id')) {
            $this->execute("ALTER TABLE `bet_records` ADD COLUMN `submission_id` BIGINT UNSIGNED NULL AFTER `id`, ADD INDEX `idx_bet_submission` (`submission_id`,`site_id`,`user_id`,`placed_at`)");
        }

        if (!Db::name('bet_submissions')->count()) {
            $rows = Db::name('bet_records')->order('id asc')->select()->toArray();
            $groups = [];
            foreach ($rows as $row) {
                $fingerprint = trim((string)($row['submission_fingerprint'] ?? ''));
                $key = implode('|', [
                    (int)($row['tenant_id'] ?? 0),
                    (int)($row['site_id'] ?? 0),
                    (int)($row['user_id'] ?? 0),
                    $fingerprint !== '' ? $fingerprint : 'legacy',
                    (string)($row['placed_at'] ?? ''),
                    (string)($row['source_text'] ?? ''),
                    (string)($row['formatted_text'] ?? ''),
                ]);
                $groups[$key][] = $row;
            }
            foreach ($groups as $groupRows) {
                $first = $groupRows[0];
                $amount = 0.0;
                $betCount = 0;
                $winAmount = 0.0;
                $status = 'pending';
                $sealed = 0;
                $refundedAt = null;
                $issueNo = (string)($first['issue_no'] ?? '');
                foreach ($groupRows as $row) {
                    $amount += (float)($row['amount'] ?? 0);
                    $betCount += (int)($row['bet_count'] ?? 0);
                    $winAmount += (float)($row['win_amount'] ?? 0);
                    $sealed = max($sealed, (int)($row['sealed'] ?? 0));
                    $rowStatus = (string)($row['status'] ?? 'pending');
                    if ($rowStatus === 'refunded') {
                        $status = 'refunded';
                        $refundedAt = $refundedAt ?: ($row['refunded_at'] ?? null) ?: ($row['placed_at'] ?? null);
                    } elseif ($rowStatus === 'won' && $status === 'pending') {
                        $status = 'won';
                    } elseif ($rowStatus === 'unwon' && $status === 'pending') {
                        $status = 'unwon';
                    }
                    if ($issueNo === '' && isset($row['issue_no'])) $issueNo = (string)$row['issue_no'];
                }
                if ($status !== 'refunded') {
                    $status = $winAmount > 0 ? 'won' : ($status === 'pending' ? 'pending' : $status);
                }
                $submissionId = (int)Db::name('bet_submissions')->insertGetId([
                    'tenant_id' => (int)$first['tenant_id'],
                    'site_id' => (int)$first['site_id'],
                    'user_id' => (int)$first['user_id'],
                    'issue_no' => $issueNo,
                    'source_text' => $first['source_text'] ?? null,
                    'formatted_text' => $first['formatted_text'] ?? null,
                    'submission_fingerprint' => $first['submission_fingerprint'] ?? null,
                    'bet_count' => $betCount,
                    'amount' => number_format($amount, 2, '.', ''),
                    'win_amount' => number_format($winAmount, 2, '.', ''),
                    'status' => $status,
                    'sealed' => $sealed,
                    'placed_at' => $first['placed_at'],
                    'refunded_at' => $refundedAt,
                    'created_at' => $first['created_at'] ?? $first['placed_at'],
                ]);
                Db::name('bet_records')->whereIn('id', array_map(static fn(array $row): int => (int)$row['id'], $groupRows))->update(['submission_id' => $submissionId]);
            }
        }
    }

    public function down(): void
    {
        if ($this->table('bet_records')->hasColumn('submission_id')) {
            $this->execute("ALTER TABLE `bet_records` DROP INDEX `idx_bet_submission`, DROP COLUMN `submission_id`");
        }
        $this->execute('DROP TABLE IF EXISTS `bet_submissions`');
    }
}
