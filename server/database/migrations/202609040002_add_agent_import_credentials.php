<?php
declare(strict_types=1);
use think\migration\Migrator;

final class AddAgentImportCredentials extends Migrator
{
    public function change(): void
    {
        $this->execute("ALTER TABLE `agent_import_batches` ADD COLUMN `created_credentials` JSON NULL AFTER `created_counts`");
    }
}
