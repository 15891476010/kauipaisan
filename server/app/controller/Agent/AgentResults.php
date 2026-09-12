<?php
declare(strict_types=1);
namespace app\controller\Agent;

/** Agent API entry; explicit signatures preserve ThinkPHP argument binding. */
class AgentResults
{
    private object $delegate;

    public function __construct()
    {
        $this->delegate = new \app\controller\AgentResults();
    }

    public function index(\think\Request $r): \think\response\Json
    {
        return $this->delegate->index($r);
    }
}
