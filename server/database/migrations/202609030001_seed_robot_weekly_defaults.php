<?php
declare(strict_types=1);

use think\migration\Migrator;

/** Convert the previous flat monthly defaults to the requested five-week defaults. */
final class SeedRobotWeeklyDefaults extends Migrator
{
    public function up(): void
    {
        $rows = $this->fetchAll('SELECT id, monthly_rules FROM robot_accounts WHERE monthly_rules IS NOT NULL');
        $defaults = [
            ['win_weight' => '80.00', 'max_amount' => '100000.00'],
            ['win_weight' => '70.00', 'max_amount' => '50000.00'],
            ['win_weight' => '50.00', 'max_amount' => '30000.00'],
            ['win_weight' => '20.00', 'max_amount' => '150000.00'],
            ['win_weight' => '10.00', 'max_amount' => '200000.00'],
        ];
        foreach ($rows as $row) {
            $rules = json_decode((string)($row['monthly_rules'] ?? ''), true);
            if (!is_array($rules) || $rules === []) continue;
            $changed = false;
            foreach ($rules as &$rule) {
                if (!is_array($rule) || !isset($rule['month']) || !empty($rule['weeks'])) continue;
                $rule['weeks'] = [];
                foreach ($defaults as $index => $default) {
                    $rule['weeks'][] = ['week' => $index + 1, ...$default];
                }
                // Keep the flat values as a fallback for older code, while
                // the weekly scheduler and new UI use the nested values.
                $changed = true;
            }
            unset($rule);
            if ($changed) {
                $this->execute('UPDATE robot_accounts SET monthly_rules = ? WHERE id = ?', [
                    json_encode($rules, JSON_UNESCAPED_UNICODE),
                    (int)$row['id'],
                ]);
            }
        }
    }

    public function down(): void
    {
        // Keep converted values intact on rollback; removing defaults could
        // silently erase operator changes made after this migration.
    }
}
