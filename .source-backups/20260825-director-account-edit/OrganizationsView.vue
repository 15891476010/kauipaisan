<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ArrowLeft, Plus, Refresh, Wallet } from '@element-plus/icons-vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  createOrganization, deleteOrganization,
  getSiteOrganizations, updateOrganization, setDirectorCreditShare,
  type OrganizationCatalog, type OrganizationLevel, type OrganizationNode,
} from '../api/admin'

const route=useRoute()
const router=useRouter()
const siteId=Number(route.params.siteId)
const siteName=ref(String(route.query.name||'当前站点'))
const loading=ref(false)
const nodes=ref<OrganizationNode[]>([])
const catalog=ref<OrganizationCatalog>({levels:[],permissions:[]})
const nodeDrawer=ref(false)
const directorConfigDialog=ref(false)
const siteMaxShareRate=ref(100)
const directorConfig=reactive({id:0,name:'',credit_limit:0,max_share_rate:100})
const editingNodeId=ref<number|null>(null)
const currentOrganizationId=ref<number|null>(null)
const nodeForm=reactive({parent_id:0,level:'director' as OrganizationLevel,name:'',credit_limit:0,permissions:[] as string[],status:1,username:'',display_name:'',phone:'',password:''})
const directorNodes=computed(()=>nodes.value.filter(item=>item.parent_id===0&&item.level==='director'))
const currentOrganization=computed(()=>currentOrganizationId.value?nodes.value.find(item=>item.id===currentOrganizationId.value)||null:null)
const visibleNodes=computed(()=>currentOrganizationId.value?nodes.value.filter(item=>item.parent_id===currentOrganizationId.value):directorNodes.value)
const breadcrumbs=computed(()=>{const result:OrganizationNode[]=[];let cursor=currentOrganization.value;const visited=new Set<number>();while(cursor&&!visited.has(cursor.id)){result.unshift(cursor);visited.add(cursor.id);cursor=nodes.value.find(item=>item.id===cursor?.parent_id)||null}return result})

async function load(){loading.value=true;try{const response=await getSiteOrganizations(siteId);siteName.value=response.data.site.name;siteMaxShareRate.value=Number(response.data.site_max_share_rate||100);nodes.value=response.data.nodes;catalog.value=response.data.catalog;if(currentOrganizationId.value&&!nodes.value.some(item=>item.id===currentOrganizationId.value))currentOrganizationId.value=null}catch(error){ElMessage.error(error instanceof Error?error.message:'总监列表加载失败')}finally{loading.value=false}}
function openChildren(row:OrganizationNode){currentOrganizationId.value=row.id}
function goToOrganization(id:number|null){currentOrganizationId.value=id}
function openCreate(){editingNodeId.value=null;Object.assign(nodeForm,{parent_id:0,level:'director',name:'',credit_limit:0,permissions:catalog.value.permissions.map(item=>item.code),status:1,username:'',display_name:'',phone:'',password:''});nodeDrawer.value=true}
function openEdit(row:OrganizationNode){editingNodeId.value=row.id;Object.assign(nodeForm,{parent_id:row.parent_id,level:row.level,name:row.name,credit_limit:Number(row.credit_limit),permissions:row.permissions.includes('*')?catalog.value.permissions.map(item=>item.code):[...row.permissions],status:row.status,username:'',display_name:'',phone:'',password:''});nodeDrawer.value=true}
function openDirectorConfig(row:OrganizationNode){if(row.level!=='director'||row.parent_id!==0)return;const children=nodes.value.filter(item=>item.parent_id===row.id);const maxRates=children.map(item=>Number(item.max_share_rate||siteMaxShareRate.value));Object.assign(directorConfig,{id:row.id,name:row.name,credit_limit:Number(row.credit_limit||0),max_share_rate:maxRates.length?Math.min(...maxRates):siteMaxShareRate.value});directorConfigDialog.value=true}
async function saveDirectorConfig(){try{await setDirectorCreditShare(directorConfig.id,{credit_limit:directorConfig.credit_limit,max_share_rate:directorConfig.max_share_rate});directorConfigDialog.value=false;ElMessage.success('总监分数和下级最高占成已更新');await load()}catch(error){ElMessage.error(error instanceof Error?error.message:'总监配置保存失败')}}
async function saveNode(){if(!nodeForm.name.trim())return ElMessage.warning(editingNodeId.value?'请输入组织名称':'请输入总监名称');if(!editingNodeId.value&&(!nodeForm.username.trim()||!nodeForm.password))return ElMessage.warning('请输入总监登录账号和密码');const payload={...nodeForm,parent_id:editingNodeId.value?nodeForm.parent_id:0,level:editingNodeId.value?nodeForm.level:'director' as const,name:nodeForm.name.trim(),username:nodeForm.username.trim(),display_name:nodeForm.display_name.trim()||nodeForm.name.trim()};try{if(editingNodeId.value)await updateOrganization(editingNodeId.value,payload);else await createOrganization(siteId,payload);nodeDrawer.value=false;ElMessage.success(editingNodeId.value?'组织已更新':'总监及登录账号创建成功');await load()}catch(error){ElMessage.error(error instanceof Error?error.message:'保存失败')}}
async function removeNode(row:OrganizationNode){await ElMessageBox.confirm(`确定删除“${row.name}”吗？只能删除没有下级、会员和子账号的总监。`,'删除总监',{type:'warning'});try{await deleteOrganization(row.id);ElMessage.success('总监已删除');await load()}catch(error){ElMessage.error(error instanceof Error?error.message:'删除失败')}}
onMounted(()=>void load())
</script>

<template>
  <div class="organization-page">
    <header class="organization-toolbar">
      <div><h2>{{ currentOrganization ? `${currentOrganization.name} · 下级管理` : `${siteName} · 总监管理` }}</h2><p>点击下级名称可继续查看直属下级，登录信息来自该组织的管理员账号。</p></div>
      <el-button :icon="Refresh" @click="load">刷新</el-button>
      <el-button v-if="!currentOrganizationId" type="primary" :icon="Plus" @click="openCreate">新增总监</el-button>
      <el-button :icon="ArrowLeft" @click="router.push('/agent-center')">返回代理中心</el-button>
    </header>
    <div class="organization-summary"><div><span>{{ currentOrganization ? '当前层级人数' : '总监数量' }}</span><b>{{ visibleNodes.length }}</b></div><div><span>{{ currentOrganization ? '当前层级额度合计' : '总监额度合计' }}</span><strong>{{ visibleNodes.reduce((sum,row)=>sum+Number(row.credit_limit||0),0).toFixed(2) }}</strong></div><div><span>当前站点</span><strong>{{ siteName }}</strong></div></div>
    <div v-if="breadcrumbs.length" class="organization-breadcrumb"><button type="button" @click="goToOrganization(null)">{{ siteName }} · 总监</button><span v-for="crumb in breadcrumbs" :key="crumb.id"><i>/</i><button type="button" :class="{current:crumb.id===currentOrganizationId}" @click="goToOrganization(crumb.id)">{{ crumb.level_label || crumb.level }} · {{ crumb.name }}</button></span></div>
    <el-table v-loading="loading" :data="visibleNodes" border stripe :empty-text="currentOrganization ? '当前组织暂无直属下级' : '当前站点暂无总监，请先新增总监'">
      <el-table-column label="登录账号" min-width="150"><template #default="{row}">{{ row.username || '-' }}</template></el-table-column>
      <el-table-column label="名称" min-width="250"><template #default="{row}"><div class="org-name"><span class="org-level-dot">{{ row.level === 'director' ? '总' : (row.level_label || row.level).slice(0,1) }}</span><div><button type="button" class="org-drill-link" @click="openChildren(row)">{{ row.display_name || row.name }}<small>{{ row.child_count || 0 }} 个下级</small></button><small>{{ row.code }}</small></div></div></template></el-table-column>
      <el-table-column label="层级" width="110"><template #default="{row}"><el-tag effect="plain">{{ row.level_label || row.level }}</el-tag></template></el-table-column>
      <el-table-column label="分数额度" min-width="150" align="right"><template #default="{row}"><div>{{ row.credit_limit || '0.00' }}</div><small class="balance-hint">可用 {{ row.balance || '0.00' }}</small></template></el-table-column>
      <el-table-column label="占成比例" min-width="120" align="right"><template #default="{row}">{{ Number(row.share_rate || 0).toFixed(4) }}%</template></el-table-column>
      <el-table-column label="最后登录时间" min-width="170"><template #default="{row}">{{ row.last_login_at || '-' }}</template></el-table-column>
      <el-table-column label="登录地点" min-width="180"><template #default="{row}">{{ row.last_login_location || '-' }}</template></el-table-column>
      <el-table-column label="登录 IP" min-width="150"><template #default="{row}">{{ row.last_login_ip || '-' }}</template></el-table-column>
      <el-table-column label="状态" width="90"><template #default="{row}"><el-tag :type="row.status===1?'success':'info'">{{ row.status===1?'启用':'停用' }}</el-tag></template></el-table-column>
      <el-table-column label="操作" fixed="right" width="250"><template #default="{row}"><div class="row-actions"><el-button v-if="row.level==='director'&&row.parent_id===0" link type="primary" :icon="Wallet" @click="openDirectorConfig(row)">分数占成</el-button><el-button link type="primary" @click="openEdit(row)">编辑</el-button><el-button link type="danger" @click="removeNode(row)">删除</el-button></div></template></el-table-column>
    </el-table>

    <el-drawer v-model="nodeDrawer" :title="editingNodeId ? `编辑${nodeForm.level === 'director' ? '总监' : '组织'}` : '新增总监'" size="560px"><el-form label-width="110px"><el-form-item label="组织层级"><el-input :model-value="catalog.levels.find(item=>item.value===nodeForm.level)?.label || nodeForm.level" disabled/></el-form-item><el-form-item :label="nodeForm.level === 'director' ? '总监名称' : '组织名称'"><el-input v-model="nodeForm.name" maxlength="120"/></el-form-item><el-form-item label="独立分数"><el-input-number v-model="nodeForm.credit_limit" :min="0" :precision="2" controls-position="right" style="width:100%" :disabled="Boolean(editingNodeId)"/><div v-if="editingNodeId && nodeForm.level === 'director'" class="dialog-tip">已存在的总监请使用列表中的“分数占成”修改独立分数。</div></el-form-item><template v-if="!editingNodeId"><el-divider content-position="left">总监登录账号</el-divider><el-form-item label="登录账号"><el-input v-model="nodeForm.username" maxlength="80" autocomplete="off"/></el-form-item><el-form-item label="显示名称"><el-input v-model="nodeForm.display_name" maxlength="120" placeholder="默认使用总监名称"/></el-form-item><el-form-item label="登录密码"><el-input v-model="nodeForm.password" type="password" show-password maxlength="128" autocomplete="new-password" placeholder="至少6位，包含字母和数字"/></el-form-item><el-form-item label="手机号"><el-input v-model="nodeForm.phone" maxlength="30"/></el-form-item></template><el-form-item label="权限来源"><div class="dialog-tip">路由和按钮权限由当前站点的“路由权限”页面按层级统一配置，此处不对单独总监设置。</div></el-form-item><el-form-item label="状态"><el-switch v-model="nodeForm.status" :active-value="1" :inactive-value="0"/></el-form-item></el-form><template #footer><el-button @click="nodeDrawer=false">取消</el-button><el-button type="primary" @click="saveNode">保存</el-button></template></el-drawer>
    <el-dialog v-model="directorConfigDialog" title="总监 · 分数占成" width="480px"><el-form label-width="130px"><el-form-item label="总监"><el-input :model-value="directorConfig.name" disabled/></el-form-item><el-form-item label="总监独立分数"><el-input-number v-model="directorConfig.credit_limit" :min="0" :precision="2" controls-position="right" style="width:100%"/></el-form-item><el-form-item label="下级最高占成"><el-input-number v-model="directorConfig.max_share_rate" :min="0" :max="siteMaxShareRate" :precision="4" controls-position="right" style="width:100%"/><div class="dialog-tip">保存后同步当前总监所有直属下级的最高占成；其他总监的分数和占成不会变化。</div></el-form-item></el-form><template #footer><el-button @click="directorConfigDialog=false">取消</el-button><el-button type="primary" @click="saveDirectorConfig">保存</el-button></template></el-dialog>
  </div>
</template>

<style scoped>
.organization-page{min-height:100%;padding:22px;background:#fff;border-radius:8px}.organization-toolbar{display:flex;align-items:center;gap:12px;margin-bottom:18px}.organization-toolbar>div{margin-right:auto}.organization-toolbar h2{margin:0;color:#27334a;font-size:20px}.organization-toolbar p{margin:6px 0 0;color:#8993a5;font-size:13px}.organization-summary{display:grid;grid-template-columns:180px 220px minmax(220px,1fr);gap:12px;margin-bottom:16px}.organization-summary>div{display:flex;min-height:72px;flex-direction:column;justify-content:center;padding:12px 16px;border:1px solid #e7ebf2;border-radius:6px;background:#f8fafc}.organization-summary span{color:#8993a5;font-size:12px}.organization-summary b{margin-top:4px;color:#2563eb;font-size:24px}.organization-summary strong{margin-top:7px;color:#27334a}.organization-breadcrumb{display:flex;align-items:center;gap:8px;margin-bottom:14px;padding:10px 12px;border:1px solid #e7ebf2;border-radius:5px;background:#fafbfc}.organization-breadcrumb span{display:flex;align-items:center;gap:8px}.organization-breadcrumb i{color:#a3acba;font-style:normal}.organization-breadcrumb button,.org-drill-link{border:0;padding:0;background:none;color:#1769aa;cursor:pointer}.organization-breadcrumb button.current{color:#27334a;font-weight:700}.org-name{display:flex;align-items:center;gap:10px}.org-name>div{display:flex;flex-direction:column}.org-name small{margin-top:2px;color:#98a2b3}.org-drill-link{display:flex;align-items:center;gap:8px;font:inherit;font-weight:700;text-align:left}.org-drill-link small{margin:0;padding:1px 5px;border-radius:8px;background:#eef3ff;color:#6479a6;font-size:11px;font-weight:400}.org-level-dot{display:grid;width:28px;height:28px;place-items:center;border-radius:4px;background:#e8efff;color:#366ef4;font-size:12px;font-weight:700}.permission-summary{color:#667085}.balance-hint,.dialog-tip{display:block;color:#8993a5;font-size:12px;line-height:1.6}.row-actions{display:flex;gap:8px}
</style>
