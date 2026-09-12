<?php
declare(strict_types=1);
namespace app\controller\Saas;

/** Saas API entry; explicit signatures preserve ThinkPHP argument binding. */
class Lottery
{
    private object $delegate;

    public function __construct()
    {
        $this->delegate = new \app\controller\Lottery();
    }

    public function index(\think\Request $request): \think\response\Json
    {
        return $this->delegate->index($request);
    }

    public function create(\think\Request $request): \think\response\Json
    {
        return $this->delegate->create($request);
    }

    public function rules(\think\Request $request): \think\response\Json
    {
        return $this->delegate->rules($request);
    }

    public function saveRules(\think\Request $request): \think\response\Json
    {
        return $this->delegate->saveRules($request);
    }

    public function assign(\think\Request $request): \think\response\Json
    {
        return $this->delegate->assign($request);
    }

    public function update(\think\Request $request): \think\response\Json
    {
        return $this->delegate->update($request);
    }

    public function delete(\think\Request $request): \think\response\Json
    {
        return $this->delegate->delete($request);
    }

    public function config(\think\Request $request): \think\response\Json
    {
        return $this->delegate->config($request);
    }

    public function saveConfig(\think\Request $request): \think\response\Json
    {
        return $this->delegate->saveConfig($request);
    }

    public function testConfig(\think\Request $request): \think\response\Json
    {
        return $this->delegate->testConfig($request);
    }

    public function history(\think\Request $request): \think\response\Json
    {
        return $this->delegate->history($request);
    }

    public function updateHistory(\think\Request $request, int $id): \think\response\Json
    {
        return $this->delegate->updateHistory($request, $id);
    }

    public function copyOdds(\think\Request $request, int $id): \think\response\Json
    {
        return $this->delegate->copyOdds($request, $id);
    }

    public function odds(\think\Request $request): \think\response\Json
    {
        return $this->delegate->odds($request);
    }

    public function createOddsCategory(\think\Request $request): \think\response\Json
    {
        return $this->delegate->createOddsCategory($request);
    }

    public function updateOddsCategory(\think\Request $request): \think\response\Json
    {
        return $this->delegate->updateOddsCategory($request);
    }

    public function deleteOddsCategory(\think\Request $request): \think\response\Json
    {
        return $this->delegate->deleteOddsCategory($request);
    }

    public function createOdds(\think\Request $request): \think\response\Json
    {
        return $this->delegate->createOdds($request);
    }

    public function updateOdds(\think\Request $request): \think\response\Json
    {
        return $this->delegate->updateOdds($request);
    }

    public function deleteOdds(\think\Request $request): \think\response\Json
    {
        return $this->delegate->deleteOdds($request);
    }

    public function boards(\think\Request $request): \think\response\Json
    {
        return $this->delegate->boards($request);
    }

    public function createBoard(\think\Request $request): \think\response\Json
    {
        return $this->delegate->createBoard($request);
    }

    public function updateBoard(\think\Request $request, int $id): \think\response\Json
    {
        return $this->delegate->updateBoard($request, $id);
    }
}
