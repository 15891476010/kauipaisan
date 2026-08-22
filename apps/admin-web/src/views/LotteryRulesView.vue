<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { getLotteryRules, listLotteries, saveLotteryRules, type Lottery } from '../api/admin'
import RichTextEditor from '../components/RichTextEditor.vue'

const route=useRoute()
const router=useRouter()
const lotteryId=computed(() => Number(route.params.id))
const lotteryName=ref(String(route.query.name || ''))
const content=ref('')
const loading=ref(false)
const saving=ref(false)
const copying=ref(false)
const copyFromId=ref<number>()
const lotteries=ref<Lottery[]>([])
function rulePayload(response: any): any {
  const value=response?.data?.data || response?.data || response
  return value?.data?.content !== undefined ? value.data : value
}

async function load() {
  if (!lotteryId.value) return
  loading.value=true
  try {
    const response=await getLotteryRules(lotteryId.value)
    const value=rulePayload(response)
    lotteryName.value=String(value?.name || lotteryName.value)
    content.value=String(value?.content || '')
    await new Promise<void>((resolve) => requestAnimationFrame(() => resolve()))
  } catch (error) {
    ElMessage.error(error instanceof Error ? error.message : '规则配置加载失败')
  } finally { loading.value=false }
}

async function loadLotteries() {
  try {
    const response=await listLotteries({ page: 1, page_size: 100 })
    lotteries.value=(response.data?.list || []).filter((item) => Number(item.id) !== lotteryId.value)
  } catch { lotteries.value=[] }
}

async function copyRules() {
  if (!copyFromId.value) return ElMessage.warning('请选择要复制的彩种')
  copying.value=true
  try {
    const response=await getLotteryRules(copyFromId.value)
    content.value=String(rulePayload(response)?.content || '')
    const source=lotteries.value.find((item) => item.id === copyFromId.value)
    ElMessage.success(`已填充${source?.name || '所选彩种'}规则，请保存当前配置`)
  } catch (error) {
    ElMessage.error(error instanceof Error ? error.message : '规则复制失败')
  } finally { copying.value=false }
}

async function save() {
  if (!content.value.replace(/<[^>]+>/g, '').trim()) return ElMessage.warning('请输入规则内容')
  saving.value=true
  try {
    await saveLotteryRules(lotteryId.value,content.value)
    ElMessage.success('规则配置已保存')
  } catch (error) {
    ElMessage.error(error instanceof Error ? error.message : '规则配置保存失败')
  } finally { saving.value=false }
}

onMounted(() => { void load(); void loadLotteries() })
watch(lotteryId, () => { void load(); copyFromId.value=undefined; void loadLotteries() })
</script>

<template>
  <div class="lottery-rules-page">
    <header><div><h2>{{ lotteryName }} · 规则配置</h2><p>该内容会在用户端切换到当前彩种时显示，并保留富文本样式。</p></div><el-button @click="router.push({name:'lotteries'})">返回彩票列表</el-button></header>
    <el-card v-loading="loading" shadow="never" class="rules-card">
      <div class="copy-bar">
        <span>复制其他彩种规则</span>
        <el-select v-model="copyFromId" clearable filterable placeholder="选择彩种" class="copy-select">
          <el-option v-for="item in lotteries" :key="item.id" :label="`${item.name}（${item.code}）`" :value="item.id" />
        </el-select>
        <el-button :loading="copying" :disabled="!copyFromId" @click="copyRules">填充到当前编辑器</el-button>
      </div>
      <RichTextEditor :key="`${lotteryId}-${content ? 'loaded' : 'empty'}`" v-model="content" placeholder="请输入当前彩种的规则说明" />
      <footer><el-button type="primary" :loading="saving" @click="save">保存规则配置</el-button></footer>
    </el-card>
  </div>
</template>

<style scoped>
.lottery-rules-page { height: 100%; min-height: 0; padding: 22px; background: #fff; display: flex; flex-direction: column; }
header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 18px; }
h2 { margin: 0 0 7px; color: #25314a; font-size: 20px; }
p { margin: 0; color: #8490a5; font-size: 13px; }
.rules-card { min-height: 0; flex: 1; border: 1px solid #e8edf4; }
.rules-card :deep(.el-card__body) { height: 100%; min-height: 0; display: flex; flex-direction: column; }
.rules-card :deep(.rich-editor) { min-height: 0; flex: 1; }
.copy-bar { display: flex; align-items: center; gap: 10px; flex: none; margin-bottom: 14px; color: #606a7b; font-size: 13px; }
.copy-select { width: 220px; }
footer { flex: none; padding-top: 16px; }
</style>
