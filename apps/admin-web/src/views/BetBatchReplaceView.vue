<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { ArrowLeft, Check, Refresh } from '@element-plus/icons-vue'
import { getBatchBetOptions, replaceBatchBetNumbers, type BatchBetLottery, type BatchBetNumber, type BatchBetUser } from '../api/admin'

const router = useRouter()
const loading = ref(false)
const saving = ref(false)
const lotteries = ref<BatchBetLottery[]>([])
const lotteryId = ref<number>()
const issueNo = ref('')
const users = ref<BatchBetUser[]>([])
const selectedUserKeys = ref<string[]>([])
const selectedNumberKeys = ref<string[]>([])
const replacements = ref({ hundreds: '', tens: '', units: '' })

const selectedUsers = computed(() => users.value.filter(user => selectedUserKeys.value.includes(user.key)))
const selectedNumbers = computed(() => selectedUsers.value.flatMap(user => user.numbers))
const allUsersSelected = computed(() => users.value.length > 0 && selectedUserKeys.value.length === users.value.length)
const allNumbersSelected = computed(() => selectedNumbers.value.length > 0 && selectedNumbers.value.every(number => selectedNumberKeys.value.includes(number.key)))
const selectedCount = computed(() => selectedNumbers.value.filter(number => selectedNumberKeys.value.includes(number.key)).length)
const canReplace = computed(() => selectedCount.value > 0 && Object.values(replacements.value).some(value => value.trim() !== ''))

async function loadOptions(nextLotteryId?: number) {
  loading.value = true
  try {
    const response = await getBatchBetOptions(nextLotteryId)
    const data = response.data
    lotteries.value = data.lotteries || []
    lotteryId.value = data.lottery?.id
    issueNo.value = data.issue_no || ''
    users.value = data.users || []
    selectedUserKeys.value = []
    selectedNumberKeys.value = []
  } catch (error) {
    ElMessage.error(error instanceof Error ? error.message : '批量修改数据加载失败')
  } finally {
    loading.value = false
  }
}

function changeLottery(value: number) { void loadOptions(value) }
function toggleAllUsers(checked: boolean) {
  selectedUserKeys.value = checked ? users.value.map(user => user.key) : []
  selectedNumberKeys.value = []
}
function toggleUser(user: BatchBetUser, checked: boolean) {
  selectedUserKeys.value = checked ? [...selectedUserKeys.value, user.key] : selectedUserKeys.value.filter(key => key !== user.key)
  selectedNumberKeys.value = selectedNumberKeys.value.filter(key => selectedNumbers.value.some(number => number.key === key))
}
function toggleAllNumbers(checked: boolean) { selectedNumberKeys.value = checked ? selectedNumbers.value.map(number => number.key) : [] }
function toggleNumber(number: BatchBetNumber, checked: boolean) {
  selectedNumberKeys.value = checked ? [...selectedNumberKeys.value, number.key] : selectedNumberKeys.value.filter(key => key !== number.key)
}

async function submit() {
  if (!lotteryId.value || !issueNo.value) { ElMessage.warning('当前没有可修改的未开奖期号'); return }
  if (!canReplace.value) { ElMessage.warning('请至少选择一条投注并填写需要替换的位数'); return }
  if (Object.values(replacements.value).some(value => value.trim() !== '' && !/^\d$/.test(value.trim()))) {
    ElMessage.warning('替换数字必须是0到9的单个数字'); return
  }
  await ElMessageBox.confirm(`将修改 ${selectedCount.value} 条号码，是否继续？`, '确认批量修改', { type: 'warning', confirmButtonText: '确认修改', cancelButtonText: '取消' })
  saving.value = true
  try {
    const response = await replaceBatchBetNumbers({
      lottery_id: lotteryId.value,
      issue_no: issueNo.value,
      selections: selectedNumbers.value.filter(number => selectedNumberKeys.value.includes(number.key)).map(number => ({ detail_id: number.detail_id, number_index: number.number_index })),
      replacements: { hundreds: replacements.value.hundreds.trim(), tens: replacements.value.tens.trim(), units: replacements.value.units.trim() },
    })
    ElMessage.success(`批量修改完成，共修改 ${response.data.changed} 条`)
    replacements.value = { hundreds: '', tens: '', units: '' }
    await loadOptions(lotteryId.value)
  } catch (error) {
    ElMessage.error(error instanceof Error ? error.message : '批量修改失败')
  } finally {
    saving.value = false
  }
}

onMounted(() => loadOptions())
</script>

<template>
  <div class="batch-page" v-loading="loading">
    <section class="batch-head">
      <div><h1>批量修改</h1><p>先选择彩种，系统自动匹配该彩种当前未开奖期；再选择用户和需要修改的投注号码。</p></div>
      <div class="head-actions"><el-button :icon="Refresh" @click="loadOptions(lotteryId)">刷新</el-button><el-button :icon="ArrowLeft" @click="router.push('/bet-records')">返回下单记录</el-button></div>
    </section>

    <section class="filter-panel">
      <div class="filter-item"><label>彩种</label><el-select v-model="lotteryId" placeholder="请选择彩种" @change="changeLottery" style="width:220px"><el-option v-for="lottery in lotteries" :key="lottery.id" :label="lottery.name" :value="lottery.id" /></el-select></div>
      <div class="filter-item"><label>未开奖期号</label><span class="issue-value">{{ issueNo || '当前没有可修改的未开奖期' }}</span></div>
      <div class="filter-item user-summary"><span>用户 {{ selectedUserKeys.length }} / {{ users.length }}</span><span>投注 {{ selectedCount }} 条已选</span></div>
    </section>

    <section v-if="!users.length && !loading" class="empty">该彩种当前未开奖期没有可修改的投注</section>
    <template v-else>
      <section class="users-panel">
        <div class="section-title"><h2>选择用户</h2><el-checkbox :model-value="allUsersSelected" @change="toggleAllUsers(Boolean($event))">全选用户</el-checkbox></div>
        <div class="user-grid">
          <label v-for="user in users" :key="user.key" class="user-option" :class="{ selected: selectedUserKeys.includes(user.key) }">
            <el-checkbox :model-value="selectedUserKeys.includes(user.key)" @change="toggleUser(user, Boolean($event))" />
            <span><b>{{ user.display_name || user.username }}</b><small>{{ user.username }}<template v-if="user.site_name"> · {{ user.site_name }}</template></small></span><em>{{ user.numbers.length }} 条</em>
          </label>
        </div>
      </section>

      <section class="bets-panel">
        <div class="section-title"><div><h2>投注列表</h2><span class="hint">默认不选，可逐条勾选需要修改的号码</span></div><el-checkbox :model-value="allNumbersSelected" :disabled="!selectedNumbers.length" @change="toggleAllNumbers(Boolean($event))">全选当前投注</el-checkbox></div>
        <div v-if="!selectedUsers.length" class="select-tip">请先选择用户，下面将加载该用户在 {{ issueNo || '当前期' }} 的投注。</div>
        <div v-else-if="!selectedNumbers.length" class="select-tip">所选用户暂无可修改的投注号码。</div>
        <div v-else class="bet-table-wrap">
          <table class="bet-table"><thead><tr><th class="check-col">选择</th><th>用户</th><th>号码</th><th>金额</th><th>来源</th></tr></thead><tbody><template v-for="user in selectedUsers" :key="user.key"><tr v-for="number in user.numbers" :key="number.key"><td class="check-col"><el-checkbox :model-value="selectedNumberKeys.includes(number.key)" @change="toggleNumber(number, Boolean($event))" /></td><td>{{ user.display_name || user.username }}</td><td class="number-value">{{ number.value }}</td><td>¥{{ number.amount }}</td><td class="source-value">{{ number.source_text || '-' }}</td></tr></template></tbody></table>
        </div>
      </section>

      <section class="replace-panel"><div class="section-title"><div><h2>替换位数</h2><span class="hint">留空表示不替换该位</span></div><span class="selected-hint">已选择 {{ selectedCount }} 条</span></div><div class="replace-fields"><label>百位<el-input v-model="replacements.hundreds" maxlength="1" inputmode="numeric" placeholder="0-9" /></label><label>十位<el-input v-model="replacements.tens" maxlength="1" inputmode="numeric" placeholder="0-9" /></label><label>个位<el-input v-model="replacements.units" maxlength="1" inputmode="numeric" placeholder="0-9" /></label><el-button type="primary" :icon="Check" :loading="saving" :disabled="!canReplace" @click="submit">执行修改</el-button></div></section>
    </template>
  </div>
</template>

<style scoped>
.batch-page{min-height:100%;padding:22px;background:#f5f7fb;box-sizing:border-box}.batch-head{display:flex;align-items:center;justify-content:space-between;padding:18px 20px;background:#fff;border-radius:8px}.batch-head h1{margin:0;color:#26334b;font-size:22px}.batch-head p{margin:8px 0 0;color:#7d8799;font-size:13px}.head-actions{display:flex;gap:10px}.filter-panel,.users-panel,.bets-panel,.replace-panel{margin-top:16px;padding:18px 20px;background:#fff;border:1px solid #e1e6ef;border-radius:8px}.filter-panel{display:flex;align-items:center;gap:36px;flex-wrap:wrap}.filter-item{display:flex;align-items:center;gap:12px;color:#68758b}.filter-item label{color:#344158;font-weight:600}.issue-value{color:#315fd3;font-weight:700}.user-summary{margin-left:auto;gap:20px}.section-title{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px}.section-title h2{display:inline;margin:0;color:#26334b;font-size:17px}.hint{margin-left:10px;color:#929bab;font-size:12px}.user-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px}.user-option{display:flex;align-items:center;gap:8px;padding:10px 12px;border:1px solid #e1e6ef;border-radius:6px;cursor:pointer}.user-option.selected{border-color:#356ee8;background:#f2f6ff}.user-option span{display:flex;min-width:0;flex:1;flex-direction:column}.user-option b{overflow:hidden;color:#26334b;text-overflow:ellipsis;white-space:nowrap}.user-option small{margin-top:3px;color:#9099aa}.user-option em{font-style:normal;color:#4269c6;font-size:12px}.select-tip,.empty{padding:30px 0;text-align:center;color:#9099aa}.empty{margin-top:16px;background:#fff;border-radius:8px}.bet-table-wrap{overflow:auto}.bet-table{width:100%;border-collapse:collapse;color:#344158;font-size:13px}.bet-table th,.bet-table td{padding:10px 12px;text-align:left;border-bottom:1px solid #edf0f5}.bet-table th{background:#f8faff;color:#68758b;font-weight:600}.bet-table tr:hover td{background:#fafcff}.check-col{width:70px;text-align:center!important}.number-value{font-weight:700;color:#26334b;letter-spacing:.08em}.source-value{max-width:360px;word-break:break-all;color:#7d8799}.selected-hint{color:#315fd3;font-weight:600}.replace-fields{display:flex;align-items:flex-end;gap:14px;flex-wrap:wrap}.replace-fields label{display:flex;align-items:center;gap:8px;color:#344158;font-weight:600}.replace-fields .el-input{width:100px}.replace-fields .el-button{margin-left:auto;min-width:130px}@media(max-width:700px){.batch-page{padding:12px}.batch-head{align-items:flex-start;gap:14px;flex-direction:column}.head-actions{width:100%}.filter-panel{align-items:flex-start;flex-direction:column;gap:14px}.user-summary{margin-left:0}.replace-fields .el-button{margin-left:0}.bet-table{min-width:620px}}
</style>
