<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { ArrowLeft, Check } from '@element-plus/icons-vue'
import { getSiteAgentPermissions, saveSiteAgentPermissions, type AgentPermissionNode } from '../api/admin'

const route = useRoute()
const router = useRouter()
const siteId = Number(route.params.siteId)
const loading = ref(false)
const saving = ref(false)
const siteName = ref(String(route.query.name || ''))
const tree = ref<AgentPermissionNode[]>([])
const selected = ref(new Set<string>())
const typeNames = { route: '路由', page: '页面 / 页签', button: '按钮' }
const selectedCount = computed(() => selected.value.size)
const allCodes = computed(() => tree.value.flatMap(descendants))
function typeLabel(type: AgentPermissionNode['type']) { return typeNames[type] }

function descendants(node: AgentPermissionNode): string[] {
  return [node.code, ...(node.children || []).flatMap(descendants)]
}
function childCodes(node: AgentPermissionNode): string[] {
  return (node.children || []).flatMap(descendants)
}
function checked(node: AgentPermissionNode) { return selected.value.has(node.code) }
function indeterminate(node: AgentPermissionNode) {
  const children = childCodes(node)
  if (!children.length) return false
  const count = children.filter((code) => selected.value.has(code)).length
  return count > 0 && count < children.length
}
function toggle(node: AgentPermissionNode, value: boolean) {
  const next = new Set(selected.value)
  descendants(node).forEach((code) => value ? next.add(code) : next.delete(code))
  if (value) {
    for (const root of tree.value) {
      if ((root.children || []).some((child) => descendants(child).includes(node.code))) next.add(root.code)
    }
  }
  selected.value = next
}
function selectAll(value: boolean) {
  selected.value = value ? new Set(tree.value.flatMap(descendants)) : new Set()
}
async function load() {
  loading.value = true
  try {
    const response = await getSiteAgentPermissions(siteId)
    tree.value = response.data.tree || []
    selected.value = new Set(response.data.permissions || [])
    siteName.value = response.data.site?.name || siteName.value
  } catch (error) { ElMessage.error(error instanceof Error ? error.message : '权限加载失败') }
  finally { loading.value = false }
}
async function save() {
  saving.value = true
  try {
    await saveSiteAgentPermissions(siteId, [...selected.value])
    ElMessage.success('站点路由权限已保存；代理端刷新后生效')
  } catch (error) { ElMessage.error(error instanceof Error ? error.message : '权限保存失败') }
  finally { saving.value = false }
}
onMounted(load)
</script>

<template>
  <div class="permission-page" v-loading="loading">
    <header class="permission-heading">
      <div><h1>站点路由权限</h1><p>{{ siteName || `站点 #${siteId}` }} · 控制代理系统可见的路由、页面页签和操作按钮</p></div>
      <el-button :icon="ArrowLeft" @click="router.push('/agent-center')">返回代理中心</el-button>
      <el-button type="primary" :icon="Check" :loading="saving" @click="save">保存权限</el-button>
    </header>
    <div class="permission-summary"><strong>权限分配规则</strong><span>取消路由后，该路由下的页面和按钮都会取消；未授权内容在代理端直接不显示。</span><b>已选 {{ selectedCount }} 项</b></div>
    <el-table :data="tree" row-key="code" border default-expand-all :tree-props="{ children: 'children' }">
      <el-table-column label="选择" width="90" align="center">
        <template #header><el-checkbox :model-value="selectedCount > 0 && selectedCount === allCodes.length" :indeterminate="selectedCount > 0 && selectedCount < allCodes.length" @change="selectAll(Boolean($event))" /></template>
        <template #default="scope"><el-checkbox :model-value="checked(scope.row)" :indeterminate="indeterminate(scope.row)" @change="toggle(scope.row, Boolean($event))" /></template>
      </el-table-column>
      <el-table-column prop="label" label="路由 / 权限名称" min-width="300" />
      <el-table-column prop="code" label="权限标识" min-width="290"><template #default="scope"><code>{{ scope.row.code }}</code></template></el-table-column>
      <el-table-column prop="type" label="类型" width="140"><template #default="scope"><el-tag :type="scope.row.type === 'route' ? 'primary' : scope.row.type === 'button' ? 'warning' : 'success'">{{ typeLabel(scope.row.type) }}</el-tag></template></el-table-column>
      <el-table-column label="代理端效果" min-width="220"><template #default="scope">{{ scope.row.type === 'route' ? '控制顶部菜单和整个路由' : scope.row.type === 'button' ? '控制操作按钮是否显示' : '控制页面或页签是否显示' }}</template></el-table-column>
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
</style>
