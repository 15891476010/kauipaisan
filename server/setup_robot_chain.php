#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * One-shot provisioning for a robot betting chain:
 *
 *   总监N -> 大股东N -> 小股东N -> 总代理N -> 代理N -> robot_{5,10,15,20}w
 *
 * Usage:
 *   php setup_robot_chain.php --site=15 --suffix=4 --fund=500000 \
 *       --start='2026-06-01 16:00:00' --lotteries=1,2 --yes
 *
 * Options:
 *   --site         site_id that owns the chain (required)
 *   --suffix       chain name suffix, e.g. 4 => 总监4/大股东4/.../代理4 (required)
 *   --fund         score pushed site -> 总监N and then all the way down to 代理N
 *                  (default 500000; pass 0 to skip funding)
 *   --start        robot start_at / first backfill slot (default 2026-06-01 16:00:00)
 *   --lotteries    comma separated lottery_id list (default 1,2)
 *   --prefix       robot username prefix (default "robot"), yields robot_5w etc.
 *   --yes          skip the confirmation prompt
 *
 * The script is safe to re-run: existing nodes/accounts/users are reused
 * instead of duplicated, so a failed funding step can be retried.
 */

require __DIR__.'/vendor/autoload.php';

use think\facade\Db;

$opts = getopt('', ['site:','suffix:','acct-suffix::','fund::','start::','lotteries::','prefix::','yes::','help::']);
if (isset($opts['help'])) {
    fwrite(STDOUT, "php setup_robot_chain.php --site=<site_id> --suffix=<name_suffix> [--acct-suffix=<login_suffix>] [--fund=500000] [--start='2026-06-01 16:00:00'] [--lotteries=1,2] [--prefix=robot] [--yes]\n");
    exit(0);
}
$siteId = (int)($opts['site'] ?? 0);
$suffix = trim((string)($opts['suffix'] ?? ''));
$acctSuffix = trim((string)($opts['acct-suffix'] ?? $suffix));
$fund   = (float)($opts['fund'] ?? 500000);
$start  = trim((string)($opts['start'] ?? '2026-06-01 16:00:00'));
$lotteryIds = array_values(array_filter(array_map('intval', explode(',', (string)($opts['lotteries'] ?? '1,2')))));
$prefix = trim((string)($opts['prefix'] ?? 'robot'));
$autoYes = array_key_exists('yes', $opts);

if ($siteId < 1 || $suffix === '') {
    fwrite(STDERR, "错误：必须提供 --site 和 --suffix\n");
    exit(1);
}
if (strtotime($start) === false) {
    fwrite(STDERR, "错误：--start 时间格式无效\n");
    exit(1);
}
if ($lotteryIds === []) {
    fwrite(STDERR, "错误：--lotteries 至少要有一个彩种 id\n");
    exit(1);
}
if (!preg_match('/^[A-Za-z0-9_]{1,20}$/', $prefix)) {
    fwrite(STDERR, "错误：--prefix 只允许字母、数字、下划线\n");
    exit(1);
}

$app = new think\App();
$app->initialize();

$site = Db::name('sites')->where('id', $siteId)->whereNull('deleted_at')->find();
if (!$site) {
    fwrite(STDERR, "错误：站点 {$siteId} 不存在\n");
    exit(1);
}
$tenantId = (int)$site['tenant_id'];

// ---------------------------------------------------------------------------
// Chain definition
// ---------------------------------------------------------------------------
$levels = [
    ['level' => 'director',         'name' => '总监'.$suffix,   'account' => 'zongjian'.$acctSuffix,   'code_prefix' => 'DIR', 'share' => 100.0],
    ['level' => 'shareholder',      'name' => '大股东'.$suffix, 'account' => 'dagudong'.$acctSuffix,   'code_prefix' => 'SH',  'share' => 80.0],
    ['level' => 'small_shareholder','name' => '小股东'.$suffix, 'account' => 'xiaogudong'.$acctSuffix, 'code_prefix' => 'SS',  'share' => 80.0],
    ['level' => 'general_agent',    'name' => '总代理'.$suffix, 'account' => 'zongdaili'.$acctSuffix,  'code_prefix' => 'GA',  'share' => 80.0],
    ['level' => 'agent',            'name' => '代理'.$suffix,   'account' => 'daili'.$acctSuffix,      'code_prefix' => 'AG',  'share' => 20.0],
];

$robots = [
    ['volume' => '5w',  'credit' => 50000,  'max_amount' => 2000, 'jun' => [-70000, -50000],   'jul' => [15000, 23000],  'flat' => [-5000, 5000]],
    ['volume' => '10w', 'credit' => 100000, 'max_amount' => 4000, 'jun' => [-140000, -100000], 'jul' => [36000, 46000],  'flat' => [-10000, 10000]],
    ['volume' => '15w', 'credit' => 150000, 'max_amount' => 6000, 'jun' => [-210000, -150000], 'jul' => [54000, 70000],  'flat' => [-15000, 15000]],
    ['volume' => '20w', 'credit' => 200000, 'max_amount' => 8000, 'jun' => [-280000, -200000], 'jul' => [72000, 93000],  'flat' => [-20000, 20000]],
];

$lotteryRows = Db::name('lotteries')->whereIn('id', $lotteryIds)->select()->toArray();
if (count($lotteryRows) !== count($lotteryIds)) {
    fwrite(STDERR, "错误：部分 lottery_id 不存在：".implode(',', $lotteryIds)."\n");
    exit(1);
}
foreach ($lotteryRows as $lot) {
    $linked = Db::name('site_lotteries')->where('site_id', $siteId)->where('lottery_id', (int)$lot['id'])->find();
    if (!$linked) fwrite(STDERR, "警告：站点 {$siteId} 未开通彩种 {$lot['name']}（id={$lot['id']}），机器人可能无法下注\n");
}

$operator = ['type' => 'cli', 'id' => 0, 'name' => 'setup_robot_chain'];
$now = date('Y-m-d H:i:s');

$totalRobotCredit = array_sum(array_map(static fn(array $r): float => $r['credit'], $robots));
if ($fund > 0 && $fund < $totalRobotCredit) {
    fwrite(STDERR, "错误：--fund {$fund} 小于机器人总分配 {$totalRobotCredit}\n");
    exit(1);
}

fwrite(STDOUT, "站点: {$site['name']} (id={$siteId}, tenant={$tenantId})\n");
fwrite(STDOUT, "链路: 总监{$suffix} > 大股东{$suffix} > 小股东{$suffix} > 总代理{$suffix} > 代理{$suffix}\n");
fwrite(STDOUT, "机器人: ".implode(', ', array_map(static fn(array $r): string => $prefix.'_'.$r['volume'].'（每日 '.$r['credit'].'）', $robots))."\n");
fwrite(STDOUT, "链条上分: {$fund}，机器人合计每日量: {$totalRobotCredit}\n");
fwrite(STDOUT, "打单起始: {$start}；彩种: ".implode(',', $lotteryIds)."\n");
if (!$autoYes) {
    fwrite(STDOUT, "确认创建？[y/N] ");
    $answer = trim((string)fgets(STDIN));
    if (strtolower($answer) !== 'y') { fwrite(STDOUT, "已取消\n"); exit(0); }
}

$genNodeCode = static function (string $prefixCode) use ($siteId): string {
    do {
        $code = $prefixCode.'-'.$siteId.'-'.strtoupper(bin2hex(random_bytes(4)));
    } while (Db::name('organization_nodes')->where('site_id', $siteId)->where('code', $code)->find());
    return $code;
};

$copyPermissions = static function (string $level) use ($siteId): string {
    $row = Db::name('organization_nodes')
        ->where('site_id', $siteId)->where('level', $level)->where('status', 1)->whereNull('deleted_at')
        ->order('id asc')->find();
    return is_array($row) ? (string)($row['permissions'] ?? '[]') : '[]';
};

$monthlyRules = static function (array $robot): string {
    $weeksOf = static function (float $min, float $max, float $winWeight): array {
        $weeks = [];
        for ($w = 1; $w <= 5; $w++) {
            $weeks[] = [
                'week' => $w,
                'win_weight' => number_format($winWeight, 2, '.', ''),
                'max_amount' => '100000.00',
                'profit_min' => number_format($min, 2, '.', ''),
                'profit_max' => number_format($max, 2, '.', ''),
            ];
        }
        return $weeks;
    };
    return json_encode([
        ['month' => '2026-06', 'weeks' => $weeksOf($robot['jun'][0], $robot['jun'][1], 60),  'win_weight' => '70.00', 'max_amount' => '100000.00'],
        ['month' => '2026-07', 'weeks' => $weeksOf($robot['jul'][0], $robot['jul'][1], 30),  'win_weight' => '30.00', 'max_amount' => '100000.00'],
        ['month' => '2026-08', 'weeks' => $weeksOf($robot['flat'][0], $robot['flat'][1], 50),'win_weight' => '50.00', 'max_amount' => '100000.00'],
        ['month' => '2026-09', 'weeks' => $weeksOf($robot['flat'][0], $robot['flat'][1], 50),'win_weight' => '50.00', 'max_amount' => '100000.00'],
    ], JSON_UNESCAPED_UNICODE);
};

$hourlyWeights = json_encode([['start' => '16:00', 'end' => '21:00', 'weight' => '100.00']], JSON_UNESCAPED_UNICODE);
$lotteryConfigs = json_encode(array_map(static fn(int $id): array => ['enabled' => true, 'lottery_id' => $id], $lotteryIds), JSON_UNESCAPED_UNICODE);

$nodeIds = [];
$accountCredentials = [];

Db::startTrans();
try {
    // ---- organization_nodes chain (reuse existing same-name nodes) --------
    $parentId = 0;
    $parentPath = '/';
    $depth = 0;
    foreach ($levels as $spec) {
        $depth++;
        $existing = Db::name('organization_nodes')
            ->where('site_id', $siteId)->where('name', $spec['name'])
            ->where('level', $spec['level'])->whereNull('deleted_at')->find();
        if ($existing) {
            $id = (int)$existing['id'];
            if ((int)$existing['parent_id'] !== $parentId) {
                throw new RuntimeException("节点 {$spec['name']} 已存在但上级不一致，请检查后重试");
            }
        } else {
            $id = (int)Db::name('organization_nodes')->insertGetId([
                'tenant_id' => $tenantId,
                'site_id' => $siteId,
                'parent_id' => $parentId,
                'level' => $spec['level'],
                'depth' => $depth,
                'path' => '__pending__',
                'name' => $spec['name'],
                'code' => $genNodeCode($spec['code_prefix']),
                'credit_limit' => $spec['level'] === 'director' ? '9900000.00' : '100000.00',
                'balance' => '0.00',
                'permissions' => $copyPermissions($spec['level']),
                'settings' => '[]',
                'status' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        $path = $parentPath.$id.'/';
        if ((string)($existing['path'] ?? '') !== $path) {
            Db::name('organization_nodes')->where('id', $id)->update(['path' => $path, 'depth' => $depth, 'updated_at' => $now]);
        }
        $nodeIds[$spec['level']] = $id;
        $parentId = $id;
        $parentPath = $path;
    }

    // ---- organization_accounts (reuse existing usernames, keep password) --
    foreach ($levels as $spec) {
        $existing = Db::name('organization_accounts')
            ->where('site_id', $siteId)->where('username', $spec['account'])->whereNull('deleted_at')->find();
        if ($existing) {
            if ((int)$existing['organization_id'] !== $nodeIds[$spec['level']]) {
                throw new RuntimeException("登录账号 {$spec['account']} 已被其他节点（organization_id={$existing['organization_id']}）占用，请换一个 --acct-suffix");
            }
            continue;
        }
        $password = bin2hex(random_bytes(4)).'@'.bin2hex(random_bytes(4));
        Db::name('organization_accounts')->insert([
            'tenant_id' => $tenantId,
            'site_id' => $siteId,
            'organization_id' => $nodeIds[$spec['level']],
            'username' => $spec['account'],
            'display_name' => $spec['name'],
            'phone' => '',
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'must_change_password' => 0,
            'permissions' => $copyPermissions($spec['level']),
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $accountCredentials[$spec['account']] = $password;
    }

    // ---- profit shares (insert only missing edges) ------------------------
    $prevId = 0;
    foreach ($levels as $spec) {
        $edge = Db::name('organization_profit_shares')
            ->where('site_id', $siteId)
            ->where('parent_organization_id', $prevId)
            ->where('child_organization_id', $nodeIds[$spec['level']])
            ->find();
        if (!$edge) {
            Db::name('organization_profit_shares')->insert([
                'tenant_id' => $tenantId,
                'site_id' => $siteId,
                'parent_organization_id' => $prevId,
                'child_organization_id' => $nodeIds[$spec['level']],
                'max_share_rate' => '100.0000',
                'share_rate' => number_format($spec['share'], 4, '.', ''),
                'status' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        $prevId = $nodeIds[$spec['level']];
    }

    Db::commit();
} catch (\Throwable $e) {
    Db::rollback();
    fwrite(STDERR, "创建链路失败：".$e->getMessage()."\n");
    exit(1);
}

fwrite(STDOUT, "组织链路已就绪：".implode(' > ', array_map(static fn(array $s): string => $s['name'].'#'.$nodeIds[$s['level']], $levels))."\n");

// ---- score funding --------------------------------------------------------
$funded = false;
if ($fund > 0) {
    $siteAccount = Db::name('site_credit_accounts')->where('site_id', $siteId)->find();
    $siteBalance = (float)($siteAccount['balance'] ?? 0);
    if ($siteBalance + 0.005 < $fund) {
        fwrite(STDERR, "上分跳过：站点可用分数 {$siteBalance} 不足 {$fund}。先在 SaaS 后台把该站点总分调高，然后重跑此脚本即可继续（已建链路/账号会自动复用）。\n");
    } else {
        try {
            Db::startTrans();
            foreach ($levels as $spec) {
                $node = Db::name('organization_nodes')->where('id', $nodeIds[$spec['level']])->find();
                app\service\ScoreTransfer::organizationAllocation($node, $fund, $operator, '初始化链路分数');
            }
            Db::commit();
            $funded = true;
            fwrite(STDOUT, "链路分数已分配：站点 -> 总监{$suffix} -> ... -> 代理{$suffix} 各 {$fund}\n");
        } catch (\Throwable $e) {
            Db::rollback();
            fwrite(STDERR, "上分失败：".$e->getMessage()."。链路已建好，修复后重跑脚本即可继续。\n");
            exit(1);
        }
    }
}

// ---- robots ---------------------------------------------------------------
$agentNodeId = $nodeIds['agent'];
$createdRobots = [];
foreach ($robots as $robot) {
    $uname = $prefix.'_'.$robot['volume'];
    $existing = Db::name('robot_accounts')
        ->where('site_id', $siteId)->where('username', $uname)->whereNull('converted_at')->find();
    if ($existing) {
        fwrite(STDOUT, "机器人 {$uname} 已存在（robot_id={$existing['id']}），跳过创建\n");
        $createdRobots[] = ['id' => (int)$existing['id'], 'username' => $uname, 'user_id' => (int)$existing['user_id']];
        continue;
    }
    $password = 'R'.bin2hex(random_bytes(5)).'!'.bin2hex(random_bytes(3));
    try {
        Db::startTrans();
        $userId = (int)Db::name('site_users')->insertGetId([
            'tenant_id' => $tenantId,
            'site_id' => $siteId,
            'organization_id' => $agentNodeId,
            'username' => $uname,
            'display_name' => '机器人-'.$robot['volume'],
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'status' => 1,
            'account_state' => 'enabled',
            'must_change_password' => 0,
            'balance' => '0.00',
            'credit_balance' => '0.00',
            'used_balance' => '0.00',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $user = Db::name('site_users')->where('id', $userId)->find();
        if ($funded) {
            app\service\ScoreTransfer::setUserBalances($user, 0, $robot['credit'], $operator);
        } else {
            Db::name('site_users')->where('id', $userId)->update(['credit_balance' => number_format($robot['credit'], 2, '.', '')]);
        }
        $robotId = (int)Db::name('robot_accounts')->insertGetId([
            'tenant_id' => $tenantId,
            'site_id' => $siteId,
            'organization_id' => $agentNodeId,
            'user_id' => $userId,
            'name' => '机器人-'.$robot['volume'],
            'username' => $uname,
            'plain_password' => $password,
            'min_amount' => '100.00',
            'max_amount' => number_format($robot['max_amount'], 2, '.', ''),
            'amount_precision' => 0,
            'start_at' => $start,
            'next_run_at' => $start,
            'interval_min' => 2,
            'interval_max' => 5,
            'weight_fu' => '1.00',
            'weight_ti' => '1.00',
            'weight_futi' => '1.00',
            'lottery_configs' => $lotteryConfigs,
            'skip_windows' => '[]',
            'win_weight' => '50.00',
            'monthly_rules' => $monthlyRules($robot),
            'hourly_weights' => $hourlyWeights,
            'status' => 'stopped',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::commit();
        $createdRobots[] = ['id' => $robotId, 'username' => $uname, 'user_id' => $userId];
        fwrite(STDOUT, "机器人 {$uname} 已创建（robot_id={$robotId}, user_id={$userId}, 每日 {$robot['credit']}）\n");
    } catch (\Throwable $e) {
        Db::rollback();
        fwrite(STDERR, "创建机器人 {$uname} 失败：".$e->getMessage()."\n");
        exit(1);
    }
}

fwrite(STDOUT, "\n===== 完成 =====\n");
fwrite(STDOUT, "代理节点 id: {$agentNodeId}（机器人挂在这里）\n");
if ($accountCredentials !== []) {
    fwrite(STDOUT, "新建组织账号（密码只显示一次，请保存）:\n");
    foreach ($accountCredentials as $u => $p) {
        fwrite(STDOUT, "  {$u} / {$p}\n");
    }
} else {
    fwrite(STDOUT, "组织账号均为已存在账号，未重置密码。\n");
}
fwrite(STDOUT, "\n下一步：\n");
fwrite(STDOUT, "1. 后台把 4 个机器人状态改为 running（或执行：UPDATE robot_accounts SET status='running', next_run_at='{$start}' WHERE id IN (".implode(',', array_column($createdRobots, 'id')).")）\n");
fwrite(STDOUT, "2. 历史回刷：cd server && php think robot:run --backfill\n");
fwrite(STDOUT, "3. 补完历史后切回常驻：php think robot:run（或恢复 kaipaisan-robot.service）\n");
