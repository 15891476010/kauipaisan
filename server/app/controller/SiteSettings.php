<?php
declare(strict_types=1);

namespace app\controller;

use think\Request;
use think\facade\Cache;
use think\facade\Db;

final class SiteSettings
{
    private const DEFAULT_AGREEMENT_CONTENT = "致会员\n\n1. 当您在下注之后，请等待下注后的成功状态信息。\n2. 为了避免出现争议，**您必须在下注之后检查“下注状况”。**\n3. 任何的投诉必须在开奖之前提出，**本公司不会受理任何开奖之后的投诉。**\n4. 所有投注项目，公布赔率时出现的任何打字错误或非故意人为失误，本公司保留改正错误和按正确赔率结算投注的权力。\n5. 开奖后的投注，将被视为“无效”。所有赔率将不定时浮动，**派彩时的赔率将以下注明细里赔率为准。**\n6. 敬告有意与本公司博彩之客户，应注意您所在的国家或居住地可能规定网络博彩不合法，若此情况属实，本公司将不接受任何客户因违反当地法律所引起之任何责任。\n7. 倘若发生黑客入侵、系统故障或资料损坏等情况，我们将以线上交易后的备份资料为最后处理依据；为确保各方利益，请各会员交易后打印资料。\n8. 交易之后务必进入下注明细检查，若发生任何异常，**请立即与代理商联系查证。**\n9. 如遇天灾、停电或其他不可抗力因素导致无法运作时，得中止所有未开奖前的投注。\n10. 如发生临时性、突发性等特殊情况，本公司有权作出相对应之决定。\n11. 本公司所有投注皆含本金，请认真了解规则说明。\n\n## 12. 特别提醒\n\n> ① 本公司如果输入开奖结果错误，有权利更正开奖结果，最终以官方最后公布结果为准。\n>\n> ② 为避免争议，请各会员到第二天早上才开始兑奖，当天晚上兑奖造成的损失由会员自负。";
    private const DEFAULT_AGENT_AGREEMENT_CONTENT = "1. 用户明确同意本系统的使用由用户个人承担风险。\n\n2. 本系统不作任何类型的担保，不担保服务一定能满足用户的要求，也不担保服务不会受中断；对服务的及时性、安全性、出错发生都不作担保。用户理解并接受，任何通过本系统服务取得的信息资料的可靠性取决于用户自己，用户自己承担所有风险和责任。\n\n3. 本声明的最终解释权归本系统所有。\n\n## 4. 特别提醒\n\n> ① 本公司如果输入开奖结果错误，有权利更正开奖结果，最终以官方最后公布结果为准。\n>\n> ② 为了避免出现争议，请各会员到第二天早上才开始兑奖。当天晚上兑奖造成的损失由会员自负。";
    private const DEFAULT_RULE_BASIC = "基础玩法\n支持直选、组选、定位和胆拖等常用玩法。\n请输入完整的号码、玩法和金额，系统会根据当前彩种进行识别。";
    private const DEFAULT_RULE_SPECIAL = "特殊打法\n特殊玩法请按照对应格式输入，多个号码之间使用空格分隔。\n如有疑问，请先输入示例并查看识别结果。";
    private const DEFAULT_RULE_AMOUNT = "总金额\n每注金额和总金额请使用清晰的单位标识，避免产生歧义。\n系统会在生成投注内容时自动计算号码数量和金额。";
    private const DEFAULT_RULE_TEXT = "123\n456\n789\n一直一组\n【重点】建议写成：\n123 456 789—一直一组\n四、金额\n【重点】单注金额前面尽量带上【各】字\n12 45 89 88 62 飞各6米\n【重点】总金额前面尽量带上【共】字\n1拖2345 5拖23468 6拖123组六各10米共30米\n【重点】金额单位尽量不要使用【倍】，建议以【元】或【角】为单位\n五、彩种\n【重点】彩种文本尽量使用【福】和【体】，其后不要加【3】或【三】\n福123 456—一直一组\n【重点】下注成功后请到“下注明细”确认最终注单";
    private function reply(mixed $data=null, string $message='ok', int $code=0): \think\response\Json
    {
        return json(['code'=>$code,'message'=>$message,'data'=>$data,'request_id'=>bin2hex(random_bytes(8))]);
    }

    private function adminSiteId(Request $request, ?int $requestedSiteId=null): int
    {
        $token=trim(str_ireplace('Bearer ','',(string)$request->header('authorization')));
        $session=$token !== '' ? Cache::get('token:'.$token) : null;
        if (!is_array($session) || ($session['scope'] ?? '') !== 'admin') throw new \RuntimeException('未登录或登录已过期');
        if (($session['admin_role'] ?? 'platform') === 'site') return (int)($session['site_id'] ?? 0);
        return (int)($requestedSiteId ?? 0);
    }

    private function readAgreement(int $siteId): array
    {
        $values=Db::name('settings')->where('site_id',$siteId)->whereIn('key',['agreement_title','agreement_content'])->column('value','key');
        return [
            'title'=>trim((string)($values['agreement_title'] ?? '')) ?: '责任声明',
            'content'=>trim((string)($values['agreement_content'] ?? '')) ?: self::DEFAULT_AGREEMENT_CONTENT,
        ];
    }

    private function readAnnouncement(int $siteId): array
    {
        $values=Db::name('settings')->where('site_id',$siteId)->whereIn('key',['announcement_title','announcement_content'])->column('value','key');
        return [
            'title'=>trim((string)($values['announcement_title'] ?? '')) ?: '公告',
            'content'=>trim((string)($values['announcement_content'] ?? '')) ?: '暂无公告',
        ];
    }

    private function readAgentAgreement(int $siteId): array
    {
        $values=Db::name('settings')->where('site_id',$siteId)->whereIn('key',['agent_agreement_title','agent_agreement_content'])->column('value','key');
        return [
            'title'=>trim((string)($values['agent_agreement_title'] ?? '')) ?: '代理服务协议',
            'content'=>trim((string)($values['agent_agreement_content'] ?? '')) ?: self::DEFAULT_AGENT_AGREEMENT_CONTENT,
        ];
    }

    private function readAgentAnnouncement(int $siteId): array
    {
        $values=Db::name('settings')->where('site_id',$siteId)->whereIn('key',['agent_announcement_title','agent_announcement_content'])->column('value','key');
        return [
            'title'=>trim((string)($values['agent_announcement_title'] ?? '')) ?: '代理端公告',
            'content'=>trim((string)($values['agent_announcement_content'] ?? '')) ?: '暂无公告',
        ];
    }

    private function agentSiteId(Request $request): int
    {
        $token=trim(str_ireplace('Bearer ','',(string)$request->header('authorization')));
        $session=$token !== '' ? Cache::get('token:'.$token) : null;
        if (!is_array($session) || ($session['scope'] ?? '') !== 'agent') return 0;
        return (int)($session['site_id'] ?? 0);
    }

    private function saveSettingPair(int $siteId, string $titleKey, string $contentKey, string $title, string $content): void
    {
        $tenantId=(int)Db::name('sites')->where('id',$siteId)->value('tenant_id');
        $now=date('Y-m-d H:i:s');
        foreach ([$titleKey=>$title,$contentKey=>$content] as $key=>$value) {
            $existing=Db::name('settings')->where('tenant_id',$tenantId)->where('site_id',$siteId)->where('key',$key)->find();
            if ($existing) Db::name('settings')->where('id',$existing['id'])->update(['value'=>$value,'updated_at'=>$now]);
            else Db::name('settings')->insert(['tenant_id'=>$tenantId,'site_id'=>$siteId,'key'=>$key,'value'=>$value,'updated_at'=>$now]);
        }
    }

    private function readRules(int $siteId): array
    {
        $keys=['rule_title','rule_basic','rule_special','rule_amount','rule_text'];
        $values=Db::name('settings')->where('site_id',$siteId)->whereIn('key',$keys)->column('value','key');
        return [
            'title'=>trim((string)($values['rule_title'] ?? '')) ?: '规则说明',
            'basic'=>trim((string)($values['rule_basic'] ?? '')) ?: self::DEFAULT_RULE_BASIC,
            'special'=>trim((string)($values['rule_special'] ?? '')) ?: self::DEFAULT_RULE_SPECIAL,
            'amount'=>trim((string)($values['rule_amount'] ?? '')) ?: self::DEFAULT_RULE_AMOUNT,
            'text'=>trim((string)($values['rule_text'] ?? '')) ?: self::DEFAULT_RULE_TEXT,
        ];
    }

    public function adminAgreement(Request $request): \think\response\Json
    {
        $siteId=$this->adminSiteId($request,(int)$request->param('site_id',0));
        if ($siteId < 1 || !Db::name('sites')->where('id',$siteId)->whereNull('deleted_at')->find()) throw new \InvalidArgumentException('请选择有效站点');
        return $this->reply(array_merge(['site_id'=>$siteId],$this->readAgreement($siteId)));
    }

    public function saveAdminAgreement(Request $request): \think\response\Json
    {
        $data=$request->put();
        $siteId=$this->adminSiteId($request,(int)($data['site_id'] ?? 0));
        if ($siteId < 1 || !Db::name('sites')->where('id',$siteId)->whereNull('deleted_at')->find()) throw new \InvalidArgumentException('请选择有效站点');
        $title=trim((string)($data['title'] ?? ''));
        $content=trim((string)($data['content'] ?? ''));
        if ($title === '') throw new \InvalidArgumentException('请输入声明标题');
        if ($content === '') throw new \InvalidArgumentException('请输入声明正文');
        if (mb_strlen($title) > 120) throw new \InvalidArgumentException('声明标题不能超过120个字符');
        if (mb_strlen($content) > 10000) throw new \InvalidArgumentException('声明正文不能超过10000个字符');
        $tenantId=(int)Db::name('sites')->where('id',$siteId)->value('tenant_id');
        $now=date('Y-m-d H:i:s');
        foreach (['agreement_title'=>$title,'agreement_content'=>$content] as $key=>$value) {
            $existing=Db::name('settings')->where('tenant_id',$tenantId)->where('site_id',$siteId)->where('key',$key)->find();
            if ($existing) Db::name('settings')->where('id',$existing['id'])->update(['value'=>$value,'updated_at'=>$now]);
            else Db::name('settings')->insert(['tenant_id'=>$tenantId,'site_id'=>$siteId,'key'=>$key,'value'=>$value,'updated_at'=>$now]);
        }
        return $this->reply($this->readAgreement($siteId),'保存成功');
    }

    public function adminAnnouncement(Request $request): \think\response\Json
    {
        $siteId=$this->adminSiteId($request,(int)$request->param('site_id',0));
        if ($siteId < 1 || !Db::name('sites')->where('id',$siteId)->whereNull('deleted_at')->find()) throw new \InvalidArgumentException('请选择有效站点');
        return $this->reply(array_merge(['site_id'=>$siteId],$this->readAnnouncement($siteId)));
    }

    public function saveAdminAnnouncement(Request $request): \think\response\Json
    {
        $data=$request->put();
        $siteId=$this->adminSiteId($request,(int)($data['site_id'] ?? 0));
        if ($siteId < 1 || !Db::name('sites')->where('id',$siteId)->whereNull('deleted_at')->find()) throw new \InvalidArgumentException('请选择有效站点');
        $title=trim((string)($data['title'] ?? ''));
        $content=trim((string)($data['content'] ?? ''));
        if ($title === '') throw new \InvalidArgumentException('请输入公告标题');
        if ($content === '') throw new \InvalidArgumentException('请输入公告内容');
        if (mb_strlen($title) > 120) throw new \InvalidArgumentException('公告标题不能超过120个字符');
        if (mb_strlen($content) > 20000) throw new \InvalidArgumentException('公告内容不能超过20000个字符');
        $tenantId=(int)Db::name('sites')->where('id',$siteId)->value('tenant_id');
        $now=date('Y-m-d H:i:s');
        foreach (['announcement_title'=>$title,'announcement_content'=>$content] as $key=>$value) {
            $existing=Db::name('settings')->where('tenant_id',$tenantId)->where('site_id',$siteId)->where('key',$key)->find();
            if ($existing) Db::name('settings')->where('id',$existing['id'])->update(['value'=>$value,'updated_at'=>$now]);
            else Db::name('settings')->insert(['tenant_id'=>$tenantId,'site_id'=>$siteId,'key'=>$key,'value'=>$value,'updated_at'=>$now]);
        }
        return $this->reply($this->readAnnouncement($siteId),'保存成功');
    }

    public function adminAgentAgreement(Request $request): \think\response\Json
    {
        $siteId=$this->adminSiteId($request,(int)$request->param('site_id',0));
        if ($siteId < 1 || !Db::name('sites')->where('id',$siteId)->whereNull('deleted_at')->find()) throw new \InvalidArgumentException('请选择有效站点');
        return $this->reply(array_merge(['site_id'=>$siteId],$this->readAgentAgreement($siteId)));
    }

    public function saveAdminAgentAgreement(Request $request): \think\response\Json
    {
        $data=$request->put();
        $siteId=$this->adminSiteId($request,(int)($data['site_id'] ?? 0));
        if ($siteId < 1 || !Db::name('sites')->where('id',$siteId)->whereNull('deleted_at')->find()) throw new \InvalidArgumentException('请选择有效站点');
        $title=trim((string)($data['title'] ?? '')); $content=trim((string)($data['content'] ?? ''));
        if ($title === '' || $content === '') throw new \InvalidArgumentException('请输入代理端协议标题和正文');
        if (mb_strlen($title)>120 || mb_strlen($content)>50000) throw new \InvalidArgumentException('代理端协议内容超过长度限制');
        $this->saveSettingPair($siteId,'agent_agreement_title','agent_agreement_content',$title,$content);
        return $this->reply($this->readAgentAgreement($siteId),'保存成功');
    }

    public function adminAgentAnnouncement(Request $request): \think\response\Json
    {
        $siteId=$this->adminSiteId($request,(int)$request->param('site_id',0));
        if ($siteId < 1 || !Db::name('sites')->where('id',$siteId)->whereNull('deleted_at')->find()) throw new \InvalidArgumentException('请选择有效站点');
        return $this->reply(array_merge(['site_id'=>$siteId],$this->readAgentAnnouncement($siteId)));
    }

    public function saveAdminAgentAnnouncement(Request $request): \think\response\Json
    {
        $data=$request->put();
        $siteId=$this->adminSiteId($request,(int)($data['site_id'] ?? 0));
        if ($siteId < 1 || !Db::name('sites')->where('id',$siteId)->whereNull('deleted_at')->find()) throw new \InvalidArgumentException('请选择有效站点');
        $title=trim((string)($data['title'] ?? '')); $content=trim((string)($data['content'] ?? ''));
        if ($title === '' || $content === '') throw new \InvalidArgumentException('请输入代理端公告标题和内容');
        if (mb_strlen($title)>120 || mb_strlen($content)>20000) throw new \InvalidArgumentException('代理端公告内容超过长度限制');
        $this->saveSettingPair($siteId,'agent_announcement_title','agent_announcement_content',$title,$content);
        return $this->reply($this->readAgentAnnouncement($siteId),'保存成功');
    }

    public function adminRules(Request $request): \think\response\Json
    {
        $siteId=$this->adminSiteId($request,(int)$request->param('site_id',0));
        if ($siteId < 1 || !Db::name('sites')->where('id',$siteId)->whereNull('deleted_at')->find()) throw new \InvalidArgumentException('请选择有效站点');
        return $this->reply(array_merge(['site_id'=>$siteId],$this->readRules($siteId)));
    }

    public function saveAdminRules(Request $request): \think\response\Json
    {
        $data=$request->put();
        $siteId=$this->adminSiteId($request,(int)($data['site_id'] ?? 0));
        if ($siteId < 1 || !Db::name('sites')->where('id',$siteId)->whereNull('deleted_at')->find()) throw new \InvalidArgumentException('请选择有效站点');
        $fields=['rule_title'=>'title','rule_basic'=>'basic','rule_special'=>'special','rule_amount'=>'amount','rule_text'=>'text'];
        $values=[];
        foreach ($fields as $key=>$field) {
            $value=trim((string)($data[$field] ?? ''));
            if ($value === '') throw new \InvalidArgumentException('规则说明内容不能为空');
            if (mb_strlen($value) > 50000) throw new \InvalidArgumentException('单项规则内容不能超过50000个字符');
            $values[$key]=$value;
        }
        $tenantId=(int)Db::name('sites')->where('id',$siteId)->value('tenant_id');
        $now=date('Y-m-d H:i:s');
        foreach ($values as $key=>$value) {
            $existing=Db::name('settings')->where('tenant_id',$tenantId)->where('site_id',$siteId)->where('key',$key)->find();
            if ($existing) Db::name('settings')->where('id',$existing['id'])->update(['value'=>$value,'updated_at'=>$now]);
            else Db::name('settings')->insert(['tenant_id'=>$tenantId,'site_id'=>$siteId,'key'=>$key,'value'=>$value,'updated_at'=>$now]);
        }
        return $this->reply($this->readRules($siteId),'保存成功');
    }

    public function userAgreement(Request $request): \think\response\Json
    {
        $token=trim(str_ireplace('Bearer ','',(string)$request->header('authorization')));
        $session=$token !== '' ? Cache::get('token:'.$token) : null;
        if (!is_array($session) || ($session['scope'] ?? '') !== 'user') return $this->reply(null,'未登录或登录已过期',401);
        $siteId=(int)($session['site_id'] ?? 0);
        if ($siteId < 1 || !Db::name('sites')->where('id',$siteId)->whereNull('deleted_at')->find()) return $this->reply(null,'当前站点不可用',422);
        return $this->reply($this->readAgreement($siteId));
    }

    public function userAnnouncement(Request $request): \think\response\Json
    {
        $token=trim(str_ireplace('Bearer ','',(string)$request->header('authorization')));
        $session=$token !== '' ? Cache::get('token:'.$token) : null;
        if (!is_array($session) || ($session['scope'] ?? '') !== 'user') return $this->reply(null,'未登录或登录已过期',401);
        $siteId=(int)($session['site_id'] ?? 0);
        if ($siteId < 1 || !Db::name('sites')->where('id',$siteId)->whereNull('deleted_at')->find()) return $this->reply(null,'当前站点不可用',422);
        return $this->reply($this->readAnnouncement($siteId));
    }

    public function userRules(Request $request): \think\response\Json
    {
        $token=trim(str_ireplace('Bearer ','',(string)$request->header('authorization')));
        $session=$token !== '' ? Cache::get('token:'.$token) : null;
        if (!is_array($session) || ($session['scope'] ?? '') !== 'user') return $this->reply(null,'未登录或登录已过期',401);
        $siteId=(int)($session['site_id'] ?? 0);
        if ($siteId < 1 || !Db::name('sites')->where('id',$siteId)->whereNull('deleted_at')->find()) return $this->reply(null,'当前站点不可用',422);
        return $this->reply($this->readRules($siteId));
    }

    public function agentAgreement(Request $request): \think\response\Json
    {
        $siteId=$this->agentSiteId($request);
        if ($siteId < 1) return $this->reply(null,'未登录或登录已过期',401);
        if (!Db::name('sites')->where('id',$siteId)->whereNull('deleted_at')->find()) return $this->reply(null,'当前站点不可用',422);
        return $this->reply($this->readAgentAgreement($siteId));
    }

    public function agentAnnouncement(Request $request): \think\response\Json
    {
        $siteId=$this->agentSiteId($request);
        if ($siteId < 1) return $this->reply(null,'未登录或登录已过期',401);
        if (!Db::name('sites')->where('id',$siteId)->whereNull('deleted_at')->find()) return $this->reply(null,'当前站点不可用',422);
        return $this->reply($this->readAgentAnnouncement($siteId));
    }

    public function agentRules(Request $request): \think\response\Json
    {
        $siteId=$this->agentSiteId($request);
        if ($siteId < 1) return $this->reply(null,'未登录或登录已过期',401);
        if (!Db::name('sites')->where('id',$siteId)->whereNull('deleted_at')->find()) return $this->reply(null,'当前站点不可用',422);
        return $this->reply($this->readRules($siteId));
    }
}
