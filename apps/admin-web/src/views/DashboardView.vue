<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { Refresh, Wallet } from '@element-plus/icons-vue'
import { ElMessage } from 'element-plus'
import { BarChart, LineChart } from 'echarts/charts'
import { GridComponent, LegendComponent, TooltipComponent } from 'echarts/components'
import { init, use, type ECharts, type EChartsCoreOption } from 'echarts/core'
import { CanvasRenderer } from 'echarts/renderers'
import { getDashboardScore, type DashboardScoreData, type ScoreLedgerRow } from '../api/admin'

use([BarChart, LineChart, GridComponent, LegendComponent, TooltipComponent, CanvasRenderer])

const loading = ref(false)
const dashboard = ref<DashboardScoreData | null>(null)
const trendChartElement = ref<HTMLDivElement | null>(null)
let refreshTimer: ReturnType<typeof setInterval> | null = null
let trendChart: ECharts | null = null
let trendResizeObserver: ResizeObserver | null = null

const money = (value: unknown) => Number(value || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const number = (value: unknown) => Number(value || 0)
const signedMoney = (value: unknown) => `${number(value) > 0 ? '+' : ''}${money(value)}`
const percent = (value: unknown) => {
  const total = number(dashboard.value?.overview.total_score)
  return total > 0 ? Math.min(100, Math.max(0, number(value) / total * 100)) : 0
}
const generatedAt = computed(() => dashboard.value?.generated_at ? new Date(dashboard.value.generated_at).toLocaleString('zh-CN', { hour12: false }) : '-')
const cards = computed(() => dashboard.value ? [
  { label: '平台分数总额', value: dashboard.value.overview.total_score, note: `已核算 ${money(dashboard.value.overview.accounted_score)}`, tone: 'blue' },
  { label: '平台可分配', value: dashboard.value.overview.available_score, note: `占总额 ${percent(dashboard.value.overview.available_score).toFixed(1)}%`, tone: 'green' },
  { label: '已下发站点', value: dashboard.value.overview.allocated_score, note: `${dashboard.value.counts.sites} 个站点`, tone: 'orange' },
  { label: '组织层级可用', value: dashboard.value.overview.organization_available, note: `${dashboard.value.counts.organizations} 个组织`, tone: 'cyan' },
  { label: '用户可用', value: dashboard.value.overview.user_available, note: `${dashboard.value.counts.users} 个用户`, tone: 'violet' },
  { label: '下注占用', value: dashboard.value.overview.user_locked, note: `账面差额 ${signedMoney(dashboard.value.overview.difference_score)}`, tone: 'red' },
] : [])
const distribution = computed(() => dashboard.value ? [
  { label: '平台可分配', value: dashboard.value.overview.available_score, color: '#2f6fed' },
  { label: '组织可用', value: dashboard.value.overview.organization_available, color: '#159b6c' },
  { label: '用户可用', value: dashboard.value.overview.user_available, color: '#d68718' },
  { label: '下注占用', value: dashboard.value.overview.user_locked, color: '#d84b55' },
] : [])

const categoryLabels: Record<string, string> = { allocation: '分数分配', bet: '用户下注', settlement: '开奖结算', adjustment: '人工调整', other: '其他变动' }
const accountLabels: Record<string, string> = { platform: '总平台', organization: '组织账号', user: '用户' }
const categoryLabel = (value: string) => categoryLabels[value] || value || '其他变动'
const accountLabel = (row: ScoreLedgerRow) => row.account_name || accountLabels[row.account_type] || row.account_type || '-'
const shortDate = (value: string) => value ? value.slice(5) : '-'

function compactMoney(value: number) {
  if (Math.abs(value) >= 10000) return `${(value / 10000).toFixed(value % 10000 === 0 ? 0 : 1)}万`
  return value.toLocaleString('zh-CN', { maximumFractionDigits: 0 })
}

function renderTrendChart() {
  if (!trendChartElement.value || !dashboard.value) return
  if (!trendChart) {
    trendChart = init(trendChartElement.value)
    trendResizeObserver = new ResizeObserver(() => trendChart?.resize())
    trendResizeObserver.observe(trendChartElement.value)
  }
  const trend = dashboard.value.trend
  const option: EChartsCoreOption = {
    animationDuration: 500,
    color: ['#20936a', '#d94f5c', '#2f6fed'],
    grid: { left: 18, right: 18, top: 52, bottom: 14, containLabel: true },
    legend: { top: 8, right: 8, itemWidth: 12, itemHeight: 8, textStyle: { color: '#64748b', fontSize: 12 }, data: ['流入', '流出', '净变动'] },
    tooltip: {
      trigger: 'axis',
      axisPointer: { type: 'shadow', shadowStyle: { color: 'rgba(47,111,237,.06)' } },
      valueFormatter: (value: unknown) => money(value),
    },
    xAxis: { type: 'category', data: trend.map(item => shortDate(item.day)), axisTick: { show: false }, axisLine: { lineStyle: { color: '#dfe5ee' } }, axisLabel: { color: '#64748b', fontSize: 11 } },
    yAxis: { type: 'value', splitNumber: 4, axisLabel: { color: '#8a95a8', fontSize: 10, formatter: (value: number) => compactMoney(value) }, splitLine: { lineStyle: { color: '#edf1f6', type: 'dashed' } } },
    series: [
      { name: '流入', type: 'bar', data: trend.map(item => number(item.total_in)), barMaxWidth: 22, itemStyle: { borderRadius: [3, 3, 0, 0] } },
      { name: '流出', type: 'bar', data: trend.map(item => number(item.total_out)), barMaxWidth: 22, itemStyle: { borderRadius: [3, 3, 0, 0] } },
      { name: '净变动', type: 'line', data: trend.map(item => number(item.net)), smooth: true, symbol: 'circle', symbolSize: 7, lineStyle: { width: 2 }, itemStyle: { borderColor: '#fff', borderWidth: 2 }, areaStyle: { color: 'rgba(47,111,237,.08)' } },
    ],
  }
  trendChart.setOption(option, true)
}

async function load(silent = false) {
  if (!silent) loading.value = true
  try {
    const response = await getDashboardScore()
    dashboard.value = response.data
  } catch (error) {
    if (!silent) ElMessage.error(error instanceof Error ? error.message : '分数看板加载失败')
  } finally {
    if (!silent) loading.value = false
  }
}

onMounted(() => { void load(); refreshTimer = setInterval(() => void load(true), 60_000) })
watch(() => dashboard.value?.trend, async () => { await nextTick(); renderTrendChart() }, { deep: true, flush: 'post' })
onBeforeUnmount(() => { if (refreshTimer) clearInterval(refreshTimer); trendResizeObserver?.disconnect(); trendChart?.dispose() })
</script>

<template>
  <div class="dashboard-page" v-loading="loading">
    <header class="dashboard-header">
      <div><h1>分数总览</h1><p>统计时间 {{ generatedAt }}</p></div>
      <el-button :icon="Refresh" :loading="loading" @click="load()">刷新</el-button>
    </header>

    <template v-if="dashboard">
      <section class="score-cards" aria-label="核心分数指标">
        <article v-for="item in cards" :key="item.label" :class="['score-card', item.tone]">
          <div><span>{{ item.label }}</span><el-icon><Wallet /></el-icon></div>
          <strong>{{ money(item.value) }}</strong><small>{{ item.note }}</small>
        </article>
      </section>

      <section class="distribution-section">
        <div class="section-heading"><div><h2>分数构成</h2><span>账面核算 {{ money(dashboard.overview.accounted_score) }}</span></div><b :class="number(dashboard.overview.difference_score) === 0 ? 'balanced' : 'unbalanced'">差额 {{ signedMoney(dashboard.overview.difference_score) }}</b></div>
        <div class="distribution-bar" aria-label="分数构成比例"><i v-for="item in distribution" :key="item.label" :style="{ width: `${percent(item.value)}%`, backgroundColor: item.color }" /></div>
        <div class="distribution-legend"><span v-for="item in distribution" :key="item.label"><i :style="{ backgroundColor: item.color }" />{{ item.label }}<b>{{ money(item.value) }}</b></span></div>
      </section>

      <div class="dashboard-grid">
        <section class="dashboard-section trend-section">
          <div class="section-heading"><div><h2>近 7 日分数变动</h2><span>流入、流出与每日净变动</span></div></div>
          <div ref="trendChartElement" class="trend-chart" role="img" aria-label="近 7 日分数流入、流出和净变动图表" />
        </section>

        <section class="dashboard-section today-section">
          <div class="section-heading"><div><h2>今日变动</h2><span>{{ dashboard.today.total }} 笔流水</span></div><b :class="number(dashboard.today.net) >= 0 ? 'positive' : 'negative'">净额 {{ signedMoney(dashboard.today.net) }}</b></div>
          <div class="today-totals"><div><span>流入</span><strong class="positive">+{{ money(dashboard.today.total_in) }}</strong></div><div><span>流出</span><strong class="negative">-{{ money(dashboard.today.total_out) }}</strong></div></div>
          <div class="category-list"><div v-for="item in dashboard.categories" :key="item.category"><span>{{ categoryLabel(item.category) }}<small>{{ item.total }} 笔</small></span><b class="positive">+{{ money(item.total_in) }}</b><b class="negative">-{{ money(item.total_out) }}</b></div><div v-if="dashboard.categories.length === 0" class="empty-row">今日暂无分数变动</div></div>
        </section>
      </div>

      <section class="dashboard-section site-section">
        <div class="section-heading"><div><h2>站点分数分布</h2><span>站点下属组织与用户汇总</span></div><b>{{ dashboard.counts.sites }} 个站点</b></div>
        <div class="table-scroll"><table><thead><tr><th>站点</th><th>平台下发</th><th>组织可用</th><th>用户可用</th><th>下注占用</th><th>站点流转总额</th><th>组织 / 用户</th><th>状态</th></tr></thead><tbody>
          <tr v-for="site in dashboard.sites" :key="site.site_id"><td><strong>{{ site.site_name }}</strong></td><td>{{ money(site.allocated_score) }}</td><td>{{ money(site.organization_available) }}</td><td>{{ money(site.user_available) }}</td><td class="negative">{{ money(site.user_locked) }}</td><td><b>{{ money(site.circulating_score) }}</b></td><td>{{ site.organization_count }} / {{ site.user_count }}</td><td><span :class="['status', site.status === 1 ? 'enabled' : 'disabled']">{{ site.status === 1 ? '启用' : '停用' }}</span></td></tr>
          <tr v-if="dashboard.sites.length === 0"><td colspan="8" class="empty-row">暂无站点数据</td></tr>
        </tbody></table></div>
      </section>

      <div class="dashboard-grid lower-grid">
        <section class="dashboard-section level-section">
          <div class="section-heading"><div><h2>组织层级余额</h2><span>当前可继续向下分配的分数</span></div></div>
          <div class="level-list"><div v-for="item in dashboard.levels" :key="item.level"><span><b>{{ item.label }}</b><small>{{ item.account_count }} 个</small></span><div><i :style="{ width: `${number(item.credit_limit) > 0 ? Math.min(100, number(item.available_score) / number(item.credit_limit) * 100) : 0}%` }" /></div><strong>{{ money(item.available_score) }}<small> / {{ money(item.credit_limit) }}</small></strong></div></div>
        </section>

        <section class="dashboard-section recent-section">
          <div class="section-heading"><div><h2>最近分数流水</h2><span>最新 12 条</span></div></div>
          <div class="recent-list"><div v-for="row in dashboard.recent" :key="row.id"><span class="recent-account"><b>{{ accountLabel(row) }}</b><small>{{ row.site_name || '总平台' }} · {{ categoryLabel(row.category) }}</small></span><span class="recent-reason">{{ row.reason || row.source_type || '-' }}</span><strong :class="row.direction === 'in' ? 'positive' : 'negative'">{{ row.direction === 'in' ? '+' : '-' }}{{ money(row.amount) }}</strong><time>{{ row.created_at }}</time></div><div v-if="dashboard.recent.length === 0" class="empty-row">暂无分数流水</div></div>
        </section>
      </div>
    </template>
  </div>
</template>

<style scoped>
.dashboard-page{min-height:100%;padding:22px;background:#fff;color:#28344a}.dashboard-header,.section-heading{display:flex;align-items:center;justify-content:space-between;gap:16px}.dashboard-header{margin-bottom:18px}.dashboard-header h1,.section-heading h2{margin:0;font-size:20px;letter-spacing:0}.dashboard-header p,.section-heading span{margin:6px 0 0;color:#8792a6;font-size:12px}.score-cards{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.score-card{min-width:0;padding:16px 18px;border:1px solid #e8edf4;border-left:4px solid #2f6fed;border-radius:6px;background:#fff}.score-card>div{display:flex;align-items:center;justify-content:space-between;color:#778297;font-size:13px}.score-card .el-icon{font-size:17px}.score-card strong{display:block;margin:10px 0 5px;color:#1f2b40;font-size:26px;line-height:1.2}.score-card small{color:#8994a8;font-size:12px}.score-card.green{border-left-color:#159b6c}.score-card.orange{border-left-color:#d68718}.score-card.cyan{border-left-color:#168aa3}.score-card.violet{border-left-color:#7357c8}.score-card.red{border-left-color:#d84b55}.distribution-section,.dashboard-section{margin-top:16px;padding:18px 0;border-top:1px solid #e8edf4}.section-heading h2{font-size:16px}.section-heading>div>span{display:block}.section-heading>b{font-size:13px;font-weight:600}.balanced,.positive{color:#12885d}.unbalanced,.negative{color:#cf3f4b}.distribution-bar{display:flex;width:100%;height:14px;margin-top:16px;overflow:hidden;border-radius:3px;background:#edf1f6}.distribution-bar i{display:block;height:100%;min-width:0}.distribution-legend{display:flex;flex-wrap:wrap;gap:10px 24px;margin-top:12px}.distribution-legend span{display:flex;align-items:center;gap:7px;color:#69758a;font-size:12px}.distribution-legend i{width:8px;height:8px;border-radius:2px}.distribution-legend b{margin-left:2px;color:#2b364b}.dashboard-grid{display:grid;grid-template-columns:minmax(0,1.35fr) minmax(320px,.65fr);gap:24px}.trend-section{min-width:0}.trend-chart{width:100%;height:258px;margin-top:8px}.today-totals{display:grid;grid-template-columns:1fr 1fr;margin-top:16px;border:1px solid #e5eaf1}.today-totals>div{padding:13px 14px}.today-totals>div+div{border-left:1px solid #e5eaf1}.today-totals span{display:block;color:#7d889b;font-size:12px}.today-totals strong{display:block;margin-top:5px;font-size:17px}.category-list{margin-top:10px}.category-list>div{display:grid;grid-template-columns:minmax(100px,1fr) 90px 90px;gap:8px;padding:9px 2px;border-bottom:1px solid #edf0f4;font-size:12px;text-align:right}.category-list>div>span{text-align:left}.category-list small{margin-left:6px;color:#929cad}.table-scroll{margin-top:14px;overflow:auto}table{width:100%;min-width:980px;border-collapse:collapse;font-size:12px}th,td{padding:11px 12px;border-bottom:1px solid #e8edf3;text-align:right;white-space:nowrap}th{background:#f6f8fb;color:#69758a;font-weight:600}th:first-child,td:first-child{text-align:left}tbody tr:hover{background:#f9fbfe}.status{display:inline-flex;padding:2px 7px;border-radius:3px}.status.enabled{background:#e8f7ef;color:#16875d}.status.disabled{background:#f2f3f5;color:#8b94a4}.lower-grid{grid-template-columns:minmax(320px,.7fr) minmax(0,1.3fr)}.level-list{margin-top:14px}.level-list>div{display:grid;grid-template-columns:105px minmax(80px,1fr) 190px;align-items:center;gap:14px;padding:12px 0;border-bottom:1px solid #edf0f4}.level-list span{display:flex;align-items:center;gap:8px}.level-list small{color:#929cad;font-size:11px;font-weight:400}.level-list>div>div{height:7px;overflow:hidden;border-radius:2px;background:#edf1f5}.level-list i{display:block;height:100%;background:#3975df}.level-list strong{text-align:right;font-size:13px}.recent-list{margin-top:8px}.recent-list>div{display:grid;grid-template-columns:minmax(140px,.8fr) minmax(150px,1fr) 110px 145px;align-items:center;gap:12px;padding:10px 0;border-bottom:1px solid #edf0f4;font-size:12px}.recent-account{display:flex;min-width:0;flex-direction:column}.recent-account b,.recent-account small,.recent-reason{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.recent-account small{margin-top:3px;color:#8b96a8}.recent-reason{color:#5f6b80}.recent-list strong,.recent-list time{text-align:right}.recent-list time{color:#8b96a8;font-size:11px}.empty-row{padding:22px!important;color:#98a1b1!important;text-align:center!important}.dashboard-page :deep(.el-loading-mask){background:rgba(255,255,255,.78)}
@media(max-width:1100px){.score-cards{grid-template-columns:repeat(2,minmax(0,1fr))}.dashboard-grid,.lower-grid{grid-template-columns:1fr}}@media(max-width:680px){.dashboard-page{padding:14px}.score-cards{grid-template-columns:1fr}.distribution-legend{display:grid;grid-template-columns:1fr 1fr}.trend-chart{height:230px}.level-list>div{grid-template-columns:86px 1fr 145px}.recent-list>div{grid-template-columns:1fr 95px}.recent-reason,.recent-list time{display:none}}
</style>
