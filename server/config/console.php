<?php
return ['commands'=>[
    'lottery:sync'=>\app\command\SyncLotteryHistory::class,
    'lottery:backfill'=>\app\command\BackfillLotteryHistory::class,
    'robot:run'=>\app\command\RunRobotScheduler::class,
    'quick-entry:health-check'=>\app\command\CheckThirdPartyQuickEntryAccounts::class,
]];
