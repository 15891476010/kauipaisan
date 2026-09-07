<?php
declare(strict_types=1);

// Read-only integration regression, using the installed ORM and in-query fixtures.
// No real orders, accounts, cache sessions, or database tables are created/modified.
$root = $argv[1] ?? dirname(__DIR__);
$controller = $argv[2] ?? $root.'/app/controller/UserBusiness.php';
require $root.'/vendor/autoload.php';
$app = new think\App($root);
$app->initialize();

use think\facade\Db;

function check(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}

$code = file_get_contents($controller);
$start = strpos($code, 'public function betRecords(');
$end = strpos($code, 'private function submissionRecordIds(', $start);
check($start !== false && $end !== false, 'Cannot locate betRecords');
$method = substr($code, $start, $end - $start);
preg_match_all('/\$query->where\((function\(\$nested\)use\(\$source\):void\{[^\n]+?\})\);/', $method, $matches);
check(count($matches[1]) === 2, 'Expected both legacy and submission search branches');
$fixture = "(SELECT 1 id, 15 site_id, 36 user_id, 'won' status, '2026-09-08 12:00:00' placed_at, '福组六 012' source_text, '其他' formatted_text, 10 amount
 UNION ALL SELECT 2,15,36,'won','2026-09-08 12:00:00','其他','福组六 123',20
 UNION ALL SELECT 3,15,99,'won','2026-09-08 12:00:00','其他','福组六',30
 UNION ALL SELECT 4,99,36,'won','2026-09-08 12:00:00','其他','福组六',40
 UNION ALL SELECT 5,15,36,'unwon','2026-09-08 12:00:00','其他','福组六',50
 UNION ALL SELECT 6,15,36,'won','2026-09-07 12:00:00','其他','福组六',60
 UNION ALL SELECT 7,15,36,'won','2026-09-08 12:00:00','体组三','体组三',70
 UNION ALL SELECT 8,15,36,'won','2026-09-08 12:00:00','O''Reilly','文本',80) search_fixture";
Db::execute('SET TRANSACTION READ ONLY');
Db::startTrans();
try {
    $oldFailed = false;
    try {
        Db::table($fixture)->pk('id')->field('id')->where(function($q):void {
            $q->whereLike('source_text','%福组六%')->whereOrLike('formatted_text','%福组六%');
        })->select();
    } catch (Throwable $e) {
        $oldFailed = str_contains(strtoupper($e->getMessage()), 'FORMATTED_TEXT');
        if (!$oldFailed) throw new RuntimeException('Fixture setup failed: '.get_class($e).' '.$e->getMessage());
    }
    check($oldFailed, 'Old query did not reproduce FORMATTED_TEXT error');
    foreach ($matches[1] as $index => $closureCode) {
        foreach (['福组六'=>[1,2], '012'=>[1], '体组三'=>[7], "O'Reilly"=>[8], "' OR 1=1 --"=>[], '未匹配文本'=>[]] as $source => $expected) {
            $filter = eval('return '.$closureCode.';');
            $query = Db::table($fixture)->pk('id')->where('site_id',15)->where('user_id',36)
                ->where('status','won')->where('placed_at','>=','2026-09-08 00:00:00')
                ->where('placed_at','<=','2026-09-08 23:59:59')->where($filter);
            $ids = array_map('intval', (clone $query)->order('id')->column('id'));
            check($ids === $expected, 'Search result/scope mismatch in branch '.($index+1));
            check((int)(clone $query)->count() === count($expected), 'Count mismatch');
            if ($source === '福组六') {
                check((float)(clone $query)->sum('amount') === 30.0, 'Amount total mismatch');
                $page = (clone $query)->field('id')->order('id')->page(2,1)->select()->toArray();
                check(count($page) === 1 && (int)$page[0]['id'] === 2, 'Pagination mismatch');
            }
        }
        echo 'PASS branch '.($index+1).": original/formatted text, site/user/status/date isolation, count/sum, pagination, quotes and injection literal\n";
    }
} finally {
    Db::rollback();
}
echo "PASS old FORMATTED_TEXT error reproduced; corrected searches execute in read-only transaction\n";
