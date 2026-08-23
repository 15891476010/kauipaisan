<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddFormattedTextToBetRecords extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->table('bet_records')->hasColumn('formatted_text')) {
            $this->execute("ALTER TABLE `bet_records` ADD COLUMN `formatted_text` TEXT NULL AFTER `source_text`");
        }
        $this->execute("UPDATE `bet_records` SET `formatted_text` = `source_text` WHERE `formatted_text` IS NULL");
    }

    public function down(): void
    {
        if ($this->table('bet_records')->hasColumn('formatted_text')) {
            $this->execute("ALTER TABLE `bet_records` DROP COLUMN `formatted_text`");
        }
    }
}
