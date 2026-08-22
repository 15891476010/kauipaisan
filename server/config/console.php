<?php
return ['commands'=>['lottery:sync'=>\app\command\SyncLotteryHistory::class,'lottery:backfill'=>\app\command\BackfillLotteryHistory::class]];
