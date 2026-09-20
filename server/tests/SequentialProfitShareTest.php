<?php
declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use app\service\SequentialProfitShare;

$assertions=0;
$check=static function(bool $condition,string $message)use(&$assertions):void{$assertions++;if(!$condition)throw new RuntimeException($message);};
$money=static fn(array $rows):array=>array_map(static fn(array $row):float=>$row['amount'],$rows);
$sum=static fn(array $rows):float=>round(array_sum(array_column($rows,'amount')),2);

// 直比链：每层 share_rate 是对会员总投的直接占比，合计 100%。
$chain=[
    ['id'=>5,'parent_id'=>4,'level'=>'agent','share_rate'=>0],
    ['id'=>4,'parent_id'=>3,'level'=>'general_agent','share_rate'=>5],
    ['id'=>3,'parent_id'=>2,'level'=>'small_shareholder','share_rate'=>15],
    ['id'=>2,'parent_id'=>1,'level'=>'shareholder','share_rate'=>25],
    ['id'=>1,'parent_id'=>0,'level'=>'director','share_rate'=>55],
];

$profit=SequentialProfitShare::allocate(10000,$chain);
$check($money($profit)===[0.0,500.0,1500.0,2500.0,5500.0],'会员亏损10000的直比占成金额不正确');
$check($sum($profit)===10000.0,'盈利分配不守恒');
$check($profit[2]['incoming_amount']===9500.0&&$profit[3]['incoming_amount']===8000.0,'承接额应为总投×(1−下方累计占比)');
$check($profit[4]['remaining_amount']===0.0,'占比合计100%时残余应为0');

$loss=SequentialProfitShare::allocate(-10000,$chain);
$check($money($loss)===[0.0,-500.0,-1500.0,-2500.0,-5500.0],'会员盈利10000的直比亏损承担不正确');
$check($sum($loss)===-10000.0,'赔付分配不守恒');

$capped=SequentialProfitShare::allocate(100,[
    ['id'=>2,'parent_id'=>1,'level'=>'agent','share_rate'=>90],
    ['id'=>1,'parent_id'=>0,'level'=>'director','share_rate'=>0],
],80);
$check($money($capped)===[80.0,0.0],'每级最高占成没有限制下级比例');

$tiny=SequentialProfitShare::allocate(0.05,$chain);
$check($sum($tiny)===0.05,'分币四舍五入后不守恒');

$lineA=SequentialProfitShare::allocate(216,$chain);
$lineB=SequentialProfitShare::allocate(300,$chain);
$check($sum($lineA)===216.0&&$sum($lineB)===300.0,'不同直属线路必须分别守恒');

// 占比合计不足 100%：残余归平台，顶端不再兜底吞掉。
$residual=SequentialProfitShare::allocate(10000,[
    ['id'=>2,'parent_id'=>1,'level'=>'agent','share_rate'=>20],
    ['id'=>1,'parent_id'=>0,'level'=>'director','share_rate'=>50],
]);
$check($money($residual)===[2000.0,5000.0],'直比下各层按占比分成，剩余3000留平台');

// 占比超分保护：累计不得超过 100%。
$over=SequentialProfitShare::allocate(10000,[
    ['id'=>2,'parent_id'=>1,'level'=>'agent','share_rate'=>60],
    ['id'=>1,'parent_id'=>0,'level'=>'director','share_rate'=>80],
]);
$check($money($over)===[6000.0,4000.0],'累计占比超过100%时应截断为剩余份额');

echo "Sequential profit share tests passed: {$assertions} assertions\n";
