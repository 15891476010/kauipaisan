<?php
declare(strict_types=1);

namespace app\command;

use app\service\ThirdPartyQuickEntryClient;
use app\service\ThirdPartyQuickEntryConfig;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;

/** Runs the periodic AK liveness check for every configured account pool. */
final class CheckThirdPartyQuickEntryAccounts extends Command
{
    protected function configure(): void
    {
        $this->setName('quick-entry:health-check')
            ->setDescription('逐个检活三方识别账号，AK 失效时自动重新登录');
    }

    protected function execute(Input $input, Output $output): int
    {
        $rows=Db::name('settings')->where('key',ThirdPartyQuickEntryConfig::SETTING_KEY)
            ->field('tenant_id,site_id')->select()->toArray();
        $checked=0;$valid=0;$relogged=0;$failed=0;
        foreach($rows as $row){
            $tenantId=(int)($row['tenant_id']??0);
            $siteId=isset($row['site_id'])?(int)$row['site_id']:null;
            if($tenantId<1)continue;
            try{
                $config=ThirdPartyQuickEntryConfig::load($tenantId,$siteId);
                if(empty($config['enabled'])||empty($config['accounts']))continue;
                $results=(new ThirdPartyQuickEntryClient($config))->healthCheckAllAccounts();
                foreach($results as $result){
                    $checked++;$status=(string)($result['status']??'failed');
                    if($status==='valid')$valid++;elseif($status==='relogged')$relogged++;else $failed++;
                    // AK is intentionally excluded from service logs. It is
                    // still available in the protected SaaS account-pool UI.
                    $output->writeln(json_encode([
                        'tenant_id'=>$tenantId,'site_id'=>$siteId,
                        'account'=>(string)($result['username']??''),
                        'status'=>$status,'reason'=>(string)($result['reason']??''),
                        'duration_ms'=>(int)($result['duration_ms']??0),
                        'error'=>(string)($result['error']??''),
                    ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
                }
            }catch(\Throwable $error){
                $failed++;
                $output->writeln(json_encode(['tenant_id'=>$tenantId,'site_id'=>$siteId,'status'=>'failed','error'=>$error->getMessage()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
            }
        }
        $output->writeln(sprintf('checked=%d valid=%d relogged=%d failed=%d',$checked,$valid,$relogged,$failed));
        return 0;
    }
}
