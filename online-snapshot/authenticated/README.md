# 登录后用户端快照

登录后的所有页面仍由同一组静态文件提供，没有发现新的 JavaScript 分包或独立 CSS 文件：

- `../login-page/assets/js/app.bundle.a44facbc.js`：业务组件、路由、CSS-in-JS、Redux 状态、接口封装和录入规则。
- `../login-page/assets/js/vendors.chunk.124a6ca6.js`：React、Ant Design、styled-components 等依赖和图标实现。

本目录将压缩包中的关键信息拆成可读文件：

- `route-config.extracted.js`：认证后路由配置。
- `api-map.extracted.js`：接口 action/method、参数与下注端点。
- `ROUTES.md`：实际访问的页面和功能。
- `CLICK_COVERAGE.md`：已点击与为安全起见未执行的操作。
- `QUICK_ENTRY_RULES.md`：快速录入规则弹窗摘要。

样式不是通过 `.css` 文件加载，而是由业务包里的 styled-components 模板在运行时注入。
