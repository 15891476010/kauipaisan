<?php
declare(strict_types=1);

use think\migration\Migrator;

/** Keep a generated ticket when an hourly weight draw misses. */
final class AddRobotPendingTicket extends Migrator
{
    public function up(): void
    {
        $table = $this->table('robot_accounts');
        $table
            ->addColumn('pending_ticket_text', 'text', ['null' => true, 'default' => null])
            ->addColumn('pending_ticket_lottery', 'string', ['limit' => 120, 'null' => true, 'default' => null])
            ->addColumn('pending_ticket_target_issue', 'string', ['limit' => 80, 'null' => true, 'default' => null])
            ->addColumn('pending_ticket_target_draw', 'string', ['limit' => 16, 'null' => true, 'default' => null])
            ->addColumn('pending_ticket_created_at', 'datetime', ['null' => true, 'default' => null])
            ->addColumn('pending_ticket_scheduled_at', 'datetime', ['null' => true, 'default' => null])
            ->update();
    }

    public function down(): void
    {
        $table = $this->table('robot_accounts');
        $table
            ->removeColumn('pending_ticket_text')
            ->removeColumn('pending_ticket_lottery')
            ->removeColumn('pending_ticket_target_issue')
            ->removeColumn('pending_ticket_target_draw')
            ->removeColumn('pending_ticket_created_at')
            ->removeColumn('pending_ticket_scheduled_at')
            ->update();
    }
}
