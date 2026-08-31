<?php
declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';
$app=new think\App(dirname(__DIR__));
$app->initialize();

use app\service\ScoreTransfer;
use think\facade\Db;

function money(mixed $value): float { return round((float)$value,2); }
function sameMoney(float $a,float $b,string $label): void {
    if(abs($a-$b)>0.001) throw new RuntimeException($label.': '.$a.' != '.$b);
}

$user=Db::name('site_users')->where('organization_id','>',0)->where('status',1)->where('balance','>=',0)->whereNull('deleted_at')->order('id')->find();
if(!$user) throw new RuntimeException('缺少代理直属会员测试数据');
$agent=Db::name('organization_nodes')->where('id',(int)$user['organization_id'])->where('level','agent')->whereNull('deleted_at')->find();
if(!$agent) throw new RuntimeException('会员所属代理不存在');

$beforeAgent=money($agent['balance']);
$beforeBalance=money($user['balance']);
$beforeCredit=money($user['credit_balance']);
$beforeLedger=(int)Db::name('organization_credit_ledger')->max('id');
$operator=['type'=>'test','id'=>0,'name'=>'score-transfer-test'];

Db::startTrans();
try {
    $result=ScoreTransfer::setUserBalances($user,$beforeBalance,$beforeCredit+100.00,$operator);
    sameMoney((float)$result['credit_balance'],$beforeCredit+100.00,'会员上分后信用余额');
    $agentAfter=money(Db::name('organization_nodes')->where('id',(int)$agent['id'])->value('balance'));
    $userAfter=Db::name('site_users')->where('id',(int)$user['id'])->find();
    sameMoney($agentAfter,$beforeAgent-100.00,'代理上分后可用分数');
    sameMoney(money($userAfter['balance'])+money($userAfter['credit_balance']),$beforeBalance+$beforeCredit+100.00,'会员上分后总分');

    $rows=Db::name('organization_credit_ledger')->where('id','>',$beforeLedger)->where('transaction_no','like','US%')->order('id asc')->select()->toArray();
    if(count($rows)!==2) throw new RuntimeException('一次上分必须生成代理出账和会员入账两条流水');
    $tx=array_values(array_unique(array_map(static fn(array $row): string=>(string)$row['transaction_no'],$rows)));
    if(count($tx)!==1) throw new RuntimeException('双方流水没有使用同一交易号');
    $out=$in=0.0;
    foreach($rows as $row){ if((string)$row['direction']==='out')$out+=money($row['amount']); else $in+=money($row['amount']); }
    sameMoney($out,100.00,'代理出账'); sameMoney($in,100.00,'会员入账');

    $ledgerBeforeReclaim=(int)Db::name('organization_credit_ledger')->max('id');
    ScoreTransfer::setUserBalances($userAfter,money($userAfter['balance']),money($userAfter['credit_balance'])-100.00,$operator);
    $agentRestored=money(Db::name('organization_nodes')->where('id',(int)$agent['id'])->value('balance'));
    sameMoney($agentRestored,$beforeAgent,'代理下分后恢复');
    $userRestored=Db::name('site_users')->where('id',(int)$user['id'])->find();
    sameMoney(money($userRestored['balance']),$beforeBalance,'会员下分后余额');
    sameMoney(money($userRestored['credit_balance']),$beforeCredit,'会员下分后信用余额');
    $reverseRows=Db::name('organization_credit_ledger')->where('id','>',$ledgerBeforeReclaim)->where('transaction_no','like','US%')->select()->toArray();
    if(count($reverseRows)!==2) throw new RuntimeException('一次下分必须生成两条反向流水');
    foreach($reverseRows as $row) {
        $expected=(string)$row['account_type']==='organization'?'in':'out';
        if((string)$row['direction']!==$expected) throw new RuntimeException('下分方向错误');
    }
    Db::rollback();
    echo "Score transfer accounting tests passed: member up/down score is conserved and paired\n";
} catch(Throwable $error) {
    Db::rollback();
    throw $error;
}
