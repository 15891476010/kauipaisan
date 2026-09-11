<?php
declare(strict_types=1);

namespace app\service;

/** Read-only projections of confirmed bets. No recognition, repricing or settlement writes. */
final class BetSummaryTable
{
    public const FAMILIES = ['six'=>'组六组选','three'=>'组三组选','direct'=>'直选（三码定位）','group'=>'组选','pair'=>'对子','fly'=>'双飞','triple'=>'豹子','sum'=>'和值','dan'=>'独胆','drag'=>'胆拖','three_lai'=>'组三赖','six_lai'=>'组六赖','compound'=>'复式','span'=>'跨度','other'=>'其他'];
    private const DIGITS = ['一'=>1,'二'=>2,'两'=>2,'三'=>3,'四'=>4,'五'=>5,'六'=>6,'七'=>7,'八'=>8,'九'=>9,'十'=>10];

    public static function money(int $cents): string { return number_format($cents/100, 2, '.', ''); }
    public static function cents(string|float|int $value): int { return (int)round((float)$value*100); }
    public static function odds(mixed $value): ?string { return is_numeric($value)&&(float)$value>0 ? rtrim(rtrim(number_format((float)$value,4,'.',''),'0'),'.') : null; }
    private static function sorted(string $digits, bool $unique=true): string
    {
        $values=str_split($digits); if($unique)$values=array_unique($values); sort($values,SORT_STRING); return implode('',$values);
    }
    private static function size(string $play): int
    {
        if(preg_match('/([一二两三四五六七八九十]|10|[1-9])码/u',$play,$m))return self::DIGITS[$m[1]]??(int)$m[1];
        return 0;
    }
    private static function part(string $family,string $title,string $selection,string $universe='',int $size=0): array
    {
        return compact('family','title','selection','universe','size');
    }

    /** A precise play identity always takes precedence over the parent ticket text. */
    public static function describe(string $play,string $number,string $source=''): array
    {
        $play=str_replace(['复试','沾边赖','组3','组6'],['复式','赖','组三','组六'],trim($play));
        $number=preg_replace('/\s+/u','',trim($number))??'';
        if(preg_match('/(?:胆([0-9]+)拖([0-9]+))|(?:[1-2]码拖\d)|胆拖/u',$play.' '.$number)){
            $family=str_contains($play,'组三')?'组三胆拖':(str_contains($play,'单选')?'单选全胆拖':(str_contains($play,'组六')?'组六胆拖':''));
            if($family===''&&preg_match('/(组三胆拖|组六2?胆拖|单选全胆拖)/u',$source,$m))$family=$m[1];
            if(preg_match('/胆([0-9]+)拖([0-9]+)/u',$number,$m)){
                $dan=self::sorted($m[1]);$tuo=self::sorted($m[2]);
                return self::part('drag',($family?:'胆拖').' '.strlen($dan).'码拖'.strlen($tuo),'胆'.$dan.'拖'.$tuo,'drag',strlen($dan)*100+strlen($tuo));
            }
            return self::part('drag',($family?:'胆拖').' '.$play,$number?:'待核对号码');
        }
        foreach(['三'=>'three_lai','六'=>'six_lai'] as $cn=>$family){
            if(str_contains($play,'组'.$cn.'赖')||str_starts_with($number,$cn.'赖')){
                $digits=preg_replace('/\D/','',$number)??'';$n=self::size($play)?:strlen($digits);
                return self::part($family,'组'.$cn.'赖'.($n?$n.'码':''),self::sorted($digits),'combination',$n);
            }
        }
        if(str_contains($play,'复式')){
            $digits=preg_replace('/\D/','',$number)??'';$n=self::size($play)?:strlen($digits);
            if(str_contains($play,'全包'))return self::part('compound','复式全包','全包','full');
            return self::part('compound','复式'.$n.'码',self::sorted($digits),'combination',$n);
        }
        if(str_contains($play,'全包')||in_array($number,['豹包','对子包','三包','六包'],true)){
            $family=str_contains($play,'豹子')||$number==='豹包'?'triple':(str_contains($play,'对子')?'pair':(str_contains($play,'组三')?'three':(str_contains($play,'组六')?'six':'other')));
            return self::part($family,self::FAMILIES[$family].'全包','全包','full');
        }
        if(preg_match('/和(?:值)?([大小单双])/u',$play.' '.$number,$m))return self::part('sum','和值'.$m[1],'和值'.$m[1],'binary');
        if(str_contains($play,'和值')||str_starts_with($number,'和值')){
            if(preg_match('/和值(\d{1,2})(?:-(\d{1,2}))?/u',$number.' '.$play,$m)){
                $selection=isset($m[2])?$m[1].'-'.$m[2]:$m[1];
                return self::part('sum','和值',$selection,'sum');
            }
            return self::part('sum','和值',$number,'sum');
        }
        if(str_contains($play,'跨度')){preg_match('/跨度([0-9])/u',$number.' '.$play,$m);return self::part('span','跨度',$m[1]??$number,'span');}
        if(str_contains($play,'定位')||str_contains($play,'口')||preg_match('/[xX口]/u',$number)){
            if(preg_match('/^[0-9Xx]{3}$/',$number)){
                $number=strtoupper($number);$n=3-substr_count($number,'X');$mask=preg_replace('/[0-9]/','口',$number);
                return self::part('direct',$n.'码定位 '.$mask,$number,'position',$n);
            }
            return self::part('direct',$play?:'定位',$number?:'待核对号码');
        }
        if(in_array($play,['独胆','胆'],true)){
            $digits=preg_replace('/\D/','',$number)??'';
            if(strlen($digits)===3&&str_starts_with($digits,'00'))$digits=substr($digits,-1);
            return self::part('dan','独胆',$digits,'dan');
        }
        if(in_array($play,['对子','双飞','飞','对飞'],true)||preg_match('/(?:飞|对)$/u',$number)){
            $digits=preg_replace('/\D/','',$number)??'';
            if(strlen($digits)===3&&$digits[0]==='0')$digits=substr($digits,1);
            $family=$play==='对子'||(strlen($digits)===2&&$digits[0]===$digits[1])?'pair':'fly';
            return self::part($family,self::FAMILIES[$family],self::sorted($digits,false),$family);
        }
        if(str_contains($play,'豹子'))return self::part('triple','豹子',preg_replace('/\D/','',$number)??$number,'triple');
        if(preg_match('/^(组三|组六)/u',$play,$m)){
            $n=self::size($play);$digits=preg_replace('/\D/','',$number)??'';
            if($n>0||str_contains($play,'多码')||strlen($digits)>3){
                $n=$n?:strlen($digits);
                return self::part($m[1]==='组三'?'three':'six',$m[1].$n.'码',self::sorted($digits),'combination',$n);
            }
            return self::part('group',$m[1],self::sorted($digits,false),$m[1]==='组三'?'group3':'group6');
        }
        if(str_contains($play,'直组'))return self::part('direct','直组',preg_replace('/\D/','',$number)??$number,'direct');
        if(in_array($play,['直','直选','单','单选'],true)||preg_match('/直$/u',$number))return self::part('direct','直选',preg_replace('/\D/','',$number)??$number,'direct');
        if($play==='组'||preg_match('/组$/u',$number)){
            $digits=preg_replace('/\D/','',$number)??'';$n=count(array_unique(str_split($digits)));
            if(strlen($digits)===3&&$n===1)return self::part('triple','豹子',$digits,'triple');
            return self::part('group',$n===2?'组三':'组六',self::sorted($digits,false),$n===2?'group3':'group6');
        }
        return self::part('other',$play?:'未分类',$number?:'待核对号码');
    }

    /** Explicit positions may expand; compact multi-code packages remain one paid selection. */
    public static function selections(array $row): array
    {
        $play=trim((string)($row['play_type']??''));$source=trim((string)($row['source_text']??''));
        $raw=trim((string)($row['number_text']??''));$raw=preg_replace('/(三赖|六赖|三|六|复|豹)\s+(?=[0-9])/u','$1',$raw)??$raw;
        $positionSource=$raw;
        if(str_contains($play,'定位')&&preg_match_all('/(百|十|个)\s*(?:位)?\s*([0-9]+)/u',$positionSource,$matches,PREG_SET_ORDER)){
            $sets=['百'=>['X'],'十'=>['X'],'个'=>['X']];
            foreach($matches as $match)$sets[$match[1]]=str_split(self::sorted($match[2]));
            $parts=[];foreach($sets['百'] as $a)foreach($sets['十'] as $b)foreach($sets['个'] as $c)$parts[]=self::describe($play,$a.$b.$c,$source);
            return $parts;
        }
        if($raw===''&&preg_match('/^([0-9]{1,10})\s+'.preg_quote($play,'/').'/u',$source,$m))$raw=$m[1];
        if($raw==='000'&&preg_match('/^(?:复[式试]|组三|组六)/u',$play)&&preg_match('/(?<!\d)([0-9]{2,10})\s+'.preg_quote($play,'/').'/u',$source,$m))$raw=$m[1];
        $tokens=preg_split('/[\s,，;；]+/u',$raw,-1,PREG_SPLIT_NO_EMPTY)?:[$raw];
        $count=self::size($play);
        // Restore a legacy fully expanded multi-code package only when its exact union size agrees.
        if(count($tokens)>1&&$count>0&&preg_match('/^(组三|组六|复[式试])/u',$play)){
            $digits='';$valid=true;
            foreach($tokens as $token){if(!preg_match('/^\d{3}(?:组|组三|组六)?$/u',$token)){$valid=false;break;}$digits.=preg_replace('/\D/','',$token);}
            $union=self::sorted($digits);
            if($valid&&strlen($union)===$count)$tokens=[$union];
        }
        $parts=[];foreach($tokens as $token)$parts[]=self::describe($play,$token,$source);
        return $parts;
    }

    /** Streaming aggregation: totals are conserved in cents; duplicate numbers accumulate only their own stakes. */
    public function aggregate(iterable $rows, bool $includeNumbers=false, ?string $targetFamily=null): array
    {
        $groups=[];$sourceCount=0;
        foreach($rows as $row){
            $sourceCount++;$parts=self::selections($row);$n=max(1,count($parts));$amount=self::cents($row['amount']??0);
            // Never invent a penny allocation when a legacy row cannot be divided equally.
            if ($amount % $n !== 0) {
                $parts=[self::part('other','待核对金额 · '.($row['play_type']??''),(string)($row['number_text']??''))];
                $n=1;
            }
            $unit=intdiv($amount,$n);$rowWin=self::cents($row['win_amount']??0);$winAssigned=[];
            $singleFamily=count(array_unique(array_column($parts,'family')))===1;
            foreach($parts as $i=>$part){
                $family=$part['family'];if($targetFamily!==null&&$family!==$targetFamily)continue;
                $lottery=(string)($row['lottery']??'未标注彩种');$issue=(string)($row['issue_no']??'');$board=(string)($row['board_code']??'A');
                $key=hash('sha256',json_encode([$lottery,$issue,$board,$family],JSON_UNESCAPED_UNICODE));
                if(!isset($groups[$key]))$groups[$key]=['key'=>$key,'family'=>$family,'name'=>self::FAMILIES[$family],'lottery'=>$lottery,'issue_no'=>$issue,'board_code'=>$board,'amount_cents'=>0,'max_cell_cents'=>0,'max_payout_cents'=>0,'win_amount_cents'=>0,'win_unallocated'=>false,'members'=>[],'records'=>[],'columns'=>[],'unresolved_count'=>0];
                $g=&$groups[$key];$cents=$unit;$odds=$family==='other'?null:self::odds($row['odds']??null);$payout=$odds===null?null:(int)round($cents*(float)$odds);
                $g['amount_cents']+=$cents;$g['members'][(string)($row['site_id']??0).':'.($row['user_id']??0)]=true;$g['records'][(string)($row['bet_record_id']??0)]=true;
                $colKey=hash('sha256',$part['title'].'|'.($odds??'unknown'));
                if(!isset($g['columns'][$colKey]))$g['columns'][$colKey]=['key'=>$colKey,'title'=>$part['title'],'odds'=>$odds,'universe'=>$part['universe'],'size'=>$part['size'],'amount_cents'=>0,'cells'=>[]];
                $col=&$g['columns'][$colKey];$col['amount_cents']+=$cents;$number=$part['selection'];
                $cellKey='n:'.$number; // Numeric strings (001/010/0) must never become integer keys.
                if(!isset($col['cells'][$cellKey]))$col['cells'][$cellKey]=['number'=>$number,'amount_cents'=>0,'payout_cents'=>$payout===null?null:0,'win_amount_cents'=>0,'count'=>0];
                $cell=&$col['cells'][$cellKey];$cell['amount_cents']+=$cents;if($cell['payout_cents']!==null)$cell['payout_cents']+=$payout??0;$cell['count']++;
                if ($n===1 || $rowWin===0) {
                    if($cell['win_amount_cents']!==null)$cell['win_amount_cents']+=$rowWin;
                } else {
                    // The stored total alone cannot identify which number won.
                    $cell['win_amount_cents']=null;
                }
                if(!isset($winAssigned[$family])){
                    if($singleFamily)$g['win_amount_cents']+=$rowWin;
                    elseif($rowWin!==0)$g['win_unallocated']=true;
                    $winAssigned[$family]=true;
                }
                $g['max_cell_cents']=max($g['max_cell_cents'],$cell['amount_cents']);$g['max_payout_cents']=max($g['max_payout_cents'],$cell['payout_cents']??0);
                if($odds===null||$family==='other')$g['unresolved_count']++;
                unset($cell,$col,$g);
            }
        }
        foreach($groups as &$g){$g['amount']=self::money($g['amount_cents']);$g['actual_win_amount']=$g['win_unallocated']?null:self::money($g['win_amount_cents']);$g['max_cell_amount']=self::money($g['max_cell_cents']);$g['max_payout']=self::money($g['max_payout_cents']);$g['member_count']=count($g['members']);$g['order_count']=count($g['records']);$g['column_count']=count($g['columns']);unset($g['members'],$g['records']);if(!$includeNumbers)unset($g['columns']);}unset($g);
        $familyRank=["direct"=>0,"six"=>1,"three"=>2,"group"=>2]; usort($groups,static function($a,$b)use($familyRank){$issue=strnatcmp((string)$a["issue_no"],(string)$b["issue_no"]); if($issue!==0)return $issue; $ra=$familyRank[$a["family"]]??3; $rb=$familyRank[$b["family"]]??3; return ($ra<=>$rb)?:strcmp((string)$a["key"],(string)$b["key"]);});
        return ['list'=>$groups,'source_count'=>$sourceCount];
    }

    private static function combinations(array $pool,int $count,string $prefix=''): \Generator
    {
        if($count===0){yield $prefix;return;}if($count<0||count($pool)<$count)return;
        foreach($pool as $i=>$digit)yield from self::combinations(array_slice($pool,$i+1),$count-1,$prefix.$digit);
    }
    /** Catalog coverage is for display only: zero cells never create bets or redistribute money. */
    public static function universe(array $column): iterable
    {
        $type=$column['universe'];$size=(int)$column['size'];$digits=str_split('0123456789');
        if($type==='combination'){yield from self::combinations($digits,$size);return;}
        if($type==='full'){yield '全包';return;}
        if($type==='binary'){yield $column['title'];return;}
        if($type==='dan'||$type==='span'||$type==='sum'){
            for($i=0;$i<=($type==='sum'?27:9);$i++)yield (string)$i;return;
        }
        if($type==='pair'||$type==='triple'){foreach($digits as $d)yield str_repeat($d,$type==='pair'?2:3);return;}
        if($type==='fly'){yield from self::combinations($digits,2);return;}
        if($type==='group6'){yield from self::combinations($digits,3);return;}
        if($type==='group3'){foreach($digits as $a)foreach($digits as $b)if($a!==$b)yield self::sorted($a.$a.$b,false);return;}
        if($type==='direct'){for($i=0;$i<1000;$i++)yield str_pad((string)$i,3,'0',STR_PAD_LEFT);return;}
        if($type==='position'&&preg_match('/([口X]{3})/u',$column['title'],$m)){
            $chars=preg_split('//u',$m[1],-1,PREG_SPLIT_NO_EMPTY);$sets=array_map(static fn($c)=>$c==='口'?str_split('0123456789'):['X'],$chars);
            foreach($sets[0] as $a)foreach($sets[1] as $b)foreach($sets[2] as $c)yield $a.$b.$c;return;
        }
        if($type==='drag'){
            $danSize=intdiv($size,100);$tuoSize=$size%100;
            foreach(self::combinations($digits,$danSize) as $dan)foreach(self::combinations(array_values(array_diff($digits,str_split($dan))),$tuoSize) as $tuo)yield '胆'.$dan.'拖'.$tuo;
        }
    }

    public function columns(array $group,array $catalog,string $sort='amount',string $direction='desc',int $page=1,string $onlyColumn='',bool $onlyBet=false,string $search=''): array
    {
        $columns=$group['columns'];
        foreach($catalog as $entry){
            $desc=self::describe((string)$entry['name'],(string)($entry['selection']??''),(string)($entry['category']??''));
            // Catalog drag and position rows need their parent/category to retain their identity.
            if(str_contains((string)$entry['category'],'胆拖')){
                if(!preg_match('/(\d)码拖(\d)/u',$entry['name'],$m))continue;
                $desc=self::part('drag',$entry['category'].' '.$entry['name'],'','drag',(int)$m[1]*100+(int)$m[2]);
            }
            if(str_contains((string)$entry['category'],'定位')&&str_contains((string)$entry['name'],'口')){
                $n=mb_substr_count((string)$entry['name'],'口');$desc=self::part('direct',$n.'码定位 '.$entry['name'],'','position',$n);
            }
            if($entry['name']==='三码定位')$desc=self::part('direct','直选','','direct');
            if($entry['name']==='直选')$desc=self::part('direct','直选','','direct');
            if($entry['name']==='组三')$desc=self::part('group','组三','','group3');
            if($entry['name']==='组六')$desc=self::part('group','组六','','group6');
            if($entry['name']==='大小单双'){
                foreach(['大','小','单','双'] as $label)$this->addCatalogColumn($columns,self::part('sum','和值'.$label,'','binary'),$entry['odds'],$group['family']);continue;
            }
            // Exact sums are listed under the matching catalog odds pair; never assume one odds for every sum.
            if($entry['category']==='和值'&&preg_match('/和值(\d+)-(\d+)/u',$entry['name'],$m)){
                $desc=self::part('sum','和值','','sum');$this->addCatalogColumn($columns,$desc,$entry['odds'],$group['family']);continue;
            }
            $this->addCatalogColumn($columns,$desc,$entry['odds'],$group['family']);
        }
        $result=[];
        foreach($columns as $column){
            if($onlyColumn!==''&&$column['key']!==$onlyColumn)continue;
            $cells=$column['cells'];
            if(!$onlyBet)foreach(self::universe($column) as $number){
                // For sum/span, a number's zero cell belongs only to its configured odds.
                if(in_array($column['universe'],['sum','span'],true)&&!$this->catalogNumberHasOdds($catalog,$column,(string)$number))continue;
                $key='n:'.$number;if(!isset($cells[$key]))$cells[$key]=['number'=>(string)$number,'amount_cents'=>0,'payout_cents'=>$column['odds']===null?null:0,'win_amount_cents'=>0,'count'=>0];
            }
            $cells=array_values(array_filter($cells,static fn($c)=>(!$onlyBet||$c['count']>0)&&($search===''||str_contains($c['number'],$search))));
            $field=match($sort){'payout'=>'payout_cents','actual'=>'win_amount_cents',default=>'amount_cents'};
            usort($cells,static function($a,$b)use($field,$direction){$v=($a[$field]??-1)<=>($b[$field]??-1);return ($direction==='asc'?$v:-$v)?:strcmp((string)$a['number'],(string)$b['number']);});
            $total=count($cells);$pageSize=40;$current=$onlyColumn!==''?$page:1;
            $column['items']=array_map(static fn($c)=>['number'=>$c['number'],'amount'=>self::money($c['amount_cents']),'payout'=>$c['payout_cents']===null?null:self::money($c['payout_cents']),'actual_win_amount'=>$c['win_amount_cents']===null?null:self::money($c['win_amount_cents']),'count'=>$c['count']],array_slice($cells,($current-1)*$pageSize,$pageSize));
            $column['total']=$total;$column['page']=$current;$column['page_size']=$pageSize;$column['amount']=self::money($column['amount_cents']);unset($column['cells']);$result[]=$column;
        }
        usort($result,static fn($a,$b)=>strnatcmp($a['title'],$b['title'])?:((float)$b['odds']<=>(float)$a['odds']));
        return $result;
    }
    private function addCatalogColumn(array &$columns,array $desc,mixed $odds,string $family): void
    {
        if($desc['family']!==$family||$desc['universe']===''||self::odds($odds)===null)return;
        $odds=self::odds($odds);$key=hash('sha256',$desc['title'].'|'.$odds);
        if(!isset($columns[$key]))$columns[$key]=['key'=>$key,'title'=>$desc['title'],'odds'=>$odds,'universe'=>$desc['universe'],'size'=>$desc['size'],'amount_cents'=>0,'cells'=>[]];
    }
    private function catalogNumberHasOdds(array $catalog,array $column,string $number): bool
    {
        foreach($catalog as $entry){
            if(self::odds($entry['odds'])!==$column['odds'])continue;
            if($column['universe']==='sum'&&preg_match('/^和值(\d+)(?:-(\d+))?$/u',$entry['name'],$m)&&in_array((int)$number,[(int)$m[1],(int)($m[2]??$m[1])],true))return true;
            if($column['universe']==='span'&&$entry['name']==='跨度'.$number)return true;
        }
        return false;
    }
}
