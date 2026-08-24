<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { ArrowLeft, Check } from '@element-plus/icons-vue'
import { getSiteAgentPermissions, saveSiteAgentPermissions, type AgentPermissionLevel, type AgentPermissionNode, type OrganizationLevel } from '../api/admin'

const route = useRoute()
const router = useRouter()
const siteId = Number(route.params.siteId)
const loading = ref(false)
const saving = ref(false)
const siteName = ref(String(route.query.name || ''))
const tree = ref<AgentPermissionNode[]>([])
const levels = ref<AgentPermissionLevel[]>([])
const selectedByLevel = ref<Record<string, Set<string>>>({})
const allowedByLevel = ref<Record<string, Set<string>>>({})
const typeNames = { route: '路由', page: '页面 / 页签', button: '按钮' }
function typeLabel(type: AgentPermissionNode['type']) { return typeNames[type] }

function descendants(node: AgentPermissionNode): string[] {
  return [node.code, ...(node.children || []).flatMap(descendants)]
}
function childCodes(node: AgentPermissionNode): string[] {
  return (node.children || []).flatMap(descendants)
}
function selected(level: string) { return selectedByLevel.value[level] || new Set<string>() }
function allowed(level: string) { return allowedByLevel.value[level] || new Set<string>() }
function selectedCount(level: string) { return selected(level).size }
function allowedCount(level: string) { return allowed(level).size }
function applicable(level: string, node: AgentPermissionNode) { return allowed(level).has(node.code) }
function checked(level: string, node: AgentPermissionNode) { return selected(level).has(node.code) }
function indeterminate(level: string, node: AgentPermissionNode) {
  const children = childCodes(node).filter((code) => allowed(level).has(code))
  if (!children.length) return false
  const count = children.filter((code) => selected(level).has(code)).length
  return count > 0 && count < children.length
}
function replaceSelection(level: string, next: Set<string>) {
  selectedByLevel.value = { ...selectedByLevel.value, [level]: next }
}
function toggle(level: string, node: AgentPermissionNode, value: boolean) {
  const next = new Set(selected(level))
  descendants(node).filter((code) => allowed(level).has(code)).forEach((code) => value ? next.add(code) : next.delete(code))
  if (value) {
    for (const root of tree.value) {
      if ((root.children || []).some((child) => descendants(child).includes(node.code))) next.add(root.code)
    }
  }
  replaceSelection(level, next)
}
function selectAll(level: string, value: boolean) {
  replaceSelection(level, value ? new Set(allowed(level)) : new Set())
}
async function load() {
  loading.value = true
  try {
    const response = await getSiteAgentPermissions(siteId)
    tree.value = response.data.tree || []
    levels.value = response.data.levels || []
    allowedByLevel.value = Object.fromEntries(levels.value.map((level) => [level.value, new Set(response.data.allowed_codes_by_level?.[level.value] || [])]))
    selectedByLevel.value = Object.fromEntries(levels.value.map((level) => [level.value, new Set(response.data.permissions_by_level?.[level.value] || [])]))
    siteName.value = response.data.site?.name || siteName.value
  } catch (error) { ElMessage.error(error instanceof Error ? error.message : '权限加载失败') }
  finally { loading.value = false }
}
async function save() {
  saving.value = true
  try {
    const payload = Object.fromEntries(levels.value.map((level) => [level.value, [...selected(level.value)]])) as Record<OrganizationLevel, string[]>
    await saveSiteAgentPermissions(siteId, payload)
    ElMessage.success('站点分层路由权限已保存；各层级代理端刷新后生效')
  } catch (error) { ElMessage.error(error instanceof Error ? error.message : '权限保存失败') }
  finally { saving.value = false }
}
onMounted(load)
</script>

<template>
  <div class="permission-page" v-loading="loading">
    <header class="permission-heading">
      <div><h1>站点分层路由权限</h1><p>{{ siteName || `站点 #${siteId}` }} · 分别控制总监、大股东、小股东、总代理和代理可见的功能</p></div>
      <el-button :icon="ArrowLeft" @click="router.push('/agent-center')">返回代理中心</el-button>
      <el-button type="primary" :icon="Check" :loading="saving" @click="save">保存权限</el-button>
    </header>
    <div class="permission-summary"><strong>权限分配规则</strong><span>每个层级独立配置；取消某层级的路由后，该层级下对应页面和按钮都会取消，代理端直接不显示。</span><b>共 {{ levels.length }} 个层级</b></div>
    <el-table :data="tree" row-key="code" border default-expand-all :tree-props="{ children: 'children' }">
      <el-table-column prop="label" label="路由 / 权限名称" fixed="left" min-width="250" />
      <el-table-column prop="type" label="类型" width="120"><template #default="scope"><el-tag :type="scope.row.type === 'route' ? 'primary' : scope.row.type === 'button' ? 'warning' : 'success'">{{ typeLabel(scope.row.type) }}</el-tag></template></el-table-column>
      <el-table-column v-for="level in levels" :key="level.value" :label="level.label" width="140" align="center">
        <template #header><div class="level-header"><strong>{{ level.label }}</strong><el-checkbox :model-value="selectedCount(level.value) > 0 && selectedCount(level.value) === allowedCount(level.value)" :indeterminate="selectedCount(level.value) > 0 && selectedCount(level.value) < allowedCount(level.value)" @change="selectAll(level.value, Boolean($event))">全选</el-checkbox><small>{{ selectedCount(level.value) }}/{{ allowedCount(level.value) }}</small></div></template>
        <template #default="scope"><el-checkbox v-if="applicable(level.value, scope.row)" :model-value="checked(level.value, scope.row)" :indeterminate="indeterminate(level.value, scope.row)" @change="toggle(level.value, scope.row, Boolean($event))" /><span v-else class="not-applicable">—</span></template>
      </el-table-column>
      <el-table-column prop="code" label="权限标识" min-width="250"><template #default="scope"><code>{{ scope.row.code }}</code></template></el-table-column>
      <el-table-column label="代理端效果" min-width="190"><template #default="scope">{{ scope.row.type === 'route' ? '隐藏菜单及整个路由' : scope.row.type === 'button' ? '隐藏操作按钮' : '隐藏页面或页签' }}</template></el-table-column>
    </el-table>
  </div>
</template>

<style scoped>
.permission-page { min-height:100%; padding:22px; background:#fff; }
.permission-heading { display:flex; align-items:center; gap:10px; margin-bottom:18px; }
.permission-heading>div { margin-right:auto; }.permission-heading h1 { margin:0; color:#27334a; font-size:20px; }.permission-heading p { margin:6px 0 0; color:#8993a5; font-size:13px; }
.permission-summary { display:flex; min-height:48px; align-items:center; gap:16px; margin-bottom:14px; padding:10px 14px; border:1px solid #d8e3f3; background:#f7faff; color:#5b6678; }
.permission-summary strong { color:#27334a; }.permission-summary b { margin-left:auto; color:#2563eb; }
code { color:#344054; font-family:Consolas,Monaco,monospace; }
.level-header { display:flex; align-items:center; justify-content:center; gap:7px; }.level-header strong { color:#27334a; }.level-header small { color:#98a2b3; font-weight:400; }
.not-applicable { color:#c4c9d2; }
</style>
