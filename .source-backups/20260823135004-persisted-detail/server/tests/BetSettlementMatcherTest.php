<?php
declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';
$app=new think\App(dirname(__DIR__));
$app->initialize();

use app\service\BetSettlement;
use think\facade\Db;

$matcher=new BetSettlement();
$assertions=0;
$covered=[];
$check=static function(bool $condition,string $message)use(&$assertions):void{$assertions++;if(!$condition)throw new RuntimeException($message);};
$drawText=static fn(int $value):string=>str_pad((string)$value,3,'0',STR_PAD_LEFT);

for($low=0;$low<=13;$low++){
    $high=27-$low;$source="和值{$low}-{$high} 福";
    $covered[]="和值|和值{$low}-{$high}";
    $lowNumber=str_pad((string)$low,3,'0',STR_PAD_LEFT);$highNumber=str_pad((string)$high,3,'0',STR_PAD_LEFT);
    for($value=0;$value<=999;$value++){
        $draw=$drawText($value);$sum=array_sum(array_map('intval',str_split($draw)));
        $lowHit=$matcher->numberMatches($lowNumber,$draw,$source);
        $highHit=$matcher->numberMatches($highNumber,$draw,$source);
        $check($lowHit===($sum===$low),"和值{$low}-{$high} 的低端点在开奖号 {$draw} 命中错误");
        $check($highHit===($sum===$high),"和值{$low}-{$high} 的高端点在开奖号 {$draw} 命中错误");
        $check((int)$lowHit+(int)$highHit<=1,"和值{$low}-{$high} 在开奖号 {$draw} 重复命中两个占位符");
    }
}

for($target=0;$target<=27;$target++){
    $source="和值{$target} 福";$placeholder=str_pad((string)$target,3,'0',STR_PAD_LEFT);
    for($value=0;$value<=999;$value++){
        $draw=$drawText($value);$sum=array_sum(array_map('intval',str_split($draw)));
        $check($matcher->numberMatches($placeholder,$draw,$source)===($sum===$target),"精确和值{$target} 在开奖号 {$draw} 命中错误");
    }
}

foreach(range(0,9) as $span){
    $covered[]="跨度|跨度{$span}";
    for($value=0;$value<=999;$value++){
        $draw=$drawText($value);$digits=array_map('intval',str_split($draw));$expected=max($digits)-min($digits)===$span;
        $check($matcher->numberMatches('000',$draw,"跨度{$span} 福")===$expected,"跨度{$span} 在开奖号 {$draw} 命中错误");
    }
}

$binaryCases=[
    ['123','123','123直 福',true],['123','124','123直 福',false],
    ['000','689','和大 福',true],['000','123','和大 福',false],['000','123','和小 福',true],['000','689','和小 福',false],
    ['000','124','和单 福',true],['000','123','和单 福',false],['000','123','和双 福',true],['000','124','和双 福',false],
    ['111','111','豹子全包 福',true],['111','112','豹子全包 福',false],
    ['000','112','对子全包 福',true],['000','123','对子全包 福',false],
    ['000','112','组三全包 福',true],['000','123','组三全包 福',false],
    ['000','123','组六全包 福',true],['000','112','组六全包 福',false],
    ['112','211','112组三 福',true],['112','123','112组三 福',false],
    ['123','321','123组六 福',true],['123','112','123组六 福',false],
    ['012','912','12双飞 福',true],['012','913','12双飞 福',false],
    ['003','913','3独胆 福',true],['003','912','3独胆 福',false],
    ['100','123','口XX 福',true],['100','223','口XX 福',false],
    ['020','123','X口X 福',true],['020','133','X口X 福',false],
    ['003','123','XX口 福',true],['003','124','XX口 福',false],
    ['120','123','口口X 福',true],['120','133','口口X 福',false],
    ['103','123','口X口 福',true],['103','124','口X口 福',false],
    ['023','123','X口口 福',true],['023','113','X口口 福',false],
];
foreach($binaryCases as [$number,$draw,$source,$expected])$check($matcher->numberMatches($number,$draw,$source)===$expected,"{$source} 的 {$number}/{$draw} 命中错误");
$covered=array_merge($covered,['一码定位|口XX','一码定位|X口X','一码定位|XX口','二码定位|口口X','二码定位|口X口','二码定位|X口口','和值|豹子全包','组三多码|组三全包','组六多码|组六全包','组六赖|对子全包']);

$words=[1=>'一',2=>'二',3=>'三',4=>'四',5=>'五',6=>'六',7=>'七',8=>'八',9=>'九'];
foreach([['组三',2,9],['组六',4,9],['复式',3,9],['组三赖',1,7],['组六赖',1,7]] as [$family,$min,$max]){
    for($count=$min;$count<=$max;$count++){
        $selected=substr('0123456789',0,$count);$word=$family==='组三'&&$count===2?'两':$words[$count];$source="{$selected} {$family}{$word}码 福";
        $category=['组三'=>'组三多码','组六'=>'组六多码','复式'=>'复式多码','组三赖'=>'组三赖','组六赖'=>'组六赖'][$family];
        $covered[]="{$category}|{$family}{$word}码";
        if($family==='组三'){$win='001';$lose='009';}
        elseif($family==='组六'||$family==='复式'){$win='012';$lose='019';}
        elseif($family==='组三赖'){$win='088';$lose='889';}
        else{$win='089';$lose='789';}
        $check($matcher->numberMatches('000',$win,$source),"{$family}{$word}码赢例未命中");
        $check(!$matcher->numberMatches('000',$lose,$source),"{$family}{$word}码输例错误命中");
    }
}

for($count=2;$count<=9;$count++){
    $drag=substr('234567890',0,$count);
    foreach(['组三胆拖'=>['112','223'],'组六胆拖'=>['123','234']] as $family=>[$win,$lose]){
        $covered[]="{$family}|1码拖{$count}";
        $source="{$family} 胆1拖{$drag} 1码拖{$count} 福";
        $check($matcher->numberMatches('000',$win,$source),"{$family} 1码拖{$count}赢例未命中");
        $check(!$matcher->numberMatches('000',$lose,$source),"{$family} 1码拖{$count}输例错误命中");
    }
    $singleSource="单选全胆拖 胆1拖{$drag} 1码拖{$count} 福";
    $covered[]="单选全胆拖|1码拖{$count}";
    $check($matcher->numberMatches('000','123',$singleSource),"单选全胆拖 1码拖{$count}赢例未命中");
    $check(!$matcher->numberMatches('000','234',$singleSource),"单选全胆拖 1码拖{$count}输例错误命中");
}
for($count=2;$count<=8;$count++){
    $drag=substr('34567890',0,$count);$source="组六2胆拖 胆12拖{$drag} 2码拖{$count} 福";
    $covered[]="组六2胆拖|2码拖{$count}";
    $check($matcher->numberMatches('000','123',$source),"组六2胆拖 2码拖{$count}赢例未命中");
    $check(!$matcher->numberMatches('000','134',$source),"组六2胆拖 2码拖{$count}输例错误命中");
}

$multiNumbers=['123','456','789'];
$multiHits=count(array_filter($multiNumbers,static fn(string $number):bool=>$matcher->numberMatches($number,'456','123 456 789直 福')));
$check($multiHits===1,'多号码直选行没有只计算实际命中的一个号码');

$lotteryId=(int)Db::name('lotteries')->where('code','fc3d')->where('status',1)->whereNull('deleted_at')->value('id');
$configured=array_map(static fn(array $row):string=>$row['category'].'|'.$row['name'],Db::name('lottery_odds')->where('lottery_id',$lotteryId)->where('status',1)->whereNull('deleted_at')->field('category,name')->select()->toArray());
$configured=array_values(array_unique($configured));$covered=array_values(array_unique($covered));sort($configured);sort($covered);
$missing=array_values(array_diff($configured,$covered));$unknown=array_values(array_diff($covered,$configured));
$check($missing===[]&&$unknown===[],'生产赔率玩法与 matcher 覆盖矩阵不一致: missing='.implode(',',$missing).'; unknown='.implode(',',$unknown));

echo "BetSettlement matcher tests passed: {$assertions} assertions, ".count($configured)." configured odds plays covered\n";
