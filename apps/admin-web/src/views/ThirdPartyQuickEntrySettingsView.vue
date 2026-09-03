<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { getThirdPartyQuickEntryConfig, loginThirdPartyQuickEntryAccount, saveThirdPartyQuickEntryConfig, testThirdPartyQuickEntryConfig, type ThirdPartyQuickEntryConfig } from '../api/admin'

const loading = ref(false)
const saving = ref(false)
const testing = ref(false)
const loggingInAccount = ref('')
const form = reactive<ThirdPartyQuickEntryConfig>({
  enabled: false, strict: false, base_url: '', captcha_endpoint: '/vc/qc.php', login_endpoint: '/mb/', recognize_endpoint: '/mb/',
  captcha_ocr_endpoint: '', captcha_ocr_command: 'tesseract', captcha_ocr_language: 'chi_sim+eng', request_timeout: 15, token_ttl_seconds: 28800,
  rate_window_seconds: 0, freeze_after_calls: 3, freeze_seconds: 3, accounts: [],
})

async function load() {
  loading.value = true
  try { const response = await getThirdPartyQuickEntryConfig(); Object.assign(form, response.data); form.accounts = (response.data.accounts || []).map(item => ({ ...item })) }
  catch (error) { ElMessage.error(error instanceof Error ? error.message : '三方配置加载失败') }
  finally { loading.value = false }
}
function addAccount() { form.accounts.push({ id: `account-${Date.now()}`, username: '', password: '', rate_window_seconds: null, rate_limit_calls: null, freeze_seconds: null }) }
function removeAccount(index: number) { form.accounts.splice(index, 1) }
async function loginAccount(accountId: string, username: string) {
  loggingInAccount.value = accountId
  try { const response = await loginThirdPartyQuickEntryAccount(accountId); ElMessage.success(`${username} 登录成功，AK 已更新（${response.data.duration_ms}ms）`); await load() }
  catch (error) { ElMessage.error(error instanceof Error ? error.message : `${username} 登录失败`) }
  finally { loggingInAccount.value = '' }
}
async function save() {
  saving.value = true
  try { const response = await saveThirdPartyQuickEntryConfig({ ...form, accounts: form.accounts }); Object.assign(form, response.data); form.accounts = (response.data.accounts || []).map(item => ({ ...item })); ElMessage.success('三方快速录入配置已保存') }
  catch (error) { ElMessage.error(error instanceof Error ? error.message : '保存失败') }
  finally { saving.value = false }
}
async function test() {
  testing.value = true
  try { const response = await testThirdPartyQuickEntryConfig({ text: '123直1元', lottery: '福彩3D' }); const account = response.data.account?.username ? `，账号 ${response.data.account.username}` : ''; ElMessage.success(`识别完成：${response.data.total_count ?? 0} 笔，${response.data.total_amount ?? 0} 元${account}`); await load() }
  catch (error) { ElMessage.error(error instanceof Error ? error.message : '测试失败') }
  finally { testing.value = false }
}
function statusText(status?: string) { return ({ calling: '调用中', success: '成功', failed: '失败', logged_in: '已登录', login_failed: '登录失败', valid: 'AK 有效', relogged: '失效后已重登', checking: '检活中' } as Record<string, string>)[status || ''] || '未调用' }
onMounted(load)
</script>

<template>
  <div class="third-party-page" v-loading="loading">
    <div class="page-heading"><div><h2>三方快速录入</h2><p>独立调用三方识别接口；本地解析规则仍保留并作为默认结果。</p></div><div><el-button :loading="testing" @click="test">测试识别</el-button><el-button type="primary" :loading="saving" @click="save">保存配置</el-button></div></div>
    <el-card shadow="never"><el-form label-position="top">
      <el-row :gutter="16"><el-col :span="12"><el-form-item label="启用三方识别"><el-switch v-model="form.enabled" active-text="启用" inactive-text="停用" /></el-form-item></el-col><el-col :span="12"><el-form-item label="三方失败时严格返回"><el-switch v-model="form.strict" active-text="是" inactive-text="否" /></el-form-item></el-col></el-row>
      <el-form-item label="Base URL"><el-input v-model="form.base_url" placeholder="https://example.com" /></el-form-item>
      <el-row :gutter="16"><el-col :span="8"><el-form-item label="验证码端点"><el-input v-model="form.captcha_endpoint" /></el-form-item></el-col><el-col :span="8"><el-form-item label="登录端点"><el-input v-model="form.login_endpoint" /></el-form-item></el-col><el-col :span="8"><el-form-item label="识别端点"><el-input v-model="form.recognize_endpoint" /></el-form-item></el-col></el-row>
      <el-row :gutter="16"><el-col :span="8"><el-form-item label="OCR HTTP 端点（可选）"><el-input v-model="form.captcha_ocr_endpoint" placeholder="返回 text 或 result 字段" /></el-form-item></el-col><el-col :span="8"><el-form-item label="本机 OCR 命令"><el-input v-model="form.captcha_ocr_command" placeholder="tesseract" /></el-form-item></el-col><el-col :span="8"><el-form-item label="OCR 语言"><el-input v-model="form.captcha_ocr_language" placeholder="chi_sim+eng" /></el-form-item></el-col></el-row>
      <el-row :gutter="16"><el-col :span="6"><el-form-item label="请求超时（秒）"><el-input-number v-model="form.request_timeout" :min="1" :max="60" /></el-form-item></el-col><el-col :span="6"><el-form-item label="Token 有效期（秒）"><el-input-number v-model="form.token_ttl_seconds" :min="60" :max="604800" /></el-form-item></el-col><el-col :span="6"><el-form-item label="默认统计窗口（秒）"><el-input-number v-model="form.rate_window_seconds" :min="0" :max="86400" /></el-form-item></el-col><el-col :span="6"><el-form-item label="默认窗口内调用次数"><el-input-number v-model="form.freeze_after_calls" :min="1" :max="1000" /></el-form-item></el-col></el-row>
      <el-form-item label="冻结时间（秒）"><el-input-number v-model="form.freeze_seconds" :min="0" :max="300" /></el-form-item>
      <el-divider content-position="left">三方账号池</el-divider>
      <div class="account-hint">每个账号可单独设置：统计窗口（秒）／窗口内最多调用次数／达到次数后的冻结时间（秒）。留空沿用上面的默认值。</div>
      <div v-if="form.current_account" class="current-account">
        <el-tag type="success">当前调用账号</el-tag><strong>{{ form.current_account.username }}</strong>
        <span>调用 {{ form.current_account.call_count || 0 }} 次</span><span>成功 {{ form.current_account.success_count || 0 }} 次</span><span>失败 {{ form.current_account.failure_count || 0 }} 次</span>
        <span class="ak">当前 AK：{{ form.current_account.ak || '尚未登录' }}</span>
      </div>
      <div v-for="(account, index) in form.accounts" :key="account.id" class="account-card">
        <div class="account-row"><el-input v-model="account.username" placeholder="账号" /><el-input v-model="account.password" type="password" show-password placeholder="密码；已保存账号保持 ********" /><el-input-number v-model="account.rate_window_seconds" :min="0" :max="86400" controls-position="right" placeholder="窗口秒数" /><el-input-number v-model="account.rate_limit_calls" :min="1" :max="1000" controls-position="right" placeholder="调用次数" /><el-input-number v-model="account.freeze_seconds" :min="0" :max="3600" controls-position="right" placeholder="冻结秒数" /><el-button type="primary" :loading="loggingInAccount === account.id" :disabled="!!loggingInAccount || account.password !== '********'" @click="loginAccount(account.id, account.username)">登录</el-button><el-button type="danger" link @click="removeAccount(index)">删除</el-button></div>
        <div class="account-runtime">
          <el-tag v-if="account.is_current" type="success" size="small">当前</el-tag><el-tag v-else type="info" size="small">待用</el-tag>
          <span>状态：{{ statusText(account.last_status) }}</span><span>累计调用：{{ account.call_count || 0 }}</span><span>成功：{{ account.success_count || 0 }}</span><span>失败：{{ account.failure_count || 0 }}</span><span>窗口调用：{{ account.window_call_count || 0 }}</span>
          <span>定时检活：{{ account.health_check_count || 0 }} 次</span><span>最近检活：{{ account.last_health_at || '-' }}</span><span>检活结果：{{ statusText(account.last_health_status) }}</span>
          <span>最近调用：{{ account.last_used_at || '-' }}</span><span>耗时：{{ account.last_duration_ms || 0 }}ms</span><span>AK 到期：{{ account.ak_expires_at || '-' }}</span>
          <span class="ak">AK：{{ account.ak || '尚未登录' }}</span><span v-if="account.last_error" class="runtime-error">{{ account.last_error }}</span><span v-if="account.last_health_error" class="runtime-error">检活：{{ account.last_health_error }}</span>
        </div>
      </div>
      <el-button plain @click="addAccount">新增账号</el-button>
    </el-form></el-card>
  </div>
</template>

<style scoped>
.third-party-page{min-height:100%;padding:22px;background:#fff}.page-heading{display:flex;align-items:center;justify-content:space-between;margin-bottom:18px}.page-heading h2{margin:0 0 7px;color:#25314a;font-size:20px}.page-heading p{margin:0;color:#8490a5;font-size:13px}.account-hint{margin:-4px 0 12px;color:#8490a5;font-size:12px}.current-account{display:flex;flex-wrap:wrap;gap:12px;align-items:center;margin:0 0 14px;padding:12px 14px;border:1px solid #b7ebc6;border-radius:8px;background:#f0fff4;color:#334155;font-size:13px}.account-card{margin-bottom:12px;padding:12px;border:1px solid #e5e7eb;border-radius:8px;background:#fafcff}.account-row{display:flex;gap:10px;align-items:center}.account-row .el-input:first-child{width:180px}.account-row .el-input:nth-child(2){flex:1}.account-row .el-input-number{width:130px}.account-runtime{display:flex;flex-wrap:wrap;gap:8px 16px;align-items:center;margin-top:10px;padding-top:9px;border-top:1px dashed #d9e1ec;color:#64748b;font-size:12px}.account-runtime .ak,.current-account .ak{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;color:#1f2937;word-break:break-all}.runtime-error{color:#dc2626}
</style>
