<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { ArrowLeft, Check, Refresh } from '@element-plus/icons-vue'
import { applyBatchRobot, getBatchBetOptions, planBatchRobot, previewBatchBetDraw, replaceBatchBetNumbers, type BatchBetLottery, type BatchBetNode, type BatchBetNumber, type BatchBetPreviewResult, type BatchBetUser, type RobotPlanItem, type RobotPlanResult } from '../api/admin'

const router = useRouter()
const route = useRoute()
const loading = ref(false)
const saving = ref(false)
const previewing = ref(false)
const lotteries = ref<BatchBetLottery[]>([])
const issueOptions = ref<string[]>([])
const lotteryId = ref<number>()
const issueNo = ref('')
const drawNo = ref('')
const users = ref<BatchBetUser[]>([])
const tree = ref<BatchBetNode[]>([])
const selectedUserKeys = ref<string[]>([])
const activeUserKey = ref('')
const editedSources = ref<Record<string, string>>({})
const previews = ref<Record<number, BatchBetPreviewResult>>({})
const activeSite = ref<number>()
const orgChain = ref<number[]>([])
let previewTimer: ReturnType<typeof setTimeout> | undefined

const selectedUsers = computed(() => users.value.filter(user => selectedUserKeys.value.includes(user.key)))
const selectedRobotKeys = ref<string[]>([])
const scopeRobots = computed(() => selectedUsers.value)
const selectedRobots = computed(() => scopeRobots.value.filter(user => selectedRobotKeys.value.includes(user.key)))
type DetailGroup = { key: string; user: BatchBetUser; record_id: number; source_text: string; numbers: BatchBetNumber[] }
const detailGroups = computed<DetailGroup[]>(() => selectedUsers.value.flatMap(user => {
  const groups = new Map<number, DetailGroup>()
  user.numbers.forEach(number => {
    let group = groups.get(number.record_id)
    if (!group) {
      group = { key: `${user.key}-${number.record_id}`, user, record_id: number.record_id, source_text: number.record_source_text || number.source_text || '', numbers: [] }
      groups.set(number.record_id, group)
    }
    group.numbers.push(number)
  })
  return Array.from(groups.values())
}))
const changedGroups = computed(() => detailGroups.value.filter(group => (editedSources.value[group.key] ?? group.source_text) !== group.source_text))
// Tabs only switch which user's tickets are on screen; edits stay in
// editedSources keyed by group, so switching back and forth loses nothing.
const visibleGroups = computed(() => detailGroups.value.filter(group => group.user.key === activeUserKey.value))
const visibleUser = computed(() => selectedUsers.value.find(user => user.key === activeUserKey.value))
function changedCountFor(key: string) { return detailGroups.value.filter(group => group.user.key === key && (editedSources.value[group.key] ?? group.source_text) !== group.source_text).length }
function ensureActiveUser() { if (!selectedUserKeys.value.includes(activeUserKey.value)) activeUserKey.value = selectedUserKeys.value[0] ?? '' }

// ---- Predicted-draw totals -------------------------------------------------
function baseWin(group: DetailGroup): number | null {
  const value = group.numbers[0]?.predicted_win
  return value == null ? null : Number(value)
}
function effectiveWin(group: DetailGroup): number | null {
  const preview = previews.value[group.record_id]
  return preview && !preview.error && preview.win != null ? Number(preview.win) : baseWin(group)
}
function effectiveAmount(group: DetailGroup): number {
  const preview = previews.value[group.record_id]
  return preview && !preview.error && preview.amount != null ? Number(preview.amount) : group.numbers.reduce((sum, number) => sum + Number(number.amount || 0), 0)
}
function hasPreview(group: DetailGroup) { const p = previews.value[group.record_id]; return !!(p && !p.error) }

// Winning tickets float to the top so the operator sees the risky ones first;
// ties keep the larger predicted win higher.
const sortedGroups = computed(() => [...visibleGroups.value].sort((a, b) => {
  const wa = effectiveWin(a), wb = effectiveWin(b)
  const aWon = wa != null && wa > 0 ? 1 : 0
  const bWon = wb != null && wb > 0 ? 1 : 0
  if (aWon !== bWon) return bWon - aWon
  return (wb ?? Number.NEGATIVE_INFINITY) - (wa ?? Number.NEGATIVE_INFINITY)
}))

type Totals = { bet: number; win: number | null; profit: number | null; pBet: number; pWin: number | null; pProfit: number | null; touched: boolean }
function adjustedStats(user: BatchBetUser): Totals {
  const bet = Number(user.stats?.bet || 0)
  const win = user.stats?.win == null ? null : Number(user.stats.win)
  let pBet = bet
  let pWin = win
  let touched = false
  for (const number of user.numbers) {
    const preview = previews.value[number.record_id]
    if (!preview || preview.error) continue
    touched = true
    pBet += Number(preview.amount ?? 0) - Number(number.amount || 0)
    if (pWin !== null && preview.win != null) pWin += Number(preview.win) - (number.predicted_win == null ? 0 : Number(number.predicted_win))
  }
  return { bet, win, profit: win == null ? null : win - bet, pBet, pWin, pProfit: pWin == null ? null : pWin - pBet, touched }
}
function sumStats(list: BatchBetUser[]): Totals {
  return list.reduce<Totals>((acc, user) => {
    const s = adjustedStats(user)
    acc.bet += s.bet
    acc.win = acc.win == null || s.win == null ? null : acc.win + s.win
    acc.pBet += s.pBet
    acc.pWin = acc.pWin == null || s.pWin == null ? null : acc.pWin + s.pWin
    acc.touched = acc.touched || s.touched
    return acc
  }, { bet: 0, win: 0, pBet: 0, pWin: null, touched: false, profit: null, pProfit: null } as Totals)
}
function finalize(t: Totals): Totals {
  t.profit = t.win == null ? null : t.win - t.bet
  t.pProfit = t.pWin == null ? null : t.pWin - t.pBet
  return t
}
const money = (v: number | null) => v == null ? '—' : v.toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const profitClass = (v: number | null) => v == null ? '' : v > 0 ? 'neg' : 'pos'

// ---- Hierarchical picker ----------------------------------------------------
const PSEUDO_OFFSET = 1_000_000_000
const siteIds = computed(() => Array.from(new Set(users.value.map(user => user.site_id))))
const siteList = computed(() => siteIds.value.map(id => ({ id, name: users.value.find(user => user.site_id === id)?.site_name || `站点 ${id}` })))
const nodePathById = computed(() => new Map(tree.value.map(node => [node.id, node.path])))
const siteNodes = computed(() => tree.value.filter(node => node.site_id === activeSite.value))
const hasUnassigned = computed(() => users.value.some(user => user.site_id === activeSite.value && (!user.organization_id || !nodePathById.value.has(user.organization_id))))
const pseudoId = computed(() => -(PSEUDO_OFFSET + (activeSite.value ?? 0)))
const rootNodes = computed<BatchBetNode[]>(() => {
  const roots = siteNodes.value.filter(node => node.parent_id === 0)
  if (hasUnassigned.value) roots.push({ id: pseudoId.value, site_id: activeSite.value ?? 0, site_name: '', parent_id: 0, level: 'member', label: '未分配', name: '未分配会员', path: '' })
  return roots
})
const childrenOf = (id: number) => siteNodes.value.filter(node => node.parent_id === id)
// Each entry is the option list of one cascade level; a level renders while
// its parent selection exists and still has children to drill into.
const chainLevels = computed<BatchBetNode[][]>(() => {
  const levels: BatchBetNode[][] = []
  let options = rootNodes.value
  let depth = 0
  while (options.length) {
    levels.push(options)
    const selected = orgChain.value[depth]
    const node = options.find(item => item.id === selected)
    if (!node) break
    options = childrenOf(node.id)
    depth++
  }
  return levels
})
const selectedNode = computed(() => {
  const id = orgChain.value[orgChain.value.length - 1]
  return id === pseudoId.value
    ? rootNodes.value.find(node => node.id === id)
    : tree.value.find(node => node.id === id)
})
const subtreeUsers = (node: BatchBetNode) => node.id === pseudoId.value
  ? users.value.filter(user => user.site_id === node.site_id && (!user.organization_id || !nodePathById.value.has(user.organization_id)))
  : users.value.filter(user => user.site_id === node.site_id && !!user.org_path && node.path !== '' && user.org_path.startsWith(node.path))
function nodeTotals(node: BatchBetNode) { return finalize(sumStats(subtreeUsers(node))) }
function nodeCalculatedProfit(node: BatchBetNode) { return node.calculated_profit == null ? null : Number(node.calculated_profit) }
function nodeLabel(node: BatchBetNode) { return `${node.label} ${node.name}` }
function memberLabel(user: BatchBetUser) { return `${user.display_name || user.username}（${user.username}，${user.number_count ?? user.numbers.length}条）` }
// Every selected level keeps its own stats row — picking a deeper node adds a
// row instead of replacing the director's totals.
const chainStats = computed(() => orgChain.value
  .map(id => tree.value.find(node => node.id === id) ?? rootNodes.value.find(node => node.id === id))
  .filter((node): node is BatchBetNode => !!node)
  .map(node => ({ node, totals: finalize(nodeTotals(node)), levelProfit: nodeCalculatedProfit(node) })))
const visibleUserStats = computed(() => visibleUser.value ? adjustedStats(visibleUser.value) : null)

// ---- Organization-scope number adjustment ---------------------------------
// 锚点 = 级联选择的最深节点；服务端按锚点整棵子树解析会员范围。
const anchorTotals = computed(() => finalize(selectedNode.value ? nodeTotals(selectedNode.value) : sumStats(users.value)))
const selectedStats = computed(() => finalize(sumStats(selectedUsers.value)))
const groupRatio = (profit: number | null) => {
  const anchor = anchorTotals.value.profit
  if (profit == null || anchor == null || Math.abs(anchor) < 0.005) return null
  return profit / anchor * 100
}
const payoutRate = (t: Totals) => t.bet > 0.005 && t.win != null ? t.win / t.bet * 100 : null
const percent = (v: number | null) => v == null ? '—' : `${v.toFixed(1)}%`
const robotDrawReady = computed(() => /^\d{3}$/.test(drawNo.value))
const robotAmount = ref('')
const planLoading = ref(false)
const planDialog = ref(false)
const planResult = ref<RobotPlanResult | null>(null)
const applying = ref(false)
const editingRecordId = ref<number | null>(null)
function selectAllRobots() { selectedRobotKeys.value = scopeRobots.value.map(user => user.key); planResult.value = null }
function clearSelectedRobots() { selectedRobotKeys.value = []; planResult.value = null }
function changeSelectedRobots(values: string[]) { selectedRobotKeys.value = values; planResult.value = null }

type HitSeg = { text: string; hit: boolean }
function escRe(s: string) { return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') }
// 把文本按"中奖号码/中奖表达式"切成高亮片段；纯数字 needle 加数字边界防误伤
function hitSegments(text: string, needles: string[]): HitSeg[] {
  const parts: string[] = []; const seen = new Set<string>()
  for (const n of needles) {
    if (!n || seen.has(n)) continue
    seen.add(n)
    parts.push(/^\d+$/.test(n) ? `(?<!\\d)${n}(?!\\d)` : escRe(n))
  }
  if (!parts.length || !text) return [{ text, hit: false }]
  let re: RegExp
  try { re = new RegExp(parts.join('|'), 'g') } catch { return [{ text, hit: false }] }
  const segs: HitSeg[] = []; let last = 0; let m: RegExpExecArray | null
  while ((m = re.exec(text)) !== null) {
    if (m.index > last) segs.push({ text: text.slice(last, m.index), hit: false })
    segs.push({ text: m[0], hit: true })
    last = m.index + m[0].length
    if (m[0] === '') { re.lastIndex++; if (re.lastIndex > text.length) break }
  }
  if (last < text.length) segs.push({ text: text.slice(last), hit: false })
  return segs
}
// 原始注单：注单预中奖>0 时高亮预开奖号和中奖表达式（独胆/胆拖等非三位玩法）
function rawSegments(group: DetailGroup): HitSeg[] {
  const needles: string[] = []
  const win = effectiveWin(group)
  if (drawNo.value && win != null && win > 0) {
    for (const n of group.numbers) for (const t of n.win_tokens ?? []) needles.push(t)
    needles.push(drawNo.value)
  }
  return hitSegments(sourceValue(group), needles)
}
// 方案预览：old_source 高亮原中奖 token，new_source 高亮新中奖 token
function planSegments(item: RobotPlanItem, field: 'old_source' | 'new_source'): HitSeg[] {
  const isNew = field === 'new_source'
  const text = isNew ? (item.new_source ?? '') : item.old_source
  const needles: string[] = []
  for (const d of item.details) {
    if (Number(isNew ? d.new_win : d.old_win) <= 0) continue
    for (const t of (isNew ? d.new_number : d.old_number).split(/\s+/)) {
      if (t && !/^\d{3}(直|组三|组六|组)?$/.test(t)) needles.push(t)
    }
  }
  if (drawNo.value) needles.push(drawNo.value)
  return hitSegments(text, needles)
}
function editFocus(el: { textarea?: HTMLTextAreaElement } | null) { el?.textarea?.focus() }
function startEdit(group: DetailGroup) { editingRecordId.value = group.record_id }
async function generatePlan() {
  const target = Number(robotAmount.value)
  if (!Number.isFinite(target) || robotAmount.value.trim() === '') { ElMessage.warning('请输入正数或负数目标盈亏'); return }
  if (!selectedNode.value || selectedNode.value.id <= 0) { ElMessage.warning('请先选择目标组织层级'); return }
  if (!selectedRobots.value.length) { ElMessage.warning('请选择要自动改码的用户'); return }
  planLoading.value = true
  try {
    const response = await planBatchRobot({
      lottery_id: lotteryId.value, issue_no: issueNo.value, draw: drawNo.value,
      user_ids: selectedRobots.value.map(user => user.user_id),
      node_id: selectedNode.value && selectedNode.value.id > 0 ? selectedNode.value.id : 0,
      target_profit: target,
    })
    planResult.value = response.data
    planDialog.value = true
    if (!response.data.items.length) ElMessage.warning(response.data.warnings?.[0] || '当前所选注单没有可保持目标区间的改码结果')
  } catch (error) {
    ElMessageBox.alert(error instanceof Error ? error.message : '方案生成失败', '用户自动改码', { type: 'error', confirmButtonText: '知道了' })
  } finally {
    planLoading.value = false
  }
}
async function applyPlan() {
  if (!planResult.value) return
  await ElMessageBox.confirm(`将只修改 ${planResult.value.items.length} 张所选用户注单的号码，金额保持不变；预计报表口径盈亏 ¥${money(Number(planResult.value.daily_profit_after))}。是否执行？`, '确认执行用户改单', { type: 'warning', confirmButtonText: '执行改单', cancelButtonText: '取消' })
  applying.value = true
  try {
    const response = await applyBatchRobot({
      lottery_id: lotteryId.value, issue_no: issueNo.value, draw: drawNo.value,
      user_ids: selectedRobots.value.map(user => user.user_id),
      node_id: selectedNode.value && selectedNode.value.id > 0 ? selectedNode.value.id : 0,
      target_profit: Number(robotAmount.value),
      plan_token: planResult.value.plan_token,
    })
    ElMessage.success(`用户改单完成，共修改 ${response.data.changed} 张主单`)
    planDialog.value = false
    planResult.value = null
    editedSources.value = {}
    previews.value = {}
    await loadOptions({ lotteryId: lotteryId.value, issue: issueNo.value, userIds: selectedUsers.value.map(user => user.user_id), recordIds: [], preserveSelection: true })
  } catch (error) {
    ElMessage.error(error instanceof Error ? error.message : '用户改单失败')
  } finally {
    applying.value = false
  }
}

function selectOrg(depth: number, value: number | string | undefined) {
  const id = value === '' || value == null ? undefined : Number(value)
  orgChain.value = id == null ? orgChain.value.slice(0, depth) : [...orgChain.value.slice(0, depth), id]
  const rangeUsers = selectedNode.value ? subtreeUsers(selectedNode.value) : []
  selectedUserKeys.value = rangeUsers.map(user => user.key)
  selectedRobotKeys.value = []
  planResult.value = null
  ensureActiveUser()
  if (rangeUsers.length) void loadOptions({ lotteryId: lotteryId.value, issue: issueNo.value, userIds: rangeUsers.map(user => user.user_id), recordIds: [], preserveSelection: true })
}
function changeSite(value: number) { activeSite.value = value; orgChain.value = []; selectedUserKeys.value = []; selectedRobotKeys.value = []; planResult.value = null }

// ---- Loading / preview ------------------------------------------------------
const initialRecordIds = computed(() => {
  const raw = String(route.query.record_ids || '')
  return raw.split(',').map(Number).filter(id => Number.isInteger(id) && id > 0)
})

async function loadOptions(params: { lotteryId?: number; issue?: string; userIds?: number[]; recordIds?: number[]; preserveSelection?: boolean } = {}) {
  loading.value = true
  try {
    const response = await getBatchBetOptions({ lottery_id: params.lotteryId, lottery: String(route.query.lottery || ''), issue_no: params.issue, draw: drawNo.value || undefined, user_ids: params.userIds, record_ids: params.recordIds ?? initialRecordIds.value })
    const data = response.data
    lotteries.value = data.lotteries || []
    lotteryId.value = data.lottery?.id
    issueNo.value = data.issue_no || ''
    issueOptions.value = data.issues || (issueNo.value ? [issueNo.value] : [])
    tree.value = data.tree || []
    if (params.preserveSelection) {
      const incoming = data.users || []
      const incomingByKey = new Map(incoming.map(user => [user.key, user]))
      const currentKeys = new Set(users.value.map(user => user.key))
      users.value = [
        ...users.value.map(user => incomingByKey.get(user.key) || user),
        ...incoming.filter(user => !currentKeys.has(user.key)),
      ]
      if (selectedNode.value) selectedUserKeys.value = subtreeUsers(selectedNode.value).map(user => user.key)
    } else {
      users.value = data.users || []
      const selectedIds = data.selected_user_ids || []
      selectedUserKeys.value = selectedIds.length ? users.value.filter(user => selectedIds.includes(user.user_id)).map(user => user.key) : []
      editedSources.value = {}
      previews.value = {}
      if (!activeSite.value || !siteIds.value.includes(activeSite.value)) activeSite.value = siteIds.value[0]
    }
    const validRobotKeys = new Set(scopeRobots.value.map(user => user.key))
    selectedRobotKeys.value = selectedRobotKeys.value.filter(key => validRobotKeys.has(key))
    ensureActiveUser()
  } catch (error) {
    ElMessage.error(error instanceof Error ? error.message : '批量修改数据加载失败')
  } finally {
    loading.value = false
  }
}

function resetSelection() { orgChain.value = []; selectedUserKeys.value = []; selectedRobotKeys.value = []; planResult.value = null; editedSources.value = {}; previews.value = {} }
function changeLottery(value: number) { drawNo.value = ''; resetSelection(); void loadOptions({ lotteryId: value, recordIds: [] }) }
function changeIssue(value: string) { drawNo.value = ''; resetSelection(); void loadOptions({ lotteryId: lotteryId.value, issue: value, recordIds: [] }) }
let drawTimer: ReturnType<typeof setTimeout> | undefined
function onDrawInput() {
  clearTimeout(drawTimer)
  drawTimer = setTimeout(() => {
    void loadOptions({ lotteryId: lotteryId.value, issue: issueNo.value, userIds: selectedUsers.value.map(user => user.user_id), recordIds: [], preserveSelection: true })
    schedulePreview()
  }, 400)
}
function sourceValue(group: { key: string; source_text: string }) { return editedSources.value[group.key] ?? group.source_text }
function updateSource(group: { key: string }, value: string) { editedSources.value[group.key] = value; schedulePreview() }
function schedulePreview() {
  clearTimeout(previewTimer)
  previewTimer = setTimeout(runPreview, 800)
}
async function runPreview() {
  if (!drawNo.value || !changedGroups.value.length) { previews.value = {}; return }
  previewing.value = true
  try {
    const response = await previewBatchBetDraw({ lottery_id: lotteryId.value, issue_no: issueNo.value, draw: drawNo.value, records: changedGroups.value.map(group => ({ record_id: group.record_id, source_text: sourceValue(group) })) })
    const map: Record<number, BatchBetPreviewResult> = {}
    for (const result of response.data.results || []) map[result.record_id] = result
    previews.value = map
  } catch {
    previews.value = {}
  } finally {
    previewing.value = false
  }
}

async function submit() {
  if (!lotteryId.value || !issueNo.value) { ElMessage.warning('当前没有可修改的未开奖期号'); return }
  if (!changedGroups.value.length) { ElMessage.warning('请先修改至少一条原始注单'); return }
  await ElMessageBox.confirm(`将重新计算 ${changedGroups.value.length} 条原始注单的全部明细，是否继续？`, '确认保存原始注单', { type: 'warning', confirmButtonText: '保存并重算', cancelButtonText: '取消' })
  saving.value = true
  try {
    const response = await replaceBatchBetNumbers({ lottery_id: lotteryId.value, issue_no: issueNo.value, records: changedGroups.value.map(group => ({ record_id: group.record_id, source_text: sourceValue(group) })) })
    ElMessage.success(`原始注单已保存并重算，共更新 ${response.data.changed} 条主单`)
    editedSources.value = {}
    previews.value = {}
    await loadOptions({ lotteryId: lotteryId.value, issue: issueNo.value, userIds: selectedUsers.value.map(user => user.user_id), recordIds: [], preserveSelection: false })
  } catch (error) {
    ElMessage.error(error instanceof Error ? error.message : '批量修改失败')
  } finally {
    saving.value = false
  }
}

onMounted(() => loadOptions({ issue: String(route.query.issue_no || ''), recordIds: initialRecordIds.value }))
</script>

<template>
  <div class="batch-page" v-loading="loading">
    <section class="batch-head">
      <div><h1>批量修改</h1><p>选择彩种、期号并输入预开奖号码，按组织层级定位用户后直接编辑原始注单。</p></div>
      <div class="head-actions"><el-button :icon="Refresh" @click="loadOptions({ lotteryId, issue: issueNo, userIds: selectedUsers.map(user => user.user_id), recordIds: [], preserveSelection: false })">刷新</el-button><el-button :icon="ArrowLeft" @click="router.push('/bet-records')">返回下单记录</el-button></div>
    </section>

    <section class="filter-panel">
      <div class="filter-item"><label>彩种</label><el-select v-model="lotteryId" placeholder="请选择彩种" @change="changeLottery" style="width:180px"><el-option v-for="lottery in lotteries" :key="lottery.id" :label="lottery.name" :value="lottery.id" /></el-select></div>
      <div class="filter-item"><label>期号</label><el-select v-model="issueNo" placeholder="请选择期号" :disabled="!issueOptions.length" style="width:180px" @change="changeIssue"><el-option v-for="issue in issueOptions" :key="issue" :label="issue" :value="issue" /></el-select></div>
      <div class="filter-item"><label>预开奖号码</label><el-input v-model="drawNo" placeholder="如 123" :disabled="!issueNo" style="width:110px" maxlength="12" @input="onDrawInput" /></div>
      <div class="filter-item user-summary"><span>用户 {{ selectedUserKeys.length }} / {{ users.length }}</span><span>原始注单 {{ changedGroups.length }} 条已修改</span></div>
    </section>

    <section v-if="!users.length && !loading" class="empty">该彩种当前期号没有可修改的投注</section>
    <template v-else>
      <section class="users-panel">
        <div class="section-title"><div><h2>选择改单范围</h2><span class="hint">选择总监时包含其全部下级会员；继续选择股东或代理时，范围自动缩小到该层级子树</span></div><span class="selected-hint">范围内会员 {{ selectedUserKeys.length }} 人</span></div>
        <div class="org-picker">
          <el-select v-if="siteList.length > 1" :model-value="activeSite" placeholder="请选择站点" style="width:180px" @update:model-value="(value: number | string) => changeSite(Number(value))"><el-option v-for="site in siteList" :key="site.id" :label="site.name" :value="site.id" /></el-select>
          <el-select v-for="(options, depth) in chainLevels" :key="depth" :model-value="orgChain[depth]" clearable filterable :placeholder="depth === 0 ? '请选择总监' : '请选择下级组织'" style="width:240px" @update:model-value="(value: number | string | undefined) => selectOrg(depth, value)"><el-option v-for="node in options" :key="node.id" :label="nodeLabel(node)" :value="node.id"><em class="node-level">{{ node.label }}</em>{{ node.name }}</el-option></el-select>
        </div>
        <div v-for="row in chainStats" :key="row.node.id" class="totals-row">
          <span class="level-tag">{{ row.node.label }} {{ row.node.name }}</span>
          <span class="total-chip">总投 ¥{{ money(row.totals.bet) }}</span>
          <span class="total-chip">总中 ¥{{ money(row.totals.win) }}</span>
          <span class="total-chip" :class="profitClass(row.levelProfit)">层级盈亏 ¥{{ money(row.levelProfit) }}</span>
          <template v-if="row.totals.touched"><span class="total-chip preview">预览总投 ¥{{ money(row.totals.pBet) }}</span><span class="total-chip preview">预览总中 ¥{{ money(row.totals.pWin) }}</span><span class="total-chip preview" :class="profitClass(row.totals.pProfit)">预览盈亏 ¥{{ money(row.totals.pProfit) }}</span></template>
        </div>
        <div v-if="selectedUsers.length" class="totals-row">
          <span class="level-tag selected">范围内会员（{{ selectedUsers.length }}）</span>
          <span class="total-chip">总投 ¥{{ money(selectedStats.bet) }}</span>
          <span class="total-chip">总中 ¥{{ money(selectedStats.win) }}</span>
          <span class="total-chip" :class="profitClass(selectedStats.profit)">盈亏 ¥{{ money(selectedStats.profit) }}</span>
          <span class="total-chip">盈亏占比 {{ percent(groupRatio(selectedStats.profit)) }}</span>
          <span class="total-chip">返奖率 {{ percent(payoutRate(selectedStats)) }}</span>
        </div>
        <div v-if="selectedUsers.length" class="user-tabs"><button v-for="user in selectedUsers" :key="user.key" type="button" class="user-tab" :class="{ active: user.key === activeUserKey }" @click="activeUserKey = user.key">{{ user.display_name || user.username }}<em v-if="changedCountFor(user.key)"> {{ changedCountFor(user.key) }}</em></button></div>
      </section>

      <section v-if="selectedNode && selectedNode.id > 0" class="robot-panel">
        <div class="section-title"><div><h2>用户自动改码</h2><span class="hint">可从当前层级及其全部下级的机器人和真实用户中全选或多选；只有勾选的用户进入自动改码范围</span></div></div>
        <div v-if="!robotDrawReady" class="select-tip">输入 3 位预开奖号码后可用</div>
        <template v-else>
          <div class="robot-row">
            <span class="level-tag">{{ selectedNode.label }} {{ selectedNode.name }}</span>
            <span class="scope-count">用户 {{ selectedRobots.length }} / {{ scopeRobots.length }}</span>
            <el-button size="small" :disabled="!scopeRobots.length || selectedRobots.length === scopeRobots.length" @click="selectAllRobots">全选</el-button>
            <el-button size="small" :disabled="!selectedRobots.length" @click="clearSelectedRobots">清空</el-button>
            <el-select :model-value="selectedRobotKeys" multiple collapse-tags collapse-tags-tooltip filterable placeholder="请选择要自动改码的用户" style="min-width:360px;flex:1" @update:model-value="changeSelectedRobots"><el-option v-for="user in scopeRobots" :key="user.key" :label="memberLabel(user)" :value="user.key" /></el-select>
            <div class="filter-item"><label>层级目标盈亏</label><el-input v-model="robotAmount" placeholder="正数赢，负数输" style="width:170px" /></div>
            <el-button type="warning" :loading="planLoading" :disabled="robotAmount.trim() === '' || !selectedRobots.length" @click="generatePlan">生成改单方案</el-button>
            <span class="robot-tip">口径：当天总中−总投，结果允许在目标上下 5% 内</span>
          </div>
        </template>
      </section>

      <section class="bets-panel">
        <div class="section-title">
          <div><h2>原始注单</h2><span class="hint">按预开奖号码评估，命中的注单排在最前；修改后会重新计算该主单的全部下单明细</span></div>
          <div class="header-stats" v-if="visibleUserStats">
            <span class="total-chip">总投 ¥{{ money(visibleUserStats.bet) }}</span>
            <span class="total-chip">总中 ¥{{ money(visibleUserStats.win) }}</span>
            <span class="total-chip" :class="profitClass(visibleUserStats.profit)">盈亏 ¥{{ money(visibleUserStats.profit) }}</span>
            <template v-if="visibleUserStats.touched"><span class="total-chip preview">预览总投 ¥{{ money(visibleUserStats.pBet) }}</span><span class="total-chip preview">预览总中 ¥{{ money(visibleUserStats.pWin) }}</span><span class="total-chip preview" :class="profitClass(visibleUserStats.pProfit)">预览盈亏 ¥{{ money(visibleUserStats.pProfit) }}</span></template>
            <span v-if="previewing" class="previewing">预览计算中…</span>
          </div>
        </div>
        <div v-if="!selectedUsers.length" class="select-tip">请先在上方选择组织层级，下面将自动加载该层级全部会员在 {{ issueNo || '当前期' }} 的投注。</div>
        <div v-else-if="!sortedGroups.length" class="select-tip">{{ (visibleUser && (visibleUser.display_name || visibleUser.username)) || '该用户' }} 暂无可修改的投注号码，可切换到其他用户。</div>
        <div v-else class="bet-table-wrap">
          <table class="bet-table"><thead><tr><th>用户</th><th>原始注单（可编辑）</th><th>注单金额</th><th>{{ drawNo ? '预中奖' : '中奖' }}</th></tr></thead><tbody><tr v-for="group in sortedGroups" :key="group.key" :class="{ won: (effectiveWin(group) ?? 0) > 0 }"><td>{{ group.user.display_name || group.user.username }}</td><td class="source-value"><div v-if="editingRecordId !== group.record_id" class="ticket-view" title="点击编辑注单文本" @click="startEdit(group)"><template v-for="(seg, i) in rawSegments(group)" :key="i"><mark v-if="seg.hit">{{ seg.text }}</mark><template v-else>{{ seg.text }}</template></template></div><el-input v-else :ref="editFocus" type="textarea" :model-value="sourceValue(group)" :autosize="{ minRows: 2, maxRows: 8 }" @update:model-value="updateSource(group, $event)" @blur="editingRecordId = null" /><div v-if="previews[group.record_id]?.error" class="preview-error">{{ previews[group.record_id].error }}</div></td><td>¥{{ money(effectiveAmount(group)) }}</td><td class="win-cell" :class="{ preview: hasPreview(group) }">{{ effectiveWin(group) == null ? '—' : `¥${money(effectiveWin(group))}` }}</td></tr></tbody></table>
        </div>
      </section>

      <section class="replace-panel"><div class="section-title"><div><h2>保存原始注单</h2><span class="hint">保存后原主单号不变，系统按修改后的原始文本重建全部下单明细</span></div><el-button type="primary" :icon="Check" :loading="saving" :disabled="!changedGroups.length" @click="submit">保存并重算</el-button></div></section>

      <el-dialog v-model="planDialog" title="用户改单方案预览" width="84%" :close-on-click-modal="false">
        <template v-if="planResult">
          <div class="plan-summary">
            <span class="level-tag">{{ planResult.node.name }} · {{ planResult.day }}</span>
            <span class="total-chip">当天总投 ¥{{ money(Number(planResult.daily_bet)) }}</span>
            <span class="total-chip">当天总中 ¥{{ money(Number(planResult.daily_win_before)) }} → ¥{{ money(Number(planResult.daily_win_after)) }}</span>
            <span class="total-chip preview">目标盈亏 ¥{{ money(Number(planResult.target_profit)) }}</span>
            <span class="total-chip preview">允许区间 ¥{{ money(Number(planResult.target_min)) }} 至 ¥{{ money(Number(planResult.target_max)) }}</span>
            <span class="total-chip" :class="profitClass(Number(planResult.daily_profit_after))">报表口径盈亏 ¥{{ money(Number(planResult.daily_profit_before)) }} → ¥{{ money(Number(planResult.daily_profit_after)) }}</span>
            <span class="total-chip">金额保持不变</span>
          </div>
          <el-alert v-for="(warning, index) in planResult.warnings" :key="index" :title="warning" type="warning" :closable="false" class="plan-warning" />
          <el-table :data="planResult.items" max-height="430" size="small" border>
            <el-table-column label="用户" min-width="110"><template #default="{ row }">{{ row.display_name || row.username }}</template></el-table-column>
            <el-table-column label="方式" width="86"><template #default="{ row }"><el-tag :type="row.action === 'win' ? 'warning' : 'info'" size="small">{{ row.action === 'win' ? '改为中奖' : '改为不中奖' }}</el-tag></template></el-table-column>
            <el-table-column label="原始注单" min-width="230"><template #default="{ row }"><div class="plan-src"><template v-for="(seg, i) in planSegments(row, 'old_source')" :key="i"><mark v-if="seg.hit">{{ seg.text }}</mark><template v-else>{{ seg.text }}</template></template></div><div v-if="row.new_source" class="plan-src new">→ <template v-for="(seg, i) in planSegments(row, 'new_source')" :key="i"><mark v-if="seg.hit">{{ seg.text }}</mark><template v-else>{{ seg.text }}</template></template></div></template></el-table-column>
            <el-table-column label="金额（不变）" width="150"><template #default="{ row }">¥{{ row.old_amount }}</template></el-table-column>
            <el-table-column label="中奖" width="160"><template #default="{ row }"><span :class="{ won: Number(row.new_win) > 0 }">¥{{ row.old_win }} → ¥{{ row.new_win }}</span></template></el-table-column>
          </el-table>
        </template>
        <template #footer><el-button @click="planDialog = false">取消</el-button><el-button type="danger" :loading="applying" :disabled="!planResult?.within_tolerance" @click="applyPlan">确认执行改单</el-button></template>
      </el-dialog>
    </template>
  </div>
</template>

<style scoped>
.batch-page{min-height:100%;padding:22px;background:#f5f7fb;box-sizing:border-box}.batch-head{display:flex;align-items:center;justify-content:space-between;padding:18px 20px;background:#fff;border-radius:8px}.batch-head h1{margin:0;color:#26334b;font-size:22px}.batch-head p{margin:8px 0 0;color:#7d8799;font-size:13px}.head-actions{display:flex;gap:10px}.filter-panel,.users-panel,.robot-panel,.bets-panel,.replace-panel{margin-top:16px;padding:18px 20px;background:#fff;border:1px solid #e1e6ef;border-radius:8px}.filter-panel{display:flex;align-items:center;gap:36px;flex-wrap:wrap}.filter-item{display:flex;align-items:center;gap:12px;color:#68758b}.filter-item label{color:#344158;font-weight:600}.issue-value{color:#315fd3;font-weight:700}.user-summary{margin-left:auto;gap:20px}.section-title{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px}.section-title h2{display:inline;margin:0;color:#26334b;font-size:17px}.hint{margin-left:10px;color:#929bab;font-size:12px}.header-stats{display:flex;flex-wrap:wrap;gap:8px;align-items:center}.totals-row{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}.total-chip{padding:4px 10px;border:1px solid #dfe5f0;border-radius:4px;background:#f7f9fd;color:#344158;font-size:12px;font-weight:600}.total-chip.neg{color:#c0392b}.total-chip.pos{color:#1a7f37}.total-chip.preview{border-color:#f0c089;background:#fdf6ec;color:#b25d09}.previewing{color:#b25d09;font-size:12px}.org-picker{display:flex;flex-wrap:wrap;gap:10px}.node-level{font-style:normal;color:#4269c6;font-size:12px;margin-right:6px}.level-tag{padding:4px 10px;border-radius:4px;background:#eef3ff;color:#315fd3;font-size:12px;font-weight:700}.user-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px}.user-option{display:flex;align-items:center;gap:8px;padding:10px 12px;border:1px solid #e1e6ef;border-radius:6px;cursor:pointer}.user-option.selected{border-color:#356ee8;background:#f2f6ff}.user-option span{display:flex;min-width:0;flex:1;flex-direction:column}.user-option b{overflow:hidden;color:#26334b;text-overflow:ellipsis;white-space:nowrap}.user-option small{margin-top:3px;color:#9099aa}.user-option em{font-style:normal;color:#4269c6;font-size:12px}.user-tabs{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}.user-tab{padding:7px 16px;border:1px solid #e1e6ef;border-radius:6px;background:#fff;color:#344158;font-size:13px;cursor:pointer}.user-tab em{font-style:normal;color:#e8562d;font-weight:700}.user-tab.active{border-color:#356ee8;background:#f2f6ff;color:#315fd3;font-weight:600}.select-tip,.empty{padding:30px 0;text-align:center;color:#9099aa}.empty{margin-top:16px;background:#fff;border-radius:8px}.bet-table-wrap{overflow:auto}.bet-table{width:100%;border-collapse:collapse;color:#344158;font-size:13px}.bet-table th,.bet-table td{padding:10px 12px;text-align:left;border-bottom:1px solid #edf0f5}.bet-table th{background:#f8faff;color:#68758b;font-weight:600}.bet-table tr:hover td{background:#fafcff}.bet-table tr.won td{background:#fff7f0}.win-cell{font-weight:700;color:#c0392b;white-space:nowrap}.win-cell.preview{color:#b25d09}.preview-error{margin-top:6px;color:#c0392b;font-size:12px}.check-col{width:70px;text-align:center!important}.number-value{font-weight:700;color:#26334b;letter-spacing:.08em}.source-value{min-width:360px;max-width:620px;word-break:break-all;color:#7d8799;line-height:1.6}.number-picker{min-width:220px}.number-picker .el-select{width:220px}.picker-hint{display:block;margin-top:4px;color:#929bab;font-size:12px}.selected-hint{color:#315fd3;font-weight:600}.replace-fields{display:flex;align-items:flex-end;gap:14px;flex-wrap:wrap}.replace-fields label{display:flex;align-items:center;gap:8px;color:#344158;font-weight:600}.replace-fields .el-input{width:100px}.replace-fields .el-button{margin-left:auto;min-width:130px}@media(max-width:700px){.batch-page{padding:12px}.batch-head{align-items:flex-start;gap:14px;flex-direction:column}.head-actions{width:100%}.filter-panel{align-items:flex-start;flex-direction:column;gap:14px}.user-summary{margin-left:0}.replace-fields .el-button{margin-left:0}.bet-table{min-width:900px}}
.robot-row{display:flex;flex-wrap:wrap;gap:14px;align-items:center;padding:0 2px}.robot-tip{font-size:12px;color:#b25d09}.level-tag.selected{background:#fdf3e0;color:#b25d09}.level-tag.unselected{background:#f2f4f8;color:#68758b}.plan-summary{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:12px}.plan-warning{margin-bottom:6px}.plan-src{font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:12px;white-space:pre-wrap;word-break:break-all}.plan-src.new{color:#b25d09}.factor{color:#b25d09;font-style:normal;font-size:11px}.plan-stake{color:#68758b;font-size:11px;margin-top:2px}.ticket-view{min-height:64px;max-height:220px;overflow-y:auto;padding:6px 8px;border:1px solid transparent;border-radius:4px;font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:12px;line-height:1.7;white-space:pre-wrap;word-break:break-all;cursor:text;background:#fafbfd}.ticket-view:hover{border-color:#c8d2e3}.ticket-view mark,.plan-src mark{background:#ffe3a3;color:#8a4b00;padding:0 1px;border-radius:2px;font-weight:700}
</style>
