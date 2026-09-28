<?php
declare(strict_types=1);

// Pure display regression: no app bootstrap, database, placement or settlement.
require dirname(__DIR__).'/vendor/autoload.php';

$controller=(new ReflectionClass(app\controller\User\UserBusiness::class))->newInstanceWithoutConstructor();
$collapse=new ReflectionMethod($controller,'collapseSingleGroupSelection');
$splitMoney=new ReflectionMethod($controller,'splitDetailMoney');
$digits=array_map('strval',range(0,9));
$cases=[
    [$digits,'福胆0 1 2 3 4 5 6 7 8 9各100倍','独胆',$digits],
    [$digits,'福胆0 1 2 3 4 5 6 7 8 9各200倍','独胆',$digits],
    [$digits,'福胆0 1 2 3 4 5 6 7 8 9各1000倍','独胆',$digits],
    [['0','1'],'胆0 1各100元 胆2 3各200元','独胆',['0','1']],
    [['2','3'],'胆0 1各100元 胆2 3各200元','独胆',['2','3']],
    [['0','1'],'胆0 1各100元 234组三三码各20元','独胆',['0','1']],
    [['123直','456直'],'123 456直各100元','直',['123直','456直']],
    [['123直','132直','213直'],'123复式100元','复式',['123直','132直','213直']],
    [['10','11'],'和值10 11各100元','和值',['10','11']],
    [['12','23'],'12 23双飞各100元','双飞',['12','23']],
    [['112','113','221','223','331','332'],'123组三三码各100元','组三三码',['三123']],
    [['112','113','221','223','331','332'],'123五组100元','组三三码',['三123']],
    [['三','23456'],'23456组三五码各100元','组三五码',['三23456']],
    [['六','23456'],'23456组六五码各100元','组六五码',['六23456']],
];
foreach($cases as $index=>[$tokens,$source,$play,$expected]) {
    $actual=$collapse->invoke($controller,$tokens,$source,$play);
    if($actual!==$expected) throw new RuntimeException('Display case '.($index+1).' failed: '.json_encode($actual,JSON_UNESCAPED_UNICODE));
}
$display=$collapse->invoke($controller,$digits,'福胆0 1 2 3 4 5 6 7 8 9各100倍','独胆');
$amounts=$splitMoney->invoke($controller,10000.0,count($display));
if(count($amounts)!==10 || array_sum(array_map('floatval',$amounts))!==10000.0) throw new RuntimeException('Total amount changed');
foreach($amounts as $amount) if((float)$amount!==1000.0) throw new RuntimeException('Per-number amount changed');
echo 'Bet detail display regression passed: '.count($cases).' cases; 10 x 1000 = 10000.'.PHP_EOL;
