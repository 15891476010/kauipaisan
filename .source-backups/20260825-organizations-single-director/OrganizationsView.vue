<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ArrowLeft, Plus, Refresh, UserFilled, Wallet } from '@element-plus/icons-vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  createOrganization, createOrganizationAccount, deleteOrganization, deleteOrganizationAccount,
  getSiteOrganizations, updateOrganization, updateOrganizationAccount, setDirectorCreditShare,
  type OrganizationAccount, type OrganizationCatalog, type OrganizationLevel, type OrganizationNode,
} from '../api/admin'

const route=useRoute()
const router=useRouter()
const siteId=Number(route.params.siteId)
const siteName=ref(String(route.query.name||'当前站点'))
const loading=ref(false)
const nodes=ref<OrganizationNode[]>([])
const accounts=ref<OrganizationAccount[]>([])
const catalog=ref<OrganizationCatalog>({levels:[],permissions:[]})
const selectedId=ref<number|null>(null)
const nodeDrawer=ref(false)
const accountDrawer=ref(false)
const directorConfigDialog=ref(false)
const siteMaxShareRate=ref(100)
const directorConfig=reactive({id:0,name:'',credit_limit:0,max_share_rate:100})
const credentialDialog=ref(false)
const credential=reactive({username:'',initial_password:''})
const editingNodeId=ref<number|null>(null)
const editingAccountId=ref<number|null>(null)
const nodeForm=reactive({parent_id:0,level:'director' as OrganizationLevel,name:'',credit_limit:0,permissions:[] as string[],status:1})
const accountForm=reactive({organization_id:0,username:'',display_name:'',phone:'',password:'',status:1})
const selectedNode=computed(()=>nodes.value.find(item=>item.id===selectedId.value)||null)
const selectedAccounts=computed(()=>accounts.value.filter(item=>item.organization_id===selectedId.value))
const treeNodes=computed(()=>{const map=new Map<number,OrganizationNode>();for(const row of nodes.value)map.set(row.id,{...row,children:[]});const roots:OrganizationNode[]=[];for(const row of nodes.value){const current=map.get(row.id)!;if(row.parent_id>0&&map.has(row.parent_id))(map.get(row.parent_id)!.children as OrganizationNode[]).push(current);else roots.push(current)}return roots})

async function load(){loading.value=true;try{const response=await getSiteOrganizations(siteId);siteName.value=response.data.site.name;siteMaxShareRate.value=Number(response.data.site_max_share_rate||100);nodes.value=response.data.nodes;accounts.value=response.data.accounts;catalog.value=response.data.catalog;if(!selectedId.value||!nodes.value.some(item=>item.id===selectedId.value))selectedId.value=nodes.value[0]?.id||null}catch(error){ElMessage.error(error instanceof Error?error.message:'组织架构加载失败')}finally{loading.value=false}}
function parentName(row:OrganizationNode){return nodes.value.find(item=>item.id===row.parent_id)?.name||'站点根节点'}
function permissionsFor(parentId:number){const parent=nodes.value.find(item=>item.id===parentId);return parent?.permissions.includes('*')?catalog.value.permissions.map(item=>item.code):(parent?.permissions||catalog.value.permissions.map(item=>item.code))}
function openCreate(parent?:OrganizationNode){editingNodeId.value=null;const level=(parent?.next_level||'director') as OrganizationLevel;Object.assign(nodeForm,{parent_id:parent?.id||0,level,name:'',credit_limit:Number(parent?.credit_limit||0),permissions:permissionsFor(parent?.id||0),status:1});nodeDrawer.value=true}
function openEdit(row:OrganizationNode){editingNodeId.value=row.id;Object.assign(nodeForm,{parent_id:row.parent_id,level:row.level,name:row.name,credit_limit:Number(row.credit_limit),permissions:row.permissions.includes('*')?catalog.value.permissions.map(item=>item.code):[...row.permissions],status:row.status});nodeDrawer.value=true}
function openDirectorConfig(row:OrganizationNode){if(row.level!=='director'||row.parent_id!==0)return;const children=nodes.value.filter(item=>item.parent_id===row.id);const maxRates=children.map(item=>Number(item.max_share_rate||siteMaxShareRate.value));Object.assign(directorConfig,{id:row.id,name:row.name,credit_limit:Number(row.credit_limit||0),max_share_rate:maxRates.length?Math.min(...maxRates):siteMaxShareRate.value});directorConfigDialog.value=true}
async function saveDirectorConfig(){try{await setDirectorCreditShare(directorConfig.id,{credit_limit:directorConfig.credit_limit,max_share_rate:directorConfig.max_share_rate});directorConfigDialog.value=false;ElMessage.success('总监分数和下级最高占成已更新');await load()}catch(error){ElMessage.error(error instanceof Error?error.message:'总监配置保存失败')}}
async function saveNode(){if(!nodeForm.name.trim())return ElMessage.warning('请输入组织名称');const payload={...nodeForm,name:nodeForm.name.trim()};try{if(editingNodeId.value)await updateOrganization(editingNodeId.value,payload);else await createOrganization(siteId,payload);nodeDrawer.value=false;ElMessage.success(editingNodeId.value?'组织已更新':'组织创建成功');await load()}catch(error){ElMessage.error(error instanceof Error?error.message:'保存失败')}}
async function removeNode(row:OrganizationNode){await ElMessageBox.confirm(`确定删除“${row.name}”吗？只能删除没有下级的组织。`,'删除组织',{type:'warning'});try{await deleteOrganization(row.id);ElMessage.success('组织已删除');await load()}catch(error){ElMessage.error(error instanceof Error?error.message:'删除失败')}}
function openCreateAccount(row:OrganizationNode){editingAccountId.value=null;Object.assign(accountForm,{organization_id:row.id,username:'',display_name:'',phone:'',password:'',status:1});selectedId.value=row.id;accountDrawer.value=true}
function organizationForAccount(id:number){return nodes.value.find(item=>item.id===id)||null}
function openDirectorConfigForAccount(account:OrganizationAccount){const node=organizationForAccount(account.organization_id);if(node)openDirectorConfig(node)}
function openEditAccount(row:OrganizationAccount){editingAccountId.value=row.id;Object.assign(accountForm,{organization_id:row.organization_id,username:row.username,display_name:row.display_name,phone:row.phone||'',password:'',status:row.status});selectedId.value=row.organization_id;accountDrawer.value=true}
async function saveAccount(){if(!accountForm.username.trim())return ElMessage.warning('请输入管理员账号');if(accountForm.password!==''&&accountForm.password.length<6)return ElMessage.warning('登录密码至少 6 位');const payload={organization_id:accountForm.organization_id,username:accountForm.username.trim(),display_name:accountForm.display_name.trim(),phone:accountForm.phone,password:accountForm.password,status:accountForm.status};try{if(editingAccountId.value){await updateOrganizationAccount(editingAccountId.value,payload);ElMessage.success('管理员已更新')}else{const response=await createOrganizationAccount(accountForm.organization_id,payload);Object.assign(credential,response.data);credentialDialog.value=true}accountDrawer.value=false;await load()}catch(error){ElMessage.error(error instanceof Error?error.message:'保存失败')}}
async function copyCredential(){try{await navigator.clipboard.writeText(`账号：${credential.username}\n密码：${credential.initial_password}`);ElMessage.success('账号和密码已复制')}catch{ElMessage.error('复制失败，请手动复制')}}
async function removeAccount(row:OrganizationAccount){await ElMessageBox.confirm(`确定删除管理员“${row.username}”吗？`,'删除管理员',{type:'warning'});await deleteOrganizationAccount(row.id);ElMessage.success('管理员已删除');await load()}
function selectNode(row:OrganizationNode|undefined){selectedId.value=row?.id||null}
onMounted(()=>void load())
</script>

<template>
  <div class="organization-page">
    <header class="organization-toolbar">
      <div><h2>{{ siteName }} · 组织架构</h2><p>站点是配置和数据边界；路由和按钮权限请在“站点路由权限”页面按层级统一配置。</p></div>
      <el-button :icon="Refresh" @click="load">刷新</el-button>
      <el-button type="primary" :icon="Plus" @click="openCreate()">新增总监</el-button>
      <el-button :icon="ArrowLeft" @click="router.push('/agent-center')">返回代理中心</el-button>
    </header>
    <div class="organization-summary"><div><span>组织数量</span><b>{{ nodes.length }}</b></div><div><span>管理员数量</span><b>{{ accounts.length }}</b></div><div><span>总监额度合计</span><strong>{{ nodes.reduce((sum,row)=>sum+Number(row.parent_id===0?row.credit_limit:0),0).toFixed(2) }}</strong></div><div><span>当前站点</span><strong>{{ siteName }}</strong></div></div>
    <el-table v-loading="loading" :data="treeNodes" border stripe row-key="id" :tree-props="{ children: 'children' }" highlight-current-row @current-change="selectNode">
      <el-table-column label="组织名称" min-width="240"><template #default="{row}"><div class="org-name"><span class="org-level-dot">{{ row.depth }}</span><div><b>{{ row.name }}</b></div></div></template></el-table-column>
      <el-table-column prop="level_label" label="层级" width="100"><template #default="{row}"><el-tag effect="plain">{{ row.level_label }}</el-tag></template></el-table-column>
      <el-table-column label="直属上级" min-width="150"><template #default="{row}">{{ parentName(row) }}</template></el-table-column>
      <el-table-column label="分数额度" min-width="150" align="right"><template #default="{row}"><div>{{ row.credit_limit }}</div><small class="balance-hint">可用 {{ row.balance || '0.00' }}</small></template></el-table-column>
      <el-table-column label="直属下级占成" min-width="130" align="right"><template #default="{row}"><span v-if="row.parent_id">{{ row.share_rate || '0.0000' }}%</span><span v-else class="balance-hint">按直属下级设置</span></template></el-table-column>
      <el-table-column label="层级权限" min-width="250"><template #default><span class="permission-summary">由站点路由权限按层级统一配置</span></template></el-table-column>
      <el-table-column label="管理员" width="90" align="center"><template #default="{row}">{{ accounts.filter(item=>item.organization_id===row.id).length }}</template></el-table-column>
      <el-table-column label="状态" width="84"><template #default="{row}"><el-tag :type="row.status===1?'success':'info'">{{ row.status===1?'启用':'停用' }}</el-tag></template></el-table-column>
      <el-table-column label="操作" fixed="right" width="300"><template #default="{row}"><div class="row-actions"><el-button v-if="row.next_level" link type="primary" @click.stop="openCreate(row)">新增下级</el-button><el-button link type="primary" @click.stop="openCreateAccount(row)">管理员</el-button><el-button link type="primary" @click.stop="openEdit(row)">编辑</el-button><el-button v-if="row.parent_id" link type="danger" @click.stop="removeNode(row)">删除</el-button></div></template></el-table-column>
    </el-table>
    <section v-if="selectedNode" class="account-section">
      <header><div><h3>{{ selectedNode.name }} · 管理员</h3><span>管理员权限跟随所属组织和站点层级配置，同一组织下的管理员权限一致。</span></div><el-button type="primary" plain :icon="UserFilled" @click="openCreateAccount(selectedNode)">新增管理员</el-button></header>
      <el-table :data="selectedAccounts" border empty-text="当前组织暂无管理员"><el-table-column prop="username" label="账号" min-width="130"/><el-table-column prop="display_name" label="姓名" min-width="120"/><el-table-column label="权限来源" min-width="240"><template #default>跟随所属组织及站点层级配置</template></el-table-column><el-table-column label="在线" width="80"><template #default="{row}"><el-tag :type="row.online===1?'success':'info'">{{ row.online===1?'在线':'离线' }}</el-tag></template></el-table-column><el-table-column prop="last_login_at" label="最后登录" min-width="160"/><el-table-column prop="last_login_location" label="最后登录地点" min-width="180"/><el-table-column prop="last_login_ip" label="登录 IP" min-width="180"/><el-table-column label="操作" width="220"><template #default="{row}"><el-button v-if="organizationForAccount(row.organization_id)?.level==='director'&&organizationForAccount(row.organization_id)?.parent_id===0" link type="primary" :icon="Wallet" @click="openDirectorConfigForAccount(row)">分数占成</el-button><el-button link type="primary" @click="openEditAccount(row)">编辑</el-button><el-button link type="danger" @click="removeAccount(row)">删除</el-button></template></el-table-column></el-table>
    </section>

    <el-drawer v-model="nodeDrawer" :title="editingNodeId?'编辑组织':'新增组织'" size="560px"><el-form label-width="100px"><el-form-item label="所属层级"><el-input :model-value="catalog.levels.find(item=>item.value===nodeForm.level)?.label||nodeForm.level" disabled/></el-form-item><el-form-item label="组织名称"><el-input v-model="nodeForm.name" maxlength="120"/></el-form-item><el-form-item label="分数额度"><el-input-number v-model="nodeForm.credit_limit" :min="0" :precision="2" controls-position="right" style="width:100%" :disabled="Boolean(editingNodeId)&&nodeForm.level==='director'"/><div v-if="editingNodeId&&nodeForm.level==='director'" class="dialog-tip">根总监请使用“设置分数”操作修改独立额度。</div></el-form-item><el-form-item label="权限来源"><div class="dialog-tip">路由和按钮权限由当前站点的“路由权限”页面按层级统一配置，此处不再单独设置。</div></el-form-item><el-form-item label="状态"><el-switch v-model="nodeForm.status" :active-value="1" :inactive-value="0"/></el-form-item></el-form><template #footer><el-button @click="nodeDrawer=false">取消</el-button><el-button type="primary" @click="saveNode">保存</el-button></template></el-drawer>
    <el-drawer v-model="accountDrawer" :title="editingAccountId?'编辑组织管理员':'新增组织管理员'" size="560px"><el-form label-width="100px"><el-form-item label="管理员账号"><el-input v-model="accountForm.username" maxlength="80"/></el-form-item><el-form-item label="姓名"><el-input v-model="accountForm.display_name" maxlength="120"/></el-form-item><el-form-item label="手机号"><el-input v-model="accountForm.phone" maxlength="30"/></el-form-item><el-form-item :label="editingAccountId?'新密码':'登录密码'"><el-input v-model="accountForm.password" type="password" show-password :placeholder="editingAccountId?'留空不修改':'可留空，系统自动生成'"/></el-form-item><el-form-item label="权限说明"><div class="dialog-tip account-permission-note">管理员权限跟随所属组织和站点层级权限。请在当前站点的“路由权限”页面统一配置，不需要对单独账号勾选。</div></el-form-item><el-form-item label="状态"><el-switch v-model="accountForm.status" :active-value="1" :inactive-value="0"/></el-form-item></el-form><template #footer><el-button @click="accountDrawer=false">取消</el-button><el-button type="primary" @click="saveAccount">保存</el-button></template></el-drawer>
    <el-dialog v-model="directorConfigDialog" title="总监 · 分数占成" width="480px"><el-form label-width="130px"><el-form-item label="总监"><el-input :model-value="directorConfig.name" disabled/></el-form-item><el-form-item label="总监独立分数"><el-input-number v-model="directorConfig.credit_limit" :min="0" :precision="2" controls-position="right" style="width:100%"/></el-form-item><el-form-item label="下级最高占成"><el-input-number v-model="directorConfig.max_share_rate" :min="0" :max="siteMaxShareRate" :precision="4" controls-position="right" style="width:100%"/><div class="dialog-tip">保存后同步当前总监所有直属下级的最高占成；其他总监的分数和占成不会变化。</div></el-form-item></el-form><template #footer><el-button @click="directorConfigDialog=false">取消</el-button><el-button type="primary" @click="saveDirectorConfig">保存</el-button></template></el-dialog>
    <el-dialog v-model="credentialDialog" title="管理员创建成功" width="480px" align-center :close-on-click-modal="false"><div class="credential-dialog"><p>请立即保存以下登录信息，关闭后初始密码将不再显示。</p><dl><div><dt>用户名</dt><dd>{{ credential.username }}</dd></div><div><dt>初始密码</dt><dd>{{ credential.initial_password }}</dd></div></dl><el-button type="primary" @click="copyCredential">复制账号和密码</el-button><small>该账号首次登录并同意责任声明后，必须修改密码才能进入系统。</small></div><template #footer><el-button type="primary" @click="credentialDialog=false">我已保存</el-button></template></el-dialog>
  </div>
</template>

<style scoped>
.account-permission-note{max-width:420px}
.organization-page{min-height:100%;padding:22px;background:#fff;border-radius:8px}.organization-toolbar{display:flex;align-items:center;gap:12px;margin-bottom:18px}.organization-toolbar>div{margin-right:auto}.organization-toolbar h2{margin:0;color:#27334a;font-size:20px}.organization-toolbar p{margin:6px 0 0;color:#8993a5;font-size:13px}.organization-summary{display:grid;grid-template-columns:160px 160px minmax(220px,1fr);gap:12px;margin-bottom:16px}.organization-summary>div{display:flex;min-height:72px;flex-direction:column;justify-content:center;padding:12px 16px;border:1px solid #e7ebf2;border-radius:6px;background:#f8fafc}.organization-summary span{color:#8993a5;font-size:12px}.organization-summary b{margin-top:4px;color:#2563eb;font-size:24px}.organization-summary strong{margin-top:7px;color:#27334a}.org-name{display:flex;align-items:center;gap:10px}.org-name>div{display:flex;flex-direction:column}.org-name small{margin-top:2px;color:#98a2b3}.org-level-dot{display:grid;width:24px;height:24px;place-items:center;border-radius:4px;background:#e8efff;color:#366ef4;font-size:12px;font-weight:700}.permission-summary{color:#667085}.balance-hint,.dialog-tip{display:block;color:#8993a5;font-size:12px;line-height:1.6}.account-section{margin-top:20px;padding-top:18px;border-top:1px solid #edf0f5}.account-section>header{display:flex;align-items:center;margin-bottom:12px}.account-section>header>div{margin-right:auto}.account-section h3{margin:0 0 4px;color:#27334a}.account-section header span{color:#8993a5;font-size:13px}.permission-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));width:100%;gap:4px 12px}.permission-grid :deep(.el-checkbox){margin-right:0}.row-actions{gap:8px}
.credential-dialog>p{margin:0 0 14px;color:#667085}.credential-dialog dl{margin:0 0 16px;border:1px solid #e4e7ec;border-radius:6px;overflow:hidden}.credential-dialog dl>div{display:grid;grid-template-columns:100px 1fr;min-height:46px;align-items:center}.credential-dialog dl>div+div{border-top:1px solid #e4e7ec}.credential-dialog dt{height:100%;display:flex;align-items:center;padding:0 14px;background:#f8fafc;color:#667085}.credential-dialog dd{margin:0;padding:0 16px;font-family:Consolas,monospace;font-size:16px;font-weight:700;user-select:all}.credential-dialog small{display:block;margin-top:14px;color:#b54708;line-height:1.6}
</style>
