<?php
declare(strict_types=1);
namespace app\controller\User;

/** User API entry; explicit signatures preserve ThinkPHP argument binding. */
class ThirdPartyQuickEntry
{
    private object $delegate;

    public function __construct()
    {
        $this->delegate = new \app\controller\ThirdPartyQuickEntry();
    }

    public function preview(\think\Request $request): \think\response\Json
    {
        return $this->delegate->preview($request);
    }
}
