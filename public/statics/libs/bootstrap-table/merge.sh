#!/bin/bash
# ====================================================
# 工作目录
# ====================================================
# cd /d/WrokBuddyWorkspace/wiseadmin/src/public/statics/libs/bootstrap-table

# ====================================================
# 1. JS 合并
# ====================================================
cat \
  bootstrap-table.min.js \
  locale\bootstrap-table-en-US.min \
  locale\bootstrap-table-zh-CN.min.js \
  extensions/toolbar/bootstrap-table-toolbar.min.js \
  extensions/export/bootstrap-table-export.min.js \
  extensions/filter-control/utils.min.js \
  extensions/filter-control/bootstrap-table-filter-control.min.js \
  extensions/addrbar/bootstrap-table-addrbar.min.js \
  extensions/auto-refresh/bootstrap-table-auto-refresh.min.js \
  extensions/cookie/bootstrap-table-cookie.min.js \
  extensions/copy-rows/bootstrap-table-copy-rows.min.js \
  extensions/custom-view/bootstrap-table-custom-view.min.js \
  extensions/defer-url/bootstrap-table-defer-url.min.js \
  extensions/editable/bootstrap-table-editable.min.js \
  extensions/fixed-columns/bootstrap-table-fixed-columns.min.js \
  extensions/group-by-v2/bootstrap-table-group-by.min.js \
  extensions/i18n-enhance/bootstrap-table-i18n-enhance.min.js \
  extensions/key-events/bootstrap-table-key-events.min.js \
  extensions/mobile/bootstrap-table-mobile.min.js \
  extensions/multiple-sort/bootstrap-table-multiple-sort.min.js \
  extensions/page-jump-to/bootstrap-table-page-jump-to.min.js \
  extensions/pipeline/bootstrap-table-pipeline.min.js \
  extensions/print/bootstrap-table-print.min.js \
  extensions/reorder-columns/bootstrap-table-reorder-columns.min.js \
  extensions/reorder-rows/bootstrap-table-reorder-rows.min.js \
  extensions/resizable/bootstrap-table-resizable.min.js \
  extensions/sticky-header/bootstrap-table-sticky-header.min.js \
  extensions/treegrid/bootstrap-table-treegrid.min.js \
  extensions/advanced-search/bootstrap-table-advanced-search.min.js \
  > bootstrap-table-bundle.min.js 2>/dev/null

# ====================================================
# 2. CSS 合并
# ====================================================
cat \
  bootstrap-table.min.css \
  extensions/filter-control/bootstrap-table-filter-control.min.css \
  extensions/fixed-columns/bootstrap-table-fixed-columns.min.css \
  extensions/group-by-v2/bootstrap-table-group-by.min.css \
  extensions/page-jump-to/bootstrap-table-page-jump-to.min.css \
  extensions/reorder-rows/bootstrap-table-reorder-rows.min.css \
  extensions/sticky-header/bootstrap-table-sticky-header.min.css \
  > bootstrap-table-bundle.min.css 2>/dev/null

# ====================================================
# 3. 验证输出
# ====================================================
echo "JS bundle: $(wc -c < bootstrap-table-bundle.min.js) bytes"
echo "CSS bundle: $(wc -c < bootstrap-table-bundle.min.css) bytes"
