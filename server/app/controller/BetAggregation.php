<?php
declare(strict_types=1);

namespace app\controller;

use app\service\BetSettlement;
use think\Request;
use think\facade\Cache;
use think\facade\Db;
use think\response\Json;

final class BetAggregation
{
    private function reply(mixed $data=null,string $message='',int $code=0): Json
    {
        return json(['code'=>$code,'message'=>$message,'data'=>$data,'request_id'=>bin2hex(random_bytes(8))]);
    }

    private function scopedSiteId(Request $request): ?int
    {
        $token=trim(str_ireplace('Bearer ','',(string)$request->header('authorization')));
        $session=$token!==''?Cache::get('token:'.$token):null;
        if(!is_array($session)||($session['admin_role']??'platform')==='platform')return null;
        $siteId=(int)($session['site_id']??0);
        if($siteId<1)throw new \RuntimeException('当前管理员未绑定站点');
        return $siteId;
    }

    /** @return array<string,mixed> */
    private function filters(Request $request): array
    {
        $drawStatus=strtolower(trim((string)$request->param('draw_status','pending')));
        if(!in_array($drawStatus,['all','pending','opened'],true))throw new \InvalidArgumentException('开奖状态筛选值无效');
        $from=trim((string)$request->param('from',''));
        $to=trim((string)$request->param('to',''));
        foreach([$from,$to] as $date)if($date!==''&&preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)!==1)throw new \InvalidArgumentException('日期格式必须为 YYYY-MM-DD');
        return [
            'site_id'=>(int)$request->param('site_id',0),
            'lottery'=>trim((string)$request->param('lottery','')),
            'issue_no'=>trim((string)$request->param('issue_no','')),
            'draw_status'=>$drawStatus,
            'member'=>trim((string)$request->param('member','')),
            'play'=>trim((string)$request->param('play','')),
            'from'=>$from,
            'to'=>$to,
            'include_refunded'=>(int)$request->param('include_refunded',0)===1,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function detailRows(array $filters,?int $scopedSiteId): array
    {
        $query=Db::name('bet_details')->alias('d')
            ->join('bet_records r','r.id=d.bet_record_id')
            ->leftJoin('user_stop_drops s','s.bet_detail_id=d.id')
            ->leftJoin('site_users u','u.id=r.user_id')
            ->leftJoin('sites st','st.id=r.site_id')
            ->field('d.id AS detail_id,d.bet_record_id,r.submission_id,r.site_id,r.user_id,r.issue_no,r.status AS record_status,r.placed_at,r.source_text AS record_source,d.number_text,d.source_text,d.amount,d.odds,d.win_amount,d.status AS detail_status,d.board_code,s.lottery,s.play_type,u.username,u.display_name,st.name AS site_name');
        if($scopedSiteId!==null)$query->where('r.site_id',$scopedSiteId);
        elseif((int)$filters['site_id']>0)$query->where('r.site_id',(int)$filters['site_id']);
        if($filters['lottery']!=='')$query->where('s.lottery',(string)$filters['lottery']);
        if($filters['issue_no']!=='')$query->where('r.issue_no',(string)$filters['issue_no']);
        if($filters['member']!=='')$query->where(function($q)use($filters){$value='%'.(string)$filters['member'].'%';$q->whereLike('u.username',$value)->whereOr('u.display_name','like',$value);});
        if($filters['play']!=='')$query->where(function($q)use($filters){$value='%'.(string)$filters['play'].'%';$q->whereLike('s.play_type',$value)->whereOr('d.source_text','like',$value);});
        if($filters['draw_status']==='pending')$query->where('r.status','pending');
        elseif($filters['draw_status']==='opened')$query->whereIn('r.status',['won','unwon']);
        elseif(!$filters['include_refunded'])$query->where('r.status','<>','refunded');
        if($filters['from']!=='')$query->where('r.placed_at','>=',(string)$filters['from'].' 00:00:00');
        if($filters['to']!=='')$query->where('r.placed_at','<=',(string)$filters['to'].' 23:59:59');
        return $query->order('d.id','desc')->select()->toArray();
    }

    private function sortedUniqueDigits(string $value): string
    {
        $digits=array_values(array_unique(str_split(preg_replace('/\D/','',$value)??'')));
        sort($digits,SORT_STRING);
        return implode('',$digits);
    }

    private function sortedDigits(string $value): string
    {
        $digits=str_split(preg_replace('/\D/','',$value)??'');
        sort($digits,SORT_STRING);
        return implode('',$digits);
    }

    /** @return array{play_type:string,position:string,selection:string,match_number:string,match_source:string} */
    private function canonicalToken(string $token,string $playType,string $source): array
    {
        $token=trim($token);$playType=trim($playType);$compact=preg_replace('/\s+/u','',$token)??$token;
        // Some legacy/provider rows store the selected digits without the
        // compact prefix (for example number_text=`654321`, play_type=`组三六码`).
        // Grouped bets are order-independent, so restore the same canonical
        // expression used by settlement and sort the selection before making
        // the aggregation key. Direct bets intentionally do not enter this
        // branch and keep their positional order.
        if(preg_match('/^[0-9]{2,10}$/',$compact)===1&&preg_match('/^(组三|组六)(?:[一二两三四五六七八九1-9]码)?$/u',$playType,$groupPlay)===1){
            $family=$groupPlay[1]==='组三'?'三':'六';
            $digits=strlen($compact)===3?$this->sortedDigits($compact):$this->sortedUniqueDigits($compact);
            return ['play_type'=>$playType,'position'=>'','selection'=>$digits,'match_number'=>$family.$digits,'match_source'=>$source];
        }
        if(preg_match('/^(三赖|六赖|三|六|复|豹)([0-9]{1,10})$/u',$compact,$match)){
            $digits=$this->sortedUniqueDigits($match[2]);
            return ['play_type'=>$playType!==''?$playType:match($match[1]){'三','三赖'=>'组三','六','六赖'=>'组六','复'=>'复式','豹'=>'豹子'},'position'=>'','selection'=>$digits,'match_number'=>$match[1].$digits,'match_source'=>$source];
        }
        if(preg_match('/^胆([0-9]+)拖([0-9]+)$/u',$compact,$match)){
            $dan=$this->sortedUniqueDigits($match[1]);$tuo=$this->sortedUniqueDigits($match[2]);
            return ['play_type'=>$playType!==''?$playType:'胆拖','position'=>'','selection'=>'胆'.$dan.'拖'.$tuo,'match_number'=>'胆'.$dan.'拖'.$tuo,'match_source'=>$source];
        }
        if(preg_match('/^([0-9]{3})(直|组)$/u',$compact,$match)){
            if($match[2]==='直')return ['play_type'=>'直','position'=>'','selection'=>$match[1],'match_number'=>$match[1].'直','match_source'=>$source];
            $selection=$this->sortedDigits($match[1]);$unique=count(array_unique(str_split($selection)));
            return ['play_type'=>$unique===2?'组三':($unique===3?'组六':($playType!==''?$playType:'组')),'position'=>'','selection'=>$selection,'match_number'=>$selection.'组','match_source'=>$source];
        }
        if(preg_match('/^([0-9X]{3})$/i',$compact,$match)&&str_contains($playType.$source,'定位')){
            $labels=[];foreach(['百','十','个'] as $index=>$label)if(($match[1][$index]??'X')!=='X')$labels[]=$label.($match[1][$index]??'');
            $position=implode('、',array_map(static fn(string $value):string=>mb_substr($value,0,1).'位',$labels));
            return ['play_type'=>$playType!==''?$playType:'定位','position'=>$position,'selection'=>implode(' ', $labels),'match_number'=>strtoupper($match[1]),'match_source'=>$source];
        }
        if(preg_match('/^([百十个])([0-9])$/u',$compact,$match)){
            $position=$match[1].'位';$number=match($match[1]){'百'=>$match[2].'XX','十'=>'X'.$match[2].'X','个'=>'XX'.$match[2]};
            return ['play_type'=>$playType!==''?$playType:'一码定位','position'=>$position,'selection'=>$match[2],'match_number'=>$number,'match_source'=>$match[1].$match[2].' 一码定位'];
        }
        if(preg_match('/^([0-9]{2})(飞|双飞|对|对子)$/u',$compact,$match)){
            $digits=$this->sortedDigits($match[1]);
            return ['play_type'=>$playType!==''?$playType:$match[2],'position'=>'','selection'=>$digits,'match_number'=>$digits.$match[2],'match_source'=>$source];
        }
        if(preg_match('/^和值(.+)$/u',$playType,$match))return ['play_type'=>'和值','position'=>'','selection'=>$match[1],'match_number'=>'和值'.$match[1],'match_source'=>$source];
        if(preg_match('/^跨度(.+)$/u',$playType,$match))return ['play_type'=>'跨度','position'=>'','selection'=>$match[1],'match_number'=>'跨度'.$match[1],'match_source'=>$source];
        if(in_array($playType,['豹子全包','对子全包','组三全包','组六全包'],true))return ['play_type'=>$playType,'position'=>'','selection'=>'全部','match_number'=>$playType,'match_source'=>$source];
        if(preg_match('/^([0-9]+)D$/i',$compact,$match)){
            $digits=$this->sortedUniqueDigits($match[1]);
            return ['play_type'=>$playType!==''?$playType:'定位','position'=>'未指定','selection'=>$digits,'match_number'=>$digits.'D','match_source'=>$source];
        }
        $selection=$compact!==''?$compact:($playType!==''?$playType:'未识别');
        return ['play_type'=>$playType!==''?$playType:'其他','position'=>'','selection'=>$selection,'match_number'=>$selection,'match_source'=>$source];
    }

    /** @return array<int,array<string,mixed>> */
    private function normalizeRow(array $row): array
    {
        $numberText=trim((string)($row['number_text']??''));$playType=trim((string)($row['play_type']??''));$source=trim((string)($row['source_text']??''));
        if($source==='')$source=trim((string)($row['record_source']??''));
        $tokens=$numberText!==''?(preg_split('/\s+/u',$numberText)?:[]):[];
        // Older provider rows expanded one 组三/组六多码 package into many
        // three-digit combinations. Restore the selected digit set first so
        // frequency, amount and payout are counted once for the original bet.
        if(count($tokens)>1&&preg_match('/^(组三|组六)([一二两三四五六七八九])码$/u',$playType,$package)){
            $lengths=['一'=>1,'二'=>2,'两'=>2,'三'=>3,'四'=>4,'五'=>5,'六'=>6,'七'=>7,'八'=>8,'九'=>9];$selectionLength=$lengths[$package[2]]??0;$required=$package[1]==='组三'?2:3;$union=[];$valid=$selectionLength>=2;
            foreach($tokens as$token){$digits=array_values(array_unique(str_split(preg_replace('/\D/','',(string)$token)??'')));if(strlen((string)$token)!==3||count($digits)!==$required){$valid=false;break;}foreach($digits as$digit)$union[$digit]=true;}
            if($valid&&count($union)===$selectionLength){$digits=array_keys($union);sort($digits,SORT_STRING);$tokens=[($package[1]==='组三'?'三':'六').implode('',$digits)];}
        }
        if(count($tokens)===1&&preg_match('/^([百十个])([0-9]{2,10})$/u',$tokens[0],$positionSet)){
            $tokens=[];foreach(array_values(array_unique(str_split($positionSet[2])))as$digit)$tokens[]=$positionSet[1].$digit;
        }
        if($tokens===[])$tokens=[$playType!==''?$playType:$source];
        $count=max(1,count($tokens));$cents=(int)round((float)($row['amount']??0)*100);$base=intdiv($cents,$count);$remainder=$cents-$base*$count;$items=[];
        foreach($tokens as $index=>$token){
            $canonical=$this->canonicalToken((string)$token,$playType,$source);
            $amount=($base+($index<$remainder?1:0))/100;$potential=$amount*max(0,(float)($row['odds']??0));
            $items[]=array_merge($row,$canonical,['amount_value'=>$amount,'potential_value'=>$potential]);
        }
        return $items;
    }

    /** @return array<int,array<string,mixed>> */
    private function items(Request $request): array
    {
        $filters=$this->filters($request);$rows=$this->detailRows($filters,$this->scopedSiteId($request));$items=[];
        foreach($rows as $row)foreach($this->normalizeRow($row)as$item)$items[]=$item;
        return $items;
    }

    /**
     * Build risk rows from the persisted detail records without invoking the
     * quick-entry parser or expanding a ticket into hypothetical bets. The
     * stored play_type/number_text are the already-confirmed submission
     * result; only deterministic whitespace/order normalization is applied so
     * equivalent grouped expressions can share one risk key.
     * @return array<int,array<string,mixed>>
     */
    private function persistedRiskItems(Request $request): array
    {
        $filters=$this->filters($request);$rows=$this->detailRows($filters,$this->scopedSiteId($request));$items=[];
        foreach($rows as $row){
            $playType=trim((string)($row['play_type']??''));
            $source=trim((string)($row['source_text']??''));
            if($source==='')$source=trim((string)($row['record_source']??''));
            $raw=preg_replace('/\s+/u',' ',trim((string)($row['number_text']??'')))??'';
            $selection=$raw!==''?$raw:$source;
            // Grouped selections are order-independent. This uses the stored
            // play type only; it does not infer a new play or split amounts.
            if(preg_match('/^(组三|组六)/u',$playType)===1&&$selection!==''){
                $tokens=preg_split('/\s+/u',$selection)?:[$selection];$normalized=[];
                foreach($tokens as $token){$digits=preg_replace('/\D/','',(string)$token)??'';if($digits===''){ $normalized[]=$token;continue; }$chars=str_split($digits);if(strlen($digits)===3)sort($chars,SORT_STRING);else{$chars=array_values(array_unique($chars));sort($chars,SORT_STRING);}$normalized[]=implode('',$chars);}
                sort($normalized,SORT_STRING);$selection=implode(' ',$normalized);
            }elseif(preg_match('/定位/u',$playType)===1){
                $selection=strtoupper($selection);
            }
            $amount=(float)($row['amount']??0);$odds=(float)($row['odds']??0);
            $items[]=array_merge($row,['play_type'=>$playType!==''?$playType:'其他','position'=>'','selection'=>$selection,'match_number'=>$selection,'match_source'=>$source,'amount_value'=>$amount,'potential_value'=>$amount*max(0,$odds)]);
        }
        return $items;
    }

    private function expressionId(array $item): string
    {
        return sha1(json_encode([(string)$item['lottery'],(string)$item['issue_no'],(string)$item['play_type'],(string)$item['position'],(string)$item['selection']],JSON_UNESCAPED_UNICODE));
    }

    /** @return array<int,string> */
    private function permutations(string $digits): array
    {
        if(strlen($digits)!==3)return [];$result=[];
        foreach([[0,1,2],[0,2,1],[1,0,2],[1,2,0],[2,0,1],[2,1,0]]as$order)$result[$digits[$order[0]].$digits[$order[1]].$digits[$order[2]]]=true;
        return array_keys($result);
    }

    /** @return array<int,string> */
    private function generatedGroupDraws(string $selected,string $family,bool $intersects=false): array
    {
        $selectedDigits=str_split($selected);$pool=$intersects?str_split('0123456789'):$selectedDigits;$result=[];
        if($family==='组三'){
            foreach($pool as $repeated)foreach($pool as $other){if($repeated===$other)continue;if($intersects&&array_intersect([$repeated,$other],$selectedDigits)===[])continue;foreach($this->permutations($repeated.$repeated.$other)as$draw)$result[$draw]=true;}
        }else{
            $count=count($pool);for($a=0;$a<$count;$a++)for($b=$a+1;$b<$count;$b++)for($c=$b+1;$c<$count;$c++){if($intersects&&array_intersect([$pool[$a],$pool[$b],$pool[$c]],$selectedDigits)===[])continue;foreach($this->permutations($pool[$a].$pool[$b].$pool[$c])as$draw)$result[$draw]=true;}
        }
        ksort($result,SORT_STRING);return array_keys($result);
    }

    /** @return ?array<int,string> */
    private function fastCoverage(array $item): ?array
    {
        $number=(string)$item['match_number'];$play=(string)$item['play_type'];$source=(string)$item['match_source'];$result=[];
        if(preg_match('/^([0-9]{3})直$/',$number,$match))return [$match[1]];
        if(preg_match('/^([0-9]{3})组$/',$number,$match))return $this->permutations($match[1]);
        if(preg_match('/^(三|六|三赖|六赖)([0-9]{1,10})$/u',$number,$match))return $this->generatedGroupDraws($this->sortedUniqueDigits($match[2]),str_starts_with($match[1],'三')?'组三':'组六',str_contains($match[1],'赖'));
        if(preg_match('/^复([0-9]{1,10})$/u',$number,$match)){
            $digits=str_split($this->sortedUniqueDigits($match[1]));foreach($digits as$a)foreach($digits as$b)foreach($digits as$c)$result[$a.$b.$c]=true;ksort($result,SORT_STRING);return array_keys($result);
        }
        if(preg_match('/^豹([0-9]{1,10})$/u',$number,$match)){foreach(str_split($this->sortedUniqueDigits($match[1]))as$digit)$result[]=$digit.$digit.$digit;return $result;}
        if(preg_match('/^([0-9X]{3})$/i',$number,$match)&&str_contains($play.$source,'定位')){
            $sets=[];foreach(str_split(strtoupper($match[1]))as$value)$sets[]=$value==='X'?str_split('0123456789'):[$value];foreach($sets[0]as$a)foreach($sets[1]as$b)foreach($sets[2]as$c)$result[]=$a.$b.$c;return $result;
        }
        if(preg_match('/^和值(2[0-7]|1\d|\d)$/u',$number,$match)){
            $sum=(int)$match[1];for($value=0;$value<=999;$value++){$draw=str_pad((string)$value,3,'0',STR_PAD_LEFT);if(array_sum(array_map('intval',str_split($draw)))===$sum)$result[]=$draw;}return $result;
        }
        if(preg_match('/^跨度([0-9])$/u',$number,$match)){
            $span=(int)$match[1];for($value=0;$value<=999;$value++){$draw=str_pad((string)$value,3,'0',STR_PAD_LEFT);$digits=str_split($draw);if((int)max($digits)-(int)min($digits)===$span)$result[]=$draw;}return $result;
        }
        if(in_array($number,['豹子全包','组三全包','对子全包','组六全包'],true)){
            $family=$number==='组六全包'?'组六':($number==='豹子全包'?'豹子':'组三');for($value=0;$value<=999;$value++){$draw=str_pad((string)$value,3,'0',STR_PAD_LEFT);$unique=count(array_unique(str_split($draw)));if(($family==='豹子'&&$unique===1)||($family==='组三'&&$unique===2)||($family==='组六'&&$unique===3))$result[]=$draw;}return $result;
        }
        if(preg_match('/^胆([0-9]+)拖([0-9]+)$/u',$number,$match)){
            $dan=str_split($this->sortedUniqueDigits($match[1]));$tuo=str_split($this->sortedUniqueDigits($match[2]));$allowed=array_values(array_unique(array_merge($dan,$tuo)));
            for($value=0;$value<=999;$value++){$draw=str_pad((string)$value,3,'0',STR_PAD_LEFT);$drawDigits=str_split($draw);$unique=array_values(array_unique($drawDigits));if(array_diff($dan,$drawDigits)!==[])continue;$ok=false;if(str_contains($source,'单选全胆拖'))$ok=array_diff($drawDigits,$allowed)===[]&&array_intersect($drawDigits,$tuo)!==[];elseif(str_contains($source,'组六2胆拖'))$ok=count($unique)===3&&count($dan)===2&&array_diff($dan,$unique)===[]&&count(array_intersect($unique,$tuo))>=1;else{$required=str_contains($source,'组三胆拖')?2:3;$others=array_values(array_diff($unique,$dan));$ok=count($unique)===$required&&array_diff($unique,$allowed)===[]&&array_intersect($others,$tuo)!==[];}if($ok)$result[]=$draw;}return $result;
        }
        if(preg_match('/^([0-9]{2})(飞|双飞)$/u',$number,$match)){
            $digits=str_split($match[1]);for($value=0;$value<=999;$value++){$draw=str_pad((string)$value,3,'0',STR_PAD_LEFT);$ok=$digits[0]===$digits[1]?substr_count($draw,$digits[0])>=2:str_contains($draw,$digits[0])&&str_contains($draw,$digits[1]);if($ok)$result[]=$draw;}return $result;
        }
        if(preg_match('/^([0-9]{2})(对|对子)$/u',$number,$match)){
            for($value=0;$value<=999;$value++){$draw=str_pad((string)$value,3,'0',STR_PAD_LEFT);if(substr_count($draw,$match[1][0])>=2)$result[]=$draw;}return $result;
        }
        return null;
    }

    /** @param array<int,array<string,mixed>> $items @return array<int,array<string,mixed>> */
    private function playSummary(array $items): array
    {
        $groups=[];$allOccurrences=count($items);
        foreach($items as $item){
            $id=$this->expressionId($item);
            if(!isset($groups[$id]))$groups[$id]=['group_id'=>$id,'lottery'=>(string)$item['lottery'],'issue_no'=>(string)$item['issue_no'],'play_type'=>(string)$item['play_type'],'position'=>(string)$item['position'],'selection'=>(string)$item['selection'],'occurrence_count'=>0,'record_ids'=>[],'member_ids'=>[],'bet_amount_value'=>0.0,'potential_value'=>0.0];
            $groups[$id]['occurrence_count']++;$groups[$id]['record_ids'][(int)$item['bet_record_id']]=true;$groups[$id]['member_ids'][(int)$item['user_id']]=true;
            $groups[$id]['bet_amount_value']+=(float)$item['amount_value'];$groups[$id]['potential_value']+=(float)$item['potential_value'];
        }
        $result=[];foreach($groups as $group){$group['order_count']=count($group['record_ids']);$group['member_count']=count($group['member_ids']);unset($group['record_ids'],$group['member_ids']);$group['frequency_rate']=$allOccurrences>0?round($group['occurrence_count']*100/$allOccurrences,4):0;$group['bet_amount']=number_format($group['bet_amount_value'],2,'.','');$group['potential_win_amount']=number_format($group['potential_value'],2,'.','');unset($group['bet_amount_value'],$group['potential_value']);$result[]=$group;}
        return $result;
    }

    /** @param array<int,array<string,mixed>> $items @return array{rows:array<int,array<string,mixed>>,unmapped:int} */
    private function riskSummary(array $items): array
    {
        // Risk must use the same compact expression that settlement uses. A
        // ticket such as `六123456` is one exposure, not dozens of unrelated
        // three-digit rows. Expanding it into every possible draw both makes
        // the report misleading and turns a full-page request into a 000-999
        // scan for every distinct ticket.
        $groups=[];
        foreach($items as $item){
            $id=$this->expressionId($item);
            if(!isset($groups[$id]))$groups[$id]=[
                'group_id'=>$id,'lottery'=>(string)$item['lottery'],'issue_no'=>(string)$item['issue_no'],
                'play_type'=>(string)$item['play_type'],'position'=>(string)$item['position'],'selection'=>(string)$item['selection'],
                'match_number'=>(string)$item['match_number'],'occurrence_count'=>0,'record_ids'=>[],'member_ids'=>[],
                'bet_amount_value'=>0.0,'potential_value'=>0.0,
            ];
            $groups[$id]['occurrence_count']++;$groups[$id]['record_ids'][(int)$item['bet_record_id']]=true;$groups[$id]['member_ids'][(int)$item['user_id']]=true;$groups[$id]['bet_amount_value']+=(float)$item['amount_value'];$groups[$id]['potential_value']+=(float)$item['potential_value'];
        }
        $result=[];foreach($groups as $group){$group['order_count']=count($group['record_ids']);$group['member_count']=count($group['member_ids']);unset($group['record_ids'],$group['member_ids']);$group['bet_amount']=number_format($group['bet_amount_value'],2,'.','');$group['potential_win_amount']=number_format($group['potential_value'],2,'.','');unset($group['bet_amount_value'],$group['potential_value']);$result[]=$group;}
        return ['rows'=>$result,'unmapped'=>0];
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function sortRows(array &$rows,string $mode,Request $request): void
    {
        $numeric=['occurrence_count','order_count','member_count','frequency_rate','bet_amount','potential_win_amount'];
        $allowed=array_merge($numeric,['lottery','issue_no','play_type','position','selection','outcome']);
        $default=$mode==='risk'?'potential_win_amount':'occurrence_count';$field=trim((string)$request->param('sort_field',$default));$order=strtolower(trim((string)$request->param('sort_order','desc')));
        if($field==='')$field=$default;
        if($order==='')$order='desc';
        if(!in_array($field,$allowed,true))throw new \InvalidArgumentException('汇总排序字段无效');
        if(!in_array($order,['asc','desc'],true))throw new \InvalidArgumentException('汇总排序方向无效');
        usort($rows,static function(array $a,array $b)use($field,$order,$numeric):int{$comparison=in_array($field,$numeric,true)?((float)($a[$field]??0)<=>(float)($b[$field]??0)):strnatcasecmp((string)($a[$field]??''),(string)($b[$field]??''));if($comparison===0)$comparison=strcmp((string)($a['group_id']??''),(string)($b['group_id']??''));return $order==='asc'?$comparison:-$comparison;});
    }

    public function index(Request $request): Json
    {
        try{
            $mode=strtolower(trim((string)$request->param('mode','play')));if(!in_array($mode,['play','risk'],true))throw new \InvalidArgumentException('汇总模式无效');
            $items=$mode==='risk'?$this->persistedRiskItems($request):$this->items($request);$unmapped=0;
            if($mode==='risk'){$summary=$this->riskSummary($items);$rows=$summary['rows'];$unmapped=$summary['unmapped'];}else$rows=$this->playSummary($items);
            $this->sortRows($rows,$mode,$request);$total=count($rows);$page=max(1,(int)$request->param('page',1));$pageSize=min(100,max(1,(int)$request->param('page_size',20)));
            return $this->reply(['list'=>array_slice($rows,($page-1)*$pageSize,$pageSize),'total'=>$total,'source_item_count'=>count($items),'unmapped_item_count'=>$unmapped]);
        }catch(\Throwable $error){return $this->reply(null,$error->getMessage(),422);}
    }

    private function itemMatchesDetail(array $item,string $mode,Request $request,BetSettlement $settlement): bool
    {
        if((string)$item['lottery']!==(string)$request->param('lottery','')||(string)$item['issue_no']!==(string)$request->param('issue_no',''))return false;
        if($mode==='risk') {
            // Risk rows are compact expressions, so details use the same
            // canonical grouping key as the summary instead of an expanded
            // hypothetical draw number.
            return $this->expressionId($item) === sha1(json_encode([
                (string)$item['lottery'], (string)$item['issue_no'],
                trim((string)$request->param('play_type','')), trim((string)$request->param('position','')),
                trim((string)$request->param('selection','')),
            ], JSON_UNESCAPED_UNICODE));
        }
        return (string)$item['play_type']===(string)$request->param('play_type','')&&(string)$item['position']===(string)$request->param('position','')&&(string)$item['selection']===(string)$request->param('selection','');
    }

    public function details(Request $request): Json
    {
        try{
            $mode=strtolower(trim((string)$request->param('mode','play')));if(!in_array($mode,['play','risk'],true))throw new \InvalidArgumentException('汇总模式无效');
            $settlement=new BetSettlement();$matched=[];$sourceItems=$mode==='risk'?$this->persistedRiskItems($request):$this->items($request);foreach($sourceItems as$item)if($this->itemMatchesDetail($item,$mode,$request,$settlement))$matched[]=$item;
            $members=[];$orders=[];
            foreach($matched as $item){
                $memberKey=(int)$item['site_id'].'#'.(int)$item['user_id'];
                if(!isset($members[$memberKey]))$members[$memberKey]=['site_id'=>(int)$item['site_id'],'user_id'=>(int)$item['user_id'],'site_name'=>(string)($item['site_name']??'站点已删除'),'username'=>(string)($item['username']??'用户已删除'),'display_name'=>(string)($item['display_name']??''),'occurrence_count'=>0,'record_ids'=>[],'bet_amount_value'=>0.0,'potential_value'=>0.0];
                $members[$memberKey]['occurrence_count']++;$members[$memberKey]['record_ids'][(int)$item['bet_record_id']]=true;$members[$memberKey]['bet_amount_value']+=(float)$item['amount_value'];$members[$memberKey]['potential_value']+=(float)$item['potential_value'];
                $orders[]=['record_id'=>(int)$item['bet_record_id'],'detail_id'=>(int)$item['detail_id'],'site_name'=>(string)($item['site_name']??''),'username'=>(string)($item['username']??''),'placed_at'=>(string)$item['placed_at'],'lottery'=>(string)$item['lottery'],'issue_no'=>(string)$item['issue_no'],'play_type'=>(string)$item['play_type'],'position'=>(string)$item['position'],'selection'=>(string)$item['selection'],'amount'=>number_format((float)$item['amount_value'],2,'.',''),'odds'=>number_format((float)$item['odds'],4,'.',''),'potential_win_amount'=>number_format((float)$item['potential_value'],2,'.',''),'source_text'=>(string)$item['source_text']];
            }
            $memberRows=[];foreach($members as $member){$member['order_count']=count($member['record_ids']);unset($member['record_ids']);$member['bet_amount']=number_format($member['bet_amount_value'],2,'.','');$member['potential_win_amount']=number_format($member['potential_value'],2,'.','');unset($member['bet_amount_value'],$member['potential_value']);$memberRows[]=$member;}
            usort($memberRows,static fn(array $a,array $b):int=>(float)$b['potential_win_amount']<=>(float)$a['potential_win_amount']);
            usort($orders,static fn(array $a,array $b):int=>strcmp($b['placed_at'],$a['placed_at'])?:($b['record_id']<=>$a['record_id']));
            $page=max(1,(int)$request->param('detail_page',1));$pageSize=min(100,max(1,(int)$request->param('detail_page_size',30)));
            return $this->reply(['members'=>$memberRows,'member_total'=>count($memberRows),'orders'=>array_slice($orders,($page-1)*$pageSize,$pageSize),'orders_total'=>count($orders),'page'=>$page,'page_size'=>$pageSize]);
        }catch(\Throwable $error){return $this->reply(null,$error->getMessage(),422);}
    }
}
