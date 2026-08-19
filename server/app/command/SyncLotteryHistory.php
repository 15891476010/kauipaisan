<?php
declare(strict_types=1);
namespace app\command;

use app\service\LotteryHistorySync;
use think\console\Command;
use think\console\Input;
use think\console\Output;

final class SyncLotteryHistory extends Command
{
    protected function configure(): void { $this->setName('lottery:sync')->setDescription('同步每种彩票最新一期开奖记录'); }
    protected function execute(Input $input, Output $output): int
    {
        $results=(new LotteryHistorySync())->syncAll();
        foreach ($results as $result) $output->writeln(sprintf('lottery=%d inserted=%d updated=%d time=%.2fms ok=%s',$result['lottery_id'],$result['inserted'],$result['updated'],$result['response_time_ms'],$result['ok']?'yes':'no'));
        return 0;
    }
}
