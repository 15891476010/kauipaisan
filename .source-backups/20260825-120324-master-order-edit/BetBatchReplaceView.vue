<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { ArrowLeft, Check, Refresh } from '@element-plus/icons-vue'
import { getBatchBetOptions, replaceBatchBetNumbers, type BatchBetLottery, type BatchBetUser } from '../api/admin'

const router=useRouter()
const loading=ref(false)
const saving=ref(false)
const lotteries=ref<BatchBetLottery[]>([])
const lotteryId=ref<number>()
const issueNo=ref('')
const users=ref<BatchBetUser[]>([])
const selectedUsers=ref<string[]>([])
const selectedNumbers=ref<string[]>([])
const replacements=ref({ hundreds:'', tens:'', units:'' })
const visibleUsers=computed(()=>users.value.filter(user=>selectedUsers.value.includes(user.key)))
const selectedSet=computed(()=>new Set(selectedNumbers.value))
const selectedVisibleNumbers=computed(()=>visibleUsers.value.flatMap(user=>user.numbers).filter(number=>selectedSet.value.has(number.key)))

async function load(preferredLotteryId?: number) {
  loading.value=true
  try {
    const response=await getBatchBetOptions(preferredLotteryId ?? lotteryId.value)
    const data=response.data
    lotteries.value=data.lotteries || []
    lotteryId.value=data.lottery?.id
    issueNo.value=data.issue_no || ''
    users.value=data.users || []
    selectedUsers.value=users.value.map(user=>user.key)
    selectedNumbers.value=users.value.flatMap(user=>user.numbers.map(number=>number.key))
  } catch(error) {
    ElMessage.error(error instanceof Error ? error.message : '批量替换数据加载失败')
  } finally { loading.value=false }
}
function userAllSelected(user: BatchBetUser) { return user.numbers.length>0 && user.numbers.every(number=>selectedSet.value.has(number.key)) }
function toggleUserAll(user: BatchBetUser, checked: boolean) {
  const keys=new Set(selectedNumbers.value)
  user.numbers.forEach(number=>checked ? keys.add(number.key) : keys.delete(number.key))
  selectedNumbers.value=Array.from(keys)
}
function toggleNumber(key: string) {
  const keys=new Set(selectedNumbers.value)
  if (keys.has(key)) keys.delete(key); else keys.add(key)
  selectedNumbers.value=Array.from(keys)
}
function sanitize(field: 'hundreds'|'tens'|'units') {
  replacements.value[field]=replacements.value[field].replace(/\D/g,'').slice(-1)
}
function changeLottery(value: unknown) { void load(Number(value)) }
function changeUserAll(user: BatchBetUser, checked: unknown) { toggleUserAll(user, Boolean(checked)) }
async function save() {
  if (!lotteryId.value || !issueNo.value) { ElMessage.warning('当前彩种暂无可批量修改的未开奖期'); return }
  const selected=selectedVisibleNumbers.value
  if (!selected.length) { ElMessage.warning('请选择需要替换的号码'); return }
  if (!Object.values(replacements.value).some(Boolean)) { ElMessage.warning('请至少输入一个替换数字'); return }
  await ElMessageBox.confirm(`确定修改 ${selected.length} 个选中号码吗？保存后将直接更新当前未开奖期注单。`,'确认批量替换',{type:'warning',confirmButtonText:'确认保存',cancelButtonText:'取消'})
  saving.value=true
  try {
    const response=await replaceBatchBetNumbers({lottery_id:lotteryId.value,issue_no:issueNo.value,selections:selected.map(number=>({detail_id:number.detail_id,number_index:number.number_index})),replacements:{...replacements.value}})
    ElMessage.success(`批量替换完成，共修改 ${response.data.changed} 个号码`)
    replacements.value={hundreds:'',tens:'',units:''}
    await load(lotteryId.value)
  } catch(error) { ElMessage.error(error instanceof Error ? error.message : '批量替换失败') }
  finally { saving.value=false }
}
onMounted(()=>load())
</script>

<template>
  <div class="batch-page" v-loading="loading">
    <section class="batch-filter">
      <label><span>彩种</span><el-select v-model="lotteryId" placeholder="请选择彩种" @change="changeLottery"><el-option v-for="lottery in lotteries" :key="lottery.id" :label="lottery.name" :value="lottery.id" /></el-select></label>
      <label class="user-select"><span>参与用户</span><el-select v-model="selectedUsers" multiple collapse-tags collapse-tags-tooltip filterable placeholder="请选择用户"><el-option v-for="user in users" :key="user.key" :label="`${user.username}${user.site_name ? `（${user.site_name}）` : ''}`" :value="user.key" /></el-select></label>
      <div class="issue-box"><span>当前未开奖期</span><strong>{{ issueNo || '暂无未开奖注单' }}</strong></div>
      <el-button :icon="Refresh" @click="load(lotteryId)">刷新</el-button>
      <el-button :icon="ArrowLeft" @click="router.push('/bet-records')">返回下单记录</el-button>
    </section>

    <section class="batch-users">
      <div v-if="!visibleUsers.length && !loading" class="batch-empty">请选择参与用户，或当前彩种暂无未开奖注单</div>
      <article v-for="user in visibleUsers" :key="user.key" class="user-card">
        <header><div><b>{{ user.username }}</b><span v-if="user.display_name">{{ user.display_name }}</span><em v-if="user.site_name">{{ user.site_name }}</em></div><el-checkbox :model-value="userAllSelected(user)" @change="changeUserAll(user, $event)">全选</el-checkbox></header>
        <div class="number-grid"><button v-for="number in user.numbers" :key="number.key" type="button" :class="['number-chip',{selected:selectedSet.has(number.key)}]" @click="toggleNumber(number.key)"><span>{{ number.value }}</span><small>¥{{ number.amount }}</small></button></div>
      </article>
    </section>

    <footer class="replace-panel">
      <div class="replace-fields"><label><span>百位</span><el-input v-model="replacements.hundreds" maxlength="1" inputmode="numeric" placeholder="不修改" @input="sanitize('hundreds')" /></label><label><span>十位</span><el-input v-model="replacements.tens" maxlength="1" inputmode="numeric" placeholder="不修改" @input="sanitize('tens')" /></label><label><span>个位</span><el-input v-model="replacements.units" maxlength="1" inputmode="numeric" placeholder="不修改" @input="sanitize('units')" /></label></div>
      <div class="replace-summary">已选择 <b>{{ selectedVisibleNumbers.length }}</b> 个号码，空白位保持原数字</div>
      <el-button type="primary" :icon="Check" :loading="saving" @click="save">保存替换</el-button>
    </footer>
  </div>
</template>

<style scoped>
.batch-page{min-height:100%;padding:22px;background:#f5f7fb}.batch-filter{display:flex;align-items:flex-end;gap:14px;padding:18px 20px;background:#fff;border-radius:8px}.batch-filter label{display:flex;width:190px;flex-direction:column;gap:7px;color:#5c667a;font-size:13px}.batch-filter .user-select{width:min(440px,36vw)}.batch-filter .el-select{width:100%}.issue-box{display:flex;min-width:180px;height:54px;flex-direction:column;justify-content:center;padding:0 14px;border:1px solid #dcdfe6;border-radius:4px}.issue-box span{color:#8a93a5;font-size:12px}.issue-box strong{margin-top:4px;color:#315fd3}.batch-users{display:flex;flex-direction:column;gap:14px;margin:16px 0 104px}.batch-empty{display:grid;min-height:240px;place-items:center;background:#fff;color:#9099aa;border-radius:8px}.user-card{width:100%;overflow:hidden;background:#fff;border:1px solid #e1e6ef;border-radius:8px;box-sizing:border-box;box-shadow:0 2px 8px #26344d0a}.user-card>header{display:flex;min-height:52px;align-items:center;justify-content:space-between;padding:9px 14px;border-bottom:1px solid #edf0f5;background:#f9fbff}.user-card>header div{display:flex;align-items:center;gap:8px}.user-card b{color:#26334b}.user-card header span{color:#6e788b;font-size:13px}.user-card header em{padding:2px 7px;border-radius:3px;background:#eef3ff;color:#4269c6;font-size:12px;font-style:normal}.number-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(80px,1fr));gap:8px;padding:14px}.number-chip{display:flex;height:48px;align-items:center;justify-content:center;gap:2px;flex-direction:column;border:1px solid #ccd4e2;border-radius:5px;background:#fff;color:#26334b;cursor:pointer}.number-chip span{font-size:16px;font-weight:700}.number-chip small{color:#8b95a7}.number-chip:hover{border-color:#6f91e8;background:#f6f9ff}.number-chip.selected{border-color:#356ee8;background:#356ee8;color:#fff;box-shadow:0 2px 6px #356ee833}.number-chip.selected small{color:#dce7ff}.replace-panel{position:sticky;bottom:0;z-index:5;display:flex;min-height:88px;align-items:center;gap:20px;padding:14px 20px;border:1px solid #dce2ec;border-radius:8px;background:#fff;box-shadow:0 -5px 18px #25345114}.replace-fields{display:flex;gap:12px}.replace-fields label{display:flex;align-items:center;gap:7px;color:#4b566a}.replace-fields .el-input{width:92px}.replace-summary{margin-left:auto;color:#758095}.replace-summary b{color:#356ee8;font-size:17px}@media(max-width:1200px){.batch-filter{flex-wrap:wrap}.batch-filter .user-select{width:360px}.replace-panel{flex-wrap:wrap}.replace-summary{margin-left:0}}
</style>
