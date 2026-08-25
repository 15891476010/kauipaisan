<?php
declare(strict_types=1);
namespace app\controller;

use think\Request;
use think\facade\Cache;
use think\facade\Db;

final class AdminBetBatch
{
    private function reply(mixed $data=null, string $message='ok', int $code=0): \think\response\Json
    {
        return json(['code'=>$code,'message'=>$message,'data'=>$data,'request_id'=>bin2hex(random_bytes(8))]);
    }

    private function scopedSiteId(Request $request): ?int
    {
        $token=trim(str_ireplace('Bearer ','',(string)$request->header('authorization')));
        $session=$token!==''?Cache::get('token:'.$token):null;
        if (!is_array($session) || ($session['scope']??'')!=='admin') throw new \RuntimeException('未登录或登录已过期');
        if (($session['admin_role']??'platform')==='platform') return null;
        $siteId=(int)($session['site_id']??0);
        if ($siteId<1) throw new \RuntimeException('当前管理员未绑定站点');
        return $siteId;
    }

    private function lotteries(?int $siteId): array
    {
        $query=Db::name('lotteries')->alias('l')->where('l.status',1)->whereNull('l.deleted_at');
        if ($siteId!==null) $query->join('site_lotteries sl','sl.lottery_id=l.id')->where('sl.site_id',$siteId);
        return $query->field('l.id,l.name,l.code,l.sort')->order('l.sort asc')->order('l.id asc')->select()->toArray();
    }

    private function currentIssue(array $lottery, ?int $siteId): string
    {
        $query=Db::name('bet_details')->alias('d')
            ->join('bet_records r','r.id=d.bet_record_id')
            ->join('user_stop_drops s','s.bet_detail_id=d.id')
            ->where('s.lottery',(string)$lottery['name'])
            ->where('r.status','pending')->where('d.status','pending');
        if ($siteId!==null) $query->where('d.site_id',$siteId);
        $rows=$query->field('r.issue_no,r.placed_at')->order('r.placed_at desc')->order('r.id desc')->limit(200)->select()->toArray();
        $seen=[];
        foreach ($rows as $row) {
            $issue=trim((string)($row['issue_no']??''));
            if ($issue==='' || isset($seen[$issue])) continue;
            $seen[$issue]=true;
            $numbers=(string)(Db::name('lottery_histories')->where('lottery_id',(int)$lottery['id'])->where('code',$issue)->value('numbers')??'');
            if (count(array_filter(preg_split('/[,，\s]+/u',trim($numbers))?:[],static fn(string $value): bool=>$value!==''))<3) return $issue;
        }
        return '';
    }

    public function options(Request $request): \think\response\Json
    {
        $siteId=$this->scopedSiteId($request);
        $lotteries=$this->lotteries($siteId);
        $lotteryId=(int)$request->param('lottery_id',0);
        $lottery=null;
        foreach ($lotteries as $item) if ((int)$item['id']===$lotteryId) { $lottery=$item; break; }
        if (!$lottery) $lottery=$lotteries[0]??null;
        if (!$lottery) return $this->reply(['lotteries'=>[],'lottery'=>null,'issue_no'=>'','users'=>[]]);
        $issue=$this->currentIssue($lottery,$siteId);
        if ($issue==='') return $this->reply(['lotteries'=>$lotteries,'lottery'=>$lottery,'issue_no'=>'','users'=>[]]);

        $query=Db::name('bet_details')->alias('d')
            ->join('bet_records r','r.id=d.bet_record_id')
            ->join('user_stop_drops s','s.bet_detail_id=d.id')
            ->leftJoin('site_users u','u.id=d.user_id')
            ->leftJoin('sites st','st.id=d.site_id')
            ->where('s.lottery',(string)$lottery['name'])->where('r.issue_no',$issue)
            ->where('r.status','pending')->where('d.status','pending');
        if ($siteId!==null) $query->where('d.site_id',$siteId);
        $rows=$query->field('d.id,d.user_id,d.site_id,d.number_text,d.amount,d.source_text,r.id AS record_id,u.username,u.display_name,st.name AS site_name')
            ->order('d.site_id asc')->order('d.user_id asc')->order('d.id asc')->select()->toArray();
        $users=[];
        foreach ($rows as $row) {
            $userKey=(int)$row['site_id'].'-'.(int)$row['user_id'];
            if (!isset($users[$userKey])) $users[$userKey]=[
                'key'=>$userKey,'user_id'=>(int)$row['user_id'],'site_id'=>(int)$row['site_id'],
                'username'=>(string)($row['username']??'未知用户'),'display_name'=>(string)($row['display_name']??''),
                'site_name'=>(string)($row['site_name']??''),'numbers'=>[],
            ];
            $numbers=preg_split('/\s+/',trim((string)($row['number_text']??'')))?:[];
            $numbers=array_values(array_filter($numbers,static fn(string $number): bool=>preg_match('/^\d{3}$/',$number)===1));
            $unitAmount=(float)($row['amount']??0)/max(1,count($numbers));
            foreach ($numbers as $index=>$number) $users[$userKey]['numbers'][]=[
                'key'=>(int)$row['id'].'-'.$index,'detail_id'=>(int)$row['id'],'number_index'=>$index,
                'value'=>$number,'amount'=>number_format($unitAmount,2,'.',''),'source_text'=>(string)($row['source_text']??''),
            ];
        }
        return $this->reply(['lotteries'=>$lotteries,'lottery'=>$lottery,'issue_no'=>$issue,'users'=>array_values($users)]);
    }

    public function replace(Request $request): \think\response\Json
    {
        $siteId=$this->scopedSiteId($request);
        $data=$request->put();
        $lotteryId=(int)($data['lottery_id']??0); $issue=trim((string)($data['issue_no']??''));
        $lottery=null;
        foreach ($this->lotteries($siteId) as $item) if ((int)$item['id']===$lotteryId) { $lottery=$item; break; }
        if (!$lottery) throw new \InvalidArgumentException('请选择有效彩种');
        if ($issue==='' || $issue!==$this->currentIssue($lottery,$siteId)) throw new \RuntimeException('当前期号已变化，请刷新后重新选择');
        $replacement=[];
        foreach (['hundreds'=>0,'tens'=>1,'units'=>2] as $field=>$position) {
            $value=trim((string)($data['replacements'][$field]??''));
            if ($value!=='' && !preg_match('/^\d$/',$value)) throw new \InvalidArgumentException('替换数字必须是0到9的单个数字');
            if ($value!=='') $replacement[$position]=$value;
        }
        if ($replacement===[]) throw new \InvalidArgumentException('请至少输入一个需要替换的位数');
        $selections=$data['selections']??null;
        if (!is_array($selections) || $selections===[] || count($selections)>5000) throw new \InvalidArgumentException('请选择需要替换的号码');
        $selected=[];
        foreach ($selections as $selection) {
            if (!is_array($selection)) continue;
            $detailId=(int)($selection['detail_id']??0); $numberIndex=(int)($selection['number_index']??-1);
            if ($detailId>0 && $numberIndex>=0) $selected[$detailId][$numberIndex]=true;
        }
        if ($selected===[]) throw new \InvalidArgumentException('请选择需要替换的号码');
        $changed=Db::transaction(function () use ($selected,$replacement,$lottery,$issue,$siteId): int {
            $updates=[]; $changed=0;
            foreach ($selected as $detailId=>$indexes) {
                $query=Db::name('bet_details')->alias('d')->join('bet_records r','r.id=d.bet_record_id')
                    ->where('d.id',$detailId)->where('d.issue_no',$issue)->where('d.status','pending')->where('r.status','pending');
                if ($siteId!==null) $query->where('d.site_id',$siteId);
                $detail=$query->field('d.id,d.number_text,d.bet_record_id')->lock(true)->find();
                if (!$detail) throw new \RuntimeException('选中的号码已不可修改，请刷新后重试');
                $stop=Db::name('user_stop_drops')->where('bet_detail_id',$detailId)->where('lottery',(string)$lottery['name'])->lock(true)->find();
                if (!$stop) throw new \RuntimeException('选中的号码不属于当前彩种');
                $numbers=preg_split('/\s+/',trim((string)$detail['number_text']))?:[];
                $numbers=array_values(array_filter($numbers,static fn(string $number): bool=>preg_match('/^\d{3}$/',$number)===1));
                foreach (array_keys($indexes) as $index) {
                    if (!isset($numbers[$index])) throw new \RuntimeException('选中的号码位置已变化，请刷新后重试');
                    $chars=str_split($numbers[$index]);
                    foreach ($replacement as $position=>$value) $chars[$position]=$value;
                    $next=implode('',$chars);
                    if ($next!==$numbers[$index]) { $numbers[$index]=$next; $changed++; }
                }
                $updates[$detailId]=implode(' ',$numbers);
            }
            foreach ($updates as $detailId=>$numberText) {
                Db::name('bet_details')->where('id',$detailId)->update(['number_text'=>$numberText]);
                Db::name('user_stop_drops')->where('bet_detail_id',$detailId)->where('lottery',(string)$lottery['name'])->update(['number_text'=>$numberText]);
            }
            return $changed;
        });
        return $this->reply(['changed'=>$changed],'批量替换完成');
    }
}
