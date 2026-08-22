<script setup lang="ts">
import { onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ArrowLeft, Plus, Refresh } from '@element-plus/icons-vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { createResource, deleteResource, listResource, updateResource } from '../api/admin'

const route=useRoute()
const router=useRouter()
const siteId=Number(route.params.siteId)
const siteName=ref(String(route.query.name || '当前站点'))
const loading=ref(false)
const rows=ref<Record<string,unknown>[]>([])
const total=ref(0)
const drawer=ref(false)
const editingId=ref<number|null>(null)
const query=reactive({keyword:'',page:1,page_size:20,site_id:siteId})
const form=reactive({username:'',display_name:'',phone:'',password:'',status:1})
let timer:ReturnType<typeof setInterval>|null=null
let refreshing=false

async function load(silent=false){
  if(silent&&refreshing)return
  if(silent)refreshing=true;else loading.value=true
  try{const response=await listResource('site-admins',query);rows.value=response.data.list;total.value=response.data.total}
  catch(error){if(!silent)ElMessage.error(error instanceof Error?error.message:'管理员列表加载失败')}
  finally{if(silent)refreshing=false;else loading.value=false}
}
function openCreate(){editingId.value=null;Object.assign(form,{username:'',display_name:'',phone:'',password:'',status:1});drawer.value=true}
function openEdit(row:Record<string,unknown>){editingId.value=Number(row.id);Object.assign(form,{username:String(row.username||''),display_name:String(row.display_name||''),phone:String(row.phone||''),password:'',status:Number(row.status??1)});drawer.value=true}
async function save(){
  if(!form.username.trim())return ElMessage.warning('请输入管理员账号')
  if(!editingId.value&&form.password.length<6)return ElMessage.warning('管理员密码至少 6 位')
  const payload={site_id:siteId,username:form.username.trim(),display_name:form.display_name.trim(),phone:form.phone.trim(),password:form.password,status:form.status}
  try{if(editingId.value)await updateResource('site-admins',editingId.value,payload);else await createResource('site-admins',payload);drawer.value=false;ElMessage.success(editingId.value?'管理员已更新':'管理员创建成功');await load()}
  catch(error){ElMessage.error(error instanceof Error?error.message:'保存失败')}
}
async function remove(row:Record<string,unknown>){await ElMessageBox.confirm(`确定删除管理员“${row.username}”吗？`,'操作确认',{type:'warning'});await deleteResource('site-admins',Number(row.id));ElMessage.success('管理员已删除');await load()}
function show(value:unknown){return String(value||'-')}
onMounted(()=>{void load();timer=setInterval(()=>void load(true),15000)})
onBeforeUnmount(()=>{if(timer)clearInterval(timer)})
</script>

<template>
  <div class="site-admin-page">
    <header class="site-admin-toolbar">
      <div><b>{{ siteName }}</b><span>站点管理员</span></div>
      <el-input v-model="query.keyword" clearable placeholder="搜索账号、姓名或手机号" @keyup.enter="query.page=1;load()" />
      <el-button :icon="Refresh" @click="load()">刷新</el-button>
      <el-button type="primary" :icon="Plus" @click="openCreate">新增管理员</el-button>
      <el-button :icon="ArrowLeft" @click="router.push('/agent-center')">返回代理中心</el-button>
    </header>
    <el-table v-loading="loading" :data="rows" border stripe height="calc(100vh - 260px)">
      <el-table-column prop="id" label="ID" width="72" />
      <el-table-column prop="username" label="管理员账号" min-width="140" />
      <el-table-column prop="display_name" label="姓名" min-width="120" />
      <el-table-column prop="phone" label="手机号" min-width="135"><template #default="scope">{{ show(scope.row.phone) }}</template></el-table-column>
      <el-table-column label="在线状态" width="100"><template #default="scope"><el-tag :type="Number(scope.row.online)===1?'success':'info'" effect="dark">{{ Number(scope.row.online)===1?'在线':'离线' }}</el-tag></template></el-table-column>
      <el-table-column prop="last_seen_at" label="最后活跃" min-width="165"><template #default="scope">{{ show(scope.row.last_seen_at) }}</template></el-table-column>
      <el-table-column prop="last_login_at" label="最后登录" min-width="165"><template #default="scope">{{ show(scope.row.last_login_at) }}</template></el-table-column>
      <el-table-column prop="last_login_device" label="最后登录设备" min-width="190"><template #default="scope">{{ show(scope.row.last_login_device) }}</template></el-table-column>
      <el-table-column prop="last_login_location" label="最后登录地点" min-width="180"><template #default="scope">{{ show(scope.row.last_login_location) }}</template></el-table-column>
      <el-table-column prop="last_login_ip" label="最后登录 IP" min-width="210"><template #default="scope"><span class="ip-value">{{ show(scope.row.last_login_ip) }}</span></template></el-table-column>
      <el-table-column label="状态" width="90"><template #default="scope"><el-tag :type="Number(scope.row.status)===1?'success':'info'">{{ Number(scope.row.status)===1?'启用':'停用' }}</el-tag></template></el-table-column>
      <el-table-column label="操作" fixed="right" width="130"><template #default="scope"><el-button link type="primary" @click="openEdit(scope.row)">编辑</el-button><el-button link type="danger" @click="remove(scope.row)">删除</el-button></template></el-table-column>
    </el-table>
    <el-pagination v-model:current-page="query.page" v-model:page-size="query.page_size" :total="total" layout="total, prev, pager, next" @change="load()" />
    <el-drawer v-model="drawer" :title="editingId?'编辑管理员':'新增管理员'" size="520px">
      <el-form label-width="110px">
        <el-form-item label="管理员账号"><el-input v-model="form.username" maxlength="80" /></el-form-item>
        <el-form-item label="姓名"><el-input v-model="form.display_name" maxlength="120" /></el-form-item>
        <el-form-item label="手机号"><el-input v-model="form.phone" maxlength="30" /></el-form-item>
        <el-form-item :label="editingId?'新密码':'登录密码'"><el-input v-model="form.password" type="password" show-password :placeholder="editingId?'留空则不修改':'至少 6 位'" /></el-form-item>
        <el-form-item label="状态"><el-switch v-model="form.status" :active-value="1" :inactive-value="0" /></el-form-item>
      </el-form>
      <template #footer><el-button @click="drawer=false">取消</el-button><el-button type="primary" @click="save">保存</el-button></template>
    </el-drawer>
  </div>
</template>

<style scoped>
.site-admin-page{min-height:100%;padding:22px;background:#fff;border-radius:8px;box-sizing:border-box}.site-admin-toolbar{display:flex;align-items:center;gap:12px;margin-bottom:18px}.site-admin-toolbar>div{display:flex;min-width:230px;flex-direction:column;gap:3px}.site-admin-toolbar b{color:#27334a;font-size:18px}.site-admin-toolbar span{color:#8993a5;font-size:13px}.site-admin-toolbar .el-input{width:280px;margin-left:auto}.el-pagination{justify-content:flex-end;margin-top:18px}.ip-value{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;white-space:nowrap}
</style>
