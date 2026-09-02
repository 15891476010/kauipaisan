<?php
declare(strict_types=1);

use think\migration\Migrator;

final class CreateRobotRunLogs extends Migrator
{
    public function up(): void
    {
        $this->execute("CREATE TABLE IF NOT EXISTS `robot_run_logs` (
            `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `robot_id` BIGINT UNSIGNED NOT NULL,
            `level` VARCHAR(16) NOT NULL DEFAULT 'info',
            `status` VARCHAR(16) NULL,
            `message` VARCHAR(500) NOT NULL,
            `context` JSON NULL,
            `created_at` DATETIME NOT NULL,
            INDEX `idx_robot_run_logs_robot_id` (`robot_id`, `id`),
            INDEX `idx_robot_run_logs_created_at` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS `robot_run_logs`');
    }
}
