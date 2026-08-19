<?php
declare(strict_types=1);
namespace app\command;

use app\service\LotteryHistorySync;
use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\Output;

final class BackfillLotteryHistory extends Command
{
    protected function configure(): void
    {
        $this->setName('lottery:backfill')->setDescription('一次性批量回填指定彩票的开奖历史')
            ->addArgument('lottery_id',Argument::REQUIRED,'彩票 ID')
            ->addArgument('pages',Argument::OPTIONAL,'页数，最大 100；完整回填可传 100',10)
            ->addArgument('limit',Argument::OPTIONAL,'每页条数，最大 1000',1000)
            ->addArgument('delay',Argument::OPTIONAL,'每页请求后的等待秒数',0);
    }
    protected function execute(Input $input, Output $output): int
    {
        $result=(new LotteryHistorySync())->backfill((int)$input->getArgument('lottery_id'),(int)$input->getArgument('pages'),(int)$input->getArgument('limit'),(int)$input->getArgument('delay'));
        $output->writeln(json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)); return $result['failed_pages'] ? 1 : 0;
    }
}
