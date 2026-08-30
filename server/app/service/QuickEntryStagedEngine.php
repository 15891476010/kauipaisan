<?php
declare(strict_types=1);
namespace app\service;

/**
 * Standalone staged scanner. It deliberately returns data only; the legacy
 * parser remains the settlement adapter until every play family is migrated.
 */
final class QuickEntryStagedEngine
{
    private readonly QuickEntryLexer $lexer;
    public function __construct(private readonly QuickEntryRules $rules=new QuickEntryRules()){$this->lexer=new QuickEntryLexer($this->rules);}
    public function scan(string $text,string $lottery,float $unitStake=2.0): QuickEntryStageContext
    {
        $source=trim($text);$normalized=$this->rules->normalize($source);$category=$this->category($normalized,$lottery);$this->lexer->tokenize($source);
        $lines=preg_split('/\r?\n/u',$source)?:[];$collapsed=[];$blank=0;foreach($lines as $line){if(trim((string)$line)===''){$blank++;if($blank>=2)$collapsed[]='';continue;}$blank=0;$collapsed[]=$line;}$source=implode("\n",$collapsed);
        $numberSource=preg_replace('/(?:各|每|共|合计|总计)?\s*[\d.一二两三四五六七八九十百]+\s*(?:元|米|块|角|毛|倍|注)/u',' ',$normalized)??$normalized;
        $numberSource=preg_replace('/(?:组三|组六|组|直|单|复式)\s*\d{1,4}\s*$/u',' ',$numberSource)??$numberSource;
        preg_match_all('/(?<!\d)(\d{1,10})(?!\d)/u',$numberSource,$numberMatches);$numbers=array_values($numberMatches[1]??[]);
        preg_match_all('/组三|组六|直|单|组|复式|胆|胆拖|双飞|飞|全包|豹子|对子|和值|跨度|转|组拖|定位/u',$normalized,$playMatches);$plays=array_values(array_unique($playMatches[0]??[]));
        $amount=null;$unit=null;$amounts=[];if(preg_match_all('/(?:各|每|共|合计|总计)?\s*(\d+(?:\.\d+)?)\s*(元|米|块|角|毛|倍|注)/u',$normalized,$am,PREG_SET_ORDER)){foreach($am as $a){$amounts[]=['value'=>(float)$a[1],'unit'=>$a[2]];}$amount=$this->rules->amountWithUnit((float)$am[0][1],$am[0][2],$unitStake);$unit=$am[0][2];}elseif(preg_match('/(?:组六|组三|组|直|单)\s+(\d+(?:\.\d+)?)\s*$/u',$normalized,$bare)){$amount=$this->rules->amountWithUnit((float)$bare[1],'',$unitStake);$unit=null;}
        $lotteryCode=match($category){'福'=>LotteryCode::FU,'体'=>LotteryCode::TI,'福体'=>LotteryCode::FU_TI,default=>LotteryCode::UNKNOWN};$playCodes=[];
        $map=['直'=>PlayCode::DIRECT,'单'=>PlayCode::DIRECT,'组'=>PlayCode::GROUP,'组三'=>PlayCode::GROUP_THREE,'组六'=>PlayCode::GROUP_SIX,'复式'=>PlayCode::COMPOUND,'胆'=>PlayCode::DAN,'胆拖'=>PlayCode::DAN_TUO,'双飞'=>PlayCode::FLY,'飞'=>PlayCode::FLY,'全包'=>PlayCode::GROUP_SIX_PACKAGE,'豹子'=>PlayCode::LEOPARD_PACKAGE,'对子'=>PlayCode::PAIR_PACKAGE,'和值'=>PlayCode::SUM,'跨度'=>PlayCode::SPAN,'转'=>PlayCode::TRANSFER,'组拖'=>PlayCode::GROUP_DRAG,'定位'=>PlayCode::POSITION];foreach($plays as $play)if(isset($map[$play])&&!in_array($map[$play],$playCodes,true))$playCodes[]=$map[$play];
        $errorStage=null;$error=null;if($plays!==[]&&$numbers===[]){$errorStage=ParseStage::NUMBER->value;$error='未识别到有效号码';}
        return new QuickEntryStageContext($source,$lottery,$category,$numbers,$plays,$amount,$unit,$errorStage,$error,$lotteryCode,$playCodes,$errorStage===null?null:ParseStage::from($errorStage),$amounts);
    }
    private function category(string $source,string $fallback):string{$fu=str_contains($source,'福');$ti=str_contains($source,'体');return $fu&&$ti?'福体':($fu?'福':($ti?'体':($fallback==='排列三'?'体':'福')));}
}
