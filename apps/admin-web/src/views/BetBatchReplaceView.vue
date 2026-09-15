<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { ArrowLeft, Check, Refresh } from '@element-plus/icons-vue'
import { getBatchBetOptions, previewBatchBetDraw, replaceBatchBetNumbers, type BatchBetLottery, type BatchBetNode, type BatchBetNumber, type BatchBetPreviewResult, type BatchBetUser } from '../api/admin'

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
const statText = (t: { bet: number; win: number | null; profit: number | null }) => `投 ${money(t.bet)} 中 ${money(t.win)} 盈亏 ${money(t.profit)}`
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
// Members selectable at the current cascade position: direct members of the
// selected node (or the unassigned bucket for the pseudo node).
const memberOptions = computed(() => {
  const node = selectedNode.value
  if (!node) return []
  if (node.id === pseudoId.value) return users.value.filter(user => user.site_id === activeSite.value && (!user.organization_id || !nodePathById.value.has(user.organization_id)))
  return users.value.filter(user => user.organization_id === node.id)
})
const subtreeUsers = (node: BatchBetNode) => node.id === pseudoId.value
  ? users.value.filter(user => user.site_id === node.site_id && (!user.organization_id || !nodePathById.value.has(user.organization_id)))
  : users.value.filter(user => user.site_id === node.site_id && !!user.org_path && node.path !== '' && user.org_path.startsWith(node.path))
function nodeTotals(node: BatchBetNode) { return finalize(sumStats(subtreeUsers(node))) }
function nodeLabel(node: BatchBetNode) {
  const t = nodeTotals(node)
  const base = `${node.label} ${node.name}｜投 ${money(t.bet)} 中 ${money(t.win)} 盈亏 ${money(t.profit)}`
  return t.touched ? `${base}｜预览 ${money(t.pProfit)}` : base
}
function memberLabel(user: BatchBetUser) {
  const s = adjustedStats(user)
  const base = `${user.display_name || user.username}（${user.username}，${user.number_count ?? user.numbers.length}条｜投 ${money(s.bet)} 中 ${money(s.win)} 盈亏 ${money(s.profit)}`
  return s.touched ? `${base}｜预览 ${money(s.pProfit)}）` : `${base}）`
}
const selectedNodeTotals = computed(() => selectedNode.value ? finalize(nodeTotals(selectedNode.value)) : null)
const visibleUserStats = computed(() => visibleUser.value ? adjustedStats(visibleUser.value) : null)

function selectOrg(depth: number, value: number | string | undefined) {
  const id = value === '' || value == null ? undefined : Number(value)
  orgChain.value = id == null ? orgChain.value.slice(0, depth) : [...orgChain.value.slice(0, depth), id]
  // Edited sources stay keyed by record so navigating the tree never loses
  // work; only the member selection follows the new position.
  selectedUserKeys.value = []
  ensureActiveUser()
}
function changeSite(value: number) { activeSite.value = value; orgChain.value = []; selectedUserKeys.value = [] }

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
      users.value = users.value.map(user => incomingByKey.get(user.key) || user)
    } else {
      users.value = data.users || []
      const selectedIds = data.selected_user_ids || []
      selectedUserKeys.value = selectedIds.length ? users.value.filter(user => selectedIds.includes(user.user_id)).map(user => user.key) : []
      editedSources.value = {}
      previews.value = {}
      if (!activeSite.value || !siteIds.value.includes(activeSite.value)) activeSite.value = siteIds.value[0]
    }
    ensureActiveUser()
  } catch (error) {
    ElMessage.error(error instanceof Error ? error.message : '批量修改数据加载失败')
  } finally {
    loading.value = false
  }
}

function resetSelection() { orgChain.value = []; selectedUserKeys.value = []; editedSources.value = {}; previews.value = {} }
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
function changeUsers(values: string[]) {
  selectedUserKeys.value = values
  ensureActiveUser()
  const userIds = selectedUsers.value.map(user => user.user_id)
  void loadOptions({ lotteryId: lotteryId.value, issue: issueNo.value, userIds, recordIds: [], preserveSelection: true })
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
        <div class="section-title"><div><h2>选择用户</h2><span class="hint">按层级选择：站点 → 总监 → 下级组织 → 会员；选项内标注该层级本期总投/总中/盈亏</span></div><span class="selected-hint">已选 {{ selectedUserKeys.length }} 人</span></div>
        <div class="org-picker">
          <el-select v-if="siteList.length > 1" :model-value="activeSite" placeholder="请选择站点" style="width:180px" @update:model-value="(value: number | string) => changeSite(Number(value))"><el-option v-for="site in siteList" :key="site.id" :label="site.name" :value="site.id" /></el-select>
          <el-select v-for="(options, depth) in chainLevels" :key="depth" :model-value="orgChain[depth]" clearable filterable :placeholder="depth === 0 ? '请选择总监' : '请选择下级组织'" style="width:320px" @update:model-value="(value: number | string | undefined) => selectOrg(depth, value)"><el-option v-for="node in options" :key="node.id" :label="nodeLabel(node)" :value="node.id"><span class="node-option"><em>{{ node.label }}</em>{{ node.name }}</span><span class="node-stats">{{ statText(nodeTotals(node)) }}</span></el-option></el-select>
          <el-select v-if="memberOptions.length" v-model="selectedUserKeys" multiple collapse-tags collapse-tags-tooltip filterable placeholder="请选择用户（可多选）" style="min-width:320px;flex:1" @change="changeUsers"><el-option v-for="user in memberOptions" :key="user.key" :label="memberLabel(user)" :value="user.key" /></el-select>
        </div>
        <div v-if="selectedNodeTotals" class="totals-row">
          <span class="total-chip">总投 ¥{{ money(selectedNodeTotals.bet) }}</span>
          <span class="total-chip">总中 ¥{{ money(selectedNodeTotals.win) }}</span>
          <span class="total-chip" :class="profitClass(selectedNodeTotals.profit)">盈亏 ¥{{ money(selectedNodeTotals.profit) }}</span>
          <template v-if="selectedNodeTotals.touched"><span class="total-chip preview">预览总投 ¥{{ money(selectedNodeTotals.pBet) }}</span><span class="total-chip preview">预览总中 ¥{{ money(selectedNodeTotals.pWin) }}</span><span class="total-chip preview" :class="profitClass(selectedNodeTotals.pProfit)">预览盈亏 ¥{{ money(selectedNodeTotals.pProfit) }}</span></template>
        </div>
        <div v-if="selectedUsers.length" class="user-tabs"><button v-for="user in selectedUsers" :key="user.key" type="button" class="user-tab" :class="{ active: user.key === activeUserKey }" @click="activeUserKey = user.key">{{ user.display_name || user.username }}<em v-if="changedCountFor(user.key)"> {{ changedCountFor(user.key) }}</em></button></div>
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
        <div v-if="!selectedUsers.length" class="select-tip">请先在上方逐级选择组织和用户，下面将加载该用户在 {{ issueNo || '当前期' }} 的投注。</div>
        <div v-else-if="!sortedGroups.length" class="select-tip">{{ (visibleUser && (visibleUser.display_name || visibleUser.username)) || '该用户' }} 暂无可修改的投注号码，可切换到其他用户。</div>
        <div v-else class="bet-table-wrap">
          <table class="bet-table"><thead><tr><th>用户</th><th>原始注单（可编辑）</th><th>注单金额</th><th>{{ drawNo ? '预中奖' : '中奖' }}</th></tr></thead><tbody><tr v-for="group in sortedGroups" :key="group.key" :class="{ won: (effectiveWin(group) ?? 0) > 0 }"><td>{{ group.user.display_name || group.user.username }}</td><td class="source-value"><el-input type="textarea" :model-value="sourceValue(group)" :autosize="{ minRows: 2, maxRows: 8 }" @update:model-value="updateSource(group, $event)" /><div v-if="previews[group.record_id]?.error" class="preview-error">{{ previews[group.record_id].error }}</div></td><td>¥{{ money(effectiveAmount(group)) }}</td><td class="win-cell" :class="{ preview: hasPreview(group) }">{{ effectiveWin(group) == null ? '—' : `¥${money(effectiveWin(group))}` }}</td></tr></tbody></table>
        </div>
      </section>

      <section class="replace-panel"><div class="section-title"><div><h2>保存原始注单</h2><span class="hint">保存后原主单号不变，系统按修改后的原始文本重建全部下单明细</span></div><el-button type="primary" :icon="Check" :loading="saving" :disabled="!changedGroups.length" @click="submit">保存并重算</el-button></div></section>
    </template>
  </div>
</template>

<style scoped>
.batch-page{min-height:100%;padding:22px;background:#f5f7fb;box-sizing:border-box}.batch-head{display:flex;align-items:center;justify-content:space-between;padding:18px 20px;background:#fff;border-radius:8px}.batch-head h1{margin:0;color:#26334b;font-size:22px}.batch-head p{margin:8px 0 0;color:#7d8799;font-size:13px}.head-actions{display:flex;gap:10px}.filter-panel,.users-panel,.bets-panel,.replace-panel{margin-top:16px;padding:18px 20px;background:#fff;border:1px solid #e1e6ef;border-radius:8px}.filter-panel{display:flex;align-items:center;gap:36px;flex-wrap:wrap}.filter-item{display:flex;align-items:center;gap:12px;color:#68758b}.filter-item label{color:#344158;font-weight:600}.issue-value{color:#315fd3;font-weight:700}.user-summary{margin-left:auto;gap:20px}.section-title{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px}.section-title h2{display:inline;margin:0;color:#26334b;font-size:17px}.hint{margin-left:10px;color:#929bab;font-size:12px}.header-stats{display:flex;flex-wrap:wrap;gap:8px;align-items:center}.totals-row{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}.total-chip{padding:4px 10px;border:1px solid #dfe5f0;border-radius:4px;background:#f7f9fd;color:#344158;font-size:12px;font-weight:600}.total-chip.neg{color:#c0392b}.total-chip.pos{color:#1a7f37}.total-chip.preview{border-color:#f0c089;background:#fdf6ec;color:#b25d09}.previewing{color:#b25d09;font-size:12px}.org-picker{display:flex;flex-wrap:wrap;gap:10px}.node-option{display:flex;gap:8px;align-items:baseline}.node-option em{font-style:normal;color:#4269c6;font-size:12px}.node-stats{float:right;color:#9099aa;font-size:12px}.user-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px}.user-option{display:flex;align-items:center;gap:8px;padding:10px 12px;border:1px solid #e1e6ef;border-radius:6px;cursor:pointer}.user-option.selected{border-color:#356ee8;background:#f2f6ff}.user-option span{display:flex;min-width:0;flex:1;flex-direction:column}.user-option b{overflow:hidden;color:#26334b;text-overflow:ellipsis;white-space:nowrap}.user-option small{margin-top:3px;color:#9099aa}.user-option em{font-style:normal;color:#4269c6;font-size:12px}.user-tabs{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}.user-tab{padding:7px 16px;border:1px solid #e1e6ef;border-radius:6px;background:#fff;color:#344158;font-size:13px;cursor:pointer}.user-tab em{font-style:normal;color:#e8562d;font-weight:700}.user-tab.active{border-color:#356ee8;background:#f2f6ff;color:#315fd3;font-weight:600}.select-tip,.empty{padding:30px 0;text-align:center;color:#9099aa}.empty{margin-top:16px;background:#fff;border-radius:8px}.bet-table-wrap{overflow:auto}.bet-table{width:100%;border-collapse:collapse;color:#344158;font-size:13px}.bet-table th,.bet-table td{padding:10px 12px;text-align:left;border-bottom:1px solid #edf0f5}.bet-table th{background:#f8faff;color:#68758b;font-weight:600}.bet-table tr:hover td{background:#fafcff}.bet-table tr.won td{background:#fff7f0}.win-cell{font-weight:700;color:#c0392b;white-space:nowrap}.win-cell.preview{color:#b25d09}.preview-error{margin-top:6px;color:#c0392b;font-size:12px}.check-col{width:70px;text-align:center!important}.number-value{font-weight:700;color:#26334b;letter-spacing:.08em}.source-value{min-width:360px;max-width:620px;word-break:break-all;color:#7d8799;line-height:1.6}.number-picker{min-width:220px}.number-picker .el-select{width:220px}.picker-hint{display:block;margin-top:4px;color:#929bab;font-size:12px}.selected-hint{color:#315fd3;font-weight:600}.replace-fields{display:flex;align-items:flex-end;gap:14px;flex-wrap:wrap}.replace-fields label{display:flex;align-items:center;gap:8px;color:#344158;font-weight:600}.replace-fields .el-input{width:100px}.replace-fields .el-button{margin-left:auto;min-width:130px}@media(max-width:700px){.batch-page{padding:12px}.batch-head{align-items:flex-start;gap:14px;flex-direction:column}.head-actions{width:100%}.filter-panel{align-items:flex-start;flex-direction:column;gap:14px}.user-summary{margin-left:0}.replace-fields .el-button{margin-left:0}.bet-table{min-width:900px}}
</style>
