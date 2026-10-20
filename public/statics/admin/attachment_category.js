/**
 * attachment_category.js — ESM 模块（已从 AMD 迁移）
 */
const $ = window.jQuery;
const FormValidator = window.FormValidator;
const Layer = window.Layer;

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
                
                // 保存分类按钮
                $('#saveCategoryBtn').on('click', () => {
                    saveCategory();
                });
                
                // 编辑分类按钮
                $('#editCategoryBtn').on('click', () => {
                    showEditModal();
                });
                
                // 删除分类按钮
                $('#deleteCategoryBtn').on('click', () => {
                    deleteCategory();
                });
                
                // 表单提交
                $('#categoryForm').on('submit', (e) => {
                    e.preventDefault();
                    saveCategory();
                });
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
            
            // 显示添加模态框
            function showAddModal() {
                $('#categoryModalLabel').text('添加分类');
                $('#categoryForm')[0].reset();
                $('#categoryId').val('');
                loadParentOptions();
                $('#categoryModal').modal('show');
            }
            
            // 显示编辑模态框
            function showEditModal() {
                if (!selectedCategory) {
                    showAlert('请先选择一个分类', 'warning');
                    return;
                }
                
                $('#categoryModalLabel').text('编辑分类');
                $('#categoryId').val(selectedCategory.id);
                $('#categoryName').val(selectedCategory.name);
                $('#categorySort').val(selectedCategory.sort);
                
                loadParentOptions(selectedCategory.id);
                
                $('#categoryModal').modal('show');
            }
            
            // 加载父级分类选项
            function loadParentOptions(excludeId = null) {
                $('#parentCategory').empty().append('<option value="0">无父级分类</option>');
                
                const addOptions = (items, level = 0) => {
                    items.forEach(item => {
                        // 排除自身及其子分类
                        if (excludeId && (item.id == excludeId || isChildOf(item, excludeId))) {
                            return;
                        }
                        
                        const prefix = '&nbsp;'.repeat(level * 4);
                        $('#parentCategory').append(
                            `<option value="${item.id}">${prefix}${item.name}</option>`
                        );
                        
                        if (item.children && item.children.length > 0) {
                            addOptions(item.children, level + 1);
                        }
                    });
                };
                
                addOptions(categories);
                
                // 设置当前父级ID
                if (selectedCategory) {
                    $('#parentCategory').val(selectedCategory.parent_id);
                }
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
            
            // 保存分类
            function saveCategory() {
                const formData = $('#categoryForm').serializeArray();
                const data = {};
                
                formData.forEach(item => {
                    data[item.name] = item.value;
                });
                
                // 验证
                if (!data.name || data.name.trim() === '') {
                    showAlert('请输入分类名称', 'warning');
                    return;
                }
                
                const url = data.id ? '/admin/attachment_category/update' : '/admin/attachment_category/add';
                const method = data.id ? 'POST' : 'POST';
                
                $.ajax({
                    url: url,
                    type: method,
                    data: data,
                    success: (result) => {
                        if (result.code == 0) {
                            showAlert('保存成功', 'success');
                            $('#categoryModal').modal('hide');
                            loadCategories();
                            
                            // 如果是编辑，更新选中分类
                            if (data.id && selectedCategory && selectedCategory.id == data.id) {
                                selectCategory(data.id);
                            }
                        } else {
                            showAlert('保存失败: ' + result.msg, 'danger');
                        }
                    },
                    error: () => {
                        showAlert('网络错误，请稍后重试', 'danger');
                    }
                });
            }
            
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
