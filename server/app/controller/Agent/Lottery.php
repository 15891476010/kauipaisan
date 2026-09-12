<?php
declare(strict_types=1);
namespace app\controller\Agent;

/** Agent API entry; explicit signatures preserve ThinkPHP argument binding. */
class Lottery
{
    private object $delegate;

    public function __construct()
    {
        $this->delegate = new \app\controller\Lottery();
    }

    public function agent(\think\Request $request): \think\response\Json
    {
        return $this->delegate->agent($request);
    }
}
