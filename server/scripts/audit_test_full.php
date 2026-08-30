<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
$app=new think\App(dirname(__DIR__));$app->initialize();
use think\facade\Db;
$p=new app\service\QuickEntryParser();$text=file_get_contents(dirname(__DIR__,2).'/test.txt')?:'';
$ids=[];foreach(['福彩3D','排列三'] as $name)$ids[$name]=(int)Db::name('lotteries')->where('name',$name)->value('id');
$rawBlocks=[];$current=[];$blankRun=0;foreach(preg_split('/\r?\n/',$text)?:[] as $line){if(preg_match('/^\d{4}年/',$line))continue;if(trim($line)===''){$blankRun++;if($blankRun>=2&&$current!==[]){$rawBlocks[]=implode("\n",$current);$current=[];$blankRun=0;}continue;}$blankRun=0;$current[]=$line;}if($current!==[])$rawBlocks[]=implode("\n",$current);if(in_array('--reverse',$argv,true))$rawBlocks=array_reverse($rawBlocks);$report=[];$index=0;
foreach($rawBlocks as $raw){$raw=trim($raw);if($raw==='')continue;$lines=preg_split('/\r?\n/',$raw)?:[];if(isset($lines[0])&&preg_match('/^\d{4}年/',$lines[0]))array_shift($lines);$raw=trim(implode("\n",$lines));if($raw==='')continue;$index++;
    $rows=$p->parse($raw,'福彩3D',2.0);$amount=0.0;$count=0;$recognized=true;$odds=[];$declared=null;
    if(preg_match('/(?:合计|共计|总计|🈴|合)\s*([\d.]+(?:\s*[+*×]\s*[\d.]+)+)\s*=\s*(\d+(?:\.\d+)?)/u',$raw,$decl))$declared=null;
    elseif(preg_match('/(?:合计|共计|总计|🈴|合)\s*(\d+(?:\.\d+)?)\s*[*×]\s*(\d+(?:\.\d+)?)/u',$raw,$expr))$declared=(float)$expr[1]*(float)$expr[2];
    elseif(preg_match('/(?:合计|共计|总计|🈴|合)\s*(\d+(?:\.\d+)?)(?:\s*(?:元|米|块|角|毛))?\s*$/u',$raw,$decl))$declared=(float)$decl[1];
    foreach($rows as $row){if(($row['status']??'')!=='success'){$recognized=false;continue;}$amount+=(float)($row['amount']??0);$count+=(int)($row['count']??0);$cat=(string)($row['category']??'福');$lot=$cat==='体'?'排列三':'福彩3D';$source=(string)($row['settlement_text']??'');$oddsRow=$source!==''&&$ids[$lot]>0?(new app\service\BetSettlement())->oddsRowFor($ids[$lot],$source):[];$odds[]=['lottery'=>$lot,'play_type'=>$row['play_type']??'','source'=>$source,'unique'=>(bool)$oddsRow,'odds'=>$oddsRow['odds']??null];}
    $amountOk=$declared===null||abs($declared-$amount)<0.001;
    $report[]=['block'=>$index,'recognized'=>$recognized,'rows'=>count($rows),'count'=>$count,'amount'=>number_format($amount,2,'.',''),'declared_amount'=>$declared===null?null:number_format($declared,2,'.',''),'amount_ok'=>$amountOk,'odds_all_unique'=>$odds!==[]&&!in_array(false,array_column($odds,'unique'),true),'odds'=>$odds,'text'=>mb_substr(str_replace("\n",' / ',$raw),0,240)];
}
file_put_contents(dirname(__DIR__,2).'/findings-test-format-full.json',json_encode($report,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
$ok=array_filter($report,static fn(array $r):bool=>$r['recognized']&&$r['amount_ok']&&$r['odds_all_unique']);$sum=array_sum(array_map(static fn(array $r):float=>(float)$r['amount'],$report));
$amountBad=count(array_filter($report,static fn(array $r):bool=>!$r['amount_ok']));$oddsBad=count(array_filter($report,static fn(array $r):bool=>!$r['odds_all_unique']));$recognitionBad=count(array_filter($report,static fn(array $r):bool=>!$r['recognized']));
echo json_encode(['order'=>in_array('--reverse',$argv,true)?'reverse':'forward','blocks'=>count($report),'fully_correct'=>count($ok),'failed'=>count($report)-count($ok),'recognition_fail'=>$recognitionBad,'amount_fail'=>$amountBad,'odds_fail'=>$oddsBad,'amount_sum'=>number_format($sum,2,'.',''),'report'=>'findings-test-format-full.json'],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),"\n";
