<?php
declare(strict_types=1);
use think\migration\Migrator;

final class BackfillUserBusinessTenants extends Migrator
{
    public function up(): void
    {
        foreach (['bet_records','bet_details','user_stop_drops'] as $table) {
            $this->execute("UPDATE `{$table}` AS business INNER JOIN `sites` AS site ON site.id = business.site_id SET business.tenant_id = site.tenant_id WHERE business.tenant_id = 0");
        }
    }

    public function down(): void {}
}
