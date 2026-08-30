<?php
declare(strict_types=1);
$all=json_decode((string)file_get_contents(dirname(__DIR__,2).'/findings-test-format-full.json'),true)?:[];
$failed=array_values(array_filter($all,static fn(array $x):bool=>!($x['recognized']??false)||!($x['amount_ok']??false)||!($x['odds_all_unique']??false)));
file_put_contents(dirname(__DIR__,2).'/findings-test-format-failed.json',json_encode($failed,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
$md=['# test.txt 失败注单（'.count($failed).' 条）','','| 编号 | 识别 | 金额 | 赔率 | 计算金额 | 申报金额 | 原文 |','|---:|---|---|---|---:|---:|---|'];
foreach($failed as $x){$md[]='| '.($x['block']??0).' | '.(($x['recognized']??false)?'通过':'失败').' | '.(($x['amount_ok']??false)?'通过':'失败').' | '.(($x['odds_all_unique']??false)?'唯一':'不唯一').' | '.($x['amount']??'0.00').' | '.($x['declared_amount']??'-').' | '.str_replace('|','\\|',str_replace("\n",' / ',$x['text']??'')).' |';}
file_put_contents(dirname(__DIR__,2).'/findings-test-format-failed.md',implode("\n",$md)."\n");
echo count($failed),"\n";
