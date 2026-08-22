<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { useAuthStore } from '../stores/auth'
import { getBranding } from '../api/admin'

const form = reactive({ username: '', password: '' })
const loading = ref(false)
const platformName = ref('快排 SaaS')
const router = useRouter(); const route = useRoute(); const auth = useAuthStore()
onMounted(async () => { try { const response = await getBranding(); platformName.value = response.data.platform_name || '快排 SaaS' } catch { /* Keep the default brand. */ } document.title = `${platformName.value} - 登录` })
async function submit() {
  if (!form.username || !form.password) return ElMessage.warning('请输入账号和密码')
  loading.value = true
  try { await auth.login(form.username, form.password); await router.replace(String(route.query.redirect || '/')) }
  catch (error) { ElMessage.error(error instanceof Error ? error.message : '登录失败') }
  finally { loading.value = false }
}
</script>
<template>
  <div class="login-page"><div class="visual"><div class="visual-copy"><span class="eyebrow">MULTI-TENANT OPERATIONS</span><h1>一个后台，管理所有代理与站点</h1><p>域名、权限、审计与业务数据统一隔离，清楚掌握每一个租户的运行状态。</p></div></div><div class="login-panel"><form class="login-card" @submit.prevent="submit"><div class="logo">K</div><h2>欢迎回来</h2><p>登录 {{ platformName }} 管理平台</p><el-input v-model="form.username" size="large" placeholder="管理账号" autocomplete="username" /><el-input v-model="form.password" size="large" type="password" show-password placeholder="登录密码" autocomplete="current-password" @keyup.enter="submit" /><el-button native-type="submit" type="primary" size="large" :loading="loading">登录管理平台</el-button></form></div></div>
</template>
<style scoped>
.login-page { height: 100%; display: grid; grid-template-columns: 56% 44%; background: #fff; }.visual { position: relative; padding: 72px; display: flex; align-items: end; overflow: hidden; color: #fff; background: radial-gradient(circle at 30% 28%,#4878f0 0,transparent 31%),linear-gradient(145deg,#101b3c,#1d3e8c 60%,#405cff); }.visual::after { content:""; position:absolute; width:440px; height:440px; right:-90px; top:30px; border:1px solid #ffffff40; border-radius:50%; box-shadow:0 0 0 80px #ffffff09,0 0 0 160px #ffffff08; }.visual-copy { position: relative; z-index: 1; max-width: 560px; }.eyebrow { color:#a8c8ff; letter-spacing:3px; font-size:12px; }.visual h1 { margin:18px 0; font-size:44px; line-height:1.2; }.visual p { color:#c9d8f5; line-height:1.8; }.login-panel { display:grid; place-items:center; padding:60px; }.login-card { width:360px; display:flex; flex-direction:column; gap:18px; }.logo { width:44px; height:44px; display:grid; place-items:center; border-radius:12px; color:#fff; background:#386ff3; font-weight:800; }.login-card h2 { margin:10px 0 -10px; font-size:28px; }.login-card p { margin:0 0 14px; color:#8b94a8; }.login-card .el-button { width:100%; margin-top:6px; }
</style>
