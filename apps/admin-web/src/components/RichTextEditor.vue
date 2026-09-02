<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { createEditor, createToolbar } from '@wangeditor/editor'
import type { IDomEditor } from '@wangeditor/editor'
import '@wangeditor/editor/dist/css/style.css'

const props = withDefaults(defineProps<{ modelValue: string; placeholder?: string }>(), { placeholder: '请输入内容' })
const emit = defineEmits<{ 'update:modelValue': [value: string] }>()
const toolbarHost = ref<HTMLElement>()
const editorHost = ref<HTMLElement>()
let editor: IDomEditor | null = null

function mount() {
  if (!editorHost.value || !toolbarHost.value || editor) return
  editor = createEditor({
    selector: editorHost.value,
    html: props.modelValue || '<p><br></p>',
    config: {
      placeholder: props.placeholder,
      onChange: (instance: IDomEditor) => emit('update:modelValue', instance.getHtml()),
    },
    mode: 'default',
  })
  createToolbar({ editor, selector: toolbarHost.value, config: {}, mode: 'default' })
}

watch(() => props.modelValue, (value) => {
  if (!editor || editor.getHtml() === value) return
  editor.setHtml(value || '<p><br></p>')
})

onMounted(async () => { await nextTick(); mount() })
onBeforeUnmount(() => { editor?.destroy(); editor = null })
</script>

<template>
  <div class="rich-editor">
    <div ref="toolbarHost" class="rich-editor-toolbar" />
    <div ref="editorHost" class="rich-editor-content" />
  </div>
</template>

<style scoped>
.rich-editor { display: flex; min-height: 300px; height: 100%; flex-direction: column; border: 1px solid #dcdfe6; }
.rich-editor-toolbar { flex: 0 0 auto; border-bottom: 1px solid #dcdfe6; }
.rich-editor-content { min-height: 240px; flex: 1; overflow-y: auto; }
.rich-editor-content :deep(.w-e-text-container) { min-height: 240px; height: 100% !important; }
.rich-editor-content :deep(.w-e-text-placeholder) { color: #a8abb2; }
/* Keep the SaaS editing surface visually identical to the user rule modal.
   WangEditor renders the loaded HTML inside .w-e-text, so the editor needs
   explicit typography/spacing rules instead of relying on browser defaults. */
.rich-editor-content :deep(.w-e-text-container [data-slate-editor]) {
  color: #111;
  font-family: Arial, "Microsoft YaHei", sans-serif;
  font-size: 14px;
  line-height: 1.7;
  padding: 12px;
}
.rich-editor-content :deep(.w-e-text-container [data-slate-editor] h4) {
  margin: 12px 0 6px;
  color: #080c93;
  font-size: 15px;
  font-weight: 700;
  line-height: 1.45;
}
.rich-editor-content :deep(.w-e-text-container [data-slate-editor] p) {
  margin: 0 0 5px;
  color: #111;
  font-size: 14px;
  line-height: 1.7;
}
.rich-editor-content :deep(.w-e-text-container [data-slate-editor] span[style*="background-color"]) {
  display: inline-block;
  padding: 3px 12px;
  border: 1px solid #f0a900;
  border-radius: 16px;
  background: #fff500 !important;
  color: #111;
  line-height: 20px;
}
.rich-editor-content :deep(.w-e-text-container [data-slate-editor] div[style*="background-color:rgb(255,219,219)"]) {
  padding: 4px 6px;
  background: #ffdbdb !important;
}
</style>
