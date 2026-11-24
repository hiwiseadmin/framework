# advanced-search 扩展：**未加载，请勿在此改动**

## 结论

本目录下的 `bootstrap-table-advanced-search.js` / `.min.js`（`AdvancedMultipleSearch` 版本）
**没有被任何页面加载**，仅作为上游参考保留。

实际启用的是 **bundle 内的 `AdvancedSearch`** 插件：

- 加载入口：`app/admin/view/public/base.html` 引用的 `libs/bootstrap-table/bootstrap-table-bundle.min.js`
  （该 bundle 尾部已内联高级查询插件，无需额外 `<script>`）
- 默认配置：`AdvancedSearch: true`、`AdvancedSearchOptions`（bundle 内 `$.extend($.fn.bootstrapTable.defaults, …)`）
- 打开方式：工具栏 `advsearch` 按钮 → `EnhancedTable.showAdvancedSearch()` →
  `table.bootstrapTable('showAdvancedSearchModal')`（不走插件自建的工具栏按钮）
- BS5 适配：bundle 内 `showAdvancedSearchModal()` 已改为
  `bootstrap.Modal.getOrCreateInstance(el).show()`（原 `.modal('show')` 在 BS5 下静默失效）

## 为什么保留

`docs/table-search-audit.md` §3 P0-1 记录本目录版本存在逻辑分组序列化缺陷；
替换为 bundle 内的 `AdvancedSearch` 后避免两份实现并存。保留文件只为对照上游实现，
**修改这里不会生效**。

## 要改高级查询时改哪里

| 需求 | 位置 |
|:---|:---|
| 默认开关 / 条件上限 / 文案 / 弹窗尺寸 | `bootstrap-table-bundle.min.js` 中 `AdvancedSearchOptions` |
| 打开入口与按钮 | `src/public/statics/modules/enhanced-table.js`（`showAdvancedSearch` / `advsearch` 预设） |
| 与筛选条共存 | `bootstrap-table-bundle.min.js` 的 `queryParams` 包装（`$.extend` 合并 `_amsParams`） |
| 契约与分工 | `docs/wiseadmin-后台页面统一方案.md` §6.7 |
