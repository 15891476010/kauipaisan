<?php
declare(strict_types=1);
namespace app\controller\User;

/** User API entry; explicit signatures preserve ThinkPHP argument binding. */
class Lottery
{
    private object $delegate;

    public function __construct()
    {
        $this->delegate = new \app\controller\Lottery();
    }

    public function user(\think\Request $request): \think\response\Json
    {
        return $this->delegate->user($request);
    }
}
