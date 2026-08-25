<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { createEditor, createToolbar } from '@wangeditor/editor'
import type { IDomEditor } from '@wangeditor/editor'
import '@wangeditor/editor/dist/css/style.css'
import { getAgentAgreement, getAgentAnnouncement, getAgreement, getAnnouncement, getRules, getSiteBettingControls, listLotteries, listResource, saveAgentAgreement, saveAgentAnnouncement, saveAgreement, saveAnnouncement, saveRules, saveSiteBettingControls, type Lottery, type SiteBettingControl } from '../api/admin'
import RichTextEditor from '../components/RichTextEditor.vue'

const sites = ref<Record<string, unknown>[]>([])
const siteId = ref<number>()
const referenceSiteId = ref<number>()
const copying = ref(false)
const loading = ref(false)
const activeTab = ref('agreement')
const form = reactive({ title: '', content: '' })
const announcement = reactive({ title: '公告', content: '' })
const agentAgreement = reactive({ title: '代理服务协议', content: '' })
const agentAnnouncement = reactive({ title: '代理端公告', content: '' })
const rules = reactive({ title: '规则说明', basic: '', special: '', amount: '', text: '' })
const lotteries = ref<Lottery[]>([])
const bettingControls = reactive<Record<string, SiteBettingControl>>({})
const drawHistoryLimit = ref(80)
const activeRuleTab = ref('basic')
const editorHost = ref<HTMLElement>()
let editor: IDomEditor | null = null
const selectedSite = computed(() => sites.value.find((site) => Number(site.id) === siteId.value))
const referenceSites = computed(() => sites.value.filter((site) => Number(site.id) !== siteId.value))
function control(row: Lottery): SiteBettingControl { const key=String(row.id); if (!bettingControls[key]) bettingControls[key]={ cutoff_enabled: 0, cutoff_time: null, mask_enabled: 1, refund_enabled: 1, timing_rules: [] }; if (!bettingControls[key].timing_rules) bettingControls[key].timing_rules=[]; return bettingControls[key] }
function addTimingRule(row: Lottery) { control(row).timing_rules!.push({ start_time: '00:00', end_time: '08:30', allow_bet: 0, mask_enabled: 1, show_next_issue: 0, header_show_next_issue: 0, display_text: '即将开盘' }) }
function removeTimingRule(row: Lottery, index: number) { control(row).timing_rules!.splice(index, 1) }

function markdownToHtml(source: string): string {
  if (/<\/?[a-z][^>]*>/i.test(source)) return source
  return source.split(/\r?\n/).map((line) => {
    const value=line.trim()
    if (!value) return ''
    if (value.startsWith('## ')) return `<h2>${value.slice(3)}</h2>`
    if (value.startsWith('> ')) return `<blockquote><p>${value.slice(2)}</p></blockquote>`
    const html=value.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
    return `<p>${html}</p>`
  }).join('')
}

function rulesToHtml(source: string): string {
  if (/<\/?[a-z][^>]*>/i.test(source)) return source
  return source.split(/\r?\n/).map((line) => line.trim() ? `<p>${line.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')}</p>` : '<p><br></p>').join('')
}

function mountEditor() {
  if (!editorHost.value || editor) return
  const editorConfig = {
    placeholder: '请输入责任声明内容',
    onChange: (instance: IDomEditor) => { form.content = instance.getHtml() },
  }
  const toolbarConfig = { excludeKeys: [] }
  editor = createEditor({ selector: editorHost.value, html: form.content, config: editorConfig, mode: 'default' })
  createToolbar({ editor, selector: editorHost.value.parentElement?.querySelector('.rich-text-toolbar') as HTMLElement, config: toolbarConfig, mode: 'default' })
}

async function loadAgreement() {
  if (!siteId.value) { form.title = ''; form.content = ''; announcement.title = '公告'; announcement.content = ''; agentAgreement.title = '代理服务协议'; agentAgreement.content = ''; agentAnnouncement.title = '代理端公告'; agentAnnouncement.content = ''; rules.title = '规则说明'; rules.basic = ''; rules.special = ''; rules.amount = ''; rules.text = ''; return }
  loading.value = true
  try {
    const [agreementResponse, announcementResponse, rulesResponse, agentAgreementResponse, agentAnnouncementResponse, controlsResponse] = await Promise.all([getAgreement(siteId.value), getAnnouncement(siteId.value), getRules(siteId.value), getAgentAgreement(siteId.value), getAgentAnnouncement(siteId.value), getSiteBettingControls(siteId.value)])
    form.title = agreementResponse.data.title
    form.content = markdownToHtml(agreementResponse.data.content)
    announcement.title = announcementResponse.data.title
    announcement.content = announcementResponse.data.content
    agentAgreement.title = agentAgreementResponse.data.title
    agentAgreement.content = markdownToHtml(agentAgreementResponse.data.content)
    agentAnnouncement.title = agentAnnouncementResponse.data.title
    agentAnnouncement.content = agentAnnouncementResponse.data.content
    rules.title = rulesResponse.data.title
    rules.basic = rulesToHtml(rulesResponse.data.basic)
    rules.special = rulesToHtml(rulesResponse.data.special)
    rules.amount = rulesToHtml(rulesResponse.data.amount)
    rules.text = rulesToHtml(rulesResponse.data.text)
    Object.keys(bettingControls).forEach((key) => delete bettingControls[key]); Object.assign(bettingControls, controlsResponse.data.controls || {})
    drawHistoryLimit.value=Number(controlsResponse.data.draw_history_limit || 80)
    editor?.setHtml(form.content)
  } catch (error) { ElMessage.error(error instanceof Error ? error.message : '加载声明配置失败') } finally { loading.value = false }
}

async function fillFromSite() {
  if (!siteId.value || !referenceSiteId.value) { ElMessage.warning('请选择当前站点和参考站点'); return }
  copying.value = true
  try {
    const [agreementResponse, announcementResponse, rulesResponse, agentAgreementResponse, agentAnnouncementResponse, controlsResponse] = await Promise.all([
      getAgreement(referenceSiteId.value), getAnnouncement(referenceSiteId.value), getRules(referenceSiteId.value),
      getAgentAgreement(referenceSiteId.value), getAgentAnnouncement(referenceSiteId.value), getSiteBettingControls(referenceSiteId.value),
    ])
    form.title = agreementResponse.data.title
    form.content = markdownToHtml(agreementResponse.data.content)
    announcement.title = announcementResponse.data.title
    announcement.content = announcementResponse.data.content
    agentAgreement.title = agentAgreementResponse.data.title
    agentAgreement.content = markdownToHtml(agentAgreementResponse.data.content)
    agentAnnouncement.title = agentAnnouncementResponse.data.title
    agentAnnouncement.content = agentAnnouncementResponse.data.content
    rules.title = rulesResponse.data.title
    rules.basic = rulesToHtml(rulesResponse.data.basic)
    rules.special = rulesToHtml(rulesResponse.data.special)
    rules.amount = rulesToHtml(rulesResponse.data.amount)
    rules.text = rulesToHtml(rulesResponse.data.text)
    Object.keys(bettingControls).forEach((key) => delete bettingControls[key]); Object.assign(bettingControls, JSON.parse(JSON.stringify(controlsResponse.data.controls || {})))
    drawHistoryLimit.value=Number(controlsResponse.data.draw_history_limit || 80)
    editor?.setHtml(form.content)
    ElMessage.success('已填充参考站点配置，请检查后分别保存')
  } catch (error) { ElMessage.error(error instanceof Error ? error.message : '参考站点配置读取失败') } finally { copying.value = false }
}

async function save() {
  if (!siteId.value) { ElMessage.warning('请选择站点'); return }
  loading.value = true
  try {
    if (activeTab.value === 'agreement') {
      await saveAgreement({ site_id: siteId.value, title: form.title, content: editor?.getHtml() || form.content })
      ElMessage.success('责任声明已保存')
    } else if (activeTab.value === 'announcement') {
      await saveAnnouncement({ site_id: siteId.value, title: announcement.title, content: announcement.content })
      ElMessage.success('首页公告已保存')
    } else if (activeTab.value === 'agent-agreement') {
      await saveAgentAgreement({ site_id: siteId.value, ...agentAgreement })
      ElMessage.success('代理端服务协议已保存')
    } else if (activeTab.value === 'agent-announcement') {
      await saveAgentAnnouncement({ site_id: siteId.value, ...agentAnnouncement })
      ElMessage.success('代理端公告已保存')
    } else {
      if (activeTab.value === 'betting-controls') { await saveSiteBettingControls({ site_id: siteId.value, controls: bettingControls, draw_history_limit: Math.min(200, Math.max(1, Number(drawHistoryLimit.value) || 80)) }); ElMessage.success('下注控制已保存') }
      else { await saveRules({ site_id: siteId.value, ...rules }); ElMessage.success('规则说明已保存') }
    }
  } catch (error) { ElMessage.error(error instanceof Error ? error.message : '保存失败') } finally { loading.value = false }
}

onMounted(async () => {
  await nextTick()
  mountEditor()
  try {
    const response = await listResource('agent-center', { page: 1, page_size: 100 })
    sites.value = response.data.list
    lotteries.value = (await listLotteries({ page: 1, page_size: 100 })).data.list
    if (sites.value.length) { siteId.value = Number(sites.value[0].id); await loadAgreement() }
  } catch (error) { ElMessage.error(error instanceof Error ? error.message : '加载站点失败') }
})

onBeforeUnmount(() => { editor?.destroy(); editor = null })
</script>

<template>
  <div class="settings-page">
    <h1 class="page-title">站点配置</h1>
    <p class="page-subtitle">按站点分别维护用户端和代理端功能配置</p>
    <section class="settings-panel" v-loading="loading">
      <el-form label-width="90px" @submit.prevent="save">
        <el-form-item label="配置站点">
          <el-select v-model="siteId" filterable placeholder="请选择站点" style="width:360px" @change="loadAgreement">
            <el-option v-for="site in sites" :key="site.id" :label="String(site.name)" :value="Number(site.id)" />
          </el-select>
        </el-form-item>
        <el-form-item label="参考站点">
          <el-select v-model="referenceSiteId" filterable clearable placeholder="选择其他站点进行填充" style="width:360px">
            <el-option v-for="site in referenceSites" :key="site.id" :label="String(site.name)" :value="Number(site.id)" />
          </el-select>
          <el-button class="fill-button" :loading="copying" :disabled="!referenceSiteId || !siteId" @click="fillFromSite">填充配置</el-button>
          <span class="fill-tip">只填入当前页面，不会自动保存</span>
        </el-form-item>
        <el-tabs v-model="activeTab" class="settings-tabs">
          <el-tab-pane label="责任声明" name="agreement">
            <div class="tab-content">
              <el-form-item label="声明标题"><el-input v-model="form.title" maxlength="120" show-word-limit style="width:560px" placeholder="例如：责任声明" /></el-form-item>
              <el-form-item label="声明正文"><div class="rich-text-field"><div class="rich-text-toolbar"></div><div ref="editorHost" class="rich-text-editor"></div><span>富文本编辑器：可选中文字设置颜色、加粗、字号、对齐和列表，用户端会保留编辑样式。</span></div></el-form-item>
              <div class="tab-footer"><el-button type="primary" :loading="loading" @click="save">保存责任声明</el-button><span v-if="selectedSite" class="site-hint">当前配置：{{ selectedSite.name }}</span></div>
            </div>
          </el-tab-pane>
          <el-tab-pane label="首页公告" name="announcement">
            <div class="tab-content announcement-content">
              <el-form-item label="公告标题"><el-input v-model="announcement.title" maxlength="120" show-word-limit style="width:560px" placeholder="例如：公告" /></el-form-item>
              <el-form-item label="公告内容"><el-input v-model="announcement.content" type="textarea" maxlength="20000" show-word-limit class="announcement-editor" placeholder="请输入用户端顶部跑马灯和弹窗中展示的公告内容" /></el-form-item>
              <div class="tab-footer"><el-button type="primary" :loading="loading" @click="save">保存首页公告</el-button><span v-if="selectedSite" class="site-hint">当前配置：{{ selectedSite.name }}</span></div>
            </div>
          </el-tab-pane>
          <el-tab-pane label="代理端协议" name="agent-agreement">
            <div class="tab-content">
              <el-form-item label="协议标题"><el-input v-model="agentAgreement.title" maxlength="120" show-word-limit style="width:560px" placeholder="例如：代理服务协议" /></el-form-item>
              <el-form-item label="协议正文"><div class="rich-text-field"><RichTextEditor v-model="agentAgreement.content" placeholder="请输入代理端登录后展示的服务协议" /><span>代理账号登录后必须同意该协议才能进入主页面，颜色和排版会完整保留。</span></div></el-form-item>
              <div class="tab-footer"><el-button type="primary" :loading="loading" @click="save">保存代理端协议</el-button><span v-if="selectedSite" class="site-hint">当前配置：{{ selectedSite.name }}</span></div>
            </div>
          </el-tab-pane>
          <el-tab-pane label="代理端公告" name="agent-announcement">
            <div class="tab-content announcement-content">
              <el-form-item label="公告标题"><el-input v-model="agentAnnouncement.title" maxlength="120" show-word-limit style="width:560px" placeholder="例如：代理端公告" /></el-form-item>
              <el-form-item label="公告内容"><el-input v-model="agentAnnouncement.content" type="textarea" maxlength="20000" show-word-limit class="announcement-editor" placeholder="请输入代理端顶部跑马灯和弹窗展示的公告" /></el-form-item>
              <div class="tab-footer"><el-button type="primary" :loading="loading" @click="save">保存代理端公告</el-button><span v-if="selectedSite" class="site-hint">当前配置：{{ selectedSite.name }}</span></div>
            </div>
          </el-tab-pane>
          <el-tab-pane label="规则说明" name="rules">
            <div class="tab-content rules-content">
              <el-form-item label="弹窗标题"><el-input v-model="rules.title" maxlength="120" show-word-limit style="width:560px" placeholder="例如：规则说明" /></el-form-item>
              <el-form-item label="规则内容">
                <div class="rules-editor-wrap">
                  <el-tabs v-model="activeRuleTab" type="border-card" class="rules-inner-tabs">
                    <el-tab-pane label="基础玩法" name="basic"><RichTextEditor v-model="rules.basic" placeholder="请输入基础玩法规则" /></el-tab-pane>
                    <el-tab-pane label="特殊打法" name="special"><RichTextEditor v-model="rules.special" placeholder="请输入特殊打法规则" /></el-tab-pane>
                    <el-tab-pane label="总金额" name="amount"><RichTextEditor v-model="rules.amount" placeholder="请输入总金额规则" /></el-tab-pane>
                    <el-tab-pane label="文本规范" name="text"><RichTextEditor v-model="rules.text" placeholder="请输入文本规范" /></el-tab-pane>
                  </el-tabs>
                  <span>每行显示为一段；以“【重点】”开头的行会在用户端显示为黄色重点提示。</span>
                </div>
              </el-form-item>
              <div class="tab-footer"><el-button type="primary" :loading="loading" @click="save">保存规则说明</el-button><span v-if="selectedSite" class="site-hint">当前配置：{{ selectedSite.name }}</span></div>
            </div>
          </el-tab-pane>
          <el-tab-pane label="下注控制" name="betting-controls"><div class="tab-content betting-controls-content"><el-alert title="以下配置只对当前站点生效，按彩种独立控制；时间段按服务器 Asia/Shanghai 时间判断。" type="info" :closable="false"/><div class="draw-limit-setting"><span>开奖记录返回条数</span><el-input-number v-model="drawHistoryLimit" :min="1" :max="200" :step="1" controls-position="right"/><small>用户端开奖号码页面最多显示的记录数量</small></div><el-table :data="lotteries" border><el-table-column prop="name" label="彩种" width="120"/><el-table-column label="兼容每日截止" width="150"><template #default="{row}"><el-switch v-model="control(row).cutoff_enabled" :active-value="1" :inactive-value="0"/><el-time-picker v-model="control(row).cutoff_time" value-format="HH:mm" format="HH:mm" placeholder="时间" style="width:105px;margin-left:6px"/></template></el-table-column><el-table-column label="时间段策略" min-width="700"><template #default="{row}"><div v-for="(rule,index) in control(row).timing_rules" :key="index" class="timing-rule"><el-time-picker v-model="rule.start_time" value-format="HH:mm" format="HH:mm" placeholder="开始"/><span>至</span><el-time-picker v-model="rule.end_time" value-format="HH:mm" format="HH:mm" placeholder="结束"/><el-input v-model="rule.display_text" placeholder="显示文案" maxlength="40" style="width:130px"/><el-checkbox v-model="rule.allow_bet" :true-label="1" :false-label="0">可下注</el-checkbox><el-checkbox v-model="rule.mask_enabled" :true-label="1" :false-label="0">蒙版</el-checkbox><el-select v-model="rule.show_next_issue" size="small" style="width:126px"><el-option :value="0" label="历史/下拉：当前期"/><el-option :value="1" label="历史/下拉：下一期"/></el-select><el-select v-model="rule.header_show_next_issue" size="small" style="width:126px"><el-option :value="0" label="顶部：当前期"/><el-option :value="1" label="顶部：下一期"/></el-select><el-button link type="danger" @click="removeTimingRule(row,index)">删除</el-button></div><el-button link type="primary" @click="addTimingRule(row)">＋ 添加时间段</el-button></template></el-table-column><el-table-column label="允许退单" width="90"><template #default="{row}"><el-switch v-model="control(row).refund_enabled" :active-value="1" :inactive-value="0"/></template></el-table-column></el-table><div class="tab-footer"><el-button type="primary" :loading="loading" @click="save">保存下注控制</el-button><span v-if="selectedSite" class="site-hint">当前配置：{{ selectedSite.name }}</span></div></div></el-tab-pane>
        </el-tabs>
      </el-form>
    </section>
  </div>
</template>

<style scoped>
.settings-page { height: 100%; min-height: 0; padding: 22px 24px; background: #fff; overflow: hidden; display: flex; flex-direction: column; }
.draw-limit-setting { display: flex; align-items: center; gap: 12px; padding: 14px 0; color: #303747; }
.draw-limit-setting small { color: #8a94a6; }
.settings-panel { max-width: 1100px; min-height: 0; flex: 1; padding-top: 12px; overflow: hidden; }
.settings-panel :deep(.el-form) { height: 100%; display: flex; flex-direction: column; }
.settings-tabs { min-height: 0; flex: 1; display: flex; flex-direction: column; }
.settings-tabs :deep(.el-tabs__content) { min-height: 0; flex: 1; overflow: hidden; }
.settings-tabs :deep(.el-tab-pane) { height: 100%; overflow: hidden; }
.tab-content { height: 100%; display: flex; flex-direction: column; overflow: hidden; }
.tab-content :deep(.el-form-item:nth-child(2)) { min-height: 0; flex: 1; }
.rich-text-field { width: 100%; height: 100%; min-height: 0; display: flex; flex-direction: column; }
.rich-text-toolbar { border: 1px solid #dcdfe6; border-bottom: 0; }
.rich-text-editor { min-height: 300px; flex: 1; border: 1px solid #dcdfe6; overflow: hidden; }
.rich-text-editor :deep(.w-e-text-container) { height: 100%; min-height: 300px; overflow-y: auto; }
.tab-footer { position: relative; z-index: 2; display: flex; min-height: 50px; flex: 0 0 50px; align-items: center; padding-top: 8px; background: #fff; }
.announcement-content :deep(.el-form-item:nth-child(2)) { min-height: 0; flex: 1; }
.announcement-content :deep(.el-form-item:nth-child(2) .el-form-item__content) { min-height: 0; height: 100%; }
.announcement-editor { min-height: 0; height: 100%; }
.announcement-editor :deep(.el-textarea__inner) { min-height: 0 !important; height: 100% !important; resize: none; overflow-y: auto; }
.rules-editor-wrap { width: 100%; height: 100%; min-height: 0; display: flex; flex-direction: column; }
.rules-inner-tabs { min-height: 0; flex: 1; display: flex; flex-direction: column; }
.rules-inner-tabs :deep(.el-tabs__content), .rules-inner-tabs :deep(.el-tab-pane), .rules-textarea { min-height: 0; height: 100%; }
.rules-textarea :deep(.el-textarea__inner) { height: 100%; min-height: 220px; resize: none; overflow-y: auto; }
.rules-editor-wrap > span { margin-top: 6px; color: #697386; font-size: 12px; }
.site-hint { margin-left: 14px; color: #697386; font-size: 13px; }
.rich-text-field > span { display: block; margin-top: 6px; color: #697386; font-size: 12px; line-height: 1.5; }
.fill-button { margin-left: 10px; }
.fill-tip { margin-left: 10px; color: #8490a5; font-size: 12px; }
.timing-rule { display: flex; align-items: center; gap: 6px; margin: 4px 0; flex-wrap: wrap; }
</style>
