<?php
declare(strict_types=1);
namespace app\controller\Saas;

/** Saas API entry; explicit signatures preserve ThinkPHP argument binding. */
class AgentImport
{
    private object $delegate;

    public function __construct()
    {
        $this->delegate = new \app\controller\AgentImport();
    }

    public function profiles(\think\Request $r): \think\response\Json
    {
        return $this->delegate->profiles($r);
    }

    public function saveProfile(\think\Request $r): \think\response\Json
    {
        return $this->delegate->saveProfile($r);
    }

    public function probe(\think\Request $r): \think\response\Json
    {
        return $this->delegate->probe($r);
    }

    public function batches(\think\Request $r): \think\response\Json
    {
        return $this->delegate->batches($r);
    }
}
