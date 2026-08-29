<?php
declare(strict_types=1);
require dirname(__DIR__,1).'/vendor/autoload.php';
$app=new think\App(dirname(__DIR__,1));$app->initialize();
$text=file_get_contents(dirname(__DIR__,2).'/test.txt')?:'';
$rawBlocks=preg_split('/^555\s*$/m',$text)?:[];$p=new app\service\QuickEntryParser();$out=[];$index=0;
foreach($rawBlocks as $raw){$raw=trim($raw);if($raw==='')continue;$lines=preg_split('/\r?\n/',$raw)?:[];if(isset($lines[0])&&preg_match('/^\d{4}年/',$lines[0]))array_shift($lines);$block=trim(implode("\n",$lines));if($block==='')continue;$index++;$rows=$p->parse($block,'福彩3D',2.0);$failed=array_values(array_filter($rows,static fn(array $r):bool=>($r['status']??'')!=='success'));$out[]=['block'=>$index,'failed'=>count($failed),'rows'=>count($rows),'text'=>mb_substr(str_replace("\n",' / ',$block),0,180),'reasons'=>array_values(array_unique(array_map(static fn(array $r):string=>(string)($r['reason']??''),$failed)))];}
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT),"\n";
