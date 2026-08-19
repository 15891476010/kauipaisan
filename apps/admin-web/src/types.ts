export interface MenuItem {
  id: number
  parent_id: number
  name: string
  title: string
  path: string
  component: string
  icon?: string
  permission?: string
  children?: MenuItem[]
}

export interface AdminUser { id: number; username: string; display_name: string; tenant_id: number | null; agent_id: number | null; site_id: number | null; role: 'platform' | 'site' }
export interface LoginResponse { token: string; expires_at: string; user: AdminUser; menus: MenuItem[] }
