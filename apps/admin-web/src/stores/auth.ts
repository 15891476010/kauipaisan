import { defineStore } from 'pinia'
import { login as loginApi, logout as logoutApi, getMenus } from '../api/admin'
import type { AdminUser, MenuItem } from '../types'

interface LoginPayload { token: string; user?: AdminUser; menus?: MenuItem[] }

export const useAuthStore = defineStore('auth', {
  state: () => ({
    token: localStorage.getItem('admin_token') || '',
    user: JSON.parse(localStorage.getItem('admin_user') || 'null') as AdminUser | null,
    menus: [] as MenuItem[],
    menusLoaded: false,
  }),
  actions: {
    async login(username: string, password: string) {
      const response = await loginApi({ username, password })
      // Axios is configured to unwrap the HTTP response once. Keep this
      // tolerant of a proxy that unwraps the API envelope as well.
      const envelope = response as unknown as { code?: number; message?: string; data?: unknown }
      const payload = (envelope.data && typeof envelope.data === 'object' ? envelope.data : envelope) as Partial<LoginPayload>
      if (!payload.token) throw new Error(envelope.message || '登录响应无效，请检查 API 地址')
      this.token = payload.token
      this.user = payload.user || null
      this.menus = payload.menus || []
      this.menusLoaded = true
      localStorage.setItem('admin_token', this.token)
      localStorage.setItem('admin_user', JSON.stringify(this.user))
    },
    async loadMenus() {
      if (!this.token || this.menusLoaded) return
      const response = await getMenus()
      const envelope = response as unknown as { data?: MenuItem[] | { menus?: MenuItem[] } }
      this.menus = Array.isArray(envelope.data) ? envelope.data : (envelope.data as { menus?: MenuItem[] } | undefined)?.menus || []
      this.menusLoaded = true
    },
    async logout() {
      try { await logoutApi() } catch { /* local logout remains available */ }
      this.token = ''
      this.user = null
      this.menus = []
      this.menusLoaded = false
      localStorage.removeItem('admin_token')
      localStorage.removeItem('admin_user')
    },
  },
})
