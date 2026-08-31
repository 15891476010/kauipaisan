<script setup lang="ts">
import { computed, onMounted, ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import { ElMessage, ElMessageBox } from "element-plus";
import {
  createLotteryOdds,
  createLotteryOddsCategory,
  deleteLotteryOdds,
  deleteLotteryOddsCategory,
  listLotteryOdds,
  listLotteries,
  copyLotteryOdds,
  updateLotteryOdds,
  updateLotteryOddsCategory,
  type LotteryOdds,
  type LotteryOddsCategory,
  type Lottery,
} from "../api/admin";

type TreeRow =
  | (LotteryOddsCategory & { node_type: "category"; tree_key: string })
  | (LotteryOdds & { node_type: "play"; tree_key: string });
type PlayForm = {
  category_id: number;
  name: string;
  min_bet: string;
  odds_limit: string;
  single_bet_limit: string;
  single_item_limit: string;
  odds: string;
  offline_rebate: string;
  status: number;
  sort: number;
};
const route = useRoute();
const router = useRouter();
const id = Number(route.params.id);
const name = String(route.query.name || "彩票");
const categories = ref<LotteryOddsCategory[]>([]);
const total = ref(0);
const categoryTotal = ref(0);
const page = ref(1);
const pageSize = ref(10);
const loading = ref(false);
const sourceLotteries = ref<Lottery[]>([]);
const sourceLotteryId = ref<number | null>(null);
const drawer = ref(false);
const drawerMode = ref<"category" | "play">("category");
const editingCategory = ref<LotteryOddsCategory | null>(null);
const editingPlay = ref<LotteryOdds | null>(null);
const categoryForm = ref({ name: "", is_playable: 0, min_bet: "", odds_limit: "", single_bet_limit: "", single_item_limit: "", odds: "", offline_rebate: "", status: 1, sort: 0 });
const emptyPlayForm = (categoryId = 0): PlayForm => ({
  category_id: categoryId,
  name: "",
  min_bet: "",
  odds_limit: "",
  single_bet_limit: "",
  single_item_limit: "",
  odds: "",
  offline_rebate: "",
  status: 1,
  sort: 0,
});
const playForm = ref<PlayForm>(emptyPlayForm());
const treeRows = computed<TreeRow[]>(
  () =>
    categories.value.map((category) => ({
      ...category,
      node_type: "category",
      tree_key: `category-${category.id}`,
      children: category.children.map((play) => ({
        ...play,
        node_type: "play",
        tree_key: `play-${play.id}`,
      })),
    })) as TreeRow[],
);
const drawerTitle = computed(() =>
  drawerMode.value === "category"
    ? editingCategory.value
      ? "编辑类别"
      : "新增类别"
    : editingPlay.value
      ? "编辑玩法赔率"
      : "新增玩法赔率",
);
const numericKeys: Array<
  keyof Pick<
    PlayForm,
    | "min_bet"
    | "odds_limit"
    | "single_bet_limit"
    | "single_item_limit"
    | "odds"
    | "offline_rebate"
  >
> = [
  "min_bet",
  "odds_limit",
  "single_bet_limit",
  "single_item_limit",
  "odds",
  "offline_rebate",
];
function optionalValue(value: string | null) {
  return value === null || value === "" ? "" : String(value);
}
function displayValue(value: string | null) {
  return value === null || value === "" ? "未设置" : String(Number(value));
}
async function load() {
  loading.value = true;
  try {
    const result = await listLotteryOdds(id, {
      page: page.value,
      page_size: pageSize.value,
    });
    categories.value = result.data.categories;
    total.value = result.data.total;
    categoryTotal.value = result.data.category_total ?? categories.value.length;
  } catch (error) {
    ElMessage.error(error instanceof Error ? error.message : "赔率加载失败");
  } finally {
    loading.value = false;
  }
}
async function loadSources() {
  try { sourceLotteries.value = (await listLotteries({ page: 1, page_size: 100 })).data.list.filter((item) => item.id !== id); } catch { sourceLotteries.value = []; }
}
async function copyFromSource() {
  if (!sourceLotteryId.value) return ElMessage.warning("请选择赔率来源彩种");
  try { await ElMessageBox.confirm("复制后会替换当前彩种现有赔率，确定继续吗？", "复制赔率", { type: "warning" }); await copyLotteryOdds(id, sourceLotteryId.value, true); ElMessage.success("赔率复制成功"); await load(); } catch (error) { if (error !== "cancel") ElMessage.error(error instanceof Error ? error.message : "复制失败"); }
}
function openCreateCategory() {
  drawerMode.value = "category";
  editingCategory.value = null;
  categoryForm.value = { name: "", is_playable: 0, min_bet: "", odds_limit: "", single_bet_limit: "", single_item_limit: "", odds: "", offline_rebate: "", status: 1, sort: categories.value.length };
  drawer.value = true;
}
function openEditCategory(category: LotteryOddsCategory) {
  drawerMode.value = "category";
  editingCategory.value = category;
  categoryForm.value = { name: category.name, is_playable: category.is_playable, min_bet: optionalValue(category.min_bet), odds_limit: optionalValue(category.odds_limit), single_bet_limit: optionalValue(category.single_bet_limit), single_item_limit: optionalValue(category.single_item_limit), odds: optionalValue(category.odds), offline_rebate: optionalValue(category.offline_rebate), status: category.status, sort: category.sort };
  drawer.value = true;
}
function openCreatePlay(category: LotteryOddsCategory) {
  drawerMode.value = "play";
  editingPlay.value = null;
  playForm.value = emptyPlayForm(category.id);
  playForm.value.sort = category.children.length;
  drawer.value = true;
}
function openEditPlay(play: LotteryOdds) {
  drawerMode.value = "play";
  editingPlay.value = play;
  playForm.value = {
    category_id: play.category_id,
    name: play.name,
    min_bet: optionalValue(play.min_bet),
    odds_limit: optionalValue(play.odds_limit),
    single_bet_limit: optionalValue(play.single_bet_limit),
    single_item_limit: optionalValue(play.single_item_limit),
    odds: optionalValue(play.odds),
    offline_rebate: optionalValue(play.offline_rebate),
    status: play.status,
    sort: play.sort,
  };
  drawer.value = true;
}
async function save() {
  try {
    if (drawerMode.value === "category") {
      if (!categoryForm.value.name.trim())
        return ElMessage.warning("请输入类别名称");
      if (editingCategory.value)
        await updateLotteryOddsCategory(
          id,
          editingCategory.value.id,
          categoryForm.value,
        );
      else await createLotteryOddsCategory(id, categoryForm.value);
    } else {
      if (!playForm.value.category_id || !playForm.value.name.trim())
        return ElMessage.warning("请选择类别并输入玩法名称");
      if (
        numericKeys.some(
          (key) =>
            playForm.value[key] !== "" &&
            (!Number.isFinite(Number(playForm.value[key])) ||
              Number(playForm.value[key]) < 0),
        )
      )
        return ElMessage.warning("赔率和限额只能填写非负数字或留空");
      if (
        playForm.value.offline_rebate !== "" &&
        Number(playForm.value.offline_rebate) > 0.1
      )
        return ElMessage.warning("明水统一由站点配置（0.085），玩法不再单独设置");
      const payload = {
        ...playForm.value,
        offline_rebate: "0.0000",
        ...Object.fromEntries(
          numericKeys.map((key) => [
            key,
            playForm.value[key] === "" ? null : playForm.value[key],
          ]),
        ),
      };
      if (editingPlay.value)
        await updateLotteryOdds(id, editingPlay.value.id, payload);
      else
        await createLotteryOdds(
          id,
          payload as Omit<LotteryOdds, "id" | "lottery_id" | "category">,
        );
    }
    drawer.value = false;
    ElMessage.success("赔率设置已保存");
    await load();
  } catch (error) {
    ElMessage.error(error instanceof Error ? error.message : "保存失败");
  }
}
async function removeCategory(category: LotteryOddsCategory) {
  try {
    await ElMessageBox.confirm(
      `删除“${category.name}”会同时删除其下 ${category.children.length} 个玩法，确定继续吗？`,
      "删除类别",
      { type: "warning" },
    );
    await deleteLotteryOddsCategory(id, category.id);
    ElMessage.success("类别已删除");
    await load();
  } catch (error) {
    if (error !== "cancel")
      ElMessage.error(error instanceof Error ? error.message : "删除失败");
  }
}
async function removePlay(play: LotteryOdds) {
  try {
    await ElMessageBox.confirm(`确定删除玩法“${play.name}”吗？`, "删除玩法", {
      type: "warning",
    });
    await deleteLotteryOdds(id, play.id);
    ElMessage.success("玩法已删除");
    await load();
  } catch (error) {
    if (error !== "cancel")
      ElMessage.error(error instanceof Error ? error.message : "删除失败");
  }
}
onMounted(() => { void load(); void loadSources(); });
</script>

<template>
  <div class="odds-page">
    <div class="heading">
      <div>
        <h2>{{ name }}赔率设置</h2>
        <p>按类别管理玩法赔率，未填写的数值保持为“未设置”。</p>
      </div>
      <div class="heading-actions">
        <el-select v-model="sourceLotteryId" clearable placeholder="从其他彩种复制赔率" style="width:210px"><el-option v-for="item in sourceLotteries" :key="item.id" :label="item.name" :value="item.id" /></el-select><el-button @click="copyFromSource">复制赔率</el-button><el-button @click="router.back()">返回彩票列表</el-button
        ><el-button type="primary" @click="openCreateCategory"
          >新增类别</el-button
        >
      </div>
    </div>
    <div class="tree-summary">
      <strong>{{ categoryTotal }}</strong
      ><span>个类别</span><i /><strong>{{ total }}</strong
      ><span>个玩法</span>
    </div>
    <div class="tree-panel">
      <el-table
        v-loading="loading"
        :data="treeRows"
        row-key="tree_key"
        :default-expand-all="false"
        :tree-props="{ children: 'children' }"
        ><el-table-column label="类别 / 玩法" min-width="190"
          ><template #default="{ row }"
            ><div :class="['node-name', row.node_type]">
              <b>{{ row.name }}</b
              ><el-tag
                size="small"
                :type="row.node_type === 'category' ? 'primary' : 'info'"
                >{{ row.node_type === "category" ? (row.is_playable ? "玩法" : "类别") : "玩法" }}</el-tag
              >
            </div></template
          ></el-table-column
        ><el-table-column label="最小下注" width="105"
          ><template #default="{ row }"
            ><span
              v-if="row.node_type === 'play' || row.is_playable"
              :class="{ unset: row.min_bet == null }"
              >{{ displayValue(row.min_bet) }}</span
            ></template
          ></el-table-column
        ><el-table-column label="赔率上限" width="105"
          ><template #default="{ row }"
            ><span
              v-if="row.node_type === 'play' || row.is_playable"
              :class="{ unset: row.odds_limit == null }"
              >{{ displayValue(row.odds_limit) }}</span
            ></template
          ></el-table-column
        ><el-table-column label="单注上限" width="110"
          ><template #default="{ row }"
            ><span
              v-if="row.node_type === 'play' || row.is_playable"
              :class="{ unset: row.single_bet_limit == null }"
              >{{ displayValue(row.single_bet_limit) }}</span
            ></template
          ></el-table-column
        ><el-table-column label="单项上限" width="110"
          ><template #default="{ row }"
            ><span
              v-if="row.node_type === 'play' || row.is_playable"
              :class="{ unset: row.single_item_limit == null }"
              >{{ displayValue(row.single_item_limit) }}</span
            ></template
          ></el-table-column
        ><el-table-column label="赔率" width="95"
          ><template #default="{ row }"
            ><span
              v-if="row.node_type === 'play' || row.is_playable"
              :class="{ unset: row.odds == null }"
              >{{ displayValue(row.odds) }}</span
            ></template
          ></el-table-column
        ><el-table-column label="明水（统一）" width="105"
          ><template #default="{ row }"
            ><span
              v-if="row.node_type === 'play' || row.is_playable"
              :class="{ unset: row.offline_rebate == null }"
              >{{ displayValue(row.offline_rebate) }}</span
            ></template
          ></el-table-column
        ><el-table-column label="状态" width="82"
          ><template #default="{ row }"
            ><el-tag size="small" :type="row.status ? 'success' : 'info'">{{
              row.status ? "启用" : "停用"
            }}</el-tag></template
          ></el-table-column
        ><el-table-column label="排序" width="70" prop="sort" /><el-table-column
          label="操作"
          width="210"
          fixed="right"
          ><template #default="{ row }"
            ><div v-if="row.node_type === 'category'" class="row-actions">
              <el-button link type="primary" @click="openCreatePlay(row)"
                >新增玩法</el-button
              ><el-button link type="primary" @click="openEditCategory(row)"
                >编辑</el-button
              ><el-button link type="danger" @click="removeCategory(row)"
                >删除</el-button
              >
            </div>
            <div v-else class="row-actions">
              <el-button link type="primary" @click="openEditPlay(row)"
                >编辑玩法</el-button
              ><el-button link type="danger" @click="removePlay(row)"
                >删除</el-button
              >
            </div></template
          ></el-table-column
        ></el-table
      >
      <div v-if="!categories.length && !loading" class="empty">
        暂无赔率类别，请先新增类别。
      </div>
      <div class="pager">
        <el-pagination v-model:current-page="page" v-model:page-size="pageSize" layout="total, sizes, prev, pager, next" :page-sizes="[5, 10, 20, 50]" :total="Math.max(categoryTotal, categories.length)" @current-change="load" @size-change="() => { page = 1; load() }" />
      </div>
    </div>
    <el-drawer v-model="drawer" :title="drawerTitle" size="480px"
      ><el-form v-if="drawerMode === 'category'" label-position="top"
        ><el-form-item label="类别名称" required
          ><el-input
            v-model="categoryForm.name"
            placeholder="例如：一码定位" /></el-form-item
        ><el-form-item label="节点类型"
          ><el-switch v-model="categoryForm.is_playable" :active-value="1" :inactive-value="0" active-text="类别本身就是玩法" inactive-text="类别下挂玩法" /></el-form-item
        ><div v-if="categoryForm.is_playable" class="number-grid"><el-form-item label="最小下注"><el-input v-model="categoryForm.min_bet" type="number" min="0" placeholder="可留空" /></el-form-item><el-form-item label="赔率上限"><el-input v-model="categoryForm.odds_limit" type="number" min="0" placeholder="可留空" /></el-form-item><el-form-item label="单注上限"><el-input v-model="categoryForm.single_bet_limit" type="number" min="0" placeholder="可留空" /></el-form-item><el-form-item label="单项上限"><el-input v-model="categoryForm.single_item_limit" type="number" min="0" placeholder="可留空" /></el-form-item><el-form-item label="赔率"><el-input v-model="categoryForm.odds" type="number" min="0" placeholder="可留空" /></el-form-item><el-form-item label="明水（统一）"><el-input model-value="0.085" disabled /></el-form-item></div
        ><el-form-item label="排序"
          ><el-input-number
            v-model="categoryForm.sort"
            :min="0"
            :max="9999" /></el-form-item
        ><el-form-item label="状态"
          ><el-switch
            v-model="categoryForm.status"
            :active-value="1"
            :inactive-value="0"
            active-text="启用"
            inactive-text="停用" /></el-form-item></el-form
      ><el-form v-else label-position="top"
        ><el-form-item label="所属类别" required
          ><el-select v-model="playForm.category_id" style="width: 100%"
            ><el-option
              v-for="category in categories"
              :key="category.id"
              :label="category.name"
              :value="category.id" /></el-select></el-form-item
        ><el-form-item label="玩法名称" required
          ><el-input v-model="playForm.name" placeholder="例如：百位定位"
        /></el-form-item>
        <div class="number-grid">
          <el-form-item label="最小下注"
            ><el-input
              v-model="playForm.min_bet"
              type="number"
              min="0"
              placeholder="可留空" /></el-form-item
          ><el-form-item label="赔率上限"
            ><el-input
              v-model="playForm.odds_limit"
              type="number"
              min="0"
              placeholder="可留空" /></el-form-item
          ><el-form-item label="单注上限"
            ><el-input
              v-model="playForm.single_bet_limit"
              type="number"
              min="0"
              placeholder="可留空" /></el-form-item
          ><el-form-item label="单项上限"
            ><el-input
              v-model="playForm.single_item_limit"
              type="number"
              min="0"
              placeholder="可留空" /></el-form-item
          ><el-form-item label="赔率"
            ><el-input
              v-model="playForm.odds"
              type="number"
              min="0"
              placeholder="可留空" /></el-form-item
          ><el-form-item label="明水（统一）"
            ><el-input
              model-value="0.085"
              disabled
              type="number"
              min="0"
              max="0.1"
              step="0.001"
              placeholder="可留空"
          /></el-form-item>
        </div>
        <el-form-item label="排序"
          ><el-input-number
            v-model="playForm.sort"
            :min="0"
            :max="9999" /></el-form-item
        ><el-form-item label="状态"
          ><el-switch
            v-model="playForm.status"
            :active-value="1"
            :inactive-value="0"
            active-text="启用"
            inactive-text="停用" /></el-form-item></el-form
      ><template #footer
        ><el-button @click="drawer = false">取消</el-button
        ><el-button type="primary" @click="save">保存</el-button></template
      ></el-drawer
    >
  </div>
</template>

<style scoped>
.odds-page {
  height: 100%;
  padding: 22px;
  background: #fff;
  overflow: auto;
}
.heading {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 14px;
}
.heading h2 {
  margin: 0 0 6px;
  color: #25314a;
  font-size: 20px;
}
.heading p {
  margin: 0;
  color: #8490a5;
  font-size: 13px;
}
.heading-actions {
  display: flex;
  gap: 10px;
}
.tree-summary {
  display: flex;
  height: 34px;
  align-items: center;
  gap: 5px;
  padding: 0 12px;
  border: 1px solid #e1e6ee;
  border-bottom: 0;
  background: #f8fafc;
  color: #6b768a;
  font-size: 13px;
}
.tree-summary strong {
  color: #1677b8;
  font-size: 15px;
}
.tree-summary i {
  width: 1px;
  height: 14px;
  margin: 0 7px;
  background: #ccd3dd;
}
.tree-panel {
  border: 1px solid #e1e6ee;
}
.pager {
  display: flex;
  justify-content: flex-end;
  padding: 14px 12px;
}
.node-name {
  display: flex;
  align-items: center;
  gap: 8px;
}
.node-name b {
  font-weight: 500;
}
.node-name.category b {
  color: #174e78;
  font-weight: 600;
}
.unset {
  color: #a8b0bd;
}
.empty {
  padding: 44px;
  text-align: center;
  color: #9aa5b5;
}
.number-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0 14px;
}
</style>
