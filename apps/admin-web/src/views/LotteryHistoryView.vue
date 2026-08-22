<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { getLotteryHistory, type LotteryHistory } from '../api/admin'
const route=useRoute(); const router=useRouter(); const rows=ref<LotteryHistory[]>([]); const total=ref(0); const page=ref(1); const loading=ref(false); const name=String(route.query.name||'彩票')
async function load(silent=false){if(!silent)loading.value=true; try{const r=await getLotteryHistory(Number(route.params.id),{page:page.value,page_size:20}); rows.value=r.data.list; total.value=r.data.total}catch(e){if(!silent)ElMessage.error(e instanceof Error?e.message:'开奖历史加载失败')}finally{if(!silent)loading.value=false}}
onMounted(() => { void load() })
let timer: number | undefined
onMounted(() => { timer = window.setInterval(() => { void load(true) }, 60_000) })
onBeforeUnmount(() => { if (timer) window.clearInterval(timer) })
</script>
<template><div class="history-page"><div class="heading"><div><h2>{{ name }}开奖历史</h2><p>页面优先读取本地数据库；后台每分钟只同步最新一期，新增记录会静默显示在第一行。</p></div><el-button @click="router.back()">返回彩票列表</el-button></div><el-card shadow="never" class="history-card"><el-table v-loading="loading" :data="rows" stripe row-key="id"><el-table-column prop="code" label="期号" width="140"/><el-table-column prop="draw_day" label="开奖日期" width="150"/><el-table-column label="开奖号码" min-width="220"><template #default="{row}"><b>{{ row.one }} {{ row.two }} {{ row.three }}</b></template></el-table-column><el-table-column prop="open_time" label="开奖时间" width="210"/><el-table-column prop="next_code" label="下一期" width="130"/></el-table><div class="pager"><el-pagination v-model:current-page="page" layout="total, prev, pager, next" :page-size="20" :total="total" @current-change="() => load()"/></div></el-card></div></template>
<style scoped>.history-page{min-height:100%;padding:22px;background:#fff}.heading{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px}.heading h2{margin:0 0 7px;color:#25314a;font-size:20px}.heading p{margin:0;color:#8490a5;font-size:13px}.history-card :deep(.el-card__body){padding:15px}.pager{display:flex;justify-content:flex-end;padding-top:18px}</style>
