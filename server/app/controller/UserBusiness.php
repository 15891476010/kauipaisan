<?php
declare(strict_types=1);
namespace app\controller;

use app\service\QuickEntryParser;
use think\Request;
use think\facade\Cache;
use think\facade\Db;
use think\facade\Log;

final class UserBusiness
{
    private function reply(mixed $data=null, string $message='ok', int $code=0): \think\response\Json { return json(['code'=>$code,'message'=>$message,'data'=>$data,'request_id'=>bin2hex(random_bytes(8))]); }
    private function session(Request $request): array
    {
        $token=trim(str_ireplace('Bearer ','',(string)$request->header('authorization')));
        $session=$token !== '' ? Cache::get('token:'.$token) : null;
        if (!is_array($session) || ($session['scope'] ?? '') !== 'user') throw new \RuntimeException('未登录或登录已过期');
        $siteId=(int)($session['site_id'] ?? 0); $userId=(int)($session['user_id'] ?? 0);
        if ($siteId < 1 || $userId < 1) throw new \RuntimeException('用户会话无效');
        $tenantId=(int)($session['tenant_id'] ?? 0);
        if ($tenantId < 1) $tenantId=(int)Db::name('sites')->where('id',$siteId)->value('tenant_id');
        if ($tenantId < 1) throw new \RuntimeException('用户租户信息无效');
        return ['tenant_id'=>$tenantId,'site_id'=>$siteId,'user_id'=>$userId];
    }
    private function range(Request $request): array
    {
        $from=trim((string)$request->param('from','')); $to=trim((string)$request->param('to',''));
        return [$from !== '' ? $from.' 00:00:00' : null, $to !== '' ? $to.' 23:59:59' : null];
    }
    public function betRecords(Request $request): \think\response\Json
    {
        $s=$this->session($request); [$from,$to]=$this->range($request);
        $query=Db::name('bet_records')->where('site_id',$s['site_id'])->where('user_id',$s['user_id']);
        if ($from) $query->where('placed_at','>=',$from); if ($to) $query->where('placed_at','<=',$to);
        $status=(string)$request->param('status',''); if (in_array($status,['won','unwon'],true)) $query->where('status',$status);
        $source=trim((string)$request->param('source','')); if ($source !== '') $query->whereLike('source_text','%'.$source.'%');
        $total=(clone $query)->count(); $amountTotal=(float)(clone $query)->sum('amount'); $page=max(1,(int)$request->param('page',1)); $size=min(100,max(1,(int)$request->param('page_size',20)));
        $list=$query->order('placed_at','desc')->page($page,$size)->select()->toArray();
        foreach ($list as &$record) {
            $refundState=$this->betRecordRefundState($record);
            $record['lottery']=$refundState['lottery'];
            $record['open_time']=$refundState['open_time'];
            $record['can_refund']=$refundState['can_refund'];
            $record['amount']=number_format((float)$record['amount'],2,'.','');
            $record['win_amount']=number_format((float)$record['win_amount'],2,'.','');
        }
        return $this->reply(['list'=>$list,'total'=>$total,'amount_total'=>number_format($amountTotal,2,'.',''),'page'=>$page,'page_size'=>$size]);
    }

    private function betRecordRefundState(array $record): array
    {
        $details=Db::name('bet_details')->where('bet_record_id',(int)$record['id'])->column('id');
        $lotteries=$details ? array_values(array_unique(array_filter(Db::name('user_stop_drops')->whereIn('bet_detail_id',$details)->column('lottery')))) : [];
        $result=['lottery'=>implode('',array_map(static fn(string $name): string=>$name==='福彩3D'?'福':($name==='排列三'?'体':$name),$lotteries)),'open_time'=>null,'can_refund'=>false];
        if ((string)($record['status']??'')!=='pending' || !$lotteries) return $result;
        $deadlines=[];
        foreach ($lotteries as $lotteryName) {
            $lotteryId=(int)Db::name('lotteries')->where('tenant_id',(int)$record['tenant_id'])->where('name',$lotteryName)->whereNull('deleted_at')->value('id');
            if ($lotteryId<1 || Db::name('lottery_histories')->where('lottery_id',$lotteryId)->where('code',(string)$record['issue_no'])->count()>0) return $result;
            $deadline=Db::name('lottery_histories')->where('lottery_id',$lotteryId)->where('next_code',(string)$record['issue_no'])->order('open_time','desc')->value('next_open_time');
            if (!$deadline || strtotime((string)$deadline)<=time()) return $result;
            $deadlines[]=(string)$deadline;
        }
        sort($deadlines);
        $result['open_time']=$deadlines[0]??null;
        $result['can_refund']=true;
        return $result;
    }

    public function refundBetRecord(Request $request): \think\response\Json
    {
        $s=$this->session($request); $id=(int)$request->param('id');
        try {
            $amount=Db::transaction(function () use ($s,$id): float {
                $record=Db::name('bet_records')->where('id',$id)->where('site_id',$s['site_id'])->where('user_id',$s['user_id'])->lock(true)->find();
                if (!$record) throw new \InvalidArgumentException('投注记录不存在');
                $state=$this->betRecordRefundState($record);
                if (!$state['can_refund']) throw new \DomainException((string)($record['status']??'')==='refunded'?'该注单已经退回':'该期已开奖或已到开奖时间，不能退回');
                $now=date('Y-m-d H:i:s'); $amount=(float)$record['amount'];
                Db::name('bet_records')->where('id',$id)->update(['status'=>'refunded','sealed'=>1]);
                Db::name('bet_details')->where('bet_record_id',$id)->update(['status'=>'refunded']);
                Db::name('site_users')->where('id',$s['user_id'])->where('site_id',$s['site_id'])->update(['used_balance'=>Db::raw('GREATEST(used_balance - '.number_format($amount,2,'.','').', 0)'),'updated_at'=>$now]);
                return $amount;
            });
        } catch (\InvalidArgumentException $e) { return $this->reply(null,$e->getMessage(),404); }
          catch (\DomainException $e) { return $this->reply(null,$e->getMessage(),409); }
          catch (\Throwable $e) { return $this->reply(null,'退单失败，请稍后重试',500); }
        return $this->reply(['record_id'=>$id,'amount'=>number_format($amount,2,'.','')],'退单成功');
    }
    public function stopDrops(Request $request): \think\response\Json
    {
        $s=$this->session($request); [$from,$to]=$this->range($request); $query=Db::name('user_stop_drops')->where('site_id',$s['site_id'])->where('user_id',$s['user_id']);
        if ($from) $query->where('placed_at','>=',$from); if ($to) $query->where('placed_at','<=',$to);
        $number=trim((string)$request->param('number','')); if ($number!=='') $query->whereLike('number_text','%'.$number.'%');
        $type=(string)$request->param('type','all'); if (in_array($type,['stop','drop'],true)) $query->where('stop_type',$type);
        $lottery=(string)$request->param('lottery','all');
        $lotteryMap=['体'=>'排列三','福'=>'福彩3D','排列三'=>'排列三','福彩3D'=>'福彩3D'];
        if (isset($lotteryMap[$lottery])) $query->where('lottery',$lotteryMap[$lottery]);
        $category=trim((string)$request->param('category','')); if ($category!=='' && $category!=='所有') $query->where('play_type',$category);
        $total=(clone $query)->count(); $page=max(1,(int)$request->param('page',1)); $size=min(100,max(1,(int)$request->param('page_size',50))); $sort=(string)$request->param('sort','desc');
        return $this->reply(['list'=>$query->order('placed_at',$sort==='asc'?'asc':'desc')->page($page,$size)->select()->toArray(),'total'=>$total,'page'=>$page,'page_size'=>$size]);
    }
    public function betDetails(Request $request): \think\response\Json
    {
        $s=$this->session($request); [$from,$to]=$this->range($request);
        $query=Db::name('bet_details')->alias('d')->leftJoin('user_stop_drops s','s.bet_detail_id=d.id')->where('d.site_id',$s['site_id'])->where('d.user_id',$s['user_id'])->field('d.*,s.play_type,s.lottery');
        $recordId=(int)$request->param('bet_record_id',0); if ($recordId>0) $query->where('d.bet_record_id',$recordId);
        if ($from) $query->where('d.placed_at','>=',$from); if ($to) $query->where('d.placed_at','<=',$to);
        $issue=trim((string)$request->param('issue_no','')); if ($issue !== '') $query->where('d.issue_no',$issue);
        $number=trim((string)$request->param('number','')); if ($number !== '') $query->whereLike('d.number_text','%'.$number.'%');
        $lottery=trim((string)$request->param('lottery','')); if ($lottery !== '') $query->where('s.lottery',$lottery);
        $category=trim((string)$request->param('category','')); if ($category !== '' && $category !== '所有') $query->where('s.play_type',$category);
        $metric=(string)$request->param('metric','odds'); $metric=in_array($metric,['odds','amount'],true)?$metric:'odds';
        $min=$request->param('min'); $max=$request->param('max'); if ($min !== null && $min !== '' && is_numeric($min)) $query->where('d.'.$metric,'>=',(float)$min); if ($max !== null && $max !== '' && is_numeric($max)) $query->where('d.'.$metric,'<=',(float)$max);
        if ((string)$request->param('winning','') === '1') $query->where('d.status','won');
        $total=(clone $query)->count(); $page=max(1,(int)$request->param('page',1)); $size=min(100,max(1,(int)$request->param('page_size',50)));
        $sort=(string)$request->param('sort','desc')==='asc'?'asc':'desc';
        return $this->reply(['list'=>$query->order('d.placed_at',$sort)->page($page,$size)->select()->toArray(),'total'=>$total,'page'=>$page,'page_size'=>$size]);
    }
    public function bills(Request $request): \think\response\Json
    {
        $s=$this->session($request); $query=Db::name('bills')->where('site_id',$s['site_id'])->where('user_id',$s['user_id']);
        $from=trim((string)$request->param('from','')); $to=trim((string)$request->param('to','')); if ($from) $query->where('bill_date','>=',$from); if ($to) $query->where('bill_date','<=',$to);
        $list=$query->order('bill_date','desc')->select()->toArray();
        $total=['bet_count'=>0,'amount'=>'0.00','rebate'=>'0.00','offline_rebate'=>'0.00','win_amount'=>'0.00','profit'=>'0.00']; foreach ($list as $row) foreach ($total as $key=>$value) $total[$key]=$key==='bet_count' ? $total[$key]+(int)$row[$key] : number_format((float)$total[$key]+(float)$row[$key],2,'.','');
        return $this->reply(['list'=>$list,'total'=>$total]);
    }
    public function draws(Request $request): \think\response\Json
    {
        $s=$this->session($request); $query=Db::name('lottery_draws')->where('site_id',$s['site_id']); $lottery=trim((string)$request->param('lottery','')); if ($lottery) $query->where('lottery',$lottery);
        $size=min(100,max(1,(int)$request->param('page_size',30))); return $this->reply(['list'=>$query->order('draw_date','desc')->order('issue_no','desc')->limit($size)->select()->toArray()]);
    }

    private function quickLines(string $text, string $lottery): array
    {
        return (new QuickEntryParser())->parse($text, $lottery);
    }
    private function assertLotteryPermission(array $session, string $lottery, bool $bet=false): void
    {
        $lotteryId=(int)Db::name('lotteries')->alias('l')->join('site_lotteries sl','sl.lottery_id=l.id')
            ->where('sl.site_id',$session['site_id'])->where('sl.tenant_id',$session['tenant_id'])->where('l.tenant_id',$session['tenant_id'])
            ->where('l.name',$lottery)->where('l.status',1)->whereNull('l.deleted_at')->value('l.id');
        if ($lotteryId < 1) throw new \InvalidArgumentException('当前站点未开通该彩种');
        $hasPermissions=Db::name('user_lottery_permissions')->where('site_id',$session['site_id'])->where('user_id',$session['user_id'])->count()>0;
        if (!$hasPermissions) return;
        $permission=Db::name('user_lottery_permissions')->where('site_id',$session['site_id'])->where('user_id',$session['user_id'])->where('lottery_id',$lotteryId)->find();
        if (!$permission || (int)$permission['can_view']!==1) throw new \InvalidArgumentException('您没有该彩种的访问权限');
        if ($bet && (int)$permission['can_bet']!==1) throw new \InvalidArgumentException('您没有该彩种的下注权限');
    }
    public function quickPreview(Request $request): \think\response\Json
    {
        $s=$this->session($request); $text=trim((string)$request->post('text','')); if (mb_strlen($text)>10000) return $this->reply(null,'投注文本不能超过10000个字符',422); $lottery=trim((string)$request->post('lottery','福彩3D')); if (!in_array($lottery,['福彩3D','排列三'],true)) return $this->reply(null,'彩种无效',422); $this->assertLotteryPermission($s,$lottery);
        if ($text==='') return $this->reply(['lines'=>[],'count'=>0,'amount'=>'0.00'],'请输入投注文本',422);
        $lines=$this->quickLines($text,$lottery); $count=0; $amount=0.0; foreach ($lines as $line) if ($line['status']==='success') { $count+=(int)$line['count']; $amount+=(float)$line['amount']; }
        return $this->reply(['lines'=>$lines,'count'=>$count,'amount'=>number_format($amount,2,'.','')]);
    }
    public function quickPlace(Request $request): \think\response\Json
    {
        $s=$this->session($request); if (!(bool)$request->post('confirmed',false)) return $this->reply(null,'请确认下注内容后再提交',422);
        if ((string)Db::name('site_users')->where('id',$s['user_id'])->where('site_id',$s['site_id'])->value('account_state')==='bet_paused') return $this->reply(null,'当前账号已暂停下注',403);
        $text=trim((string)$request->post('text','')); if ($text==='' || mb_strlen($text)>10000) return $this->reply(null,'投注文本无效',422); $lottery=trim((string)$request->post('lottery','福彩3D')); if (!in_array($lottery,['福彩3D','排列三'],true)) return $this->reply(null,'彩种无效',422); $this->assertLotteryPermission($s,$lottery,true);
        $closingTime=Db::name('lotteries')->alias('l')->join('site_lotteries sl','sl.lottery_id=l.id')->join('lottery_histories lh','lh.lottery_id=l.id')->where('sl.site_id',$s['site_id'])->where('sl.tenant_id',$s['tenant_id'])->where('l.name',$lottery)->whereNull('l.deleted_at')->order('lh.open_time desc')->value('lh.next_open_time');
        $origin=(string)$request->header('origin');
        $localTest=preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#i',$origin)===1;
        if (!$localTest && $closingTime && strtotime((string)$closingTime) <= time()) return $this->reply(null,'当前彩种已到开奖时间，已封盘，暂不能下注',423);
        $lines=$this->quickLines($text,$lottery); $valid=array_values(array_filter($lines,static fn(array $line): bool=>$line['status']==='success')); if (!$valid) return $this->reply(null,'没有可下注的有效内容',422);
        $now=date('Y-m-d H:i:s'); $amount=0.0; $count=0; foreach ($valid as $line) { $amount+=(float)$line['amount']; $count+=(int)$line['count']; }
        $issueNo=(string)Db::name('lotteries')->alias('l')->join('site_lotteries sl','sl.lottery_id=l.id')->join('lottery_histories lh','lh.lottery_id=l.id')->where('sl.site_id',$s['site_id'])->where('sl.tenant_id',$s['tenant_id'])->where('l.name',$lottery)->whereNull('l.deleted_at')->order('lh.open_time desc')->value('lh.next_code');
        if ($issueNo==='') return $this->reply(null,'当前彩种暂无可下注期号，请稍后刷新开奖数据',422);
        $user=Db::name('site_users')->where('id',$s['user_id'])->where('site_id',$s['site_id'])->whereNull('deleted_at')->field('balance,credit_balance,used_balance')->find();
        if (!$user) return $this->reply(null,'用户不存在或已停用',404);
        $available=(float)$user['balance']+(float)$user['credit_balance']-(float)$user['used_balance']; if ($amount>$available) return $this->reply(null,'可用余额不足，无法下注',422);
        $duplicate=Db::name('bet_records')->where('site_id',$s['site_id'])->where('user_id',$s['user_id'])->where('source_text',$text)->where('created_at','>=',date('Y-m-d H:i:s',time()-15))->find(); if ($duplicate) return $this->reply(['record_id'=>(int)$duplicate['id'],'count'=>(int)$duplicate['bet_count'],'amount'=>number_format((float)$duplicate['amount'],2,'.','')],'请勿重复提交',409);
        try {
            $recordId=(int)Db::transaction(function () use ($s,$text,$lottery,$valid,$amount,$count,$now,$issueNo): int {
                $recordId=(int)Db::name('bet_records')->insertGetId(['tenant_id'=>$s['tenant_id'],'site_id'=>$s['site_id'],'user_id'=>$s['user_id'],'issue_no'=>$issueNo,'source_text'=>$text,'bet_count'=>$count,'amount'=>$amount,'win_amount'=>0,'status'=>'pending','sealed'=>0,'placed_at'=>$now,'created_at'=>$now]);
                foreach ($valid as $line) {
                    $detailId=(int)Db::name('bet_details')->insertGetId(['tenant_id'=>$s['tenant_id'],'site_id'=>$s['site_id'],'user_id'=>$s['user_id'],'bet_record_id'=>$recordId,'issue_no'=>$issueNo,'number_text'=>$line['number_text'],'category'=>$line['category'],'amount'=>$line['amount'],'odds'=>null,'win_amount'=>0,'rebate'=>0,'status'=>'pending','placed_at'=>$now,'source_text'=>$line['raw_text']]);
                    preg_match('/直|组|胆|拖|跨|和|单双|大小|飞|定位|复式|豹子/u',(string)$line['raw_text'],$playMatch); $playType=(string)($playMatch[0] ?? '');
                    Db::name('user_stop_drops')->insert(['tenant_id'=>$s['tenant_id'],'site_id'=>$s['site_id'],'user_id'=>$s['user_id'],'bet_detail_id'=>$detailId,'lottery'=>$lottery,'issue_no'=>$issueNo,'number_text'=>$line['number_text'],'play_type'=>$playType,'stop_type'=>'none','original_amount'=>$line['amount'],'actual_amount'=>$line['amount'],'stop_amount'=>0,'original_odds'=>null,'actual_odds'=>null,'drop_odds'=>null,'source_text'=>$line['raw_text'],'placed_at'=>$now,'created_at'=>$now]);
                }
                Db::name('site_users')->where('id',$s['user_id'])->where('site_id',$s['site_id'])->update(['used_balance'=>Db::raw('used_balance + '.number_format($amount,2,'.',''))]);
                return $recordId;
            });
        } catch (\Throwable $e) {
            Log::error('quickPlace failed: '.$e->getMessage().' at '.$e->getFile().':'.$e->getLine());
            $origin=(string)$request->header('origin');
            $localTest=preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d+)?$#i',$origin)===1;
            return $this->reply(null,$localTest ? '下注保存失败：'.$e->getMessage() : '下注保存失败，请稍后重试',500);
        }
        return $this->reply(['record_id'=>$recordId,'count'=>$count,'amount'=>number_format($amount,2,'.','')],'下注提交成功');
    }
    public function quickSettings(Request $request): \think\response\Json
    {
        $s=$this->session($request); $row=Db::name('user_quick_preferences')->where('site_id',$s['site_id'])->where('user_id',$s['user_id'])->find(); $tags=Db::name('user_quick_tags')->where('site_id',$s['site_id'])->where('user_id',$s['user_id'])->order('id')->field('id,name')->select()->toArray();
        return $this->reply(['preferences'=>$row ? (json_decode((string)$row['preferences'],true) ?: []) : ['autoBet'=>true,'recognize'=>false,'copyTicket'=>false,'copyHeader'=>false,'textMode'=>false,'lottery'=>'福彩3D'],'tags'=>$tags]);
    }
    public function saveQuickSettings(Request $request): \think\response\Json
    {
        $s=$this->session($request); $preferences=$request->post('preferences',[]); if (!is_array($preferences)) return $this->reply(null,'偏好设置格式错误',422); $now=date('Y-m-d H:i:s'); $existing=Db::name('user_quick_preferences')->where('site_id',$s['site_id'])->where('user_id',$s['user_id'])->find(); $data=['tenant_id'=>$s['tenant_id'],'site_id'=>$s['site_id'],'user_id'=>$s['user_id'],'preferences'=>json_encode($preferences,JSON_UNESCAPED_UNICODE),'updated_at'=>$now]; if ($existing) Db::name('user_quick_preferences')->where('id',$existing['id'])->update($data); else Db::name('user_quick_preferences')->insert($data); return $this->reply(['preferences'=>$preferences],'设置已保存');
    }
    public function createQuickTag(Request $request): \think\response\Json
    {
        $s=$this->session($request); $name=trim((string)$request->post('name','')); if ($name==='' || mb_strlen($name)>40) return $this->reply(null,'标签名称无效',422); try { $id=Db::name('user_quick_tags')->insertGetId(['tenant_id'=>$s['tenant_id'],'site_id'=>$s['site_id'],'user_id'=>$s['user_id'],'name'=>$name,'created_at'=>date('Y-m-d H:i:s')]); } catch (\Throwable $e) { return $this->reply(null,'标签已存在',409); } return $this->reply(['id'=>$id,'name'=>$name],'标签已添加');
    }
    public function deleteQuickTag(Request $request): \think\response\Json
    {
        $s=$this->session($request); $id=(int)$request->param('id'); Db::name('user_quick_tags')->where('id',$id)->where('site_id',$s['site_id'])->where('user_id',$s['user_id'])->delete(); return $this->reply(null,'标签已删除');
    }
}
