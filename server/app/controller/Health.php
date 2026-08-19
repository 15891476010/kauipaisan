<?php
declare(strict_types=1);
namespace app\controller;
use think\facade\Db;
final class Health
{
    public function index(): \think\response\Json
    {
        $db = 'ok';
        try { Db::query('SELECT 1'); } catch (\Throwable) { $db = 'error'; }
        return json(['code'=>0,'message'=>'ok','data'=>['service'=>'api','database'=>$db,'timestamp'=>date(DATE_ATOM)],'request_id'=>bin2hex(random_bytes(8))]);
    }
}

