import axios from 'axios'

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
  (response) => response.data,
  (error) => {
    if (error.response?.status === 401) {
      localStorage.removeItem('admin_token')
      localStorage.removeItem('admin_user')
      redirectToLogin()
    }
    return Promise.reject(new Error(error.response?.data?.message || error.message || '网络请求失败'))
  },
)

export default http
