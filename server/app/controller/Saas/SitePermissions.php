<?php
declare(strict_types=1);
namespace app\controller\Saas;

/** Saas API entry; explicit signatures preserve ThinkPHP argument binding. */
class SitePermissions
{
    private object $delegate;

    public function __construct()
    {
        $this->delegate = new \app\controller\SitePermissions();
    }

    public function show(\think\Request $request, int $siteId): \think\response\Json
    {
        return $this->delegate->show($request, $siteId);
    }

    public function save(\think\Request $request, int $siteId): \think\response\Json
    {
        return $this->delegate->save($request, $siteId);
    }
}
