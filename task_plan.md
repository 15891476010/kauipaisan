# 代理端四个明细页面实施计划

## 目标
完整实现总货明细、中奖明细、投注明细、查看退码四个代理端页面，包括参考站一致的筛选区、表格、分页、真实 API、站点与代理数据隔离、加载/空状态和浏览器验证。

## 阶段
- [complete] 阶段 1：盘点参考站四页 DOM、字段、筛选条件和交互
- [complete] 阶段 2：审计现有代理端组件、请求层、服务端路由和业务表
- [complete] 阶段 3：设计并实现四页 API、查询参数与隔离规则
- [complete] 阶段 4：实现 React 页面、共享筛选/表格组件和路由切换
- [in_progress] 阶段 5：lint、PHP 语法、构建、API 与内置浏览器联调
- [in_progress] 阶段 6：实现剩余导航页面、样式和交互
- [pending] 阶段 7：登录后逐页浏览器验证与交付记录

## 约束
- 保留现有脏工作区，不覆盖无关改动。
- 视觉与字段以参考站真实页面为准，不凭截图猜测。
- 所有数据必须按当前代理所属站点隔离。
- 独立请求尽量并行，避免串行瀑布。
- 构建使用 `npm exec vite build -- --emptyOutDir=false`，保留受保护的 `dist/.user.ini`。

## 错误记录
| 错误 | 尝试次数 | 处理 |
|---|---:|---|
| 假定 PHP 位于 `.runtime/php/php`，远端实际不存在 | 1 | 改用项目包装脚本 `scripts/php` |
| `scripts/php` 仍指向缺失的 `.runtime/php/php` | 1 | 改用服务器 `/usr/bin/php` 做语法检查；迁移另行确认可用扩展 |
| 新 feature 目录不存在，HexHub write 无法直接创建文件 | 1 | 先创建 `src/features/overview` 目录，再使用专用 write 工具 |
