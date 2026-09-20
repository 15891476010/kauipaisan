<?php
declare(strict_types=1);

namespace app\service;

use think\facade\Db;

final class AgentReportScope
{
    private array $nodes;
    private array $current;
    private int $rootId;

    public function __construct(array $session, int $organizationId = 0)
    {
        $this->rootId = (int)($session['organization_id'] ?? 0);
        $this->current = OrganizationHierarchy::assertManageableNode($session, $organizationId ?: $this->rootId, true);
        $root = OrganizationHierarchy::assertManageableNode($session, $this->rootId, true);
        $rows = Db::name('organization_nodes')->where('site_id', (int)$session['site_id'])
            ->where('tenant_id', (int)$session['tenant_id'])->whereLike('path', (string)$root['path'].'%')
            ->whereNull('deleted_at')->field('id,parent_id,name,level,path')->order('depth asc,id asc')->select()->toArray();
        $this->nodes = array_column($rows, null, 'id');
        // 展示名规则：有代号（账号 display_name）用代号，否则用账号，最后才退回节点昵称。
        $accounts = $this->nodes ? Db::name('organization_accounts')->whereIn('organization_id', array_keys($this->nodes))
            ->whereNull('deleted_at')->order('id asc')->field('organization_id,username,display_name')->select()->toArray() : [];
        $byNode = [];
        foreach ($accounts as $account) {
            $id = (int)$account['organization_id'];
            if (isset($byNode[$id])) continue;
            $byNode[$id] = ['account_username'=>(string)$account['username'], 'account_display_name'=>(string)$account['display_name']];
        }
        foreach ($byNode as $id=>$fields) {
            if (isset($this->nodes[$id])) $this->nodes[$id] += $fields;
            if ($id === (int)$this->current['id']) $this->current += $fields;
        }
    }

    public function session(array $session): array
    {
        return array_merge($session, ['organization_id'=>(int)$this->current['id']]);
    }

    public function currentLevel(): string
    {
        return (string)$this->current['level'];
    }

    public function siteId(): int
    {
        return (int)$this->current['site_id'];
    }

    public function context(): array
    {
        $breadcrumbs = [];
        foreach (explode('/', trim((string)$this->current['path'], '/')) as $id) {
            if (isset($this->nodes[(int)$id])) $breadcrumbs[] = $this->nodeView($this->nodes[(int)$id]);
        }
        return ['current'=>$this->nodeView($this->current), 'root_organization_id'=>$this->rootId, 'breadcrumbs'=>$breadcrumbs];
    }

    public function groupedRows(array $rows, callable $aggregate): array
    {
        $currentId = (int)$this->current['id'];
        $path = (string)$this->current['path'];
        $groups = [];
        $branches = [];
        foreach ($this->nodes as $id=>$node) {
            if (!str_starts_with((string)$node['path'], $path) || $id === $currentId) continue;
            $branches[$id] = (int)explode('/', substr((string)$node['path'], strlen($path)))[0];
            if ((int)$node['parent_id'] === $currentId) {
                $groups['organization:'.$id] = ['id'=>(int)$id, 'type'=>'organization', 'member'=>$this->displayName($node), 'level'=>$node['level'], 'rows'=>[]];
            }
        }
        foreach ($rows as $row) {
            $organizationId = (int)($row['organization_id'] ?? 0);
            $childId = $branches[$organizationId] ?? 0;
            if ($childId > 0 && isset($groups['organization:'.$childId])) {
                $key = 'organization:'.$childId;
            } elseif ($organizationId === $currentId || ($organizationId === 0 && (int)$this->current['parent_id'] === 0)) {
                $id = (int)$row['user_id'];
                $key = 'member:'.$id;
                $memberName = trim((string)($row['display_name'] ?? '')) !== '' ? (string)$row['display_name'] : (string)$row['username'];
                $groups[$key] ??= ['id'=>$id, 'type'=>'member', 'member'=>$memberName, 'level'=>'member', 'rows'=>[]];
            } else {
                continue;
            }
            $groups[$key]['rows'][] = $row;
        }
        $list = [];
        $levels = [];
        foreach ($groups as $group) {
            $levels[$group['level']] = true;
            $group['level_label'] = OrganizationHierarchy::LABELS[$group['level']] ?? '会员';
            $group['issue_count'] = self::issueCount($group['rows']);
            $group['summary'] = $aggregate($group['rows']);
            unset($group['rows']);
            $list[] = $group;
        }
        $labels = array_intersect_key(array_merge(OrganizationHierarchy::LABELS, ['member'=>'会员']), $levels);
        $fallback = OrganizationHierarchy::nextLevel((string)$this->current['level']);
        $label = $labels ? implode(' / ', $labels) : (OrganizationHierarchy::LABELS[$fallback ?? ''] ?? '会员');
        usort($list, static fn(array $a, array $b): int=>strcmp($a['type'], $b['type']) ?: strcmp($a['member'], $b['member']) ?: ($a['id'] <=> $b['id']));
        return ['list'=>$list, 'row_label'=>$label, 'issue_count'=>self::issueCount($rows)];
    }

    public static function issueCount(array $rows): int
    {
        $issues = [];
        foreach ($rows as $row) {
            $issue = trim((string)($row['issue_no'] ?? ''));
            if ($issue !== '') $issues[$issue] = true;
        }
        return count($issues);
    }

    private function nodeView(array $node): array
    {
        return ['id'=>(int)$node['id'], 'name'=>$this->displayName($node), 'level'=>(string)$node['level'], 'level_label'=>OrganizationHierarchy::LABELS[$node['level']] ?? $node['level']];
    }

    private function displayName(array $node): string
    {
        // 组织账号的 display_name 与节点 name 一致时是昵称，不是代号；
        // 只有两者不同才按代号显示，否则回到登录账号。
        $code = trim((string)($node['account_display_name'] ?? ''));
        if ($code !== '' && $code !== trim((string)$node['name'])) return $code;
        $username = trim((string)($node['account_username'] ?? ''));
        return $username !== '' ? $username : (string)$node['name'];
    }
}
