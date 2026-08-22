<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddBetSubmissionFingerprint extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->table('bet_records')->hasColumn('submission_fingerprint')) {
            $this->execute("ALTER TABLE `bet_records` ADD COLUMN `submission_fingerprint` CHAR(64) NULL AFTER `formatted_text`, ADD INDEX `idx_bet_submission_fingerprint` (`site_id`, `user_id`, `submission_fingerprint`, `created_at`)");
        }
    }

    public function down(): void
    {
        if ($this->table('bet_records')->hasColumn('submission_fingerprint')) {
            $this->execute("ALTER TABLE `bet_records` DROP INDEX `idx_bet_submission_fingerprint`, DROP COLUMN `submission_fingerprint`");
        }
    }
}
