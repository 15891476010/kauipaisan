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
                $groups['organization:'.$id] = ['id'=>(int)$id, 'type'=>'organization', 'member'=>$node['name'], 'level'=>$node['level'], 'rows'=>[]];
            }
        }
        foreach ($rows as $row) {
            $organizationId = (int)($row['organization_id'] ?? 0);
            // A settled row belongs to the branch that booked it at settle
            // time. Members may have moved branches since, so resolve the
            // branch from the ledger snapshot orgs instead of the live
            // membership.
            if ((int)($row['settled'] ?? 0) === 1 && !empty($row['_ledger']) && is_array($row['_ledger'])) {
                foreach ($row['_ledger'] as $entry) {
                    $oid = (int)($entry['organization_id'] ?? 0);
                    if ($oid > 0 && isset($branches[$oid])) { $organizationId = $oid; break; }
                }
            }
            $childId = $branches[$organizationId] ?? 0;
            if ($childId > 0 && isset($groups['organization:'.$childId])) {
                $key = 'organization:'.$childId;
            } elseif ($organizationId === $currentId || ($organizationId === 0 && (int)$this->current['parent_id'] === 0)) {
                $id = (int)$row['user_id'];
                $key = 'member:'.$id;
                $groups[$key] ??= ['id'=>$id, 'type'=>'member', 'member'=>$row['username'], 'level'=>'member', 'rows'=>[]];
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
        return ['id'=>(int)$node['id'], 'name'=>(string)$node['name'], 'level'=>(string)$node['level'], 'level_label'=>OrganizationHierarchy::LABELS[$node['level']] ?? $node['level']];
    }
}
