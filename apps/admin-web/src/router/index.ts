import { createRouter, createWebHashHistory, type RouteRecordRaw } from 'vue-router'
import LoginView from '../views/LoginView.vue'
import AdminLayout from '../layouts/AdminLayout.vue'
import DashboardView from '../views/DashboardView.vue'
import ResourceView from '../views/ResourceView.vue'
import AgreementSettingsView from '../views/AgreementSettingsView.vue'
import LotteriesView from '../views/LotteriesView.vue'
import LotteryHistoryView from '../views/LotteryHistoryView.vue'
import SystemSettingsView from '../views/SystemSettingsView.vue'
import LotteryOddsView from '../views/LotteryOddsView.vue'
import LotteryRulesView from '../views/LotteryRulesView.vue'
import BetBatchReplaceView from '../views/BetBatchReplaceView.vue'
import SiteAdminsView from '../views/SiteAdminsView.vue'
import OrganizationsView from '../views/OrganizationsView.vue'
import { useAuthStore } from '../stores/auth'

const child = (path: string, name: string, title: string, resource: string): RouteRecordRaw => ({
  path, name, component: ResourceView, meta: { title, resource },
})

const routes: RouteRecordRaw[] = [
  { path: '/login', name: 'login', component: LoginView, meta: { public: true } },
  {
    path: '/', component: AdminLayout, redirect: '/dashboard', children: [
      { path: 'dashboard', name: 'dashboard', component: DashboardView, meta: { title: '数据看板' } },
      child('agent-center', 'agent-center', '代理中心', 'agent-center'),
      { path: 'agent-center/:siteId/admins', name: 'site-admins', component: SiteAdminsView, meta: { title: '站点后台管理员' } },
      { path: 'agent-center/:siteId/organizations', name: 'site-organizations', component: OrganizationsView, meta: { title: '组织架构' } },
      child('site-users', 'site-users', '站点用户', 'site-users'),
      child('bet-records', 'bet-records', '下单记录', 'bet-records'),
      { path: 'bet-records/batch-replace', name: 'bet-batch-replace', component: BetBatchReplaceView, meta: { title: '批量替换号码' } },
      { path: 'lotteries', name: 'lotteries', component: LotteriesView, meta: { title: '彩票列表' } },
      { path: 'lotteries/:id/history', name: 'lottery-history', component: LotteryHistoryView, meta: { title: '开奖历史' } },
      { path: 'lotteries/:id/odds', name: 'lottery-odds', component: LotteryOddsView, meta: { title: '赔率详情' } },
      { path: 'lotteries/:id/rules', name: 'lottery-rules', component: LotteryRulesView, meta: { title: '规则配置' } },
      child('admins', 'admins', '管理员', 'admins'),
      child('roles', 'roles', '角色权限', 'roles'),
      child('menus', 'menus', '菜单管理', 'menus'),
      child('audit-logs', 'audit-logs', '审计日志', 'audit-logs'),
      { path: 'site-settings', name: 'site-settings', component: AgreementSettingsView, meta: { title: '站点配置' } },
      { path: 'settings', name: 'settings', component: SystemSettingsView, meta: { title: '系统配置' } },
    ],
  },
  { path: '/:pathMatch(.*)*', redirect: '/' },
]

const router = createRouter({ history: createWebHashHistory(), routes })
router.beforeEach(async (to) => {
  const auth = useAuthStore()
  if (to.meta.public) return auth.token ? '/' : true
  if (!auth.token) return { name: 'login', query: { redirect: to.fullPath } }
  try { await auth.loadMenus() } catch { await auth.logout(); return { name: 'login' } }
  return true
})
export default router
