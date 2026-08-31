<?php
declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use app\service\OrganizationHierarchy;

$expected = [
    'director' => ['shareholder', 'small_shareholder', 'general_agent', 'agent'],
    'shareholder' => ['small_shareholder', 'general_agent', 'agent'],
    'small_shareholder' => ['general_agent', 'agent'],
    'general_agent' => ['agent'],
    'agent' => [],
];
foreach ($expected as $parent => $children) {
    if (OrganizationHierarchy::childLevels($parent) !== $children) {
        throw new RuntimeException("层级 {$parent} 的直属下级规则不正确");
    }
    foreach ($children as $child) {
        if (!OrganizationHierarchy::canParentLevelAccept($parent, $child)) {
            throw new RuntimeException("层级 {$parent} 应允许直接创建 {$child}");
        }
    }
}
if (OrganizationHierarchy::canParentLevelAccept('agent', 'director')) {
    throw new RuntimeException('代理不应允许创建总监');
}
echo "Organization hierarchy tests passed: direct child matrix verified\n";
