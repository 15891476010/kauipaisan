<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { getBrandingSettings, getLotteryConfig, getSecurityPolicy, saveBrandingSettings, saveLotteryConfig, saveSecurityPolicy, testLotteryConfig, type LotteryConfigTest } from '../api/admin'

const loading=ref(false); const testing=ref(false); const savingBranding=ref(false); const savingSecurity=ref(false); const testResult=ref<LotteryConfigTest|null>(null)
const form=reactive({base_url:''}); const brandingForm=reactive({platform_name:''}); const weakPasswordText=ref('')
async function load(){loading.value=true;try{const [lottery,branding,security]=await Promise.all([getLotteryConfig(),getBrandingSettings(),getSecurityPolicy()]);form.base_url=lottery.data.base_url;brandingForm.platform_name=branding.data.platform_name;weakPasswordText.value=(security.data.weak_passwords||[]).join('\n')}catch(e){ElMessage.error(e instanceof Error?e.message:'系统配置加载失败')}finally{loading.value=false}}
async function save(){if(!form.base_url.trim())return ElMessage.warning('请输入开奖接口 Base URL');loading.value=true;try{await saveLotteryConfig({base_url:form.base_url.trim()});ElMessage.success('系统配置已保存')}catch(e){ElMessage.error(e instanceof Error?e.message:'保存失败')}finally{loading.value=false}}
async function saveBranding(){const platform_name=brandingForm.platform_name.trim();if(!platform_name)return ElMessage.warning('请输入平台名称');savingBranding.value=true;try{const response=await saveBrandingSettings({platform_name});brandingForm.platform_name=response.data.platform_name;window.dispatchEvent(new CustomEvent('platform-branding-changed'));ElMessage.success('平台名称已全局生效')}catch(e){ElMessage.error(e instanceof Error?e.message:'保存失败')}finally{savingBranding.value=false}}
async function saveSecurity(){const weak_passwords=weakPasswordText.value.split(/[\n,，]+/).map(item=>item.trim()).filter(Boolean);if(!weak_passwords.length)return ElMessage.warning('弱密码列表不能为空');savingSecurity.value=true;try{const response=await saveSecurityPolicy({weak_passwords});weakPasswordText.value=response.data.weak_passwords.join('\n');ElMessage.success('密码安全配置已全局生效')}catch(e){ElMessage.error(e instanceof Error?e.message:'保存失败')}finally{savingSecurity.value=false}}
async function test(){testing.value=true;testResult.value=null;try{const result=await testLotteryConfig();testResult.value=result.data;result.data.available?ElMessage.success('开奖接口连接成功'):ElMessage.warning('接口已响应，但返回数据未通过校验')}catch(e){ElMessage.error(e instanceof Error?e.message:'开奖接口连接失败')}finally{testing.value=false}}
onMounted(load)
</script>
<template>
  <div class="system-page" v-loading="loading">
    <h1>系统配置</h1><p>全平台通用配置，只需要设置一次，所有站点生效。</p>
    <el-card shadow="never"><el-tabs>
      <el-tab-pane label="平台信息"><el-form label-position="top" @submit.prevent="saveBranding"><el-form-item label="SaaS 平台名称"><el-input v-model="brandingForm.platform_name" maxlength="80" show-word-limit placeholder="请输入平台名称"/><div class="tip">用于 SaaS 管理端的登录页、侧栏和浏览器标题。各业务站点仍显示各自配置的站点名称。</div></el-form-item><div class="actions"><el-button type="primary" :loading="savingBranding" @click="saveBranding">保存平台名称</el-button></div></el-form></el-tab-pane>
      <el-tab-pane label="开奖接口"><el-form label-position="top" @submit.prevent="save"><el-form-item label="历史开奖 Base URL"><el-input v-model="form.base_url" maxlength="500" placeholder="https://api.huiniao.top/interface/home/lotteryHistory"/><div class="tip">系统会在此地址后追加 type、page、limit 参数；每种彩票最多同步 10 页，每页最多 1000 条。</div></el-form-item><div class="actions"><el-button type="primary" @click="save">保存开奖接口</el-button><el-button :loading="testing" @click="test">测试接口</el-button><span v-if="testResult" :class="['test-result', testResult.available ? 'ok' : 'bad']">{{ testResult.available ? '接口可用' : '接口异常' }} · HTTP {{ testResult.http_status || '-' }} · 响应 {{ testResult.response_time_ms }} ms</span></div></el-form></el-tab-pane>
      <el-tab-pane label="密码安全"><el-form label-position="top" @submit.prevent="saveSecurity"><el-form-item label="常见弱密码"><el-input v-model="weakPasswordText" type="textarea" :rows="12" maxlength="13000" show-word-limit placeholder="每行填写一个弱密码"/><div class="tip">每行填写一个，也支持逗号分隔。保存后立即适用于 SaaS、总监、大股东、小股东、总代理、代理、子账号和用户端的所有新建及修改密码操作。</div></el-form-item><div class="actions"><el-button type="primary" :loading="savingSecurity" @click="saveSecurity">保存密码安全配置</el-button></div></el-form></el-tab-pane>
    </el-tabs></el-card>
  </div>
</template>
<style scoped>.system-page{min-height:100%;padding:22px;background:#fff}.system-page h1{margin:0 0 7px;color:#25314a;font-size:20px}.system-page>p{margin:0 0 20px;color:#8490a5;font-size:13px}.system-page .el-card{max-width:1100px;border:1px solid #e8edf4}.tip{margin-top:8px;color:#8490a5;font-size:12px;line-height:1.5}.actions{display:flex;align-items:center;gap:12px}.test-result{font-size:13px}.test-result.ok{color:#1a9b50}.test-result.bad{color:#d66b00}</style>
