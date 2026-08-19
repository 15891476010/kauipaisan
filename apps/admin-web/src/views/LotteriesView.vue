<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { createLottery, deleteLottery, listLotteries, updateLottery, type Lottery } from '../api/admin'
import { useRouter } from 'vue-router'

const router=useRouter()
const loading = ref(false); const rows = ref<Lottery[]>([]); const drawer = ref(false); const editing = ref<Lottery | null>(null)
const form = ref({ name: '', code: '', status: 1, sort: 0 })
async function load() { loading.value = true; try { const lotteries = await listLotteries({ page: 1, page_size: 100 }); rows.value = lotteries.data.list } catch (e) { ElMessage.error(e instanceof Error ? e.message : '彩票列表加载失败') } finally { loading.value = false } }
function openCreate() { editing.value = null; form.value = { name: '', code: '', status: 1, sort: 0 }; drawer.value = true }
function openEdit(row: Lottery) { editing.value = row; form.value = { name: row.name, code: row.code, status: row.status, sort: row.sort }; drawer.value = true }
async function save() { try { if (!form.value.name.trim() || !form.value.code.trim()) return ElMessage.warning('请填写彩票名称和编码'); if (editing.value?.id) await updateLottery(editing.value.id, form.value); else await createLottery(form.value); drawer.value = false; ElMessage.success('彩票配置已保存'); await load() } catch (e) { ElMessage.error(e instanceof Error ? e.message : '保存失败') } }
async function remove(row: Lottery) { try { await ElMessageBox.confirm(`确定删除“${row.name}”吗？删除后所有站点都不可见。`, '删除彩票', { type: 'warning' }); await deleteLottery(row.id); ElMessage.success('彩票已删除'); await load() } catch (e) { if (e !== 'cancel') ElMessage.error(e instanceof Error ? e.message : '删除失败') } }
onMounted(load)
</script>

<template>
  <div class="lotteries-page">
    <div class="page-heading"><div><h2>彩票列表</h2><p>维护全平台彩票资料；站点可见彩票请在代理中心编辑对应站点。</p></div><el-button type="primary" @click="openCreate">新增彩票</el-button></div>
    <el-card shadow="never" class="lottery-card"><el-table v-loading="loading" :data="rows" stripe><el-table-column prop="name" label="彩票名称" min-width="180"/><el-table-column prop="code" label="编码" min-width="140"/><el-table-column prop="sort" label="排序" width="90"/><el-table-column label="状态" width="100"><template #default="{ row }"><el-tag :type="row.status ? 'success' : 'info'">{{ row.status ? '启用' : '停用' }}</el-tag></template></el-table-column><el-table-column label="操作" width="330" fixed="right"><template #default="{ row }"><el-button link type="primary" @click="router.push({name:'lottery-history',params:{id:row.id},query:{name:row.name}})">开奖历史</el-button><el-button link type="primary" @click="router.push({name:'lottery-odds',params:{id:row.id},query:{name:row.name}})">赔率详情</el-button><el-button link type="primary" @click="openEdit(row)">编辑</el-button><el-button link type="danger" @click="remove(row)">删除</el-button></template></el-table-column></el-table></el-card>
    <el-drawer v-model="drawer" :title="editing ? '编辑彩票' : '新增彩票'" size="460px"><el-form label-position="top"><el-form-item label="彩票名称" required><el-input v-model="form.name" placeholder="例如：福彩3D"/></el-form-item><el-form-item label="彩票编码" required><el-input v-model="form.code" :disabled="!!editing" placeholder="例如：fc3d"/></el-form-item><el-form-item label="排序"><el-input-number v-model="form.sort" :min="0" :max="9999"/></el-form-item><el-form-item label="状态"><el-switch v-model="form.status" :active-value="1" :inactive-value="0" active-text="启用" inactive-text="停用"/></el-form-item></el-form><template #footer><el-button @click="drawer = false">取消</el-button><el-button type="primary" @click="save">保存</el-button></template></el-drawer>
  </div>
</template>

<style scoped>
.lotteries-page { min-height: 100%; padding: 22px; background: #fff; }.page-heading { display:flex; align-items:center; justify-content:space-between; margin-bottom:18px; }.page-heading h2 { margin:0 0 7px; color:#25314a; font-size:20px; }.page-heading p { margin:0; color:#8490a5; font-size:13px; }.lottery-card { border:1px solid #e8edf4; }
</style>
