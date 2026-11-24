/**
 * attachment_category.js — ESM 模块（已从 AMD 迁移）
 */
const $ = window.jQuery;
const FormValidator = window.FormValidator;
const Layer = window.Layer;

/** HTML 转义（拼接弹窗表单用） */
function esc(value) {
    return String(value == null ? '' : value)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

const Controller = {
        index(){
            var selectedCategory = null;
            var categories = [];
            
            function init() {
                initEvents();
                loadCategories();
            }
            
            function initEvents() {
                // 添加分类按钮
                $('#addCategoryBtn').on('click', () => {
                    showAddModal();
                });
                
                // 保存分类：由 dialog.form 的「保存」按钮接管，无需本页绑定
                
                // 编辑分类按钮
                $('#editCategoryBtn').on('click', () => {
                    showEditModal();
                });
                
                // 删除分类按钮
                $('#deleteCategoryBtn').on('click', () => {
                    deleteCategory();
                });
                
                // 表单提交：已由 dialog.form 接管
            }
            
            // 加载分类列表
            function loadCategories() {
                $.get('/admin/attachment_category/getList', (result) => {
                    if (result.code == 0) {
                        categories = result.data;
                        renderCategoryTree();
                    } else {
                        showAlert('加载分类失败: ' + result.msg, 'danger');
                    }
                });
            }
            
            // 渲染分类树
            function renderCategoryTree() {
                const $tree = $('#categoryTree');
                
                if (categories.length === 0) {
                    $tree.html(`
                        <div class="text-center text-muted py-4">
                            <i class="bi bi-folder-x display-4"></i>
                            <p class="mt-2">暂无分类</p>
                        </div>
                    `);
                    return;
                }
                
                let html = '';
                
                const renderTree = (items, level = 0) => {
                    items.forEach(item => {
                        const prefix = '&nbsp;'.repeat(level * 4);
                        const isSelected = selectedCategory && selectedCategory.id === item.id;
                        const selectedClass = isSelected ? 'selected' : '';
                        
                        html += `
                            <div class="tree-item ${selectedClass}" data-id="${item.id}">
                                ${prefix}
                                <i class="bi bi-folder${item.children && item.children.length > 0 ? '-fill' : ''} me-1"></i>
                                ${item.name}
                            </div>
                        `;
                        
                        if (item.children && item.children.length > 0) {
                            html += '<div class="tree-children">';
                            renderTree(item.children, level + 1);
                            html += '</div>';
                        }
                    });
                };
                
                renderTree(categories);
                $tree.html(html);
                
                // 绑定点击事件
                $tree.on('click', '.tree-item', (e) => {
                    const $item = $(e.currentTarget);
                    const categoryId = $item.data('id');
                    
                    // 移除其他选中状态
                    $('.tree-item').removeClass('selected');
                    // 设置当前选中
                    $item.addClass('selected');
                    
                    selectCategory(categoryId);
                });
            }
            
            // 选择分类
            function selectCategory(categoryId) {
                const category = findCategoryById(categories, categoryId);
                if (category) {
                    selectedCategory = category;
                    showCategoryInfo(category);
                }
            }
            
            // 递归查找分类
            function findCategoryById(items, id) {
                for (const item of items) {
                    if (item.id == id) {
                        return item;
                    }
                    if (item.children) {
                        const found = findCategoryById(item.children, id);
                        if (found) return found;
                    }
                }
                return null;
            }
            
            // 显示分类信息
            function showCategoryInfo(category) {
                $('#noCategoryInfo').hide();
                $('#categoryInfo').show();
                
                $('#infoName').text(category.name);
                $('#infoId').text(category.id);
                $('#infoParent').text(category.parent_id > 0 ? getParentName(category.parent_id) : '无');
                $('#infoSort').text(category.sort);
                $('#infoCreateTime').text(category.create_time);
            }
            
            // 获取父级分类名称
            function getParentName(parentId) {
                const parent = findCategoryById(categories, parentId);
                return parent ? parent.name : '未知';
            }
            
            // 显示添加弹窗
            function showAddModal() {
                openCategoryModal(null);
            }

            // 显示编辑弹窗
            function showEditModal() {
                if (!selectedCategory) {
                    showAlert('请先选择一个分类', 'warning');
                    return;
                }

                openCategoryModal(selectedCategory);
            }

            /** 父级分类候选（排除自身及子分类），返回 [{id,label}] */
            function buildParentOptions(excludeId = null) {
                const options = [];

                const walk = (items, level = 0) => {
                    items.forEach(item => {
                        if (excludeId && (item.id == excludeId || isChildOf(item, excludeId))) {
                            return;
                        }

                        options.push({ id: item.id, label: '　'.repeat(level) + item.name });

                        if (item.children && item.children.length > 0) {
                            walk(item.children, level + 1);
                        }
                    });
                };

                walk(categories);

                return options;
            }

            /** 分类弹窗 HTML（校验/提交/关闭统一交给 Wise.ui.dialog.form） */
            function categoryFormHtml(row, parentOptions) {
                const r = row || {};
                const parentId = String(r.parent_id || 0);

                const parentSelect = parentOptions.map(o =>
                    `<option value="${esc(o.id)}"${String(o.id) === parentId ? ' selected' : ''}>${esc(o.label)}</option>`
                ).join('');

                return `
                <div class="row g-3">
                    <input type="hidden" name="id" value="${esc(r.id || '')}">
                    <div class="col-12">
                        <label class="form-label">分类名称 <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" value="${esc(r.name || '')}"
                               data-validation="required|maxLength:50"
                               data-validation-message-required="请填写分类名称"
                               data-validation-message-maxLength="分类名称不能超过 50 个字符">
                        <div class="invalid-feedback"><span class="validation-text"></span></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">父级分类</label>
                        <select name="parent_id" class="form-select">
                            <option value="0"${parentId === '0' ? ' selected' : ''}>无父级分类</option>
                            ${parentSelect}
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">排序</label>
                        <input type="number" name="sort" class="form-control" value="${esc(r.sort || 0)}" min="0">
                        <div class="form-text">数字越小越靠前</div>
                    </div>
                </div>`;
            }

            function openCategoryModal(row) {
                const dialog = window.Wise && window.Wise.ui && window.Wise.ui.dialog;
                if (!dialog || typeof dialog.form !== 'function') {
                    showAlert('弹窗组件未加载，请刷新页面', 'danger');
                    return;
                }

                const isEdit = !!(row && row.id);

                dialog.form({
                    title: isEdit ? '编辑分类' : '添加分类',
                    size: 'lg',
                    html: categoryFormHtml(row, buildParentOptions(isEdit ? row.id : null)),
                    action: isEdit ? '/admin/attachment_category/update' : '/admin/attachment_category/add',
                    successText: '保存成功',
                }).then((res) => {
                    if (!res || !res.success) return;

                    loadCategories();

                    if (isEdit && selectedCategory && selectedCategory.id == row.id) {
                        selectCategory(row.id);
                    }
                });
            }
            
            // 检查是否是指定分类的子分类
            function isChildOf(category, parentId) {
                if (!category.children) return false;
                
                for (const child of category.children) {
                    if (child.id == parentId) return true;
                    if (isChildOf(child, parentId)) return true;
                }
                
                return false;
            }
            
            // 保存分类：已并入 openCategoryModal()（dialog.form 提交后回调）

            // 删除分类
            function deleteCategory() {
                if (!selectedCategory) {
                    showAlert('请先选择一个分类', 'warning');
                    return;
                }
                
                Layer.confirm('确定要删除分类 "' + selectedCategory.name + '" 吗？此操作不可恢复。', function(index){   
                    $.post('/admin/attachment_category/delete', { id: selectedCategory.id }, (result) => {
                        if (result.code == 0) {
                            showAlert('删除成功', 'success');
                            selectedCategory = null;
                            $('#categoryInfo').hide();
                            $('#noCategoryInfo').show();
                            loadCategories();
                        } else {
                            showAlert('删除失败: ' + result.msg, 'danger');
                        }
                    });
                    Layer.close(index);
                });
            }
            
            // 显示提示消息
            function showAlert(message, type = 'info') {
                const alertId = 'alert-' + Date.now();
                const alertHtml = `
                    <div id="${alertId}" class="alert alert-${type} alert-dismissible fade show position-fixed top-0 start-50 translate-middle-x mt-3" style="z-index: 1050;">
                        ${message}
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                `;
                
                $('body').append(alertHtml);
                
                setTimeout(() => {
                    $('#' + alertId).alert('close');
                }, 3000);
            }
        
            init();
        }
    }

export default Controller;
