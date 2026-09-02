<?php
declare(strict_types=1);

namespace app\command;

use app\service\RobotScheduler;
use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;

/** Long-running one-second robot scheduler. */
final class RunRobotScheduler extends Command
{
    protected function configure(): void
    {
        $this->setName('robot:run')->setDescription('每秒执行自动打单机器人任务')
            ->addOption('once', null, Option::VALUE_NONE, '只执行一次调度检查（健康检查用）');
    }

    protected function execute(Input $input, Output $output): int
    {
        $scheduler = new RobotScheduler();
        $output->writeln('robot scheduler started');
        if ($input->getOption('once')) {
            $output->writeln(json_encode($scheduler->tick(), JSON_UNESCAPED_UNICODE));
            return 0;
        }
        while (true) {
            $started = microtime(true);
            try {
                $result = $scheduler->tick();
                if (($result['claimed'] ?? 0) > 0) $output->writeln(json_encode($result, JSON_UNESCAPED_UNICODE));
            } catch (\Throwable $error) {
                $output->writeln('robot scheduler tick failed: '.$error->getMessage());
            }
            $sleep = max(0, 1.0 - (microtime(true) - $started));
            if ($sleep > 0) usleep((int)round($sleep * 1000000));
        }
    }
}
