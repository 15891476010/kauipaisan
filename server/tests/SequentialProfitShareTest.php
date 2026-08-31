<?php
declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use app\service\SequentialProfitShare;

$assertions=0;
$check=static function(bool $condition,string $message)use(&$assertions):void{$assertions++;if(!$condition)throw new RuntimeException($message);};
$money=static fn(array $rows):array=>array_map(static fn(array $row):float=>$row['amount'],$rows);
$sum=static fn(array $rows):float=>round(array_sum(array_column($rows,'amount')),2);

$chain=[
    ['id'=>5,'parent_id'=>4,'level'=>'agent','share_rate'=>10],
    ['id'=>4,'parent_id'=>3,'level'=>'general_agent','share_rate'=>5],
    ['id'=>3,'parent_id'=>2,'level'=>'small_shareholder','share_rate'=>2],
    ['id'=>2,'parent_id'=>1,'level'=>'shareholder','share_rate'=>10],
    ['id'=>1,'parent_id'=>0,'level'=>'director','share_rate'=>35],
];

$profit=SequentialProfitShare::allocate(10000,$chain);
$check($money($profit)===[1000.0,450.0,171.0,837.9,7541.1],'会员亏损10000的逐级占成金额不正确');
$check($sum($profit)===10000.0,'盈利分配不守恒');
$check($profit[0]['incoming_amount']===10000.0&&$profit[1]['incoming_amount']===9000.0,'下一级剩余金额未正确传给上级');
$check($profit[4]['share_rate']===100.0,'根总监必须承接最终剩余金额');

$loss=SequentialProfitShare::allocate(-10000,$chain);
$check($money($loss)===[-1000.0,-450.0,-171.0,-837.9,-7541.1],'会员盈利10000的逐级亏损承担不正确');
$check($sum($loss)===-10000.0,'赔付分配不守恒');

$capped=SequentialProfitShare::allocate(100,[
    ['id'=>2,'parent_id'=>1,'level'=>'agent','share_rate'=>90],
    ['id'=>1,'parent_id'=>0,'level'=>'director','share_rate'=>0],
],80);
$check($money($capped)===[80.0,20.0],'每级最高占成没有限制下级比例');

$tiny=SequentialProfitShare::allocate(0.05,$chain);
$check($sum($tiny)===0.05,'分币四舍五入后不守恒');

$lineA=SequentialProfitShare::allocate(216,$chain);
$lineB=SequentialProfitShare::allocate(300,$chain);
$check($sum($lineA)===216.0&&$sum($lineB)===300.0,'不同直属线路必须分别守恒');

$direct=SequentialProfitShare::allocateDirect(10000,$chain);
$check($money($direct)===[1000.0,500.0,200.0,1000.0,7300.0],'按会员盈亏直算占成金额不正确');
$check($sum($direct)===10000.0,'直算占成分配不守恒');

echo "Sequential profit share tests passed: {$assertions} assertions\n";
