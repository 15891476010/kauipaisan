import axios from 'axios'
import { ElMessageBox } from 'element-plus'

let expiredPrompt = false
let refreshPromise: Promise<string | null> | null = null

function configToken(config: any): string {
  return String(config?.headers?.Authorization || config?.headers?.authorization || '')
    .replace(/^Bearer\s+/i, '').trim()
}

/**
 * Multiple admin tabs share localStorage. If another tab has already rotated
 * the token, reuse that token instead of refreshing the stale one again.
 */
function latestTokenDifferentFrom(token: string): string | null {
  const latest = String(localStorage.getItem('admin_token') || '').trim()
  return latest && latest !== token ? latest : null
}

async function refreshToken(currentToken: string): Promise<string | null> {
  if (!currentToken) return null
  const latest = latestTokenDifferentFrom(currentToken)
  if (latest) return latest
  if (!refreshPromise) {
    const base = String(http.defaults.baseURL || '/api/v1').replace(/\/$/, '')
    refreshPromise = axios.post(`${base}/admin/auth/refresh`, null, { timeout: 8000, headers: { Authorization: `Bearer ${currentToken}` } })
      .then((response) => {
        const token = String(response.data?.data?.token || '').trim()
        if (token) localStorage.setItem('admin_token', token)
        return token || null
      }).catch(() => null).finally(() => { refreshPromise = null })
  }
  return refreshPromise
}

function handleExpiredSession(expectedToken = '') {
  // A sibling tab may have refreshed the session while this request was in
  // flight. Never erase the newer shared token in that case.
  const latest = String(localStorage.getItem('admin_token') || '').trim()
  if (expectedToken && latest && latest !== expectedToken) return
  localStorage.removeItem('admin_token')
  localStorage.removeItem('admin_user')
  if (expiredPrompt) return
  expiredPrompt = true
  void ElMessageBox.alert(
    '登录已过期，请重新登录',
    '登录已过期',
    {
      confirmButtonText: '确认',
      closeOnClickModal: false,
      closeOnPressEscape: false,
      showClose: false,
    },
  ).finally(() => {
    expiredPrompt = false
    redirectToLogin()
  })
}

function redirectToLogin() {
  if (location.hash.startsWith('#/login')) return
  const loginUrl = new URL(import.meta.env.BASE_URL || '/', `${location.origin}/`)
  loginUrl.hash = '/login'
  location.replace(loginUrl.toString())
}

const http = axios.create({ baseURL: import.meta.env.VITE_API_BASE_URL || '/api/v1', timeout: 15000 })
http.interceptors.request.use((config) => {
  const token = localStorage.getItem('admin_token')
  if (token) config.headers.Authorization = `Bearer ${token}`
  return config
})
http.interceptors.response.use(
  async (response) => {
    const payload = response.data as { code?: number }
    const requestUrl = String(response.config.url || '')
    if (payload?.code === 401 && !requestUrl.includes('/auth/refresh')) {
      const config = response.config as typeof response.config & { _tokenRefreshAttempted?: boolean }
      if (!config._tokenRefreshAttempted) {
        config._tokenRefreshAttempted = true
        const originalToken = configToken(config)
        const token = latestTokenDifferentFrom(originalToken) || await refreshToken(originalToken)
        if (token) {
          config.headers.Authorization = `Bearer ${token}`
          return http(config)
        }
      }
      handleExpiredSession(configToken(config))
    }
    return response.data
  },
  async (error) => {
    const config = error.config as (typeof error.config & { _tokenRefreshAttempted?: boolean }) | undefined
    const requestUrl = String(config?.url || '')
    if (error.response?.status === 401 && config && !config._tokenRefreshAttempted && !requestUrl.includes('/auth/refresh')) {
      config._tokenRefreshAttempted = true
      const originalToken = configToken(config)
      const token = latestTokenDifferentFrom(originalToken) || await refreshToken(originalToken)
      if (token) {
        config.headers.Authorization = `Bearer ${token}`
        return http(config)
      }
    }
    if (error.response?.status === 401) handleExpiredSession(configToken(config))
    return Promise.reject(new Error(error.response?.data?.message || error.message || '网络请求失败'))
  },
)

export default http
