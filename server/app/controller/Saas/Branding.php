<?php
declare(strict_types=1);
namespace app\controller\Saas;

/** Saas API entry; explicit signatures preserve ThinkPHP argument binding. */
class Branding
{
    private object $delegate;

    public function __construct()
    {
        $this->delegate = new \app\controller\Branding();
    }

    public function adminSettings(\think\Request $request): \think\response\Json
    {
        return $this->delegate->adminSettings($request);
    }

    public function saveAdminSettings(\think\Request $request): \think\response\Json
    {
        return $this->delegate->saveAdminSettings($request);
    }
}
