<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { ArrowLeft, Check, Refresh } from '@element-plus/icons-vue'
import { getBatchBetOptions, replaceBatchBetNumbers, type BatchBetLottery, type BatchBetNumber, type BatchBetUser } from '../api/admin'

const router = useRouter()
const route = useRoute()
const loading = ref(false)
const saving = ref(false)
const lotteries = ref<BatchBetLottery[]>([])
const issueOptions = ref<string[]>([])
const lotteryId = ref<number>()
const issueNo = ref('')
const users = ref<BatchBetUser[]>([])
const selectedUserKeys = ref<string[]>([])
const editedSources = ref<Record<string, string>>({})

const selectedUsers = computed(() => users.value.filter(user => selectedUserKeys.value.includes(user.key)))
const selectedNumbers = computed(() => selectedUsers.value.flatMap(user => user.numbers))
const detailGroups = computed(() => selectedUsers.value.flatMap(user => {
  const groups = new Map<number, { key: string; user: BatchBetUser; record_id: number; source_text: string; numbers: BatchBetNumber[] }>()
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

const initialRecordIds = computed(() => {
  const raw = String(route.query.record_ids || '')
  return raw.split(',').map(Number).filter(id => Number.isInteger(id) && id > 0)
})

async function loadOptions(params: { lotteryId?: number; issue?: string; userIds?: number[]; recordIds?: number[]; preserveSelection?: boolean } = {}) {
  loading.value = true
  try {
    const response = await getBatchBetOptions({ lottery_id: params.lotteryId, lottery: String(route.query.lottery || ''), issue_no: params.issue, user_ids: params.userIds, record_ids: params.recordIds ?? initialRecordIds.value })
    const data = response.data
    lotteries.value = data.lotteries || []
    lotteryId.value = data.lottery?.id
    issueNo.value = data.issue_no || ''
    issueOptions.value = data.issues || (issueNo.value ? [issueNo.value] : [])
    if (params.preserveSelection) {
      const incoming = data.users || []
      const incomingByKey = new Map(incoming.map(user => [user.key, user]))
      users.value = users.value.map(user => incomingByKey.get(user.key) || user)
    } else {
      users.value = data.users || []
      const selectedIds = data.selected_user_ids || []
      selectedUserKeys.value = selectedIds.length ? users.value.filter(user => selectedIds.includes(user.user_id)).map(user => user.key) : []
      editedSources.value = {}
    }
  } catch (error) {
    ElMessage.error(error instanceof Error ? error.message : '批量修改数据加载失败')
  } finally {
    loading.value = false
  }
}

function changeLottery(value: number) { selectedUserKeys.value = []; editedSources.value = {}; void loadOptions({ lotteryId: value, recordIds: [] }) }
function changeIssue(value: string) { selectedUserKeys.value = []; editedSources.value = {}; void loadOptions({ lotteryId: lotteryId.value, issue: value, recordIds: [] }) }
function changeUsers(values: string[]) {
  selectedUserKeys.value = values
  editedSources.value = {}
  const userIds = selectedUsers.value.map(user => user.user_id)
  void loadOptions({ lotteryId: lotteryId.value, issue: issueNo.value, userIds, recordIds: [], preserveSelection: true })
}
function sourceValue(group: { key: string; source_text: string }) { return editedSources.value[group.key] ?? group.source_text }
function updateSource(group: { key: string }, value: string) { editedSources.value[group.key] = value }

async function submit() {
  if (!lotteryId.value || !issueNo.value) { ElMessage.warning('当前没有可修改的未开奖期号'); return }
  if (!changedGroups.value.length) { ElMessage.warning('请先修改至少一条原始注单'); return }
  await ElMessageBox.confirm(`将重新计算 ${changedGroups.value.length} 条原始注单的全部明细，是否继续？`, '确认保存原始注单', { type: 'warning', confirmButtonText: '保存并重算', cancelButtonText: '取消' })
  saving.value = true
  try {
    const response = await replaceBatchBetNumbers({ lottery_id: lotteryId.value, issue_no: issueNo.value, records: changedGroups.value.map(group => ({ record_id: group.record_id, source_text: sourceValue(group) })) })
    ElMessage.success(`原始注单已保存并重算，共更新 ${response.data.changed} 条主单`)
    editedSources.value = {}
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
      <div><h1>批量修改</h1><p>选择彩种、期号和用户，直接编辑需要调整的原始注单。</p></div>
      <div class="head-actions"><el-button :icon="Refresh" @click="loadOptions({ lotteryId, issue: issueNo, userIds: selectedUsers.map(user => user.user_id), recordIds: [], preserveSelection: false })">刷新</el-button><el-button :icon="ArrowLeft" @click="router.push('/bet-records')">返回下单记录</el-button></div>
    </section>

    <section class="filter-panel">
      <div class="filter-item"><label>彩种</label><el-select v-model="lotteryId" placeholder="请选择彩种" @change="changeLottery" style="width:220px"><el-option v-for="lottery in lotteries" :key="lottery.id" :label="lottery.name" :value="lottery.id" /></el-select></div>
      <div class="filter-item"><label>期号</label><el-select v-model="issueNo" placeholder="请选择期号" :disabled="!issueOptions.length" style="width:220px" @change="changeIssue"><el-option v-for="issue in issueOptions" :key="issue" :label="issue" :value="issue" /></el-select></div>
      <div class="filter-item user-summary"><span>用户 {{ selectedUserKeys.length }} / {{ users.length }}</span><span>原始注单 {{ changedGroups.length }} 条已修改</span></div>
    </section>

    <section v-if="!users.length && !loading" class="empty">该彩种当前未开奖期没有可修改的投注</section>
    <template v-else>
      <section class="users-panel">
        <div class="section-title"><div><h2>选择用户</h2><span class="hint">仅显示 {{ issueNo || '当前期' }} 已下注且未开奖的用户，可多选</span></div><span class="selected-hint">已选 {{ selectedUserKeys.length }} 人</span></div>
        <el-select v-model="selectedUserKeys" multiple collapse-tags collapse-tags-tooltip filterable placeholder="请选择用户（可多选）" style="width:100%" :disabled="!users.length" @change="changeUsers"><el-option v-for="user in users" :key="user.key" :label="`${user.display_name || user.username}（${user.username}，${user.number_count ?? user.numbers.length}条）`" :value="user.key" /></el-select>
      </section>

      <section class="bets-panel">
        <div class="section-title"><div><h2>原始注单</h2><span class="hint">只显示原始文本；修改后会重新计算该主单的全部下单明细</span></div><span class="selected-hint">已修改 {{ changedGroups.length }} 条</span></div>
        <div v-if="!selectedUsers.length" class="select-tip">请先选择用户，下面将加载该用户在 {{ issueNo || '当前期' }} 的投注。</div>
        <div v-else-if="!selectedNumbers.length" class="select-tip">所选用户暂无可修改的投注号码。</div>
        <div v-else class="bet-table-wrap">
          <table class="bet-table"><thead><tr><th>用户</th><th>原始注单（可编辑）</th><th>注单金额</th></tr></thead><tbody><tr v-for="group in detailGroups" :key="group.key"><td>{{ group.user.display_name || group.user.username }}</td><td class="source-value"><el-input type="textarea" :model-value="sourceValue(group)" :autosize="{ minRows: 2, maxRows: 8 }" @update:model-value="updateSource(group, $event)" /></td><td>¥{{ group.numbers.reduce((sum, number) => sum + Number(number.amount || 0), 0).toFixed(2) }}</td></tr></tbody></table>
        </div>
      </section>

      <section class="replace-panel"><div class="section-title"><div><h2>保存原始注单</h2><span class="hint">保存后原主单号不变，系统按修改后的原始文本重建全部下单明细</span></div><el-button type="primary" :icon="Check" :loading="saving" :disabled="!changedGroups.length" @click="submit">保存并重算</el-button></div></section>
    </template>
  </div>
</template>

<style scoped>
.batch-page{min-height:100%;padding:22px;background:#f5f7fb;box-sizing:border-box}.batch-head{display:flex;align-items:center;justify-content:space-between;padding:18px 20px;background:#fff;border-radius:8px}.batch-head h1{margin:0;color:#26334b;font-size:22px}.batch-head p{margin:8px 0 0;color:#7d8799;font-size:13px}.head-actions{display:flex;gap:10px}.filter-panel,.users-panel,.bets-panel,.replace-panel{margin-top:16px;padding:18px 20px;background:#fff;border:1px solid #e1e6ef;border-radius:8px}.filter-panel{display:flex;align-items:center;gap:36px;flex-wrap:wrap}.filter-item{display:flex;align-items:center;gap:12px;color:#68758b}.filter-item label{color:#344158;font-weight:600}.issue-value{color:#315fd3;font-weight:700}.user-summary{margin-left:auto;gap:20px}.section-title{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px}.section-title h2{display:inline;margin:0;color:#26334b;font-size:17px}.hint{margin-left:10px;color:#929bab;font-size:12px}.user-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px}.user-option{display:flex;align-items:center;gap:8px;padding:10px 12px;border:1px solid #e1e6ef;border-radius:6px;cursor:pointer}.user-option.selected{border-color:#356ee8;background:#f2f6ff}.user-option span{display:flex;min-width:0;flex:1;flex-direction:column}.user-option b{overflow:hidden;color:#26334b;text-overflow:ellipsis;white-space:nowrap}.user-option small{margin-top:3px;color:#9099aa}.user-option em{font-style:normal;color:#4269c6;font-size:12px}.select-tip,.empty{padding:30px 0;text-align:center;color:#9099aa}.empty{margin-top:16px;background:#fff;border-radius:8px}.bet-table-wrap{overflow:auto}.bet-table{width:100%;border-collapse:collapse;color:#344158;font-size:13px}.bet-table th,.bet-table td{padding:10px 12px;text-align:left;border-bottom:1px solid #edf0f5}.bet-table th{background:#f8faff;color:#68758b;font-weight:600}.bet-table tr:hover td{background:#fafcff}.check-col{width:70px;text-align:center!important}.number-value{font-weight:700;color:#26334b;letter-spacing:.08em}.source-value{min-width:360px;max-width:620px;word-break:break-all;color:#7d8799;line-height:1.6}.number-picker{min-width:220px}.number-picker .el-select{width:220px}.picker-hint{display:block;margin-top:4px;color:#929bab;font-size:12px}.selected-hint{color:#315fd3;font-weight:600}.replace-fields{display:flex;align-items:flex-end;gap:14px;flex-wrap:wrap}.replace-fields label{display:flex;align-items:center;gap:8px;color:#344158;font-weight:600}.replace-fields .el-input{width:100px}.replace-fields .el-button{margin-left:auto;min-width:130px}@media(max-width:700px){.batch-page{padding:12px}.batch-head{align-items:flex-start;gap:14px;flex-direction:column}.head-actions{width:100%}.filter-panel{align-items:flex-start;flex-direction:column;gap:14px}.user-summary{margin-left:0}.replace-fields .el-button{margin-left:0}.bet-table{min-width:900px}}
</style>
