import axios from 'axios'
import { ElMessageBox } from 'element-plus'

let expiredPrompt = false

function handleExpiredSession() {
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
  (response) => {
    const payload = response.data as { code?: number }
    const requestUrl = String(response.config.url || '')
    if (payload?.code === 401 && localStorage.getItem('admin_token') && !requestUrl.includes('/auth/login')) {
      handleExpiredSession()
    }
    return response.data
  },
  (error) => {
    if (error.response?.status === 401) {
      handleExpiredSession()
    }
    return Promise.reject(new Error(error.response?.data?.message || error.message || '网络请求失败'))
  },
)

export default http
