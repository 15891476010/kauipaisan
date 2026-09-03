<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watchEffect } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { Grid, User, Connection, Lock, Menu as MenuIcon, Document, Setting, Fold, Expand, Tickets, List, Monitor } from '@element-plus/icons-vue'
import { useAuthStore } from '../stores/auth'
import { getBranding, heartbeat } from '../api/admin'

const collapsed = ref(false)
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const platformName = ref('快排 SaaS')
const menuItems = [
  { path: '/dashboard', title: '数据看板', icon: Grid },
  { path: '/agent-center', title: '代理中心', icon: Connection },
  { path: '/site-users', title: '站点用户', icon: User },
  { path: '/bet-records', title: '下单记录', icon: List },
  { path: '/robots', title: '机器人列表', icon: Monitor },
  { path: '/lotteries', title: '彩票列表', icon: Tickets },
  { path: '/admins', title: '管理员', icon: User },
  { path: '/roles', title: '角色权限', icon: Lock },
  { path: '/menus', title: '菜单管理', icon: MenuIcon },
  { path: '/audit-logs', title: '审计日志', icon: Document },
  { path: '/settings', title: '系统配置', icon: Setting },
  { path: '/settings/third-party-quick-entry', title: '三方快速录入', icon: Setting },
  { path: '/site-settings', title: '站点配置', icon: Setting },
]
const title = computed(() => String(route.meta.title || '控制台'))
let heartbeatTimer: ReturnType<typeof setInterval> | null = null
async function refreshBranding() { try { const response = await getBranding(); platformName.value = response.data.platform_name || '快排 SaaS' } catch { /* Keep the default brand if the public endpoint is unavailable. */ } }
async function sendHeartbeat() { try { await heartbeat() } catch { /* 401 is handled by the shared interceptor. */ } }
watchEffect(() => { document.title = `${platformName.value} - ${title.value}` })
onMounted(() => { void refreshBranding(); void sendHeartbeat(); heartbeatTimer = setInterval(sendHeartbeat, 20000); window.addEventListener('platform-branding-changed', refreshBranding) })
onBeforeUnmount(() => { if (heartbeatTimer) clearInterval(heartbeatTimer); window.removeEventListener('platform-branding-changed', refreshBranding) })
async function signOut() { await auth.logout(); await router.replace('/login') }
</script>

<template>
  <div class="shell">
    <aside :class="['sidebar', { collapsed }]">
      <div class="brand"><span class="brand-mark">K</span><span v-if="!collapsed">{{ platformName }}</span></div>
      <el-menu :default-active="route.path" router :collapse="collapsed" background-color="#111827" text-color="#aeb9ca" active-text-color="#fff">
        <el-menu-item v-for="item in menuItems" :key="item.path" :index="item.path"><el-icon><component :is="item.icon" /></el-icon><template #title>{{ item.title }}</template></el-menu-item>
      </el-menu>
    </aside>
    <section class="main">
      <header class="topbar">
        <button class="collapse-btn" @click="collapsed = !collapsed"><el-icon><component :is="collapsed ? Expand : Fold" /></el-icon></button>
        <div class="crumb">控制台 / <strong>{{ title }}</strong></div>
        <div class="top-actions"><span>{{ auth.user?.display_name || auth.user?.username }}</span><el-button link type="primary" @click="signOut">退出</el-button></div>
      </header>
      <div class="tabbar"><span class="tab active">{{ title }}</span></div>
      <main class="workspace"><div class="scroll-window"><RouterView /></div></main>
    </section>
  </div>
</template>

<style scoped>
.shell { height: 100%; display: flex; background: #eef2f7; }
.sidebar { width: 224px; flex: 0 0 224px; background: #111827; transition: .2s; overflow: hidden; }
.sidebar.collapsed { width: 64px; flex-basis: 64px; }
.brand { height: 64px; display: flex; align-items: center; gap: 11px; padding: 0 17px; color: #fff; font-weight: 700; white-space: nowrap; }
.brand-mark { width: 30px; height: 30px; display: grid; place-items: center; background: linear-gradient(135deg,#4f8cff,#7658ff); border-radius: 8px; }
.el-menu { border-right: 0; }
.main { min-width: 0; flex: 1; height: 100%; display: flex; flex-direction: column; }
.topbar { height: 64px; flex: 0 0 64px; display: flex; align-items: center; gap: 18px; padding: 0 22px; background: #fff; border-bottom: 1px solid #e9edf3; }
.collapse-btn { border: 0; background: none; font-size: 20px; cursor: pointer; color: #556176; }
.crumb { color: #919aad; font-size: 14px; }.crumb strong { color: #2b3447; }
.top-actions { margin-left: auto; display: flex; align-items: center; gap: 14px; font-size: 14px; }
.tabbar { height: 42px; flex: 0 0 42px; display: flex; align-items: end; padding: 0 18px; background: #fff; box-shadow: 0 2px 8px #24334a0a; }
.tab { padding: 10px 16px 9px; color: #5d677b; font-size: 13px; }.tab.active { color: #366ef4; border-bottom: 2px solid #366ef4; }
.workspace { min-height: 0; flex: 1; padding: 16px; overflow: hidden; }
.scroll-window { width: 100%; height: 100%; overflow: auto; border-radius: 10px; box-shadow: 0 2px 12px #20304a0a; }
</style>
