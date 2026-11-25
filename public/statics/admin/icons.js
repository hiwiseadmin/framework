/**
 * icons.js — ESM 模块（已从 AMD 迁移）
 */
const $ = window.jQuery;
const Framework = window.Framework;
const ClipboardJS = window.Clipboard;
const Hotkey = window.HotKey;
const layer = window.Layer;
// 弹窗统一走 Wise.ui.dialog（UIS v1.1 §4）。本脚本由 wise-bundle 动态 import，
// 执行时 window.Wise 已就绪；iframe 页面取父窗口实例，弹层才落在可视层。
const dialog = ((window.parent && window.parent.Wise && window.parent.Wise.ui) || window.Wise.ui).dialog;

'use strict';
    const Controller = {
        index() {
            // 状态管理
            const state = {
                currentCategory: 'all',
                searchQuery: '',
                favorites: JSON.parse(localStorage.getItem('iconFavorites')) || [],
                currentPage: 1,
                itemsPerPage: parseInt(localStorage.getItem('itemsPerPage')) || 24, //每页显示图标数量
                currentColor: localStorage.getItem('currentColor') || '#495057',
                currentSize: parseInt(localStorage.getItem('currentSize')) || 2,
                selectedIcon: null,
            };

            // 复制：统一交给框架的 Clipboard 组件（statics/libs/clipboard/clipboard.js，随 wise-bundle 打包）
            // 组件负责：事件委托绑定 + 多策略写入（execCommand / copy 事件 / 顶层文档 / Clipboard API）
            // 页面只负责"复制哪段文本"和复制结果的 UI 反馈
            if (ClipboardJS) {
                const clipboard = new ClipboardJS(document, {
                    delegate: '.copy-btn',
                    text: (trigger) => {
                        const $btn = $(trigger);
                        const selector = $btn.attr('data-clipboard-target');

                        // 优先取同一代码块内的 <code>，避免同名 id 的陈旧副本
                        let $target = $btn.closest('.code-example').find('code').first();
                        if (!$target.length && selector) {
                            $target = $(selector).first();
                        }

                        return $target.length ? $target.text() : '';
                    }
                });

                clipboard.on('success', (e) => {
                    $(e.trigger).removeClass('copied failed').addClass('copied');
                    setTimeout(() => $(e.trigger).removeClass('copied failed'), 2000);
                });

                clipboard.on('error', (e) => {
                    const $btn = $(e.trigger);
                    $btn.removeClass('copied failed').addClass('failed');
                    setTimeout(() => $btn.removeClass('copied failed'), 2000);

                    if (e.text) {
                        showManualCopy(e.text);
                    }
                });
            }

            // 兜底：自动复制失败时，弹出选中好的文本让用户 Ctrl+C
            const escapeHtml = (str) => String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');

            const showManualCopy = (text) => {
                const content = '<textarea readonly style="width:100%;height:180px;box-sizing:border-box;'
                    + 'padding:8px;font-family:ui-monospace,monospace;font-size:12px;line-height:1.5;'
                    + 'white-space:pre;overflow:auto;">' + escapeHtml(text) + '</textarea>'
                    + '<div class="text-secondary small mt-2">自动复制被浏览器拦截，请按 Ctrl+C（Mac: Command+C）手动复制</div>';

                try {
                    if (dialog) {
                        dialog.open(content, '手动复制', {
                            area: ['560px', '320px'],
                            success(layero) {
                                const ta = $(layero).find('textarea')[0];
                                if (ta) {
                                    ta.focus();
                                    ta.select();
                                }
                            }
                        });
                        return;
                    }
                } catch (err) {
                    // 继续走 prompt
                }

                try {
                    window.prompt('自动复制被浏览器拦截，请按 Ctrl+C 手动复制：', text);
                } catch (err) {
                    // 沙箱环境可能禁用 prompt，忽略
                }
            };

            // 获取图标数据
            const icons = Controller.getIcons();

            // 侧边栏切换（移动端）
            $('#sidebarToggle, .sidebar-overlay').on('click', function() {
                $('.sidebar').toggleClass('show');
                $('.sidebar-overlay').toggleClass('show');
            });
            
            // 分类筛选
            $('.category-item').on('click', function() {
                $('.category-item').removeClass('active');
                $(this).addClass('active');
                
                const category = $(this).data('category');
                state.currentCategory = category;
                $('#currentCategory').text($(this).text());
                state.currentPage = 1;
                
                filterIcons();
                
                // 在移动端选择后关闭侧边栏
                if ($(window).width() < 768) {
                    $('.sidebar').removeClass('show');
                    $('.sidebar-overlay').removeClass('show');
                }
            });
            
            // 搜索功能
            $('#searchInput').on('input', function() {
                state.searchQuery = $(this).val();
                state.currentPage = 1;
                filterIcons();
            });
            
            // 清空搜索
            $('#clearSearch').on('click', function() {
                $('#searchInput').val('');
                state.searchQuery = '';
                state.currentPage = 1;
                filterIcons();
            });
            
            // 颜色选择
            $('#iconColor').on('input', function() {
                state.currentColor = $(this).val();
                $('.icon-preview').css('color', state.currentColor);
                $('#modalIconPreview').css('color', state.currentColor);
                localStorage.setItem('currentColor', state.currentColor);
            });
            $('#iconColor').val(state.currentColor);
            
            // 点击图标打开模态框
            $('#iconsContainer').on('click', '.icon-card', function() {
                layer.top().setShared('selectedIcon', $(this).data('icon'));
                state.selectedIcon = $(this).data('icon');
                const iconName = $(this).data('icon');
                $('#iconsContainer .icon-card').removeClass('active');
                $(this).addClass('active');
                openIconModal(iconName);
            });
            
            // 收藏/取消收藏
            $('#iconsContainer').on('click', '.favorite-btn', function(e) {
                e.stopPropagation();
                const iconName = $(this).parent('.icon-card').data('icon');
                toggleFavorite(iconName);
                $(this).toggleClass('text-warning');
            });

            // 大小选择
            $('#iconSize').on('change', function() {
                state.currentSize = $(this).val();
                updateIconSizes();
                localStorage.setItem('currentSize', state.currentSize);
            });
            $('#iconSize').val(state.currentSize);
            
            // 视图模式切换
            $('input[name="viewMode"]').on('change', function() {
                if ($('#gridView').is(':checked')) {
                    $('#iconsContainer').removeClass('list-view').addClass('row');
                } else {
                    $('#iconsContainer').removeClass('row').addClass('list-view');
                    $('#iconsContainer .col-md-2').removeClass('col-md-2').addClass('col-12');
                }
            });
            
            // 过滤图标函数
            function filterIcons() {
                let filteredIcons = icons;
                // 按分类过滤
                if (state.currentCategory !== 'all') {
                    if (state.currentCategory === 'favorites') {
                        filteredIcons = icons.filter(icon => state.favorites.includes(icon.name));
                    } else {
                        filteredIcons = icons.filter(icon => icon.categories.includes(state.currentCategory));
                    }
                }
                
                // 按搜索词过滤
                if (state.searchQuery) {
                    filteredIcons = filteredIcons.filter(icon => 
                        icon.name.includes(state.searchQuery.toLowerCase()) || 
                        icon.tags.some(tag => tag.includes(state.searchQuery.toLowerCase())) ||
                        icon.categories.includes(state.searchQuery.toLowerCase())
                    );
                }
                
                displayIcons(filteredIcons);
                updatePagination(filteredIcons.length);
            }
            
            // 显示图标
            function displayIcons(iconsToDisplay) {
                const container = $('#iconsContainer');
                container.empty();
                
                if (iconsToDisplay.length === 0) {
                    $('#emptyState').removeClass('d-none');
                    $('#pagination').addClass('d-none');
                    return;
                }
                
                $('#emptyState').addClass('d-none');
                $('#pagination').removeClass('d-none');
                
                // 计算分页
                const startIndex = (state.currentPage - 1) * state.itemsPerPage;
                const endIndex = startIndex + state.itemsPerPage;
                const paginatedIcons = iconsToDisplay.slice(startIndex, endIndex);
                
                // 生成图标卡片
                paginatedIcons.forEach(icon => {
                    const isFavorite = state.favorites.includes(icon.name);
                    const favoriteClass = isFavorite ? 'text-warning' : '';
                    
                    const col = $('<div>').addClass('col-md-2 col-sm-2 col-3 mb-2');
                    const card = $('<div>').addClass('icon-card').attr('data-icon', icon.name);
                    const preview = $('<i>').addClass(`bi-${icon.name} icon-preview`).css('color', state.currentColor);
                    const name = $('<div>').addClass('icon-name').text(icon.name);
                    const favorite = $('<div>').addClass(`favorite-btn ${favoriteClass}`).html('<i class="bi bi-star-fill"></i>');
                    
                    card.append(preview, name, favorite);
                    col.append(card);
                    container.append(col);
                });
                
                updateIconSizes();
            }
            
            // 更新图标大小
            function updateIconSizes() {
                const sizeMap = {
                    1: '1.5rem',
                    2: '2rem',
                    3: '2.5rem',
                    4: '3rem'
                };
                
                $('.icon-preview').css('font-size', sizeMap[state.currentSize]);
            }
            
            // 更新分页
            function updatePagination(totalIcons) {
                const totalPages = Math.ceil(totalIcons / state.itemsPerPage);
                const pagination = $('#pagination');
                pagination.empty();
                
                if (totalPages <= 1) {
                    pagination.addClass('d-none');
                    return;
                }
                
                pagination.removeClass('d-none');

                // 分页数
                const pageSize = $('<div>').addClass('page-size pe-5').html(`每页显示 <div class="btn-group dropdown dropup">
                    <button class="btn btn-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <span class="page-size">${state.itemsPerPage}</span>
                        <span class="caret"></span>
                    </button>
                    <div class="dropdown-menu" style="">
                        <a class="dropdown-item ${state.itemsPerPage === 24 ? 'active' : ''}" href="#">24</a>
                        <a class="dropdown-item ${state.itemsPerPage === 36 ? 'active' : ''}" href="#">36</a>
                        <a class="dropdown-item ${state.itemsPerPage === 48 ? 'active' : ''}" href="#">48</a>
                        <a class="dropdown-item ${state.itemsPerPage === 72 ? 'active' : ''}" href="#">72</a>
                        <a class="dropdown-item ${state.itemsPerPage === 108 ? 'active' : ''}" href="#">108</a>
                    </div>
                </div> 条记录`);
                pagination.append(pageSize);
                $('a.dropdown-item').on('click', function() {
                    state.itemsPerPage = parseInt($(this).text());
                    localStorage.setItem('itemsPerPage', state.itemsPerPage);
                    filterIcons();
                });
                
                // 上一页
                const prevLi = $('<li>').addClass('page-item');
                const prevLink = $('<a>').addClass('page-link').attr('href', '#').html('<i class="bi bi-chevron-left"></i>');
                if (state.currentPage === 1) prevLi.addClass('disabled');
                
                prevLink.on('click', function(e) {
                    e.preventDefault();
                    if (state.currentPage > 1) {
                        state.currentPage--;
                        filterIcons();
                    }
                });
                
                prevLi.append(prevLink);
                pagination.append(prevLi);
                
                // 智能页码生成
                let startPage, endPage;
                
                if (totalPages <= 7) {
                    // 如果总页数小于等于7，显示所有页码
                    startPage = 1;
                    endPage = totalPages;
                } else {
                    // 如果当前页靠近开头
                    if (state.currentPage <= 4) {
                        startPage = 1;
                        endPage = 6;
                    } 
                    // 如果当前页靠近结尾
                    else if (state.currentPage >= totalPages - 3) {
                        startPage = totalPages - 5;
                        endPage = totalPages;
                    } 
                    // 如果当前页在中间
                    else {
                        startPage = state.currentPage - 2;
                        endPage = state.currentPage + 3;
                    }
                }
                
                // 显示第一页（如果需要）
                if (startPage > 1) {
                    const firstPageLi = $('<li>').addClass('page-item');
                    const firstPageLink = $('<a>').addClass('page-link').attr('href', '#').text(1);
                    
                    firstPageLink.on('click', function(e) {
                        e.preventDefault();
                        state.currentPage = 1;
                        filterIcons();
                    });
                    
                    firstPageLi.append(firstPageLink);
                    pagination.append(firstPageLi);
                    
                    // 添加省略号
                    if (startPage > 2) {
                        const ellipsisLi = $('<li>').addClass('page-item disabled');
                        const ellipsisSpan = $('<span>').addClass('page-link').html('...');
                        ellipsisLi.append(ellipsisSpan);
                        pagination.append(ellipsisLi);
                    }
                }
                
                // 生成主要页码
                for (let i = startPage; i <= endPage; i++) {
                    const pageLi = $('<li>').addClass('page-item');
                    if (i === state.currentPage) pageLi.addClass('active');
                    
                    const pageLink = $('<a>').addClass('page-link').attr('href', '#').text(i);
                    
                    pageLink.on('click', function(e) {
                        e.preventDefault();
                        state.currentPage = i;
                        filterIcons();
                    });
                    
                    pageLi.append(pageLink);
                    pagination.append(pageLi);
                }
                
                // 显示最后一页（如果需要）
                if (endPage < totalPages) {
                    // 添加省略号
                    if (endPage < totalPages - 1) {
                        const ellipsisLi = $('<li>').addClass('page-item disabled');
                        const ellipsisSpan = $('<span>').addClass('page-link').html('...');
                        ellipsisLi.append(ellipsisSpan);
                        pagination.append(ellipsisLi);
                    }
                    
                    const lastPageLi = $('<li>').addClass('page-item');
                    const lastPageLink = $('<a>').addClass('page-link').attr('href', '#').text(totalPages);
                    
                    lastPageLink.on('click', function(e) {
                        e.preventDefault();
                        state.currentPage = totalPages;
                        filterIcons();
                    });
                    
                    lastPageLi.append(lastPageLink);
                    pagination.append(lastPageLi);
                }
                
                // 下一页
                const nextLi = $('<li>').addClass('page-item');
                const nextLink = $('<a>').addClass('page-link').attr('href', '#').html('<i class="bi bi-chevron-right"></i>');
                if (state.currentPage === totalPages) nextLi.addClass('disabled');
                
                nextLink.on('click', function(e) {
                    e.preventDefault();
                    if (state.currentPage < totalPages) {
                        state.currentPage++;
                        filterIcons();
                    }
                });
                
                nextLi.append(nextLink);
                pagination.append(nextLi);

                // 生成快捷键提示
                const tips = '<span class="hotkey-tips" data-bs-toggle="tooltip" title="Ctrl + 左右方向键 翻页"><i class="bi bi-keyboard"></i></span>';
                pagination.append($('<li>').addClass('page-item').html(tips));
            }
            
            // 打开图标详情模态框
            function openIconModal(iconName) {
                const icon = icons.find(i => i.name === iconName);
                if (!icon) return;
                
                const isFavorite = state.favorites.includes(iconName);
                
                // 更新模态框内容
                $('#modalIconPreview')
                    .removeClass()
                    .addClass(`bi bi-${iconName}`)
                    .css('color', state.currentColor);
                    
                $('#modalIconName').text(iconName);
                
                // 更新代码示例（单图标 svg 文件已移除，统一以 sprite 为数据源）
                $('#fontCode').text(`<i class="bi bi-${iconName}"></i>`);
                $('#svgCode').text(`<svg width="16" height="16" fill="currentColor" class="bi bi-${iconName}" viewBox="0 0 16 16">...</svg>`);
                $('#imgCode').text(`<svg width="16" height="16" fill="currentColor"><use href="/statics/libs/bootstrap-icons/bootstrap-icons.svg#${iconName}"></use></svg>`);
                $('#cssCode').text(`background-image: url("data:image/svg+xml,...");`);
                
                // 更新收藏按钮
                const favoriteBtn = $('#favoriteBtn');
                favoriteBtn.find('i').toggleClass('bi-star', !isFavorite);
                favoriteBtn.find('i').toggleClass('bi-star-fill', isFavorite);
                favoriteBtn.find('span').text(isFavorite ? '已收藏' : '收藏');
                
                // 下载SVG按钮
                $('#downloadSvgBtn').off('click').on('click', function() {
                    downloadSvg(iconName);
                });
                
                // 收藏/取消收藏
                favoriteBtn.off('click').on('click', function() {
                    toggleFavorite(iconName);
                    const nowFavorite = state.favorites.includes(iconName);
                    $(this).find('i').toggleClass('bi-star', !nowFavorite);
                    $(this).find('i').toggleClass('bi-star-fill', nowFavorite);
                    $(this).find('span').text(nowFavorite ? '已收藏' : '收藏');
                    
                    // 更新图标网格中的收藏状态
                    $(`.icon-card[data-icon="${iconName}"] .favorite-btn`).toggleClass('text-warning', nowFavorite);
                });
                
                // 从雪碧图解析单图标 SVG（静态资源，无鉴权，浏览器缓存）
                loadSpriteSymbols()
                    .then(symbols => {
                        const svgContent = buildIconSvgFromSymbol(iconName, symbols);
                        if (!svgContent) {
                            throw new Error(`symbol not found: ${iconName}`);
                        }

                        // SVG 嵌入：整段内联，fill="currentColor" 跟随文字颜色
                        $('#svgCode').text(svgContent);
                        // CSS 背景：data URI 必须编码，否则 # / " 会截断 URL
                        $('#cssCode').text(`background-image: url("data:image/svg+xml,${encodeURIComponent(svgContent)}");`);
                    })
                    .catch(() => {
                        $('#svgCode').text('获取SVG代码失败');
                        $('#cssCode').text('获取SVG代码失败');
                    });

                // 显示模态框
                $('#iconModal').modal('show');
            }
            
            // 切换收藏状态
            function toggleFavorite(iconName) {
                const index = state.favorites.indexOf(iconName);
                if (index > -1) {
                    state.favorites.splice(index, 1);
                } else {
                    state.favorites.push(iconName);
                }
                
                localStorage.setItem('iconFavorites', JSON.stringify(state.favorites));
                
                // 如果当前在收藏夹视图，更新显示
                if (state.currentCategory === 'favorites') {
                    filterIcons();
                }
            }
            
            // 雪碧图解析：一次性加载，按图标名缓存 symbol 内容
            const spriteSymbols = { map: null, promise: null };

            function loadSpriteSymbols() {
                if (spriteSymbols.map) {
                    return Promise.resolve(spriteSymbols.map);
                }

                if (!spriteSymbols.promise) {
                    spriteSymbols.promise = fetch('/statics/libs/bootstrap-icons/bootstrap-icons.svg')
                        .then(res => {
                            if (!res.ok) {
                                throw new Error(`sprite ${res.status}`);
                            }
                            return res.text();
                        })
                        .then(text => {
                            const map = new Map();
                            text.replace(/<symbol\b([^>]*)>([\s\S]*?)<\/symbol>/g, (all, attrs, body) => {
                                const id = attrs.match(/(?:^|\s)id="([^"]+)"/);
                                if (id) {
                                    map.set(id[1], body.trim());
                                }
                                return all;
                            });
                            spriteSymbols.map = map;
                            return map;
                        })
                        .catch(err => {
                            // 失败后允许下次重试
                            spriteSymbols.promise = null;
                            throw err;
                        });
                }

                return spriteSymbols.promise;
            }

            // 单个 symbol -> 可独立使用的 SVG（16×16、currentColor）
            function buildIconSvgFromSymbol(iconName, symbols) {
                const body = symbols.get(iconName);
                if (!body) {
                    return null;
                }

                return `<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-${iconName}" viewBox="0 0 16 16">${body}</svg>`;
            }

            // 下载SVG（单图标文件已移除，由后端从 sprite 提取）
            function downloadSvg(iconName) {
                const svgUrl = `/admin/icons/download_svg?name=${encodeURIComponent(iconName)}`;
                const a = document.createElement('a');
                a.href = svgUrl;
                a.download = `bi-${iconName}.svg`;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
            }
            
            // 快捷键
            const hotKey = new Hotkey('icons', {crossPage: false});
            hotKey.register('ctrl+arrowleft', (hotkey, event) => {
                const prev = $('.pagination .bi-chevron-left').closest('a');
                if (prev.length) {prev.click();}
            }, '左箭头');
            hotKey.register('ctrl+arrowright', (hotkey, event) => {
                const next = $('.pagination .bi-chevron-right').closest('a');
                if (next.length) {next.click();}
            }, '右箭头');
            $('.category-list').on('keydown', '.category-item', function(e) {
                if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    console.log('上键');
                    const prev = $(this).prev('.category-item');
                    if (prev.length) {
                        prev.focus();
                        prev.click();
                    }
                } else if (e.key === 'ArrowDown') {
                    e.preventDefault(); 
                    console.log('下键');
                    const next = $(this).next('.category-item');
                    if (next.length) {
                        next.focus();
                        next.click();
                    }
                }
            });

            // 初始加载
            filterIcons();
        },
        getIcons() {
            // 图标数据（实际应用中可以从外部加载）
            const icons = [{ name:'0-circle',tags: ['number','numeral'],categories: 'shapes' },
{ name: '0-circle-fill',tags: ['number', 'numeral'],categories: 'shapes' },
{ name: '0-square',tags: ['number', 'numeral'],categories: 'shapes' },
{ name: '0-square-fill',tags: ['number', 'numeral'],categories: 'shapes' },
{ name: '123',tags: ['numbers'],categories: 'typography' },
{ name: '1-circle',tags: ['number', 'numeral'],categories: 'shapes' },
{ name: '1-circle-fill',tags: ['number', 'numeral'],categories: 'shapes' },
{ name: '1-square',tags: ['number', 'numeral'],categories: 'shapes' },
{ name: '1-square-fill',tags: ['number', 'numeral'],categories: 'shapes' },
{ name: '2-circle',tags: ['number', 'numeral'],categories: 'shapes' },
{ name: '2-circle-fill',tags: ['number', 'numeral'],categories: 'shapes' },
{ name: '2-square',tags: ['number', 'numeral'],categories: 'shapes' },
{ name: '2-square-fill',tags: ['number', 'numeral'],categories: 'shapes' },
{ name: '3-circle',tags: ['number', 'numeral'],categories: 'shapes' },
{ name: '3-circle-fill',tags: ['number', 'numeral'],categories: 'shapes' },
{ name: '3-square',tags: ['number', 'numeral'],categories: 'shapes' },
{ name: '3-square-fill',tags: ['number', 'numeral'],categories: 'shapes' },
{ name: '4-circle',tags: ['number', 'numeral'],categories: 'shapes' },
{ name: '4-circle-fill',tags: ['number', 'numeral'],categories: 'shapes' },
{ name: '4-square',tags: ['number', 'numeral'],categories: 'shapes' },
{ name: '4-square-fill',tags: ['number', 'numeral'],categories: 'shapes' },
{ name: '5-circle',tags: ['number', 'numeral'],categories: 'shapes' },
{ name: '5-circle-fill',tags: ['number', 'numeral'],categories: 'shapes' },
{ name: '5-square',tags: ['number', 'numeral'],categories: 'shapes' },
{ name: '5-square-fill',tags: ['number', 'numeral'],categories: 'shapes' },
{ name: '6-circle',tags: ['number', 'numeral'],categories: 'shapes' },
{ name: '6-circle-fill',tags: ['number', 'numeral'],categories: 'shapes' },
{ name: '6-square',tags: ['number', 'numeral'],categories: 'shapes' },
{ name: '6-square-fill',tags: ['number', 'numeral'],categories: 'shapes' },
{ name: '7-circle',tags: ['number', 'numeral'],categories: 'shapes' },
{ name: '7-circle-fill',tags: ['number', 'numeral'],categories: 'shapes' },
{ name: '7-square',tags: ['number', 'numeral'],categories: 'shapes' },
{ name: '7-square-fill',tags: ['number', 'numeral'],categories: 'shapes' },
{ name: '8-circle',tags: ['number', 'numeral'],categories: 'shapes' },
{ name: '8-circle-fill',tags: ['number', 'numeral'],categories: 'shapes' },
{ name: '8-square',tags: ['number', 'numeral'],categories: 'shapes' },
{ name: '8-square-fill',tags: ['number', 'numeral'],categories: 'shapes' },
{ name: '9-circle',tags: ['number', 'numeral'],categories: 'shapes' },
{ name: '9-circle-fill',tags: ['number', 'numeral'],categories: 'shapes' },
{ name: '9-square',tags: ['number', 'numeral'],categories: 'shapes' },
{ name: '9-square-fill',tags: ['number', 'numeral'],categories: 'shapes' },
{ name: 'activity',tags: ['pulse', 'heartbeat','rhythm'],categories: 'data' },
{ name: 'airplane',tags: ['flight', 'flying','plane','air','airport','aircraft'],categories: 'transportation' },
{ name: 'airplane-engines',tags: ['flight', 'flying','plane','air','airport','aircraft'],categories: 'transportation' },
{ name: 'airplane-engines-fill',tags: ['flight', 'flying','plane','air','airport','aircraft'],categories: 'transportation' },
{ name: 'airplane-fill',tags: ['flight', 'flying','plane','air','airport','aircraft'],categories: 'transportation' },
{ name: 'alarm',tags: ['alarm', 'clock','time'],categories: 'devices' },
{ name: 'alarm-fill',tags: ['alarm', 'clock','time'],categories: 'devices' },
{ name: 'alexa',tags: ['social', 'assistant'],categories: 'brand' },
{ name: 'align-bottom',tags: ['space', 'align','distribute'],categories: 'graphics' },
{ name: 'align-center',tags: ['space', 'align','distribute','centre'],categories: 'graphics' },
{ name: 'align-end',tags: ['space', 'align','distribute'],categories: 'graphics' },
{ name: 'align-middle',tags: ['space', 'align','distribute'],categories: 'graphics' },
{ name: 'align-start',tags: ['space', 'align','distribute'],categories: 'graphics' },
{ name: 'align-top',tags: ['space', 'align','distribute'],categories: 'graphics' },
{ name: 'alipay',tags: ['payments'],categories: 'brand' },
{ name: 'alphabet',tags: ['letters', 'abc'],categories: 'typography' },
{ name: 'alphabet-uppercase',tags: ['letters', 'abc'],categories: 'typography' },
{ name: 'alt',tags: ['key', 'alt','option'],categories: 'ui and keyboard' },
{ name: 'amazon',tags: ['aws'],categories: 'brand' },
{ name: 'amd',tags: ['radeon'],categories: 'brand' },
{ name: 'android',tags: ['google', 'droid'],categories: 'brand' },
{ name: 'android2',tags: ['google', 'droid'],categories: 'brand' },
{ name: 'anthropic',tags: ['ai', 'claude','agent','artificial','intelligence'],categories: 'brand' },
{ name: 'app',tags: ['app', 'application','ios','android','square'],categories: 'apps' },
{ name: 'app-indicator',tags: ['app', 'application','ios','android','notification'],categories: 'apps' },
{ name: 'apple',tags: ['aapl', 'mac','macintosh'],categories: 'brand' },
{ name: 'apple-music',tags: ['itunes'],categories: 'brand' },
{ name: 'archive',tags: ['box', 'delete'],categories: 'files and folders' },
{ name: 'archive-fill',tags: ['box', 'delete'],categories: 'files and folders' },
{ name: 'arrow-90deg-down',tags: ['arrow', 'right-angle'],categories: 'arrows' },
{ name: 'arrow-90deg-left',tags: ['arrow', 'right-angle'],categories: 'arrows' },
{ name: 'arrow-90deg-right',tags: ['arrow', 'right-angle'],categories: 'arrows' },
{ name: 'arrow-90deg-up',tags: ['arrow', 'right-angle'],categories: 'arrows' },
{ name: 'arrow-bar-down',tags: ['arrow'],categories: 'arrows' },
{ name: 'arrow-bar-left',tags: ['arrow'],categories: 'arrows' },
{ name: 'arrow-bar-right',tags: ['arrow'],categories: 'arrows' },
{ name: 'arrow-bar-up',tags: ['arrow'],categories: 'arrows' },
{ name: 'arrow-clockwise',tags: ['arrow', 'right','spin','turn','around','round','rotate','refresh','reload'],categories: 'arrows' },
{ name: 'arrow-counterclockwise',tags: ['arrow', 'left','spin','turn','around','round','rotate'],categories: 'arrows' },
{ name: 'arrow-down',tags: ['arrow'],categories: 'arrows' },
{ name: 'arrow-down-circle',tags: ['arrow', 'circle'],categories: 'shape arrows' },
{ name: 'arrow-down-circle-fill',tags: ['arrow', 'circle'],categories: 'shape arrows' },
{ name: 'arrow-down-left',tags: ['arrow'],categories: 'arrows' },
{ name: 'arrow-down-left-circle',tags: ['arrow', 'circle'],categories: 'shape arrows' },
{ name: 'arrow-down-left-circle-fill',tags: ['arrow', 'circle'],categories: 'shape arrows' },
{ name: 'arrow-down-left-square',tags: ['arrow', 'square'],categories: 'shape arrows' },
{ name: 'arrow-down-left-square-fill',tags: ['arrow', 'square'],categories: 'shape arrows' },
{ name: 'arrow-down-right',tags: ['arrow'],categories: 'arrows' },
{ name: 'arrow-down-right-circle',tags: ['arrow', 'circle'],categories: 'shape arrows' },
{ name: 'arrow-down-right-circle-fill',tags: ['arrow', 'circle'],categories: 'shape arrows' },
{ name: 'arrow-down-right-square',tags: ['arrow', 'square'],categories: 'shape arrows' },
{ name: 'arrow-down-right-square-fill',tags: ['arrow', 'square'],categories: 'shape arrows' },
{ name: 'arrow-down-short',tags: ['arrow'],categories: 'arrows' },
{ name: 'arrow-down-square',tags: ['arrow', 'square'],categories: 'shape arrows' },
{ name: 'arrow-down-square-fill',tags: ['arrow', 'square'],categories: 'shape arrows' },
{ name: 'arrow-down-up',tags: ['arrow'],categories: 'arrows' },
{ name: 'arrow-left',tags: ['arrow'],categories: 'arrows' },
{ name: 'arrow-left-circle',tags: ['arrow', 'circle'],categories: 'shape arrows' },
{ name: 'arrow-left-circle-fill',tags: ['arrow', 'circle'],categories: 'shape arrows' },
{ name: 'arrow-left-right',tags: ['arrow'],categories: 'arrows' },
{ name: 'arrow-left-short',tags: ['arrow'],categories: 'arrows' },
{ name: 'arrow-left-square',tags: ['arrow', 'square'],categories: 'shape arrows' },
{ name: 'arrow-left-square-fill',tags: ['arrow', 'square'],categories: 'shape arrows' },
{ name: 'arrow-repeat',tags: ['arrow', 'spin','turn','around','round','rotate'],categories: 'arrows' },
{ name: 'arrow-return-left',tags: ['arrow', 'return'],categories: 'arrows' },
{ name: 'arrow-return-right',tags: ['arrow', 'return'],categories: 'arrows' },
{ name: 'arrow-right',tags: ['arrow'],categories: 'arrows' },
{ name: 'arrow-right-circle',tags: ['arrow', 'circle'],categories: 'shape arrows' },
{ name: 'arrow-right-circle-fill',tags: ['arrow', 'circle'],categories: 'shape arrows' },
{ name: 'arrow-right-short',tags: ['arrow'],categories: 'arrows' },
{ name: 'arrow-right-square',tags: ['arrow', 'square'],categories: 'shape arrows' },
{ name: 'arrow-right-square-fill',tags: ['arrow', 'square'],categories: 'shape arrows' },
{ name: 'arrows',tags: ['arrow', 'resize'],categories: 'arrows' },
{ name: 'arrows-angle-contract',tags: ['arrow', 'resize'],categories: 'arrows' },
{ name: 'arrows-angle-expand',tags: ['arrow', 'resize'],categories: 'arrows' },
{ name: 'arrows-collapse',tags: ['arrow'],categories: 'arrows' },
{ name: 'arrows-collapse-vertical',tags: ['arrow', 'resize'],categories: 'arrows' },
{ name: 'arrows-expand',tags: ['arrow'],categories: 'arrows' },
{ name: 'arrows-expand-vertical',tags: ['arrow', 'resize'],categories: 'arrows' },
{ name: 'arrows-fullscreen',tags: ['arrow'],categories: 'arrows' },
{ name: 'arrows-move',tags: ['arrow', 'cursor','move'],categories: 'arrows' },
{ name: 'arrows-vertical',tags: ['arrow', 'resize'],categories: 'arrows' },
{ name: 'arrow-through-heart',tags: ['cupid', 'love','valentine'],categories: 'arrows love' },
{ name: 'arrow-through-heart-fill',tags: ['cupid', 'love','valentine'],categories: 'arrows love' },
{ name: 'arrow-up',tags: ['arrow'],categories: 'arrows' },
{ name: 'arrow-up-circle',tags: ['arrow', 'circle'],categories: 'shape arrows' },
{ name: 'arrow-up-circle-fill',tags: ['arrow', 'circle'],categories: 'shape arrows' },
{ name: 'arrow-up-left',tags: ['arrow'],categories: 'arrows' },
{ name: 'arrow-up-left-circle',tags: ['arrow', 'circle'],categories: 'shape arrows' },
{ name: 'arrow-up-left-circle-fill',tags: ['arrow', 'circle'],categories: 'shape arrows' },
{ name: 'arrow-up-left-square',tags: ['arrow', 'square'],categories: 'shape arrows' },
{ name: 'arrow-up-left-square-fill',tags: ['arrow', 'square'],categories: 'shape arrows' },
{ name: 'arrow-up-right',tags: ['arrow'],categories: 'arrows' },
{ name: 'arrow-up-right-circle',tags: ['arrow', 'circle'],categories: 'shape arrows' },
{ name: 'arrow-up-right-circle-fill',tags: ['arrow', 'circle'],categories: 'shape arrows' },
{ name: 'arrow-up-right-square',tags: ['arrow', 'square'],categories: 'shape arrows' },
{ name: 'arrow-up-right-square-fill',tags: ['arrow', 'square'],categories: 'shape arrows' },
{ name: 'arrow-up-short',tags: ['arrow'],categories: 'arrows' },
{ name: 'arrow-up-square',tags: ['arrow', 'square'],categories: 'shape arrows' },
{ name: 'arrow-up-square-fill',tags: ['arrow', 'square'],categories: 'shape arrows' },
{ name: 'aspect-ratio',tags: ['size', 'resize','crop', 'dimensions'], categories: 'media' },
{ name: 'aspect-ratio-fill',tags: ['size', 'resize','crop','dimensions'],categories: 'media' },
{ name: 'asterisk',tags: ['asterisks', 'star','math'],categories: 'typography' },
{ name: 'at',tags: ['mention', 'sign'],categories: 'communications' },
{ name: 'award',tags: ['prize', 'rosette'],categories: 'real world' },
{ name: 'award-fill',tags: ['prize', 'rosette'],categories: 'real world' },
{ name: 'back',tags: ['backward', 'layer'],categories: 'graphics' },
{ name: 'backpack',tags: ['luggage', 'bags','carry-on','student','education'],categories: 'travel' },
{ name: 'backpack2',tags: ['luggage', 'bags','carry-on','student','education'],categories: 'travel' },
{ name: 'backpack2-fill',tags: ['luggage', 'bags','carry-on','student','education'],categories: 'travel' },
{ name: 'backpack3',tags: ['luggage', 'bags','carry-on','student','education'],categories: 'travel' },
{ name: 'backpack3-fill',tags: ['luggage', 'bags','carry-on','student','education'],categories: 'travel' },
{ name: 'backpack4',tags: ['luggage', 'bags','carry-on','student','education'],categories: 'travel' },
{ name: 'backpack4-fill',tags: ['luggage', 'bags','carry-on','student','education'],categories: 'travel' },
{ name: 'backpack-fill',tags: ['luggage', 'bags','carry-on','student','education'],categories: 'travel' },
{ name: 'backspace',tags: ['key'],categories: 'ui and keyboard' },
{ name: 'backspace-fill',tags: ['key'],categories: 'ui and keyboard' },
{ name: 'backspace-reverse',tags: ['key'],categories: 'ui and keyboard' },
{ name: 'backspace-reverse-fill',tags: ['key'],categories: 'ui and keyboard' },
{ name: 'badge-3d',tags: ['3d', 'display','dimension'],categories: 'badges' },
{ name: 'badge-3d-fill',tags: ['3d', 'display','dimension'],categories: 'badges' },
{ name: 'badge-4k',tags: ['4k', 'display','resolution','retina'],categories: 'badges' },
{ name: 'badge-4k-fill',tags: ['4k', 'display','resolution','retina'],categories: 'badges' },
{ name: 'badge-8k',tags: ['4k', 'display','resolution','retina'],categories: 'badges' },
{ name: 'badge-8k-fill',tags: ['4k', 'display','resolution','retina'],categories: 'badges' },
{ name: 'badge-ad',tags: ['advertisement'],categories: 'badges' },
{ name: 'badge-ad-fill',tags: ['advertisement'],categories: 'badges' },
{ name: 'badge-ar',tags: ['augmented', 'reality','ar'],categories: 'badges' },
{ name: 'badge-ar-fill',tags: ['augmented', 'reality','ar'],categories: 'badges' },
{ name: 'badge-cc',tags: ['closed', 'captioning'],categories: 'badges' },
{ name: 'badge-cc-fill',tags: ['closed', 'captioning'],categories: 'badges' },
{ name: 'badge-hd',tags: ['display', 'resolution','high','definition'],categories: 'badges' },
{ name: 'badge-hd-fill',tags: ['display', 'resolution','high','definition'],categories: 'badges' },
{ name: 'badge-sd',tags: ['display', 'resolution','standard','definition'],categories: 'badges' },
{ name: 'badge-sd-fill',tags: ['display', 'resolution','standard','definition'],categories: 'badges' },
{ name: 'badge-tm',tags: ['trademark'],categories: 'badges' },
{ name: 'badge-tm-fill',tags: ['trademark'],categories: 'badges' },
{ name: 'badge-vo',tags: ['voiceover', 'accessibility'],categories: 'badges' },
{ name: 'badge-vo-fill',tags: ['voiceover', 'accessibility'],categories: 'badges' },
{ name: 'badge-vr',tags: ['virtual', 'reality','vr'],categories: 'badges' },
{ name: 'badge-vr-fill',tags: ['virtual', 'reality','vr'],categories: 'badges' },
{ name: 'badge-wc',tags: ['wash', 'closet','wc'],categories: 'badges' },
{ name: 'badge-wc-fill',tags: ['wash', 'closet','wc'],categories: 'badges' },
{ name: 'bag',tags: ['shopping', 'cart','purchase','buy'],categories: 'commerce' },
{ name: 'bag-check',tags: ['shopping', 'checkout','check','cart','basket'],categories: 'commerce' },
{ name: 'bag-check-fill',tags: ['shopping', 'checkout','check','cart','basket'],categories: 'commerce' },
{ name: 'bag-dash',tags: ['shopping', 'checkout','check','cart','basket'],categories: 'commerce' },
{ name: 'bag-dash-fill',tags: ['shopping', 'checkout','check','cart','basket'],categories: 'commerce' },
{ name: 'bag-fill',tags: ['shopping', 'cart','purchase','buy'],categories: 'commerce' },
{ name: 'bag-heart',tags: ['shopping', 'cart','purchase','buy','valentine'],categories: 'commerce' },
{ name: 'bag-heart-fill',tags: ['shopping', 'cart','purchase','buy','valentine'],categories: 'commerce' },
{ name: 'bag-plus',tags: ['shopping', 'checkout','check','cart','basket'],categories: 'commerce' },
{ name: 'bag-plus-fill',tags: ['shopping', 'add','cart','basket','bag'],categories: 'commerce' },
{ name: 'bag-x',tags: ['shopping', 'checkout','check','cart','basket'],categories: 'commerce' },
{ name: 'bag-x-fill',tags: ['shopping', 'checkout','check','cart','basket'],categories: 'commerce' },
{ name: 'balloon',tags: ['birthday'],categories: 'real world' },
{ name: 'balloon-fill',tags: ['birthday'],categories: 'real world' },
{ name: 'balloon-heart',tags: ['birthday', 'valentine','love'],categories: 'real world love' },
{ name: 'balloon-heart-fill',tags: ['birthday', 'valentine','love'],categories: 'real world love' },
{ name: 'ban',tags: ['false', 'not','allowed','block'],categories: 'real world' },
{ name: 'bandaid',tags: ['bandage', 'health'],categories: 'real world' },
{ name: 'bandaid-fill',tags: ['bandage', 'health'],categories: 'real world' },
{ name: 'ban-fill',tags: ['false', 'not','allowed'],categories: 'real world' },
{ name: 'bank',tags: ['money', 'finance','banking','market','temple'],categories: 'commerce' },
{ name: 'bank2',tags: ['money', 'finance','banking','market','temple'],categories: 'commerce' },
{ name: 'bar-chart',tags: ['chart', 'graph','analytics'],categories: 'data' },
{ name: 'bar-chart-fill',tags: ['chart', 'graph','analytics'],categories: 'data' },
{ name: 'bar-chart-line',tags: ['chart', 'graph','analytics'],categories: 'data' },
{ name: 'bar-chart-line-fill',tags: ['chart', 'graph','analytics'],categories: 'data' },
{ name: 'bar-chart-steps',tags: ['chart', 'graph','analytics'],categories: 'data' },
{ name: 'basket',tags: ['shopping', 'checkout','check','cart','basket'],categories: 'commerce' },
{ name: 'basket2',tags: ['shopping', 'checkout','check','cart','basket'],categories: 'commerce' },
{ name: 'basket2-fill',tags: ['shopping', 'checkout','check','cart','basket'],categories: 'commerce' },
{ name: 'basket3',tags: ['shopping', 'checkout','check','cart','basket'],categories: 'commerce' },
{ name: 'basket3-fill',tags: ['shopping', 'checkout','check','cart','basket'],categories: 'commerce' },
{ name: 'basket-fill',tags: ['shopping', 'checkout','check','cart','basket'],categories: 'commerce' },
{ name: 'battery',tags: ['power', 'charge'],categories: 'devices' },
{ name: 'battery-charging',tags: ['power', 'charge'],categories: 'devices' },
{ name: 'battery-full',tags: ['power', 'charge'],categories: 'devices' },
{ name: 'battery-half',tags: ['power', 'charge'],categories: 'devices' },
{ name: 'battery-low',tags: ['power', 'charge'],categories: 'devices' },
{ name: 'beaker',tags: ['beaker', 'science','measure','experiment'],categories: 'real world' },
{ name: 'beaker-fill',tags: ['beaker', 'science','measure','experiment'],categories: 'real world' },
{ name: 'behance',tags: ['social'],categories: 'brand' },
{ name: 'bell',tags: ['notification', 'clock'],categories: 'communications' },
{ name: 'bell-fill',tags: ['notification', 'clock'],categories: 'communications' },
{ name: 'bell-slash',tags: ['notification', 'silenced','clock'],categories: 'communications' },
{ name: 'bell-slash-fill',tags: ['notification', 'silenced','clock'],categories: 'communications' },
{ name: 'bezier',tags: ['graphics', 'vector','pen'],categories: 'graphics' },
{ name: 'bezier2',tags: ['graphics', 'vector','pen'],categories: 'graphics' },
{ name: 'bicycle',tags: ['bike', 'riding','bicycling'],categories: 'real world' },
{ name: 'bing',tags: ['search', 'microsoft'],categories: 'brand' },
{ name: 'binoculars',tags: ['distance', 'view','search'],categories: 'real world' },
{ name: 'binoculars-fill',tags: ['distance', 'view','search'],categories: 'real world' },
{ name: 'blockquote-left',tags: ['text', 'type'],categories: 'typography' },
{ name: 'blockquote-right',tags: ['text', 'type'],categories: 'typography' },
{ name: 'bluesky',tags: ['social', 'chat','bsky'],categories: 'brand' },
{ name: 'bluetooth',tags: ['wireless'],categories: 'brand' },
{ name: 'body-text',tags: ['content'],categories: 'typography' },
{ name: 'book',tags: ['novel', 'read','magazine'],categories: 'real world' },
{ name: 'book-fill',tags: ['novel', 'read','magazine'],categories: 'real world' },
{ name: 'book-half',tags: ['novel', 'read','magazine'],categories: 'real world' },
{ name: 'bookmark',tags: ['reading', 'book','label','tag','category'],categories: 'miscellaneous' },
{ name: 'bookmark-check',tags: ['reading', 'book','label','tag','category'],categories: 'miscellaneous' },
{ name: 'bookmark-check-fill',tags: ['reading', 'book','label','tag','category'],categories: 'miscellaneous' },
{ name: 'bookmark-dash',tags: ['reading', 'book','label','tag','category'],categories: 'miscellaneous' },
{ name: 'bookmark-dash-fill',tags: ['reading', 'book','label','tag','category'],categories: 'miscellaneous' },
{ name: 'bookmark-fill',tags: ['reading', 'book','label','tag','category'],categories: 'miscellaneous' },
{ name: 'bookmark-heart',tags: ['reading', 'book','label','tag','category'],categories: 'miscellaneous' },
{ name: 'bookmark-heart-fill',tags: ['reading', 'book','label','tag','category'],categories: 'miscellaneous' },
{ name: 'bookmark-plus',tags: ['reading', 'book','label','tag','category'],categories: 'miscellaneous' },
{ name: 'bookmark-plus-fill',tags: ['reading', 'book','label','tag','category'],categories: 'miscellaneous' },
{ name: 'bookmarks',tags: ['reading', 'book','label','tag','category'],categories: 'miscellaneous' },
{ name: 'bookmarks-fill',tags: ['reading', 'book','label','tag','category'],categories: 'miscellaneous' },
{ name: 'bookmark-star',tags: ['reading', 'book','label','tag','category'],categories: 'miscellaneous' },
{ name: 'bookmark-star-fill',tags: ['reading', 'book','label','tag','category'],categories: 'miscellaneous' },
{ name: 'bookmark-x',tags: ['reading', 'book','label','tag','category'],categories: 'miscellaneous' },
{ name: 'bookmark-x-fill',tags: ['reading', 'book','label','tag','category'],categories: 'miscellaneous' },
{ name: 'bookshelf',tags: ['shelf'],categories: 'real world' },
{ name: 'boombox',tags: ['music'],categories: 'real world' },
{ name: 'boombox-fill',tags: ['music'],categories: 'real world' },
{ name: 'bootstrap',tags: ['bootstrap'],categories: 'bootstrap' },
{ name: 'bootstrap-fill',tags: ['bootstrap'],categories: 'bootstrap' },
{ name: 'bootstrap-reboot',tags: ['bootstrap'],categories: 'bootstrap' },
{ name: 'border',tags: ['borders'],categories: 'ui and keyboard' },
{ name: 'border-all',tags: ['borders'],categories: 'ui and keyboard' },
{ name: 'border-bottom',tags: ['borders'],categories: 'ui and keyboard' },
{ name: 'border-center',tags: ['borders', 'centre'],categories: 'ui and keyboard' },
{ name: 'border-inner',tags: ['borders'],categories: 'ui and keyboard' },
{ name: 'border-left',tags: ['borders'],categories: 'ui and keyboard' },
{ name: 'border-middle',tags: ['borders'],categories: 'ui and keyboard' },
{ name: 'border-outer',tags: ['borders'],categories: 'ui and keyboard' },
{ name: 'border-right',tags: ['borders'],categories: 'ui and keyboard' },
{ name: 'border-style',tags: ['borders', 'wysiwyg'],categories: 'typography' },
{ name: 'border-top',tags: ['borders'],categories: 'ui and keyboard' },
{ name: 'border-width',tags: ['borders', 'wysiwyg'],categories: 'typography' },
{ name: 'bounding-box',tags: ['text', 'shape','resize','dimensions'],categories: 'graphics' },
{ name: 'bounding-box-circles',tags: ['text', 'shape','resize','dimensions'],categories: 'graphics' },
{ name: 'box',tags: ['cardboard', 'package','cube'],categories: 'real world' },
{ name: 'box2',tags: ['cardboard', 'package','cube'],categories: 'real world' },
{ name: 'box2-fill',tags: ['cardboard', 'package','cube'],categories: 'real world love' },
{ name: 'box2-heart',tags: ['cardboard', 'package','cube','gift','valentine'],categories: 'real world love' },
{ name: 'box2-heart-fill',tags: ['cardboard', 'package','cube','gift','valentine'],categories: 'real world love' },
{ name: 'box-arrow-down',tags: ['arrow', 'download','save'],categories: 'box arrows' },
{ name: 'box-arrow-down-left',tags: ['arrow'],categories: 'box arrows' },
{ name: 'box-arrow-down-right',tags: ['arrow'],categories: 'box arrows' },
{ name: 'box-arrow-in-down',tags: ['arrow', 'upload'],categories: 'box arrows' },
{ name: 'box-arrow-in-down-left',tags: ['arrow'],categories: 'box arrows' },
{ name: 'box-arrow-in-down-right',tags: ['arrow'],categories: 'box arrows' },
{ name: 'box-arrow-in-left',tags: ['arrow', 'login','signin','enter'],categories: 'box arrows' },
{ name: 'box-arrow-in-right',tags: ['arrow', 'login','signin','enter'],categories: 'box arrows' },
{ name: 'box-arrow-in-up',tags: ['arrow'],categories: 'box arrows' },
{ name: 'box-arrow-in-up-left',tags: ['arrow'],categories: 'box arrows' },
{ name: 'box-arrow-in-up-right',tags: ['arrow'],categories: 'box arrows' },
{ name: 'box-arrow-left',tags: ['arrow', 'logout','signout','exit'],categories: 'box arrows' },
{ name: 'box-arrow-right',tags: ['arrow', 'logout','signout','exit'],categories: 'box arrows' },
{ name: 'box-arrow-up',tags: ['arrow'],categories: 'box arrows' },
{ name: 'box-arrow-up-left',tags: ['arrow', 'external','link'],categories: 'box arrows' },
{ name: 'box-arrow-up-right',tags: ['arrow', 'external','link'],categories: 'box arrows' },
{ name: 'boxes',tags: ['cardboard', 'package','cube'],categories: 'real world' },
{ name: 'box-fill',tags: ['cardboard', 'package'],categories: 'real world' },
{ name: 'box-seam',tags: ['cardboard', 'package'],categories: 'real world' },
{ name: 'box-seam-fill',tags: ['cardboard', 'package'],categories: 'real world' },
{ name: 'braces',tags: ['text', 'type','code','developer','development'],categories: 'typography' },
{ name: 'braces-asterisk',tags: ['text', 'type','code','developer','development'],categories: 'typography' },
{ name: 'bricks',tags: ['wall', 'firewall'],categories: 'real world' },
{ name: 'briefcase',tags: ['business', 'bag','baggage'],categories: 'real world' },
{ name: 'briefcase-fill',tags: ['business', 'bag','baggage'],categories: 'real world' },
{ name: 'brightness-alt-high',tags: ['brightness', 'sun','weather'],categories: 'ui and keyboard' },
{ name: 'brightness-alt-high-fill',tags: ['brightness', 'sun','weather'],categories: 'ui and keyboard' },
{ name: 'brightness-alt-low',tags: ['brightness', 'sun','weather'],categories: 'ui and keyboard' },
{ name: 'brightness-alt-low-fill',tags: ['brightness', 'sun','weather'],categories: 'ui and keyboard' },
{ name: 'brightness-high',tags: ['brightness', 'sun','weather'],categories: 'ui and keyboard' },
{ name: 'brightness-high-fill',tags: ['brightness', 'sun','weather'],categories: 'ui and keyboard' },
{ name: 'brightness-low',tags: ['brightness', 'sun','weather'],categories: 'ui and keyboard' },
{ name: 'brightness-low-fill',tags: ['brightness', 'sun','weather'],categories: 'ui and keyboard' },
{ name: 'brilliance',tags: ['photo', 'editing','image','picture'],categories: 'graphics' },
{ name: 'broadcast',tags: ['radio', 'radio','wave','amplify','wavelength'],categories: 'communications' },
{ name: 'broadcast-pin',tags: ['radio', 'radio','wave','amplify','wavelength'],categories: 'communications' },
{ name: 'browser-chrome',tags: ['google', 'webkit','blink'],categories: 'brand' },
{ name: 'browser-edge',tags: ['microsoft', 'webkit'],categories: 'brand' },
{ name: 'browser-firefox',tags: ['gecko'],categories: 'brand' },
{ name: 'browser-safari',tags: ['webkit', 'apple'],categories: 'brand' },
{ name: 'brush',tags: ['paint', 'art'],categories: 'tools' },
{ name: 'brush-fill',tags: ['paint', 'art'],categories: 'tools' },
{ name: 'bucket',tags: ['tool', 'pail'],categories: 'tools' },
{ name: 'bucket-fill',tags: ['tool', 'pail'],categories: 'tools' },
{ name: 'bug',tags: ['insect', 'error'],categories: 'real world' },
{ name: 'bug-fill',tags: ['insect', 'error'],categories: 'real world' },
{ name: 'building',tags: ['company', 'enterprise','organization','office','business'],categories: 'buildings' },
{ name: 'building-add',tags: ['company', 'enterprise','organization','office','business'],categories: 'buildings' },
{ name: 'building-check',tags: ['company', 'enterprise','organization','office','business'],categories: 'buildings' },
{ name: 'building-dash',tags: ['company', 'enterprise','organization','office','business'],categories: 'buildings' },
{ name: 'building-down',tags: ['company', 'enterprise','organization','office','business'],categories: 'buildings' },
{ name: 'building-exclamation',tags: ['company', 'enterprise','organization','office','business'],categories: 'buildings' },
{ name: 'building-fill',tags: ['company', 'enterprise','organization','office','business'],categories: 'buildings' },
{ name: 'building-fill-add',tags: ['company', 'enterprise','organization','office','business'],categories: 'buildings' },
{ name: 'building-fill-check',tags: ['company', 'enterprise','organization','office','business'],categories: 'buildings' },
{ name: 'building-fill-dash',tags: ['company', 'enterprise','organization','office','business'],categories: 'buildings' },
{ name: 'building-fill-down',tags: ['company', 'enterprise','organization','office','business'],categories: 'buildings' },
{ name: 'building-fill-exclamation',tags: ['company', 'enterprise','organization','office','business'],categories: 'buildings' },
{ name: 'building-fill-gear',tags: ['company', 'enterprise','organization','office','business'],categories: 'buildings' },
{ name: 'building-fill-lock',tags: ['company', 'enterprise','organization','office','business'],categories: 'buildings' },
{ name: 'building-fill-slash',tags: ['company', 'enterprise','organization','office','business'],categories: 'buildings' },
{ name: 'building-fill-up',tags: ['company', 'enterprise','organization','office','business'],categories: 'buildings' },
{ name: 'building-fill-x',tags: ['company', 'enterprise','organization','office','business'],categories: 'buildings' },
{ name: 'building-gear',tags: ['company', 'enterprise','organization','office','business'],categories: 'buildings' },
{ name: 'building-lock',tags: ['company', 'enterprise','organization','office','business'],categories: 'buildings' },
{ name: 'buildings',tags: ['company', 'enterprise','organization','office','business'],categories: 'buildings' },
{ name: 'buildings-fill',tags: ['company', 'enterprise','organization','office','business'],categories: 'buildings' },
{ name: 'building-slash',tags: ['company', 'enterprise','organization','office','business'],categories: 'buildings' },
{ name: 'building-up',tags: ['company', 'enterprise','organization','office','business'],categories: 'buildings' },
{ name: 'building-x',tags: ['company', 'enterprise','organization','office','business'],categories: 'buildings' },
{ name: 'bullseye',tags: ['target'],categories: 'geo' },
{ name: 'bus-front',tags: ['public', 'transit','commute'],categories: 'transportation' },
{ name: 'bus-front-fill',tags: ['public', 'transit','commute'],categories: 'transportation' },
{ name: 'cake',tags: ['birthday', 'celebrate','dessert'],categories: 'real world' },
{ name: 'cake2',tags: ['birthday', 'celebrate','dessert'],categories: 'real world' },
{ name: 'cake2-fill',tags: ['birthday', 'celebrate','dessert'],categories: 'real world' },
{ name: 'cake-fill',tags: ['birthday', 'celebrate','dessert'],categories: 'real world' },
{ name: 'calculator',tags: ['calculator', 'math'],categories: 'devices' },
{ name: 'calculator-fill',tags: ['calculator', 'math'],categories: 'devices' },
{ name: 'calendar',tags: ['date', 'time','month'],categories: 'date and time' },
{ name: 'calendar2',tags: ['date', 'time','month'],categories: 'date and time' },
{ name: 'calendar2-check',tags: ['date', 'time','month'],categories: 'date and time' },
{ name: 'calendar2-check-fill',tags: ['date', 'time','month'],categories: 'date and time' },
{ name: 'calendar2-date',tags: ['date', 'time','month'],categories: 'date and time' },
{ name: 'calendar2-date-fill',tags: ['date', 'time','month'],categories: 'date and time' },
{ name: 'calendar2-day',tags: ['date', 'time','month'],categories: 'date and time' },
{ name: 'calendar2-day-fill',tags: ['date', 'time','month'],categories: 'date and time' },
{ name: 'calendar2-event',tags: ['date', 'time','event','invite'],categories: 'date and time' },
{ name: 'calendar2-event-fill',tags: ['date', 'time','event','invite'],categories: 'date and time' },
{ name: 'calendar2-fill',tags: ['date', 'time','month'],categories: 'date and time' },
{ name: 'calendar2-heart',tags: ['date', 'time','month','valentine','date'],categories: 'date and time love' },
{ name: 'calendar2-heart-fill',tags: ['date', 'time','month','valentine','date'],categories: 'date and time love' },
{ name: 'calendar2-minus',tags: ['date', 'time','month'],categories: 'date and time' },
{ name: 'calendar2-minus-fill',tags: ['date', 'time','month'],categories: 'date and time' },
{ name: 'calendar2-month',tags: ['date', 'time','month'],categories: 'date and time' },
{ name: 'calendar2-month-fill',tags: ['date', 'time','month'],categories: 'date and time' },
{ name: 'calendar2-plus',tags: ['date', 'time','month'],categories: 'date and time' },
{ name: 'calendar2-plus-fill',tags: ['date', 'time','month'],categories: 'date and time' },
{ name: 'calendar2-range',tags: ['dates', 'timeline','duration'],categories: 'date and time' },
{ name: 'calendar2-range-fill',tags: ['dates', 'timeline','duration'],categories: 'date and time' },
{ name: 'calendar2-week',tags: ['dates', 'timeline','duration','week'],categories: 'date and time' },
{ name: 'calendar2-week-fill',tags: ['dates', 'timeline','duration','week'],categories: 'date and time' },
{ name: 'calendar2-x',tags: ['date', 'time','month','remove','delete'],categories: 'date and time' },
{ name: 'calendar2-x-fill',tags: ['date', 'time','month','remove','delete'],categories: 'date and time' },
{ name: 'calendar3',tags: ['date', 'time','month'],categories: 'date and time' },
{ name: 'calendar3-event',tags: ['date', 'time','event','invite'],categories: 'date and time' },
{ name: 'calendar3-event-fill',tags: ['date', 'time','event','invite'],categories: 'date and time' },
{ name: 'calendar3-fill',tags: ['date', 'time','month'],categories: 'date and time' },
{ name: 'calendar3-range',tags: ['dates', 'timeline','duration'],categories: 'date and time' },
{ name: 'calendar3-range-fill',tags: ['dates', 'timeline','duration'],categories: 'date and time' },
{ name: 'calendar3-week',tags: ['dates', 'timeline','duration','week'],categories: 'date and time' },
{ name: 'calendar3-week-fill',tags: ['dates', 'timeline','duration','week'],categories: 'date and time' },
{ name: 'calendar4',tags: ['date', 'time','month'],categories: 'date and time' },
{ name: 'calendar4-event',tags: ['date', 'time','event','invite'],categories: 'date and time' },
{ name: 'calendar4-range',tags: ['dates', 'timeline','duration'],categories: 'date and time' },
{ name: 'calendar4-week',tags: ['dates', 'timeline','duration','week'],categories: 'date and time' },
{ name: 'calendar-check',tags: ['date', 'time','month'],categories: 'date and time' },
{ name: 'calendar-check-fill',tags: ['date', 'time','month'],categories: 'date and time' },
{ name: 'calendar-date',tags: ['date', 'time','month'],categories: 'date and time' },
{ name: 'calendar-date-fill',tags: ['date', 'time','month'],categories: 'date and time' },
{ name: 'calendar-day',tags: ['date', 'time','month'],categories: 'date and time' },
{ name: 'calendar-day-fill',tags: ['date', 'time','month'],categories: 'date and time' },
{ name: 'calendar-event',tags: ['date', 'time','event','invite'],categories: 'date and time' },
{ name: 'calendar-event-fill',tags: ['date', 'time','event','invite'],categories: 'date and time' },
{ name: 'calendar-fill',tags: ['date', 'time','month'],categories: 'date and time' },
{ name: 'calendar-heart',tags: ['date', 'time','month','valentine','date'],categories: 'date and time love' },
{ name: 'calendar-heart-fill',tags: ['date', 'time','month','valentine','date'],categories: 'date and time love' },
{ name: 'calendar-minus',tags: ['date', 'time','month'],categories: 'date and time' },
{ name: 'calendar-minus-fill',tags: ['date', 'time','month'],categories: 'date and time' },
{ name: 'calendar-month',tags: ['date', 'time','month'],categories: 'date and time' },
{ name: 'calendar-month-fill',tags: ['date', 'time','month'],categories: 'date and time' },
{ name: 'calendar-plus',tags: ['date', 'time','month'],categories: 'date and time' },
{ name: 'calendar-plus-fill',tags: ['date', 'time','month'],categories: 'date and time' },
{ name: 'calendar-range',tags: ['dates', 'timeline','duration'],categories: 'date and time' },
{ name: 'calendar-range-fill',tags: ['dates', 'timeline','duration'],categories: 'date and time' },
{ name: 'calendar-week',tags: ['dates', 'timeline','duration','week'],categories: 'date and time' },
{ name: 'calendar-week-fill',tags: ['dates', 'timeline','duration','week'],categories: 'date and time' },
{ name: 'calendar-x',tags: ['date', 'time','month','remove','delete'],categories: 'date and time' },
{ name: 'calendar-x-fill',tags: ['date', 'time','month','remove','delete'],categories: 'date and time' },
{ name: 'camera',tags: ['photos', 'photography'],categories: 'devices' },
{ name: 'camera2',tags: ['photos', 'photography'],categories: 'devices' },
{ name: 'camera-fill',tags: ['photos', 'photography'],categories: 'devices' },
{ name: 'camera-reels',tags: ['av', 'video','film'],categories: 'devices' },
{ name: 'camera-reels-fill',tags: ['av', 'video','film'],categories: 'devices' },
{ name: 'camera-video',tags: ['av', 'video','film'],categories: 'devices' },
{ name: 'camera-video-fill',tags: ['av', 'video','film'],categories: 'devices' },
{ name: 'camera-video-off',tags: ['av', 'video','film'],categories: 'devices' },
{ name: 'camera-video-off-fill',tags: ['av', 'video','film'],categories: 'devices' },
{ name: 'capslock',tags: ['key'],categories: 'ui and keyboard' },
{ name: 'capslock-fill',tags: ['key'],categories: 'ui and keyboard' },
{ name: 'capsule',tags: ['rx', 'pills','capsules','medicine'],categories: 'medical' },
{ name: 'capsule-pill',tags: ['rx', 'pills','capsules','medicine'],categories: 'medical' },
{ name: 'card-checklist',tags: ['note', 'card','notecard'],categories: 'files and folders' },
{ name: 'card-heading',tags: ['note', 'card','notecard'],categories: 'files and folders' },
{ name: 'card-image',tags: ['note', 'card','notecard'],categories: 'files and folders' },
{ name: 'card-list',tags: ['note', 'card','notecard'],categories: 'files and folders' },
{ name: 'card-text',tags: ['note', 'card','notecard','letter'],categories: 'files and folders' },
{ name: 'caret-down',tags: ['caret', 'arrow','triangle'],categories: 'carets' },
{ name: 'caret-down-fill',tags: ['caret', 'arrow','triangle'],categories: 'carets' },
{ name: 'caret-down-square',tags: ['caret', 'arrow','triangle'],categories: 'carets' },
{ name: 'caret-down-square-fill',tags: ['caret', 'arrow','triangle'],categories: 'carets' },
{ name: 'caret-left',tags: ['caret', 'arrow','triangle'],categories: 'carets' },
{ name: 'caret-left-fill',tags: ['caret', 'arrow','triangle'],categories: 'carets' },
{ name: 'caret-left-square',tags: ['caret', 'arrow','triangle'],categories: 'carets' },
{ name: 'caret-left-square-fill',tags: ['caret', 'arrow','triangle'],categories: 'carets' },
{ name: 'caret-right',tags: ['caret', 'arrow','triangle'],categories: 'carets' },
{ name: 'caret-right-fill',tags: ['caret', 'arrow','triangle'],categories: 'carets' },
{ name: 'caret-right-square',tags: ['caret', 'arrow','triangle'],categories: 'carets' },
{ name: 'caret-right-square-fill',tags: ['caret', 'arrow','triangle'],categories: 'carets' },
{ name: 'caret-up',tags: ['caret', 'arrow','triangle'],categories: 'carets' },
{ name: 'caret-up-fill',tags: ['caret', 'arrow','triangle'],categories: 'carets' },
{ name: 'caret-up-square',tags: ['caret', 'arrow','triangle'],categories: 'carets' },
{ name: 'caret-up-square-fill',tags: ['caret', 'arrow','triangle'],categories: 'carets' },
{ name: 'car-front',tags: ['automobile', 'automotive','auto','sedan','drive','driving'],categories: 'transportation' },
{ name: 'car-front-fill',tags: ['automobile', 'automotive','auto','sedan','drive','driving'],categories: 'transportation' },
{ name: 'cart',tags: ['shopping', 'checkout','check','cart','basket'],categories: 'commerce' },
{ name: 'cart2',tags: ['shopping', 'checkout','check','cart','basket'],categories: 'commerce' },
{ name: 'cart3',tags: ['shopping', 'checkout','check','cart','basket'],categories: 'commerce' },
{ name: 'cart4',tags: ['shopping', 'checkout','check','cart','basket'],categories: 'commerce' },
{ name: 'cart-check',tags: ['shopping', 'checkout','check','cart','basket'],categories: 'commerce' },
{ name: 'cart-check-fill',tags: ['shopping', 'checkout','check','cart','basket'],categories: 'commerce' },
{ name: 'cart-dash',tags: ['shopping', 'checkout','check','cart','basket'],categories: 'commerce' },
{ name: 'cart-dash-fill',tags: ['shopping', 'checkout','check','cart','basket'],categories: 'commerce' },
{ name: 'cart-fill',tags: ['shopping', 'checkout','check','cart','basket'],categories: 'commerce' },
{ name: 'cart-plus',tags: ['shopping', 'checkout','check','cart','basket'],categories: 'commerce' },
{ name: 'cart-plus-fill',tags: ['shopping', 'checkout','check','cart','basket'],categories: 'commerce' },
{ name: 'cart-x',tags: ['shopping', 'checkout','check','cart','basket'],categories: 'commerce' },
{ name: 'cart-x-fill',tags: ['shopping', 'checkout','check','cart','basket'],categories: 'commerce' },
{ name: 'cash',tags: ['money', 'bills','funds','wallet','currency'],categories: 'commerce' },
{ name: 'cash-coin',tags: ['money', 'finance','banking','currency'],categories: 'commerce' },
{ name: 'cash-stack',tags: ['money', 'bills','funds','wallet','currency'],categories: 'commerce' },
{ name: 'cassette',tags: ['tape', 'music','audio'],categories: 'media' },
{ name: 'cassette-fill',tags: ['tape', 'music','audio'],categories: 'media' },
{ name: 'cast',tags: ['airplay', 'project','stream','display'],categories: 'media' },
{ name: 'cc-circle',tags: ['creative', 'commons'],categories: 'shapes' },
{ name: 'cc-circle-fill',tags: ['creative', 'commons'],categories: 'shapes' },
{ name: 'c-circle',tags: ['copyright'],categories: 'shapes' },
{ name: 'c-circle-fill',tags: ['copyright'],categories: 'shapes' },
{ name: 'cc-square',tags: ['creative', 'commons'],categories: 'shapes' },
{ name: 'cc-square-fill',tags: ['creative', 'commons'],categories: 'shapes' },
{ name: 'chat',tags: ['chat', 'bubble','text','message','comment'],categories: 'communications' },
{ name: 'chat-dots',tags: ['chat', 'bubble','text','message','typing'],categories: 'communications' },
{ name: 'chat-dots-fill',tags: ['chat', 'bubble','text','message','typing'],categories: 'communications' },
{ name: 'chat-fill',tags: ['chat', 'bubble','text','message','comment'],categories: 'communications' },
{ name: 'chat-heart',tags: ['chat', 'bubble','text','message','valentine','romance'],categories: 'communications love' },
{ name: 'chat-heart-fill',tags: ['chat', 'bubble','text','message','valentine','romance'],categories: 'communications love' },
{ name: 'chat-left',tags: ['chat', 'bubble','text','message','comment'],categories: 'communications' },
{ name: 'chat-left-dots',tags: ['chat', 'bubble','text','message','typing'],categories: 'communications' },
{ name: 'chat-left-dots-fill',tags: ['chat', 'bubble','text','message','typing'],categories: 'communications' },
{ name: 'chat-left-fill',tags: ['chat', 'bubble','text','message','comment'],categories: 'communications' },
{ name: 'chat-left-heart',tags: ['chat', 'bubble','text','message','valentine','romance'],categories: 'communications love' },
{ name: 'chat-left-heart-fill',tags: ['chat', 'bubble','text','message','valentine','romance'],categories: 'communications love' },
{ name: 'chat-left-quote',tags: ['chat', 'bubble','text','message','quote'],categories: 'communications' },
{ name: 'chat-left-quote-fill',tags: ['chat', 'bubble','text','message','quote'],categories: 'communications' },
{ name: 'chat-left-text',tags: ['chat', 'bubble','text','message','comment'],categories: 'communications' },
{ name: 'chat-left-text-fill',tags: ['chat', 'bubble','text','message','comment'],categories: 'communications' },
{ name: 'chat-quote',tags: ['chat', 'bubble','text','message','quote'],categories: 'communications' },
{ name: 'chat-quote-fill',tags: ['chat', 'bubble','text','message','quote'],categories: 'communications' },
{ name: 'chat-right',tags: ['chat', 'bubble','text','message','comment'],categories: 'communications' },
{ name: 'chat-right-dots',tags: ['chat', 'bubble','text','message','typing'],categories: 'communications' },
{ name: 'chat-right-dots-fill',tags: ['chat', 'bubble','text','message','typing'],categories: 'communications' },
{ name: 'chat-right-fill',tags: ['chat', 'bubble','text','message','comment'],categories: 'communications' },
{ name: 'chat-right-heart',tags: ['chat', 'bubble','text','message','valentine','romance'],categories: 'communications love' },
{ name: 'chat-right-heart-fill',tags: ['chat', 'bubble','text','message','valentine','romance'],categories: 'communications love' },
{ name: 'chat-right-quote',tags: ['chat', 'bubble','text','message','quote'],categories: 'communications' },
{ name: 'chat-right-quote-fill',tags: ['chat', 'bubble','text','message','quote'],categories: 'communications' },
{ name: 'chat-right-text',tags: ['chat', 'bubble','text','message','comment'],categories: 'communications' },
{ name: 'chat-right-text-fill',tags: ['chat', 'bubble','text','message','comment'],categories: 'communications' },
{ name: 'chat-square',tags: ['chat', 'bubble','text','message','comment'],categories: 'communications' },
{ name: 'chat-square-dots',tags: ['chat', 'bubble','text','message','typing'],categories: 'communications' },
{ name: 'chat-square-dots-fill',tags: ['chat', 'bubble','text','message','typing'],categories: 'communications' },
{ name: 'chat-square-fill',tags: ['chat', 'bubble','text','message','comment'],categories: 'communications' },
{ name: 'chat-square-heart',tags: ['chat', 'bubble','text','message','valentine','romance'],categories: 'communications love' },
{ name: 'chat-square-heart-fill',tags: ['chat', 'bubble','text','message','valentine','romance'],categories: 'communications love' },
{ name: 'chat-square-quote',tags: ['chat', 'bubble','text','message','quote'],categories: 'communications' },
{ name: 'chat-square-quote-fill',tags: ['chat', 'bubble','text','message','quote'],categories: 'communications' },
{ name: 'chat-square-text',tags: ['chat', 'bubble','text','message','comment'],categories: 'communications' },
{ name: 'chat-square-text-fill',tags: ['chat', 'bubble','text','message','comment'],categories: 'communications' },
{ name: 'chat-text',tags: ['chat', 'bubble','text','message','comment'],categories: 'communications' },
{ name: 'chat-text-fill',tags: ['chat', 'bubble','text','message','comment'],categories: 'communications' },
{ name: 'check',tags: ['checkmark', 'confirm','done'],categories: 'alerts, warnings, and signs' },
{ name: 'check2',tags: ['checkmark', 'todo','select','done','checkbox'],categories: 'ui and keyboard' },
{ name: 'check2-all',tags: ['checkmark', 'todo','select','done','checkbox'],categories: 'ui and keyboard' },
{ name: 'check2-circle',tags: ['checkmark', 'todo','select','done','checkbox'],categories: 'ui and keyboard' },
{ name: 'check2-square',tags: ['checkmark', 'todo','select','done','checkbox'],categories: 'ui and keyboard' },
{ name: 'check-all',tags: ['checkmark', 'todo','done','select'],categories: 'ui and keyboard' },
{ name: 'check-circle',tags: ['checkmark', 'confirm','done'],categories: 'alerts, warnings, and signs' },
{ name: 'check-circle-fill',tags: ['checkmark', 'confirm','done'],categories: 'alerts, warnings, and signs' },
{ name: 'check-lg',tags: ['checkmark', 'confirm','done'],categories: 'alerts, warnings, and signs' },
{ name: 'check-square',tags: ['checkmark', 'confirm','done'],categories: 'alerts, warnings, and signs' },
{ name: 'check-square-fill',tags: ['checkmark', 'confirm','done'],categories: 'alerts, warnings, and signs' },
{ name: 'chevron-bar-contract',tags: ['chevron'],categories: 'chevrons' },
{ name: 'chevron-bar-down',tags: ['chevron'],categories: 'chevrons' },
{ name: 'chevron-bar-expand',tags: ['chevron'],categories: 'chevrons' },
{ name: 'chevron-bar-left',tags: ['chevron'],categories: 'chevrons' },
{ name: 'chevron-bar-right',tags: ['chevron'],categories: 'chevrons' },
{ name: 'chevron-bar-up',tags: ['chevron'],categories: 'chevrons' },
{ name: 'chevron-compact-down',tags: ['chevron'],categories: 'chevrons' },
{ name: 'chevron-compact-left',tags: ['chevron'],categories: 'chevrons' },
{ name: 'chevron-compact-right',tags: ['chevron'],categories: 'chevrons' },
{ name: 'chevron-compact-up',tags: ['chevron'],categories: 'chevrons' },
{ name: 'chevron-contract',tags: ['chevron'],categories: 'chevrons' },
{ name: 'chevron-double-down',tags: ['chevron'],categories: 'chevrons' },
{ name: 'chevron-double-left',tags: ['chevron'],categories: 'chevrons' },
{ name: 'chevron-double-right',tags: ['chevron'],categories: 'chevrons' },
{ name: 'chevron-double-up',tags: ['chevron'],categories: 'chevrons' },
{ name: 'chevron-down',tags: ['chevron'],categories: 'chevrons' },
{ name: 'chevron-expand',tags: ['chevron'],categories: 'chevrons' },
{ name: 'chevron-left',tags: ['chevron', 'math'],categories: 'chevrons' },
{ name: 'chevron-right',tags: ['chevron', 'math'],categories: 'chevrons' },
{ name: 'chevron-up',tags: ['chevron'],categories: 'chevrons' },
{ name: 'circle',tags: ['shape'],categories: 'shapes' },
{ name: 'circle-fill',tags: ['shape'],categories: 'shapes' },
{ name: 'circle-half',tags: ['shape'],categories: 'shapes' },
{ name: 'circle-square',tags: ['graphics', 'vector','merge','layers'],categories: 'graphics' },
{ name: 'claude',tags: ['ai', 'anthropic','agent','artificial','intelligence'],categories: 'brand' },
{ name: 'clipboard',tags: ['copy', 'paste'],categories: 'real world' },
{ name: 'clipboard2',tags: ['copy', 'paste'],categories: 'real world' },
{ name: 'clipboard2-check',tags: ['copy', 'paste'],categories: 'real world' },
{ name: 'clipboard2-check-fill',tags: ['copy', 'paste'],categories: 'real world' },
{ name: 'clipboard2-data',tags: ['copy', 'paste','data','analytics','graph'],categories: 'real world' },
{ name: 'clipboard2-data-fill',tags: ['copy', 'paste','data','analytics','graph'],categories: 'real world' },
{ name: 'clipboard2-fill',tags: ['copy', 'paste'],categories: 'real world' },
{ name: 'clipboard2-heart',tags: ['copy', 'paste'],categories: 'real world' },
{ name: 'clipboard2-heart-fill',tags: ['copy', 'paste'],categories: 'real world' },
{ name: 'clipboard2-minus',tags: ['copy', 'paste'],categories: 'real world' },
{ name: 'clipboard2-minus-fill',tags: ['copy', 'paste'],categories: 'real world' },
{ name: 'clipboard2-plus',tags: ['copy', 'paste'],categories: 'real world' },
{ name: 'clipboard2-plus-fill',tags: ['copy', 'paste'],categories: 'real world' },
{ name: 'clipboard2-pulse',tags: ['copy', 'paste','heartrate'],categories: 'real world medical' },
{ name: 'clipboard2-pulse-fill',tags: ['copy', 'paste','heartrate'],categories: 'real world medical' },
{ name: 'clipboard2-x',tags: ['copy', 'paste'],categories: 'real world' },
{ name: 'clipboard2-x-fill',tags: ['copy', 'paste'],categories: 'real world' },
{ name: 'clipboard-check',tags: ['copy', 'paste'],categories: 'real world' },
{ name: 'clipboard-check-fill',tags: ['copy', 'paste'],categories: 'real world' },
{ name: 'clipboard-data',tags: ['copy', 'paste','data','analytics','graph'],categories: 'real world' },
{ name: 'clipboard-data-fill',tags: ['copy', 'paste','data','analytics','graph'],categories: 'real world' },
{ name: 'clipboard-fill',tags: ['copy', 'paste'],categories: 'real world' },
{ name: 'clipboard-heart',tags: ['copy', 'paste'],categories: 'real world' },
{ name: 'clipboard-heart-fill',tags: ['copy', 'paste'],categories: 'real world' },
{ name: 'clipboard-minus',tags: ['copy', 'paste'],categories: 'real world' },
{ name: 'clipboard-minus-fill',tags: ['copy', 'paste'],categories: 'real world' },
{ name: 'clipboard-plus',tags: ['copy', 'paste'],categories: 'real world' },
{ name: 'clipboard-plus-fill',tags: ['copy', 'paste'],categories: 'real world' },
{ name: 'clipboard-pulse',tags: ['copy', 'paste'],categories: 'real world' },
{ name: 'clipboard-x',tags: ['copy', 'paste'],categories: 'real world' },
{ name: 'clipboard-x-fill',tags: ['copy', 'paste'],categories: 'real world' },
{ name: 'clock',tags: ['time'],categories: 'miscellaneous' },
{ name: 'clock-fill',tags: ['time'],categories: 'miscellaneous' },
{ name: 'clock-history',tags: ['time', 'history'],categories: 'miscellaneous' },
{ name: 'cloud',tags: ['weather','cloud'],categories: 'weather' },
{ name: 'cloud-arrow-down',tags: ['download', 'cloud'],categories: 'weather' },
{ name: 'cloud-arrow-down-fill',tags: ['download', 'cloud'],categories: 'weather' },
{ name: 'cloud-arrow-up',tags: ['upload', 'cloud'],categories: 'weather' },
{ name: 'cloud-arrow-up-fill',tags: ['upload', 'cloud'],categories: 'weather' },
{ name: 'cloud-check',tags: ['checkmark', 'cloud'],categories: 'weather' },
{ name: 'cloud-check-fill',tags: ['checkmark', 'cloud'],categories: 'weather' },
{ name: 'cloud-download',tags: ['arrow', 'save', 'cloud'],categories: 'weather' },
{ name: 'cloud-download-fill',tags: ['arrow', 'save', 'cloud'],categories: 'weather' },
{ name: 'cloud-drizzle',tags: ['storm', 'rain'],categories: 'weather' },
{ name: 'cloud-drizzle-fill',tags: ['storm', 'rain'],categories: 'weather' },
{ name: 'cloud-fill',tags: ['weather', 'cloud'],categories: 'weather' },
{ name: 'cloud-fog',tags: ['foggy'],categories: 'weather' },
{ name: 'cloud-fog2',tags: ['foggy'],categories: 'weather' },
{ name: 'cloud-fog2-fill',tags: ['foggy'],categories: 'weather' },
{ name: 'cloud-fog-fill',tags: ['foggy'],categories: 'weather' },
{ name: 'cloud-hail',tags: ['storm'],categories: 'weather' },
{ name: 'cloud-hail-fill',tags: ['storm'],categories: 'weather' },
{ name: 'cloud-haze',tags: ['smog'],categories: 'weather' },
{ name: 'cloud-haze2',tags: ['smog'],categories: 'weather' },
{ name: 'cloud-haze2-fill',tags: ['smog'],categories: 'weather' },
{ name: 'cloud-haze-fill',tags: ['smog'],categories: 'weather' },
{ name: 'cloud-lightning',tags: ['thunder', 'storm'],categories: 'weather' },
{ name: 'cloud-lightning-fill',tags: ['thunder', 'storm'],categories: 'weather' },
{ name: 'cloud-lightning-rain',tags: ['thunder', 'storm'],categories: 'weather' },
{ name: 'cloud-lightning-rain-fill',tags: ['thunder', 'storm'],categories: 'weather' },
{ name: 'cloud-minus',tags: ['subtract', 'cloud'],categories: 'weather' },
{ name: 'cloud-minus-fill',tags: ['subtract', 'cloud'],categories: 'weather' },
{ name: 'cloud-moon',tags: ['cloudy', 'overcast'],categories: 'weather' },
{ name: 'cloud-moon-fill',tags: ['cloudy', 'overcast'],categories: 'weather' },
{ name: 'cloud-plus',tags: ['add', 'new','upload', 'cloud'],categories: 'weather' },
{ name: 'cloud-plus-fill',tags: ['add', 'new','upload', 'cloud'],categories: 'weather' },
{ name: 'cloud-rain',tags: ['cloud', 'rainstorm','storm'],categories: 'weather' },
{ name: 'cloud-rain-fill',tags: ['cloud', 'rainstorm','storm'],categories: 'weather' },
{ name: 'cloud-rain-heavy',tags: ['cloud', 'rainstorm','storm'],categories: 'weather' },
{ name: 'cloud-rain-heavy-fill',tags: ['cloud', 'rainstorm','storm'],categories: 'weather' },
{ name: 'clouds',tags: ['cloud', 'overcast'],categories: 'weather' },
{ name: 'clouds-fill',tags: ['cloud', 'overcast'],categories: 'weather' },
{ name: 'cloud-slash',tags: ['cloud'],categories: 'weather' },
{ name: 'cloud-slash-fill',tags: ['cloud'],categories: 'weather' },
{ name: 'cloud-sleet',tags: ['cloud', 'blizzard','flurries'],categories: 'weather' },
{ name: 'cloud-sleet-fill',tags: ['cloud', 'blizzard','flurries'],categories: 'weather' },
{ name: 'cloud-snow',tags: ['cloud', 'blizzard','flurries'],categories: 'weather' },
{ name: 'cloud-snow-fill',tags: ['cloud', 'blizzard','flurries'],categories: 'weather' },
{ name: 'cloud-sun',tags: ['cloudy', 'overcast'],categories: 'weather' },
{ name: 'cloud-sun-fill',tags: ['cloudy', 'overcast'],categories: 'weather' },
{ name: 'cloud-upload',tags: ['cloud'],categories: 'weather' },
{ name: 'cloud-upload-fill',tags: ['cloud'],categories: 'weather' },
{ name: 'cloudy',tags: ['cloud', 'overcast'],categories: 'weather' },
{ name: 'cloudy-fill',tags: ['cloud', 'overcast'],categories: 'weather' },
{ name: 'code',tags: ['text', 'type','code','developer','development'],categories: 'typography' },
{ name: 'code-slash',tags: ['text', 'type','code','developer','development'],categories: 'typography' },
{ name: 'code-square',tags: ['text', 'type','code','developer','development','software'],categories: 'typography' },
{ name: 'coin',tags: ['money', 'finance','banking','currency'],categories: 'commerce' },
{ name: 'collection',tags: ['library', 'group'],categories: 'media' },
{ name: 'collection-fill',tags: ['library', 'group'],categories: 'media' },
{ name: 'collection-play',tags: ['library', 'group','play'],categories: 'media' },
{ name: 'collection-play-fill',tags: ['library', 'group','play'],categories: 'media' },
{ name: 'columns',tags: ['grid', 'layout'],categories: 'layout' },
{ name: 'columns-gap',tags: ['grid', 'layout'],categories: 'layout' },
{ name: 'command',tags: ['key', 'mac'],categories: 'ui and keyboard' },
{ name: 'compass',tags: ['direction', 'map','location'],categories: 'geo' },
{ name: 'compass-fill',tags: ['direction', 'map','location'],categories: 'geo' },
{ name: 'cone',tags: ['construction', 'warning','safety'],categories: 'real world' },
{ name: 'cone-striped',tags: ['construction', 'warning','safety'],categories: 'real world' },
{ name: 'controller',tags: ['game', 'gaming','video-game'],categories: 'devices' },
{ name: 'cookie',tags: ['dessert', 'biscuit'],categories: 'real world' },
{ name: 'copy',tags: ['paste', 'clone','cut','duplicate'],categories: 'ui and keyboard' },
{ name: 'cpu',tags: ['processor', 'chip','computer'],categories: 'devices' },
{ name: 'cpu-fill',tags: ['processor', 'chip','computer'],categories: 'devices' },
{ name: 'credit-card',tags: ['debit', 'card','payment'],categories: 'real world' },
{ name: 'credit-card-2-back',tags: ['debit', 'card','payment'],categories: 'real world' },
{ name: 'credit-card-2-back-fill',tags: ['debit', 'card','payment'],categories: 'real world' },
{ name: 'credit-card-2-front',tags: ['debit', 'card','payment'],categories: 'real world' },
{ name: 'credit-card-2-front-fill',tags: ['debit', 'card','payment'],categories: 'real world' },
{ name: 'credit-card-fill',tags: ['debit', 'card','payment'],categories: 'real world' },
{ name: 'crop',tags: ['crop'],categories: 'graphics' },
{ name: 'crosshair',tags: ['geography', 'map','pin','location'],categories: 'geo' },
{ name: 'crosshair2',tags: ['geography', 'map','pin','location'],categories: 'geo' },
{ name: 'c-square',tags: ['copyright'],categories: 'shapes' },
{ name: 'c-square-fill',tags: ['copyright'],categories: 'shapes' },
{ name: 'css',tags: ['frontend', 'stylesheet','web'],categories: 'brand' },
{ name: 'cup',tags: ['mug'],categories: 'real world' },
{ name: 'cup-fill',tags: ['mug'],categories: 'real world' },
{ name: 'cup-hot',tags: ['mug', 'steam','coffee','tea'],categories: 'real world' },
{ name: 'cup-hot-fill',tags: ['mug', 'steam','coffee','tea'],categories: 'real world' },
{ name: 'cup-straw',tags: ['mug', 'glass','drink'],categories: 'real world' },
{ name: 'currency-bitcoin',tags: ['money', 'finance','crypto'],categories: 'commerce' },
{ name: 'currency-dollar',tags: ['money', 'finance','usd'],categories: 'commerce' },
{ name: 'currency-euro',tags: ['money', 'finance'],categories: 'commerce' },
{ name: 'currency-exchange',tags: ['money', 'finance'],categories: 'commerce' },
{ name: 'currency-pound',tags: ['money', 'finance'],categories: 'commerce' },
{ name: 'currency-rupee',tags: ['money', 'finance'],categories: 'commerce' },
{ name: 'currency-yen',tags: ['money', 'finance'],categories: 'commerce' },
{ name: 'cursor',tags: ['pointer'],categories: 'geo' },
{ name: 'cursor-fill',tags: ['pointer'],categories: 'geo' },
{ name: 'cursor-text',tags: ['text', 'type','cursor'],categories: 'typography' },
{ name: 'dash',tags: ['minus', 'math'],categories: 'alerts, warnings, and signs' },
{ name: 'dash-circle',tags: ['minus'],categories: 'alerts, warnings, and signs' },
{ name: 'dash-circle-dotted',tags: ['minus'],categories: 'alerts, warnings, and signs' },
{ name: 'dash-circle-fill',tags: ['minus'],categories: 'alerts, warnings, and signs' },
{ name: 'dash-lg',tags: ['minus', 'math'],categories: 'alerts, warnings, and signs' },
{ name: 'dash-square',tags: ['minus'],categories: 'alerts, warnings, and signs' },
{ name: 'dash-square-dotted',tags: ['minus'],categories: 'alerts, warnings, and signs' },
{ name: 'dash-square-fill',tags: ['minus'],categories: 'alerts, warnings, and signs' },
{ name: 'database',tags: ['server', 'data'],categories: 'devices' },
{ name: 'database-add',tags: ['server', 'data'],categories: 'devices' },
{ name: 'database-check',tags: ['server', 'data'],categories: 'devices' },
{ name: 'database-dash',tags: ['server', 'data'],categories: 'devices' },
{ name: 'database-down',tags: ['server', 'data'],categories: 'devices' },
{ name: 'database-exclamation',tags: ['server', 'data'],categories: 'devices' },
{ name: 'database-fill',tags: ['server', 'data'],categories: 'devices' },
{ name: 'database-fill-add',tags: ['server', 'data'],categories: 'devices' },
{ name: 'database-fill-check',tags: ['server', 'data'],categories: 'devices' },
{ name: 'database-fill-dash',tags: ['server', 'data'],categories: 'devices' },
{ name: 'database-fill-down',tags: ['server', 'data'],categories: 'devices' },
{ name: 'database-fill-exclamation',tags: ['server', 'data'],categories: 'devices' },
{ name: 'database-fill-gear',tags: ['server', 'data'],categories: 'devices' },
{ name: 'database-fill-lock',tags: ['server', 'data'],categories: 'devices' },
{ name: 'database-fill-slash',tags: ['server', 'data'],categories: 'devices' },
{ name: 'database-fill-up',tags: ['server', 'data'],categories: 'devices' },
{ name: 'database-fill-x',tags: ['server', 'data'],categories: 'devices' },
{ name: 'database-gear',tags: ['server', 'data'],categories: 'devices' },
{ name: 'database-lock',tags: ['server', 'data'],categories: 'devices' },
{ name: 'database-slash',tags: ['server', 'data'],categories: 'devices' },
{ name: 'database-up',tags: ['server', 'data'],categories: 'devices' },
{ name: 'database-x',tags: ['server', 'data'],categories: 'devices' },
{ name: 'device-hdd',tags: ['drive', 'hard','drive'],categories: 'devices' },
{ name: 'device-hdd-fill',tags: ['drive', 'hard','drive'],categories: 'devices' },
{ name: 'device-ssd',tags: ['solid', 'state','drive'],categories: 'devices' },
{ name: 'device-ssd-fill',tags: ['solid', 'state','drive'],categories: 'devices' },
{ name: 'diagram-2',tags: ['node', 'diagram','sitemap','children','org'],categories: 'graphics' },
{ name: 'diagram-2-fill',tags: ['node', 'diagram','sitemap','children','org'],categories: 'graphics' },
{ name: 'diagram-3',tags: ['node', 'diagram','sitemap','children','org'],categories: 'graphics' },
{ name: 'diagram-3-fill',tags: ['node', 'diagram','sitemap','children','org'],categories: 'graphics' },
{ name: 'diamond',tags: ['shape'],categories: 'shapes' },
{ name: 'diamond-fill',tags: ['shape'],categories: 'shapes' },
{ name: 'diamond-half',tags: ['shape'],categories: 'shapes' },
{ name: 'dice-1',tags: ['dice', 'die','games','gaming','gambling'],categories: 'entertainment' },
{ name: 'dice-1-fill',tags: ['dice', 'die','games','gaming','gambling'],categories: 'entertainment' },
{ name: 'dice-2',tags: ['dice', 'die','games','gaming','gambling'],categories: 'entertainment' },
{ name: 'dice-2-fill',tags: ['dice', 'die','games','gaming','gambling'],categories: 'entertainment' },
{ name: 'dice-3',tags: ['dice', 'die','games','gaming','gambling'],categories: 'entertainment' },
{ name: 'dice-3-fill',tags: ['dice', 'die','games','gaming','gambling'],categories: 'entertainment' },
{ name: 'dice-4',tags: ['dice', 'die','games','gaming','gambling'],categories: 'entertainment' },
{ name: 'dice-4-fill',tags: ['dice', 'die','games','gaming','gambling'],categories: 'entertainment' },
{ name: 'dice-5',tags: ['dice', 'die','games','gaming','gambling'],categories: 'entertainment' },
{ name: 'dice-5-fill',tags: ['dice', 'die','games','gaming','gambling'],categories: 'entertainment' },
{ name: 'dice-6',tags: ['dice', 'die','games','gaming','gambling'],categories: 'entertainment' },
{ name: 'dice-6-fill',tags: ['dice', 'die','games','gaming','gambling'],categories: 'entertainment' },
{ name: 'disc',tags: ['cd', 'compact','disc','bluray','dvd'],categories: 'media' },
{ name: 'disc-fill',tags: ['cd', 'compact','disc','bluray','dvd'],categories: 'media' },
{ name: 'discord',tags: ['social', 'chat'],categories: 'brand' },
{ name: 'display',tags: ['monitor', 'external'],categories: 'devices' },
{ name: 'display-fill',tags: ['monitor', 'external'],categories: 'devices' },
{ name: 'displayport',tags: ['video', 'input'],categories: 'devices' },
{ name: 'displayport-fill',tags: ['video', 'input'],categories: 'devices' },
{ name: 'distribute-horizontal',tags: ['space', 'align'],categories: 'graphics' },
{ name: 'distribute-vertical',tags: ['space', 'align'],categories: 'graphics' },
{ name: 'door-closed',tags: ['door', 'logout','signout'],categories: 'real world' },
{ name: 'door-closed-fill',tags: ['door', 'logout','signout'],categories: 'real world' },
{ name: 'door-open',tags: ['door', 'login','signin'],categories: 'real world' },
{ name: 'door-open-fill',tags: ['door', 'login','signin'],categories: 'real world' },
{ name: 'dot',tags: ['middot', 'math'],categories: 'ui and keyboard' },
{ name: 'download',tags: ['arrow', 'network','save'],categories: 'miscellaneous' },
{ name: 'dpad',tags: ['gaming', 'controller','direction'],categories: 'entertainment' },
{ name: 'dpad-fill',tags: ['gaming', 'controller','direction'],categories: 'entertainment' },
{ name: 'dribbble',tags: ['social'],categories: 'brand' },
{ name: 'dropbox',tags: ['dropbox'],categories: 'brand' },
{ name: 'droplet',tags: ['water-drop', 'paint','ink','liquid'],categories: 'graphics' },
{ name: 'droplet-fill',tags: ['water-drop', 'paint','ink','liquid'],categories: 'graphics' },
{ name: 'droplet-half',tags: ['water-drop', 'paint','ink','liquid'],categories: 'graphics' },
{ name: 'duffle',tags: ['luggage', 'bags','carry-on','student','education'],categories: 'travel' },
{ name: 'duffle-fill',tags: ['luggage', 'bags','carry-on','student','education'],categories: 'travel' },
{ name: 'ear',tags: ['hearing', 'sound','listen'],categories: 'real world' },
{ name: 'earbuds',tags: ['headphones'],categories: 'devices' },
{ name: 'ear-fill',tags: ['hearing', 'sound','listen'],categories: 'real world' },
{ name: 'easel',tags: ['paint', 'draw','art','present'],categories: 'graphics' },
{ name: 'easel2',tags: ['paint', 'draw','art','present'],categories: 'graphics' },
{ name: 'easel2-fill',tags: ['paint', 'draw','art','present'],categories: 'graphics' },
{ name: 'easel3',tags: ['paint', 'draw','art','present'],categories: 'graphics' },
{ name: 'easel3-fill',tags: ['paint', 'draw','art','present'],categories: 'graphics' },
{ name: 'easel-fill',tags: ['paint', 'draw','art','present'],categories: 'graphics' },
{ name: 'egg',tags: ['food'],categories: 'real world' },
{ name: 'egg-fill',tags: ['food'],categories: 'real world' },
{ name: 'egg-fried',tags: ['food'],categories: 'real world' },
{ name: 'eject',tags: ['disc', 'cd','dvd'],categories: 'ui and keyboard' },
{ name: 'eject-fill',tags: ['disc', 'cd','dvd'],categories: 'ui and keyboard' },
{ name: 'emoji-angry',tags: ['emoticon', 'anger','upset'],categories: 'emoji' },
{ name: 'emoji-angry-fill',tags: ['emoticon', 'anger','upset'],categories: 'emoji' },
{ name: 'emoji-astonished',tags: ['emoticon', 'surprised'],categories: 'emoji' },
{ name: 'emoji-astonished-fill',tags: ['emoticon', 'surprised'],categories: 'emoji' },
{ name: 'emoji-dizzy',tags: ['emoticon'],categories: 'emoji' },
{ name: 'emoji-dizzy-fill',tags: ['emoticon'],categories: 'emoji' },
{ name: 'emoji-expressionless',tags: ['emoticon', 'neutral','unphased'],categories: 'emoji' },
{ name: 'emoji-expressionless-fill',tags: ['emoticon', 'neutral','unphased'],categories: 'emoji' },
{ name: 'emoji-frown',tags: ['emoticon', 'sad'],categories: 'emoji' },
{ name: 'emoji-frown-fill',tags: ['emoticon', 'sad'],categories: 'emoji' },
{ name: 'emoji-grimace',tags: ['emoticon'],categories: 'emoji' },
{ name: 'emoji-grimace-fill',tags: ['emoticon'],categories: 'emoji' },
{ name: 'emoji-grin',tags: ['emoticon'],categories: 'emoji' },
{ name: 'emoji-grin-fill',tags: ['emoticon'],categories: 'emoji' },
{ name: 'emoji-heart-eyes',tags: ['emoticon', 'heart','love'],categories: 'emoji' },
{ name: 'emoji-heart-eyes-fill',tags: ['emoticon', 'heart','love'],categories: 'emoji' },
{ name: 'emoji-kiss',tags: ['emoticon', 'heart','love'],categories: 'emoji' },
{ name: 'emoji-kiss-fill',tags: ['emoticon', 'heart','love'],categories: 'emoji' },
{ name: 'emoji-laughing',tags: ['emoticon', 'happy'],categories: 'emoji' },
{ name: 'emoji-laughing-fill',tags: ['emoticon', 'happy'],categories: 'emoji' },
{ name: 'emoji-neutral',tags: ['emoticon', 'expressionless'],categories: 'emoji' },
{ name: 'emoji-neutral-fill',tags: ['emoticon', 'expressionless'],categories: 'emoji' },
{ name: 'emoji-smile',tags: ['emoticon', 'happy'],categories: 'emoji' },
{ name: 'emoji-smile-fill',tags: ['emoticon', 'happy'],categories: 'emoji' },
{ name: 'emoji-smile-upside-down',tags: ['emoticon', 'sarcasm'],categories: 'emoji' },
{ name: 'emoji-smile-upside-down-fill',tags: ['emoticon', 'sarcasm'],categories: 'emoji' },
{ name: 'emoji-sunglasses',tags: ['emoticon', 'cool'],categories: 'emoji' },
{ name: 'emoji-sunglasses-fill',tags: ['emoticon', 'cool'],categories: 'emoji' },
{ name: 'emoji-surprise',tags: ['emoticon'],categories: 'emoji' },
{ name: 'emoji-surprise-fill',tags: ['emoticon'],categories: 'emoji' },
{ name: 'emoji-tear',tags: ['emoticon'],categories: 'emoji' },
{ name: 'emoji-tear-fill',tags: ['emoticon'],categories: 'emoji' },
{ name: 'emoji-wink',tags: ['emoticon'],categories: 'emoji' },
{ name: 'emoji-wink-fill',tags: ['emoticon'],categories: 'emoji' },
{ name: 'envelope',tags: ['email', 'message','mail','letter'],categories: 'communications' },
{ name: 'envelope-arrow-down',tags: ['email', 'message','mail','letter'],categories: 'communications' },
{ name: 'envelope-arrow-down-fill',tags: ['email', 'message','mail','letter'],categories: 'communications' },
{ name: 'envelope-arrow-up',tags: ['email', 'message','mail','letter'],categories: 'communications' },
{ name: 'envelope-arrow-up-fill',tags: ['email', 'message','mail','letter'],categories: 'communications' },
{ name: 'envelope-at',tags: ['email', 'message','mail','letter'],categories: 'communications' },
{ name: 'envelope-at-fill',tags: ['email', 'message','mail','letter'],categories: 'communications' },
{ name: 'envelope-check',tags: ['email', 'message','mail','letter'],categories: 'communications' },
{ name: 'envelope-check-fill',tags: ['email', 'message','mail','letter'],categories: 'communications' },
{ name: 'envelope-dash',tags: ['email', 'message','mail','letter'],categories: 'communications' },
{ name: 'envelope-dash-fill',tags: ['email', 'message','mail','letter'],categories: 'communications' },
{ name: 'envelope-exclamation',tags: ['email', 'message','mail','letter'],categories: 'communications' },
{ name: 'envelope-exclamation-fill',tags: ['email', 'message','mail','letter'],categories: 'communications' },
{ name: 'envelope-fill',tags: ['email', 'message','mail','letter'],categories: 'communications' },
{ name: 'envelope-heart',tags: ['email', 'message','mail','letter','love','valentine'],categories: 'communications' },
{ name: 'envelope-heart-fill',tags: ['email', 'message','mail','letter','love','valentine'],categories: 'communications' },
{ name: 'envelope-open',tags: ['email', 'message','mail','letter'],categories: 'communications' },
{ name: 'envelope-open-fill',tags: ['email', 'message','mail','letter'],categories: 'communications' },
{ name: 'envelope-open-heart',tags: ['email', 'message','mail','letter','love','valentine'],categories: 'communications' },
{ name: 'envelope-open-heart-fill',tags: ['email', 'message','mail','letter','love','valentine'],categories: 'communications' },
{ name: 'envelope-paper',tags: ['email', 'message','mail','letter'],categories: 'communications' },
{ name: 'envelope-paper-fill',tags: ['email', 'message','mail','letter'],categories: 'communications' },
{ name: 'envelope-paper-heart',tags: ['email', 'message','mail','letter','love','valentine'],categories: 'communications' },
{ name: 'envelope-paper-heart-fill',tags: ['email', 'message','mail','letter','love','valentine'],categories: 'communications' },
{ name: 'envelope-plus',tags: ['email', 'message','mail','letter'],categories: 'communications' },
{ name: 'envelope-plus-fill',tags: ['email', 'message','mail','letter'],categories: 'communications' },
{ name: 'envelope-slash',tags: ['email', 'message','mail','letter'],categories: 'communications' },
{ name: 'envelope-slash-fill',tags: ['email', 'message','mail','letter'],categories: 'communications' },
{ name: 'envelope-x',tags: ['email', 'message','mail','letter'],categories: 'communications' },
{ name: 'envelope-x-fill',tags: ['email', 'message','mail','letter'],categories: 'communications' },
{ name: 'eraser',tags: ['erase', 'remove'],categories: 'graphics' },
{ name: 'eraser-fill',tags: ['erase', 'remove'],categories: 'graphics' },
{ name: 'escape',tags: ['esc', 'quit','exit'],categories: 'ui and keyboard' },
{ name: 'ethernet',tags: ['internet', 'connection','port','plug'],categories: 'devices' },
{ name: 'ev-front',tags: ['car', 'automobile','automotive','auto','sedan','drive','driving','electric','vehicle'],categories: 'transportation' },
{ name: 'ev-front-fill',tags: ['car', 'automobile','automotive','auto','sedan','drive','driving','electric','vehicle'],categories: 'transportation' },
{ name: 'ev-station',tags: ['charging', 'electric','vehicle'],categories: 'transportation' },
{ name: 'ev-station-fill',tags: ['charging', 'electric','vehicle'],categories: 'transportation' },
{ name: 'exclamation',tags: ['alert', 'warning'],categories: 'alerts, warnings, and signs' },
{ name: 'exclamation-circle',tags: ['alert', 'warning'],categories: 'alerts, warnings, and signs' },
{ name: 'exclamation-circle-fill',tags: ['alert', 'warning'],categories: 'alerts, warnings, and signs' },
{ name: 'exclamation-diamond',tags: ['alert', 'warning'],categories: 'alerts, warnings, and signs' },
{ name: 'exclamation-diamond-fill',tags: ['alert', 'warning'],categories: 'alerts, warnings, and signs' },
{ name: 'exclamation-lg',tags: ['alert', 'warning'],categories: 'alerts, warnings, and signs' },
{ name: 'exclamation-octagon',tags: ['alert', 'warning'],categories: 'alerts, warnings, and signs' },
{ name: 'exclamation-octagon-fill',tags: ['alert', 'warning'],categories: 'alerts, warnings, and signs' },
{ name: 'exclamation-square',tags: ['alert', 'warning'],categories: 'alerts, warnings, and signs' },
{ name: 'exclamation-square-fill',tags: ['alert', 'warning'],categories: 'alerts, warnings, and signs' },
{ name: 'exclamation-triangle',tags: ['alert', 'warning'],categories: 'alerts, warnings, and signs' },
{ name: 'exclamation-triangle-fill',tags: ['alert', 'warning'],categories: 'alerts, warnings, and signs' },
{ name: 'exclude',tags: ['graphics', 'vector','merge','layers'],categories: 'graphics' },
{ name: 'explicit',tags: ['r18'],categories: 'badges' },
{ name: 'explicit-fill',tags: ['r18'],categories: 'badges' },
{ name: 'exposure',tags: ['photo', 'editing','image','picture'],categories: 'graphics' },
{ name: 'eye',tags: ['eyeball', 'look','see'],categories: 'real world' },
{ name: 'eyedropper',tags: ['color', 'picker','colour'],categories: 'graphics' },
{ name: 'eye-fill',tags: ['eyeball', 'look','see'],categories: 'real world' },
{ name: 'eyeglasses',tags: ['eyeball', 'look','see','glasses','reading'],categories: 'real world' },
{ name: 'eye-slash',tags: ['eyeball', 'look','see'],categories: 'real world' },
{ name: 'eye-slash-fill',tags: ['eyeball', 'look','see'],categories: 'real world' },
{ name: 'facebook',tags: ['social'],categories: 'brand' },
{ name: 'fan',tags: ['fan', 'vent','airflow'],categories: 'real world' },
{ name: 'fast-forward',tags: ['audio', 'video','av'],categories: 'media' },
{ name: 'fast-forward-btn',tags: ['audio', 'video','av'],categories: 'media' },
{ name: 'fast-forward-btn-fill',tags: ['audio', 'video','av'],categories: 'media' },
{ name: 'fast-forward-circle',tags: ['audio', 'video','av'],categories: 'media' },
{ name: 'fast-forward-circle-fill',tags: ['audio', 'video','av'],categories: 'media' },
{ name: 'fast-forward-fill',tags: ['audio', 'video','av'],categories: 'media' },
{ name: 'feather',tags: ['bird', 'flight','light'],categories: 'real world' },
{ name: 'feather2',tags: ['bird', 'flight','light'],categories: 'real world' },
{ name: 'file',tags: ['doc', 'document','page'],categories: 'files and folders' },
{ name: 'file-arrow-down',tags: ['doc', 'document','download'],categories: 'files and folders' },
{ name: 'file-arrow-down-fill',tags: ['doc', 'document','download'],categories: 'files and folders' },
{ name: 'file-arrow-up',tags: ['doc', 'document','upload'],categories: 'files and folders' },
{ name: 'file-arrow-up-fill',tags: ['doc', 'document','upload'],categories: 'files and folders' },
{ name: 'file-bar-graph',tags: ['doc', 'document','data','chart'],categories: 'files and folders' },
{ name: 'file-bar-graph-fill',tags: ['doc', 'document','data','chart'],categories: 'files and folders' },
{ name: 'file-binary',tags: ['doc', 'document','binary','source'],categories: 'files and folders' },
{ name: 'file-binary-fill',tags: ['doc', 'document','binary','source'],categories: 'files and folders' },
{ name: 'file-break',tags: ['doc', 'document','page-break'],categories: 'files and folders' },
{ name: 'file-break-fill',tags: ['doc', 'document','page-break'],categories: 'files and folders' },
{ name: 'file-check',tags: ['doc', 'document','check','verified'],categories: 'files and folders' },
{ name: 'file-check-fill',tags: ['doc', 'document','check','verified'],categories: 'files and folders' },
{ name: 'file-code',tags: ['doc', 'document','code','development'],categories: 'files and folders' },
{ name: 'file-code-fill',tags: ['doc', 'document','code','development'],categories: 'files and folders' },
{ name: 'file-diff',tags: ['doc', 'document','version','development'],categories: 'files and folders' },
{ name: 'file-diff-fill',tags: ['doc', 'document','version','development'],categories: 'files and folders' },
{ name: 'file-earmark',tags: ['doc', 'document','page'],categories: 'files and folders' },
{ name: 'file-earmark-arrow-down',tags: ['doc', 'document','download'],categories: 'files and folders' },
{ name: 'file-earmark-arrow-down-fill',tags: ['doc', 'document','download'],categories: 'files and folders' },
{ name: 'file-earmark-arrow-up',tags: ['doc', 'document','upload'],categories: 'files and folders' },
{ name: 'file-earmark-arrow-up-fill',tags: ['doc', 'document','upload'],categories: 'files and folders' },
{ name: 'file-earmark-bar-graph',tags: ['doc', 'document','data','chart'],categories: 'files and folders' },
{ name: 'file-earmark-bar-graph-fill',tags: ['doc', 'document','data','chart'],categories: 'files and folders' },
{ name: 'file-earmark-binary',tags: ['doc', 'document','binary','source'],categories: 'files and folders' },
{ name: 'file-earmark-binary-fill',tags: ['doc', 'document','binary','source'],categories: 'files and folders' },
{ name: 'file-earmark-break',tags: ['doc', 'document','page-break'],categories: 'files and folders' },
{ name: 'file-earmark-break-fill',tags: ['doc', 'document','page-break'],categories: 'files and folders' },
{ name: 'file-earmark-check',tags: ['doc', 'document','check','verified'],categories: 'files and folders' },
{ name: 'file-earmark-check-fill',tags: ['doc', 'document','check','verified'],categories: 'files and folders' },
{ name: 'file-earmark-code',tags: ['doc', 'document','code','development'],categories: 'files and folders' },
{ name: 'file-earmark-code-fill',tags: ['doc', 'document','code','development'],categories: 'files and folders' },
{ name: 'file-earmark-diff',tags: ['doc', 'document','version','development'],categories: 'files and folders' },
{ name: 'file-earmark-diff-fill',tags: ['doc', 'document','version','development'],categories: 'files and folders' },
{ name: 'file-earmark-easel',tags: ['slides', 'presentation','powerpoint','keynote'],categories: 'files and folders' },
{ name: 'file-earmark-easel-fill',tags: ['slides', 'presentation','powerpoint','keynote'],categories: 'files and folders' },
{ name: 'file-earmark-excel',tags: ['doc', 'document','spreadsheet','excel','table'],categories: 'files and folders' },
{ name: 'file-earmark-excel-fill',tags: ['doc', 'document','spreadsheet','excel','table'],categories: 'files and folders' },
{ name: 'file-earmark-fill',tags: ['doc', 'document','page'],categories: 'files and folders' },
{ name: 'file-earmark-font',tags: ['ttf', 'otf'],categories: 'files and folders' },
{ name: 'file-earmark-font-fill',tags: ['ttf', 'otf'],categories: 'files and folders' },
{ name: 'file-earmark-image',tags: ['photo', 'picture'],categories: 'files and folders' },
{ name: 'file-earmark-image-fill',tags: ['photo', 'picture'],categories: 'files and folders' },
{ name: 'file-earmark-lock',tags: ['lock', 'private','secure'],categories: 'files and folders' },
{ name: 'file-earmark-lock2',tags: ['lock', 'private','secure'],categories: 'files and folders' },
{ name: 'file-earmark-lock2-fill',tags: ['lock', 'private','secure'],categories: 'files and folders' },
{ name: 'file-earmark-lock-fill',tags: ['lock', 'private','secure'],categories: 'files and folders' },
{ name: 'file-earmark-medical',tags: ['doc', 'document','medical','hospital','health'],categories: 'files and folders' },
{ name: 'file-earmark-medical-fill',tags: ['doc', 'document','medical','hospital','health'],categories: 'files and folders' },
{ name: 'file-earmark-minus',tags: ['doc', 'document','delete','remove'],categories: 'files and folders' },
{ name: 'file-earmark-minus-fill',tags: ['doc', 'document','delete','remove'],categories: 'files and folders' },
{ name: 'file-earmark-music',tags: ['doc', 'document','music','audio','playlist'],categories: 'files and folders' },
{ name: 'file-earmark-music-fill',tags: ['doc', 'document','music','audio','playlist'],categories: 'files and folders' },
{ name: 'file-earmark-pdf',tags: ['doc', 'document','adobe','acrobat'],categories: 'files and folders' },
{ name: 'file-earmark-pdf-fill',tags: ['doc', 'document','adobe','acrobat'],categories: 'files and folders' },
{ name: 'file-earmark-person',tags: ['doc', 'document','personal','cv','resume'],categories: 'files and folders' },
{ name: 'file-earmark-person-fill',tags: ['doc', 'document','personal','cv','resume'],categories: 'files and folders' },
{ name: 'file-earmark-play',tags: ['video', 'present'],categories: 'files and folders' },
{ name: 'file-earmark-play-fill',tags: ['video', 'present'],categories: 'files and folders' },
{ name: 'file-earmark-plus',tags: ['doc', 'document','add','new'],categories: 'files and folders' },
{ name: 'file-earmark-plus-fill',tags: ['doc', 'document','add','new'],categories: 'files and folders' },
{ name: 'file-earmark-post',tags: ['doc', 'document','post'],categories: 'files and folders' },
{ name: 'file-earmark-post-fill',tags: ['doc', 'document','post'],categories: 'files and folders' },
{ name: 'file-earmark-ppt',tags: ['slides', 'presentation','powerpoint','keynote'],categories: 'files and folders' },
{ name: 'file-earmark-ppt-fill',tags: ['slides', 'presentation','powerpoint','keynote'],categories: 'files and folders' },
{ name: 'file-earmark-richtext',tags: ['text', 'doc','document'],categories: 'files and folders' },
{ name: 'file-earmark-richtext-fill',tags: ['text', 'doc','document'],categories: 'files and folders' },
{ name: 'file-earmark-ruled',tags: ['doc', 'document'],categories: 'files and folders' },
{ name: 'file-earmark-ruled-fill',tags: ['doc', 'document'],categories: 'files and folders' },
{ name: 'file-earmark-slides',tags: ['presentation', 'keynote','powerpoint'],categories: 'files and folders' },
{ name: 'file-earmark-slides-fill',tags: ['presentation', 'keynote','powerpoint'],categories: 'files and folders' },
{ name: 'file-earmark-spreadsheet',tags: ['doc', 'document','excel','table'],categories: 'files and folders' },
{ name: 'file-earmark-spreadsheet-fill',tags: ['doc', 'document','excel','table'],categories: 'files and folders' },
{ name: 'file-earmark-text',tags: ['doc', 'document'],categories: 'files and folders' },
{ name: 'file-earmark-text-fill',tags: ['doc', 'document'],categories: 'files and folders' },
{ name: 'file-earmark-word',tags: ['doc', 'document'],categories: 'files and folders' },
{ name: 'file-earmark-word-fill',tags: ['doc', 'document'],categories: 'files and folders' },
{ name: 'file-earmark-x',tags: ['document', 'remove','delete'],categories: 'files and folders' },
{ name: 'file-earmark-x-fill',tags: ['document', 'remove','delete'],categories: 'files and folders' },
{ name: 'file-earmark-zip',tags: ['doc', 'document','zip','archive','compress'],categories: 'files and folders' },
{ name: 'file-earmark-zip-fill',tags: ['doc', 'document','zip','archive','compress'],categories: 'files and folders' },
{ name: 'file-easel',tags: ['slides', 'presentation','powerpoint','keynote'],categories: 'files and folders' },
{ name: 'file-easel-fill',tags: ['slides', 'presentation','powerpoint','keynote'],categories: 'files and folders' },
{ name: 'file-excel',tags: ['doc', 'document','spreadsheet','excel','table'],categories: 'files and folders' },
{ name: 'file-excel-fill',tags: ['doc', 'document','spreadsheet','excel','table'],categories: 'files and folders' },
{ name: 'file-fill',tags: ['doc', 'document','page'],categories: 'files and folders' },
{ name: 'file-font',tags: ['ttf', 'otf'],categories: 'files and folders' },
{ name: 'file-font-fill',tags: ['ttf', 'otf'],categories: 'files and folders' },
{ name: 'file-image',tags: ['photo', 'picture'],categories: 'files and folders' },
{ name: 'file-image-fill',tags: ['photo', 'picture'],categories: 'files and folders' },
{ name: 'file-lock',tags: ['lock', 'private','secure'],categories: 'files and folders' },
{ name: 'file-lock2',tags: ['lock', 'private','secure'],categories: 'files and folders' },
{ name: 'file-lock2-fill',tags: ['lock', 'private','secure'],categories: 'files and folders' },
{ name: 'file-lock-fill',tags: ['lock', 'private','secure'],categories: 'files and folders' },
{ name: 'file-medical',tags: ['doc', 'document','medical','hospital','health'],categories: 'files and folders' },
{ name: 'file-medical-fill',tags: ['doc', 'document','medical','hospital','health'],categories: 'files and folders' },
{ name: 'file-minus',tags: ['doc', 'document','delete','remove'],categories: 'files and folders' },
{ name: 'file-minus-fill',tags: ['doc', 'document','delete','remove'],categories: 'files and folders' },
{ name: 'file-music',tags: ['doc', 'document','music','audio','playlist'],categories: 'files and folders' },
{ name: 'file-music-fill',tags: ['doc', 'document','music','audio','playlist'],categories: 'files and folders' },
{ name: 'file-pdf',tags: ['doc', 'document','adobe','acrobat'],categories: 'files and folders' },
{ name: 'file-pdf-fill',tags: ['doc', 'document','adobe','acrobat'],categories: 'files and folders' },
{ name: 'file-person',tags: ['doc', 'document','personal','cv','resume'],categories: 'files and folders' },
{ name: 'file-person-fill',tags: ['doc', 'document','personal','cv','resume'],categories: 'files and folders' },
{ name: 'file-play',tags: ['video', 'present'],categories: 'files and folders' },
{ name: 'file-play-fill',tags: ['video', 'present'],categories: 'files and folders' },
{ name: 'file-plus',tags: ['doc', 'document','add','new'],categories: 'files and folders' },
{ name: 'file-plus-fill',tags: ['doc', 'document','add','new'],categories: 'files and folders' },
{ name: 'file-post',tags: ['doc', 'document','post'],categories: 'files and folders' },
{ name: 'file-post-fill',tags: ['doc', 'document','post'],categories: 'files and folders' },
{ name: 'file-ppt',tags: ['slides', 'presentation','powerpoint','keynote'],categories: 'files and folders' },
{ name: 'file-ppt-fill',tags: ['slides', 'presentation','powerpoint','keynote'],categories: 'files and folders' },
{ name: 'file-richtext',tags: ['doc', 'document','richtext'],categories: 'files and folders' },
{ name: 'file-richtext-fill',tags: ['doc', 'document','richtext'],categories: 'files and folders' },
{ name: 'file-ruled',tags: ['doc', 'document'],categories: 'files and folders' },
{ name: 'file-ruled-fill',tags: ['doc', 'document'],categories: 'files and folders' },
{ name: 'files',tags: ['doc', 'document'],categories: 'files and folders' },
{ name: 'files-alt',tags: ['doc', 'document'],categories: 'files and folders' },
{ name: 'file-slides',tags: ['presentation', 'keynote','powerpoint'],categories: 'files and folders' },
{ name: 'file-slides-fill',tags: ['presentation', 'keynote','powerpoint'],categories: 'files and folders' },
{ name: 'file-spreadsheet',tags: ['doc', 'document','excel','table'],categories: 'files and folders' },
{ name: 'file-spreadsheet-fill',tags: ['doc', 'document','excel','table'],categories: 'files and folders' },
{ name: 'file-text',tags: ['doc', 'document','letter'],categories: 'files and folders' },
{ name: 'file-text-fill',tags: ['doc', 'document','letter'],categories: 'files and folders' },
{ name: 'filetype-aac',tags: ['file', 'file','type','extension'],categories: 'files and folders' },
{ name: 'filetype-ai',tags: ['file', 'file','type','extension'],categories: 'files and folders' },
{ name: 'filetype-bmp',tags: ['file', 'file','type','extension'],categories: 'files and folders' },
{ name: 'filetype-cs',tags: ['file', 'file','type','extension','code'],categories: 'files and folders' },
{ name: 'filetype-css',tags: ['file', 'file','type','extension','code'],categories: 'files and folders' },
{ name: 'filetype-csv',tags: ['file', 'file','type','extension'],categories: 'files and folders' },
{ name: 'filetype-doc',tags: ['file', 'file','type','extension'],categories: 'files and folders' },
{ name: 'filetype-docx',tags: ['file', 'file','type','extension'],categories: 'files and folders' },
{ name: 'filetype-exe',tags: ['file', 'file','type','extension'],categories: 'files and folders' },
{ name: 'filetype-gif',tags: ['file', 'file','type','extension'],categories: 'files and folders' },
{ name: 'filetype-heic',tags: ['file', 'file','type','extension'],categories: 'files and folders' },
{ name: 'filetype-html',tags: ['file', 'file','type','extension','code'],categories: 'files and folders' },
{ name: 'filetype-java',tags: ['file', 'file','type','extension','code'],categories: 'files and folders' },
{ name: 'filetype-jpg',tags: ['file', 'file','type','extension'],categories: 'files and folders' },
{ name: 'filetype-js',tags: ['file', 'file','type','extension','code'],categories: 'files and folders' },
{ name: 'filetype-json',tags: ['file', 'file','type','extension','code'],categories: 'files and folders' },
{ name: 'filetype-jsx',tags: ['file', 'file','type','extension','code','javascript'],categories: 'files and folders' },
{ name: 'filetype-key',tags: ['file', 'file','type','extension','keynote'],categories: 'files and folders' },
{ name: 'filetype-m4p',tags: ['file', 'file','type','extension'],categories: 'files and folders' },
{ name: 'filetype-md',tags: ['file', 'file','type','extension','code'],categories: 'files and folders' },
{ name: 'filetype-mdx',tags: ['file', 'file','type','extension'],categories: 'files and folders' },
{ name: 'filetype-mov',tags: ['file', 'file','type','extension'],categories: 'files and folders' },
{ name: 'filetype-mp3',tags: ['file', 'file','type','extension'],categories: 'files and folders' },
{ name: 'filetype-mp4',tags: ['file', 'file','type','extension'],categories: 'files and folders' },
{ name: 'filetype-otf',tags: ['file', 'file','type','extension','font'],categories: 'files and folders' },
{ name: 'filetype-pdf',tags: ['file', 'file','type','extension'],categories: 'files and folders' },
{ name: 'filetype-php',tags: ['file', 'file','type','extension','code'],categories: 'files and folders' },
{ name: 'filetype-png',tags: ['file', 'file','type','extension'],categories: 'files and folders' },
{ name: 'filetype-ppt',tags: ['file', 'file','type','extension','powerpoint'],categories: 'files and folders' },
{ name: 'filetype-pptx',tags: ['file', 'file','type','extension','powerpoint'],categories: 'files and folders' },
{ name: 'filetype-psd',tags: ['file', 'file','type','extension'],categories: 'files and folders' },
{ name: 'filetype-py',tags: ['file', 'file','type','extension','code'],categories: 'files and folders' },
{ name: 'filetype-raw',tags: ['file', 'file','type','extension'],categories: 'files and folders' },
{ name: 'filetype-rb',tags: ['file', 'file','type','extension','code'],categories: 'files and folders' },
{ name: 'filetype-sass',tags: ['file', 'file','type','extension','code','css'],categories: 'files and folders' },
{ name: 'filetype-scss',tags: ['file', 'file','type','extension','code','css'],categories: 'files and folders' },
{ name: 'filetype-sh',tags: ['file', 'file','type','extension','code'],categories: 'files and folders' },
{ name: 'filetype-sql',tags: ['file', 'file','type','extension','mysql'],categories: 'files and folders' },
{ name: 'filetype-svg',tags: ['file', 'file','type','extension','code'],categories: 'files and folders' },
{ name: 'filetype-tiff',tags: ['file', 'file','type','extension'],categories: 'files and folders' },
{ name: 'filetype-tsx',tags: ['file', 'file','type','extension','code'],categories: 'files and folders' },
{ name: 'filetype-ttf',tags: ['file', 'file','type','extension','font'],categories: 'files and folders' },
{ name: 'filetype-txt',tags: ['file', 'file','type','extension'],categories: 'files and folders' },
{ name: 'filetype-wav',tags: ['file', 'file','type','extension'],categories: 'files and folders' },
{ name: 'filetype-woff',tags: ['file', 'file','type','extension','font'],categories: 'files and folders' },
{ name: 'filetype-xls',tags: ['file', 'file','type','extension','spreadsheet'],categories: 'files and folders' },
{ name: 'filetype-xlsx',tags: ['file', 'file','type','extension','spreadsheet'],categories: 'files and folders' },
{ name: 'filetype-xml',tags: ['file', 'file','type','extension','code'],categories: 'files and folders' },
{ name: 'filetype-yml',tags: ['file', 'file','type','extension','code'],categories: 'files and folders' },
{ name: 'file-word',tags: ['doc', 'document'],categories: 'files and folders' },
{ name: 'file-word-fill',tags: ['doc', 'document'],categories: 'files and folders' },
{ name: 'file-x',tags: ['document', 'remove','delete'],categories: 'files and folders' },
{ name: 'file-x-fill',tags: ['document', 'remove','delete'],categories: 'files and folders' },
{ name: 'file-zip',tags: ['doc', 'document','zip','archive','compress'],categories: 'files and folders' },
{ name: 'file-zip-fill',tags: ['doc', 'document','zip','archive','compress'],categories: 'files and folders' },
{ name: 'film',tags: ['video', 'movie'],categories: 'media' },
{ name: 'filter',tags: ['sort'],categories: 'ui and keyboard' },
{ name: 'filter-circle',tags: ['sort', 'filter','organize'],categories: 'sort and filter' },
{ name: 'filter-circle-fill',tags: ['sort', 'filter','organize'],categories: 'sort and filter' },
{ name: 'filter-left',tags: ['sort', 'filter','organize'],categories: 'sort and filter' },
{ name: 'filter-right',tags: ['sort', 'filter','organize'],categories: 'sort and filter' },
{ name: 'filter-square',tags: ['sort', 'filter','organize'],categories: 'sort and filter' },
{ name: 'filter-square-fill',tags: ['sort', 'filter','organize'],categories: 'sort and filter' },
{ name: 'fingerprint',tags: ['touch', 'ID','secure','biometric'],categories: 'security' },
{ name: 'fire',tags: ['burning', 'smoke'],categories: 'real world' },
{ name: 'flag',tags: ['report'],categories: 'communications' },
{ name: 'flag-fill',tags: ['report'],categories: 'communications' },
{ name: 'flask',tags: ['beaker', 'science','measure','experiment'],categories: 'real world' },
{ name: 'flask-fill',tags: ['beaker', 'science','measure','experiment'],categories: 'real world' },
{ name: 'flask-florence',tags: ['beaker', 'science','measure','experiment'],categories: 'real world' },
{ name: 'flask-florence-fill',tags: ['beaker', 'science','measure','experiment'],categories: 'real world' },
{ name: 'floppy',tags: ['save', 'storage','disk'],categories: 'devices' },
{ name: 'floppy2',tags: ['save', 'storage','disk'],categories: 'devices' },
{ name: 'floppy2-fill',tags: ['save', 'storage','disk'],categories: 'devices' },
{ name: 'floppy-fill',tags: ['save', 'storage','disk'],categories: 'devices' },
{ name: 'flower1',tags: ['plant', 'bloom','flower'],categories: 'real world' },
{ name: 'flower2',tags: ['plant', 'bloom','flower'],categories: 'real world' },
{ name: 'flower3',tags: ['plant', 'bloom','flower'],categories: 'real world' },
{ name: 'folder',tags: ['directory'],categories: 'files and folders' },
{ name: 'folder2',tags: ['directory'],categories: 'files and folders' },
{ name: 'folder2-open',tags: ['directory'],categories: 'files and folders' },
{ name: 'folder-check',tags: ['directory', 'check','verified'],categories: 'files and folders' },
{ name: 'folder-fill',tags: ['directory'],categories: 'files and folders' },
{ name: 'folder-minus',tags: ['directory', 'delete','remove'],categories: 'files and folders' },
{ name: 'folder-plus',tags: ['directory', 'delete','add','new'],categories: 'files and folders' },
{ name: 'folder-symlink',tags: ['directory', 'symbolic-link'],categories: 'files and folders' },
{ name: 'folder-symlink-fill',tags: ['directory', 'symbolic-link'],categories: 'files and folders' },
{ name: 'folder-x',tags: ['directory', 'remove','delete'],categories: 'files and folders' },
{ name: 'fonts',tags: ['text', 'type'],categories: 'typography' },
{ name: 'fork-knife',tags: ['food', 'restaurant','breakfast','lunch','dinner','utensils'],categories: 'real world' },
{ name: 'forward',tags: ['mail', 'email'],categories: 'communications' },
{ name: 'forward-fill',tags: ['mail', 'email'],categories: 'communications' },
{ name: 'front',tags: ['forward', 'layer'],categories: 'graphics' },
{ name: 'fuel-pump',tags: ['gas', 'petrol','fuel','gas','station'],categories: 'transportation' },
{ name: 'fuel-pump-diesel',tags: ['gas', 'petrol','fuel','gas','station'],categories: 'transportation' },
{ name: 'fuel-pump-diesel-fill',tags: ['gas', 'petrol','fuel','gas','station'],categories: 'transportation' },
{ name: 'fuel-pump-fill',tags: ['gas', 'petrol','fuel','gas','station'],categories: 'transportation' },
{ name: 'fullscreen',tags: ['window', 'maximize'],categories: 'ui and keyboard' },
{ name: 'fullscreen-exit',tags: ['window', 'minimize'],categories: 'ui and keyboard' },
{ name: 'funnel',tags: ['sort', 'filter'],categories: 'real world' },
{ name: 'funnel-fill',tags: ['sort', 'filter'],categories: 'real world' },
{ name: 'gear',tags: ['tool', 'settings','preferences'],categories: 'tools' },
{ name: 'gear-fill',tags: ['tool', 'settings','preferences'],categories: 'tools' },
{ name: 'gear-wide',tags: ['tool', 'settings','preferences'],categories: 'tools' },
{ name: 'gear-wide-connected',tags: ['tool', 'settings','preferences'],categories: 'tools' },
{ name: 'gem',tags: ['shape', 'diamond'],categories: 'shapes' },
{ name: 'gender-ambiguous',tags: ['gender', 'identity'],categories: 'people' },
{ name: 'gender-female',tags: ['gender', 'identity'],categories: 'people' },
{ name: 'gender-male',tags: ['gender', 'identity'],categories: 'people' },
{ name: 'gender-neuter',tags: ['gender', 'identity'],categories: 'people' },
{ name: 'gender-trans',tags: ['gender', 'identity'],categories: 'people' },
{ name: 'geo',tags: ['geography', 'map','pin','location'],categories: 'geo' },
{ name: 'geo-alt',tags: ['geography', 'map','pin','location'],categories: 'geo' },
{ name: 'geo-alt-fill',tags: ['geography', 'map','pin','location'],categories: 'geo' },
{ name: 'geo-fill',tags: ['geography', 'map','pin','location'],categories: 'geo' },
{ name: 'gift',tags: ['present', 'gift'],categories: 'real world' },
{ name: 'gift-fill',tags: ['present', 'gift'],categories: 'real world' },
{ name: 'git',tags: ['version', 'control','vcs','github','version'],categories: 'brand' },
{ name: 'github',tags: ['social', 'microsoft','vcs','git'],categories: 'brand' },
{ name: 'gitlab',tags: ['social', 'vcs','git'],categories: 'brand' },
{ name: 'globe',tags: ['world', 'translate','global','international'],categories: 'communications' },
{ name: 'globe2',tags: ['world', 'translate','global','international'],categories: 'communications' },
{ name: 'globe-americas',tags: ['geography', 'earth','world','map'],categories: 'geo' },
{ name: 'globe-americas-fill',tags: ['geography', 'earth','world','map'],categories: 'geo' },
{ name: 'globe-asia-australia',tags: ['geography', 'earth','world','map'],categories: 'geo' },
{ name: 'globe-asia-australia-fill',tags: ['geography', 'earth','world','map'],categories: 'geo' },
{ name: 'globe-central-south-asia',tags: ['geography', 'earth','world','map'],categories: 'geo' },
{ name: 'globe-central-south-asia-fill',tags: ['geography', 'earth','world','map'],categories: 'geo' },
{ name: 'globe-europe-africa',tags: ['geography', 'earth','world','map'],categories: 'geo' },
{ name: 'globe-europe-africa-fill',tags: ['geography', 'earth','world','map'],categories: 'geo' },
{ name: 'google',tags: ['social', 'search'],categories: 'brand' },
{ name: 'google-play',tags: ['app', 'store','apps','store','android'],categories: 'brand' },
{ name: 'gpu-card',tags: ['video', 'output','graphics'],categories: 'devices' },
{ name: 'graph-down',tags: ['chart', 'graph','analytics'],categories: 'data' },
{ name: 'graph-down-arrow',tags: ['chart', 'graph','analytics'],categories: 'data' },
{ name: 'graph-up',tags: ['chart', 'graph','analytics'],categories: 'data' },
{ name: 'graph-up-arrow',tags: ['chart', 'graph','analytics'],categories: 'data' },
{ name: 'grid',tags: ['grid', 'layout'],categories: 'layout' },
{ name: 'grid-1x2',tags: ['grid', 'layout'],categories: 'layout' },
{ name: 'grid-1x2-fill',tags: ['grid', 'layout'],categories: 'layout' },
{ name: 'grid-3x2',tags: ['grid', 'layout'],categories: 'layout' },
{ name: 'grid-3x2-gap',tags: ['grid', 'layout'],categories: 'layout' },
{ name: 'grid-3x2-gap-fill',tags: ['grid', 'layout'],categories: 'layout' },
{ name: 'grid-3x3',tags: ['grid', 'layout'],categories: 'layout' },
{ name: 'grid-3x3-gap',tags: ['grid', 'layout'],categories: 'layout' },
{ name: 'grid-3x3-gap-fill',tags: ['grid', 'layout'],categories: 'layout' },
{ name: 'grid-fill',tags: ['grid', 'layout'],categories: 'layout' },
{ name: 'grip-horizontal',tags: ['drag', 'grab'],categories: 'ui and keyboard' },
{ name: 'grip-vertical',tags: ['drag', 'grab'],categories: 'ui and keyboard' },
{ name: 'hammer',tags: ['tool'],categories: 'tools' },
{ name: 'handbag',tags: ['purse', 'tote'],categories: 'real world' },
{ name: 'handbag-fill',tags: ['purse', 'tote'],categories: 'real world' },
{ name: 'hand-index',tags: ['hand', 'pointer','cursor'],categories: 'hands' },
{ name: 'hand-index-fill',tags: ['hand', 'pointer','cursor'],categories: 'hands' },
{ name: 'hand-index-thumb',tags: ['hand', 'pointer','cursor'],categories: 'hands' },
{ name: 'hand-index-thumb-fill',tags: ['hand', 'pointer','cursor'],categories: 'hands' },
{ name: 'hand-thumbs-down',tags: ['hand', 'pointer','thumbs-down','-1'],categories: 'hands' },
{ name: 'hand-thumbs-down-fill',tags: ['hand', 'pointer','thumbs-down','-1'],categories: 'hands' },
{ name: 'hand-thumbs-up',tags: ['hand', 'pointer','like','okay','thumbs-up'],categories: 'hands' },
{ name: 'hand-thumbs-up-fill',tags: ['hand', 'pointer','like','okay','thumbs-up'],categories: 'hands' },
{ name: 'hash',tags: ['text', 'type'],categories: 'typography' },
{ name: 'h-circle',tags: ['hospital', 'helicopter'],categories: 'shapes' },
{ name: 'h-circle-fill',tags: ['hospital', 'helicopter'],categories: 'shapes' },
{ name: 'hdd',tags: ['hard', 'drive','hard','disk','ssd','drive'],categories: 'devices' },
{ name: 'hdd-fill',tags: ['hard', 'drive','hard','disk','ssd','drive'],categories: 'devices' },
{ name: 'hdd-network',tags: ['hard', 'drive','hard','disk','ssd','drive'],categories: 'devices' },
{ name: 'hdd-network-fill',tags: ['hard', 'drive','hard','disk','ssd','drive'],categories: 'devices' },
{ name: 'hdd-rack',tags: ['hard', 'drive','hard','disk','ssd','drive'],categories: 'devices' },
{ name: 'hdd-rack-fill',tags: ['hard', 'drive','hard','disk','ssd','drive'],categories: 'devices' },
{ name: 'hdd-stack',tags: ['hard', 'drive','hard','disk','ssd','drive'],categories: 'devices' },
{ name: 'hdd-stack-fill',tags: ['hard', 'drive','hard','disk','ssd','drive'],categories: 'devices' },
{ name: 'hdmi',tags: ['video', 'input'],categories: 'devices' },
{ name: 'hdmi-fill',tags: ['video', 'input'],categories: 'devices' },
{ name: 'headphones',tags: ['headphones'],categories: 'devices' },
{ name: 'headset',tags: ['headphones', 'microphone'],categories: 'devices' },
{ name: 'headset-vr',tags: ['virual', 'reality','oculus','hololens'],categories: 'devices' },
{ name: 'heart',tags: ['love', 'favorite'],categories: 'shapes' },
{ name: 'heart-arrow',tags: ['romance', 'cupid','valentine'],categories: 'love' },
{ name: 'heartbreak',tags: ['love', 'valentine','romance'],categories: 'love' },
{ name: 'heartbreak-fill',tags: ['love', 'valentine','romance'],categories: 'love' },
{ name: 'heart-fill',tags: ['love', 'favorite'],categories: 'shapes' },
{ name: 'heart-half',tags: ['love', 'favorite'],categories: 'shapes' },
{ name: 'heart-pulse',tags: ['heartbeat'],categories: 'medical' },
{ name: 'heart-pulse-fill',tags: ['heartbeat'],categories: 'medical' },
{ name: 'hearts',tags: ['romance', 'valentine'],categories: 'love' },
{ name: 'heptagon',tags: ['shape', 'polygon'],categories: 'shapes' },
{ name: 'heptagon-fill',tags: ['shape', 'polygon'],categories: 'shapes' },
{ name: 'heptagon-half',tags: ['shape', 'polygon'],categories: 'shapes' },
{ name: 'hexagon',tags: ['shape', 'polygon'],categories: 'shapes' },
{ name: 'hexagon-fill',tags: ['shape', 'polygon'],categories: 'shapes' },
{ name: 'hexagon-half',tags: ['shape', 'polygon'],categories: 'shapes' },
{ name: 'highlighter',tags: ['text', 'study','highlight','marker'],categories: 'tools' },
{ name: 'highlights',tags: ['photo', 'editing','image','picture'],categories: 'graphics' },
{ name: 'hospital',tags: ['health', 'emergency','room'],categories: 'medical' },
{ name: 'hospital-fill',tags: ['health', 'emergency','room'],categories: 'medical' },
{ name: 'hourglass',tags: ['time', 'history','wait','sand','clock'],categories: 'real world' },
{ name: 'hourglass-bottom',tags: ['time', 'history','wait','sand','clock'],categories: 'real world' },
{ name: 'hourglass-split',tags: ['time', 'history','wait','sand','clock'],categories: 'real world' },
{ name: 'hourglass-top',tags: ['time', 'history','wait','sand','clock'],categories: 'real world' },
{ name: 'house',tags: ['home'],categories: 'real world' },
{ name: 'house-add',tags: ['home'],categories: 'real world' },
{ name: 'house-add-fill',tags: ['home'],categories: 'real world' },
{ name: 'house-check',tags: ['home'],categories: 'real world' },
{ name: 'house-check-fill',tags: ['home'],categories: 'real world' },
{ name: 'house-dash',tags: ['home'],categories: 'real world' },
{ name: 'house-dash-fill',tags: ['home'],categories: 'real world' },
{ name: 'house-door',tags: ['home'],categories: 'real world' },
{ name: 'house-door-fill',tags: ['home'],categories: 'real world' },
{ name: 'house-down',tags: ['home'],categories: 'real world' },
{ name: 'house-down-fill',tags: ['home'],categories: 'real world' },
{ name: 'house-exclamation',tags: ['home'],categories: 'real world' },
{ name: 'house-exclamation-fill',tags: ['home'],categories: 'real world' },
{ name: 'house-fill',tags: ['home'],categories: 'real world' },
{ name: 'house-gear',tags: ['home'],categories: 'real world' },
{ name: 'house-gear-fill',tags: ['home'],categories: 'real world' },
{ name: 'house-heart',tags: ['home', 'love','romance'],categories: 'real world' },
{ name: 'house-heart-fill',tags: ['home', 'love','romance'],categories: 'real world' },
{ name: 'house-lock',tags: ['home'],categories: 'real world' },
{ name: 'house-lock-fill',tags: ['home'],categories: 'real world' },
{ name: 'houses',tags: ['home'],categories: 'real world' },
{ name: 'houses-fill',tags: ['home'],categories: 'real world' },
{ name: 'house-slash',tags: ['home'],categories: 'real world' },
{ name: 'house-slash-fill',tags: ['home'],categories: 'real world' },
{ name: 'house-up',tags: ['home'],categories: 'real world' },
{ name: 'house-up-fill',tags: ['home'],categories: 'real world' },
{ name: 'house-x',tags: ['home'],categories: 'real world' },
{ name: 'house-x-fill',tags: ['home'],categories: 'real world' },
{ name: 'hr',tags: ['divider', 'horizonal-rule'],categories: 'typography' },
{ name: 'h-square',tags: ['hospital', 'helicopter'],categories: 'shapes' },
{ name: 'h-square-fill',tags: ['hospital', 'helicopter'],categories: 'shapes' },
{ name: 'hurricane',tags: ['storm'],categories: 'weather' },
{ name: 'hypnotize',tags: ['hypnosis', 'sleepy'],categories: 'real world' },
{ name: 'image',tags: ['picture', 'photo'],categories: 'files and folders' },
{ name: 'image-alt',tags: ['picture', 'photo'],categories: 'files and folders' },
{ name: 'image-fill',tags: ['picture', 'photo'],categories: 'files and folders' },
{ name: 'images',tags: ['picture', 'photo'],categories: 'files and folders' },
{ name: 'inbox',tags: ['mail', 'email','letter','tray'],categories: 'communications' },
{ name: 'inboxes',tags: ['mail', 'email','letter','tray'],categories: 'communications' },
{ name: 'inboxes-fill',tags: ['mail', 'email','letter','tray'],categories: 'communications' },
{ name: 'inbox-fill',tags: ['mail', 'email','letter','tray'],categories: 'communications' },
{ name: 'incognito',tags: ['private', 'investigator','secret'],categories: 'miscellaneous' },
{ name: 'indent',tags: ['tab', 'indent'],categories: 'ui and keyboard' },
{ name: 'infinity',tags: ['math', 'infinite'],categories: 'typography' },
{ name: 'info',tags: ['information', 'help'],categories: 'alerts, warnings, and signs' },
{ name: 'info-circle',tags: ['information', 'help'],categories: 'alerts, warnings, and signs' },
{ name: 'info-circle-fill',tags: ['information', 'help'],categories: 'alerts, warnings, and signs' },
{ name: 'info-lg',tags: ['information', 'help'],categories: 'alerts, warnings, and signs' },
{ name: 'info-square',tags: ['information', 'help'],categories: 'alerts, warnings, and signs' },
{ name: 'info-square-fill',tags: ['information', 'help'],categories: 'alerts, warnings, and signs' },
{ name: 'input-cursor',tags: ['textarea', 'form','input'],categories: 'graphics' },
{ name: 'input-cursor-text',tags: ['textarea', 'form','input'],categories: 'graphics' },
{ name: 'instagram',tags: ['social', 'chat'],categories: 'brand' },
{ name: 'intersect',tags: ['graphics', 'vector','merge','layers'],categories: 'graphics' },
{ name: 'javascript',tags: ['frontend', 'js','web'],categories: 'brand' },
{ name: 'journal',tags: ['file', 'folder','journal','notebook'],categories: 'files and folders' },
{ name: 'journal-album',tags: ['file', 'folder','journal','notebook'],categories: 'files and folders' },
{ name: 'journal-arrow-down',tags: ['file', 'folder','journal','notebook'],categories: 'files and folders' },
{ name: 'journal-arrow-up',tags: ['file', 'folder','journal','notebook'],categories: 'files and folders' },
{ name: 'journal-bookmark',tags: ['file', 'folder','journal','notebook'],categories: 'files and folders' },
{ name: 'journal-bookmark-fill',tags: ['file', 'folder','journal','notebook'],categories: 'files and folders' },
{ name: 'journal-check',tags: ['file', 'folder','journal','notebook'],categories: 'files and folders' },
{ name: 'journal-code',tags: ['file', 'folder','journal','notebook'],categories: 'files and folders' },
{ name: 'journal-medical',tags: ['file', 'folder','journal','notebook'],categories: 'files and folders' },
{ name: 'journal-minus',tags: ['file', 'folder','journal','notebook'],categories: 'files and folders' },
{ name: 'journal-plus',tags: ['file', 'folder','journal','notebook'],categories: 'files and folders' },
{ name: 'journal-richtext',tags: ['file', 'folder','journal','notebook'],categories: 'files and folders' },
{ name: 'journals',tags: ['file', 'folder','journal','notebook'],categories: 'files and folders' },
{ name: 'journal-text',tags: ['file', 'folder','journal','notebook'],categories: 'files and folders' },
{ name: 'journal-x',tags: ['file', 'folder','journal','notebook','remove'],categories: 'files and folders' },
{ name: 'joystick',tags: ['gaming', 'game','video','games'],categories: 'entertainment' },
{ name: 'justify',tags: ['text', 'type','justify','alignment'],categories: 'typography' },
{ name: 'justify-left',tags: ['text', 'type','justify','alignment'],categories: 'typography' },
{ name: 'justify-right',tags: ['text', 'type','justify','alignment'],categories: 'typography' },
{ name: 'kanban',tags: ['board', 'project-management'],categories: 'miscellaneous' },
{ name: 'kanban-fill',tags: ['board', 'project-management'],categories: 'miscellaneous' },
{ name: 'key',tags: ['lock', 'secure'],categories: 'real world' },
{ name: 'keyboard',tags: ['keyboard', 'keys','typing'],categories: 'devices' },
{ name: 'keyboard-fill',tags: ['keyboard', 'keys','typing'],categories: 'devices' },
{ name: 'key-fill',tags: ['lock', 'secure'],categories: 'real world' },
{ name: 'ladder',tags: ['climb', 'ladder'],categories: 'real world' },
{ name: 'lamp',tags: ['light', 'lamp'],categories: 'real world' },
{ name: 'lamp-fill',tags: ['light', 'lamp'],categories: 'real world' },
{ name: 'laptop',tags: ['computer'],categories: 'devices' },
{ name: 'laptop-fill',tags: ['computer'],categories: 'devices' },
{ name: 'layer-backward',tags: ['arrange', 'layers','back'],categories: 'graphics' },
{ name: 'layer-forward',tags: ['arrange', 'layers','front'],categories: 'graphics' },
{ name: 'layers',tags: ['perspective', 'stacked'],categories: 'graphics' },
{ name: 'layers-fill',tags: ['perspective', 'stacked'],categories: 'graphics' },
{ name: 'layers-half',tags: ['perspective', 'stacked'],categories: 'graphics' },
{ name: 'layout-sidebar',tags: ['grid', 'layout','sidebar'],categories: 'layout' },
{ name: 'layout-sidebar-inset',tags: ['layout', 'columns'],categories: 'layout' },
{ name: 'layout-sidebar-inset-reverse',tags: ['layout', 'columns'],categories: 'layout' },
{ name: 'layout-sidebar-reverse',tags: ['grid', 'layout','sidebar'],categories: 'layout' },
{ name: 'layout-split',tags: ['grid', 'layout','sidebar'],categories: 'layout' },
{ name: 'layout-text-sidebar',tags: ['layout', 'columns'],categories: 'layout' },
{ name: 'layout-text-sidebar-reverse',tags: ['layout', 'columns'],categories: 'layout' },
{ name: 'layout-text-window',tags: ['layout', 'columns'],categories: 'layout' },
{ name: 'layout-text-window-reverse',tags: ['layout', 'columns'],categories: 'layout' },
{ name: 'layout-three-columns',tags: ['layout', 'columns'],categories: 'layout' },
{ name: 'layout-wtf',tags: ['layout', 'broken'],categories: 'layout' },
{ name: 'leaf',tags: ['nature', 'science','eco','environment','green'],categories: 'real world' },
{ name: 'leaf-fill',tags: ['nature', 'science','eco','environment','green'],categories: 'real world' },
{ name: 'life-preserver',tags: ['lifesaver', 'water'],categories: 'real world' },
{ name: 'lightbulb',tags: ['lights', 'lamp'],categories: 'real world' },
{ name: 'lightbulb-fill',tags: ['lights', 'lamp'],categories: 'real world' },
{ name: 'lightbulb-off',tags: ['lights', 'lamp'],categories: 'real world' },
{ name: 'lightbulb-off-fill',tags: ['lights', 'lamp'],categories: 'real world' },
{ name: 'lightning',tags: ['storm', 'thunder','bolt'],categories: 'weather' },
{ name: 'lightning-charge',tags: ['weather', 'storm','thunder','bolt'],categories: 'miscellaneous' },
{ name: 'lightning-charge-fill',tags: ['weather', 'storm','thunder','bolt'],categories: 'miscellaneous' },
{ name: 'lightning-fill',tags: ['storm', 'thunder','bolt'],categories: 'weather' },
{ name: 'line',tags: ['social'],categories: 'brand' },
{ name: 'link',tags: ['anchor', 'hyperlink','href'],categories: 'ui and keyboard' },
{ name: 'link-45deg',tags: ['anchor', 'hyperlink','href'],categories: 'ui and keyboard' },
{ name: 'linkedin',tags: ['social', 'microsoft'],categories: 'brand' },
{ name: 'list',tags: ['text', 'type','justify','alignment','menu'],categories: 'typography' },
{ name: 'list-check',tags: ['text', 'type','justify','alignment','checklist'],categories: 'typography' },
{ name: 'list-columns',tags: ['text', 'type','alignment'],categories: 'typography' },
{ name: 'list-columns-reverse',tags: ['text', 'type','alignment'],categories: 'typography' },
{ name: 'list-nested',tags: ['text', 'type','alignment','children'],categories: 'typography' },
{ name: 'list-ol',tags: ['text', 'type','justify','alignment','ordered-list','numbered-list'],categories: 'typography' },
{ name: 'list-stars',tags: ['text', 'type','starred'],categories: 'typography' },
{ name: 'list-task',tags: ['text', 'type','justify','alignment','todos'],categories: 'typography' },
{ name: 'list-ul',tags: ['text', 'type','justify','alignment','unordered-list'],categories: 'typography' },
{ name: 'lock',tags: ['privacy', 'security'],categories: 'security' },
{ name: 'lock-fill',tags: ['privacy', 'security'],categories: 'security' },
{ name: 'luggage',tags: ['luggage', 'bags','carry-on','student','education'],categories: 'travel' },
{ name: 'luggage-fill',tags: ['luggage', 'bags','carry-on','student','education'],categories: 'travel' },
{ name: 'lungs',tags: ['medicine', 'health','breathing'],categories: 'medical' },
{ name: 'lungs-fill',tags: ['medicine', 'health','breathing'],categories: 'medical' },
{ name: 'magic',tags: ['magic', 'stick'],categories: 'real world' },
{ name: 'magnet',tags: ['magnetic'],categories: 'real world' },
{ name: 'magnet-fill',tags: ['magnetic'],categories: 'real world' },
{ name: 'mailbox',tags: ['post', 'postal','postbox','letterbox'],categories: 'real world' },
{ name: 'mailbox2',tags: ['post', 'postal','postbox','letterbox'],categories: 'real world' },
{ name: 'mailbox2-flag',tags: ['post', 'postal','postbox','letterbox'],categories: 'real world' },
{ name: 'mailbox-flag',tags: ['post', 'postal','postbox','letterbox'],categories: 'real world' },
{ name: 'map',tags: ['geography', 'directions','location'],categories: 'geo' },
{ name: 'map-fill',tags: ['geography', 'directions','location'],categories: 'geo' },
{ name: 'markdown',tags: ['markdown', 'md','content','writing'],categories: 'badges' },
{ name: 'markdown-fill',tags: ['markdown', 'md','content','writing'],categories: 'badges' },
{ name: 'marker-tip',tags: ['text', 'study','highlight'],categories: 'tools' },
{ name: 'mask',tags: ['mask'],categories: 'graphics' },
{ name: 'mastodon',tags: ['social'],categories: 'brand' },
{ name: 'measuring-cup',tags: ['baking', 'cooking','measure'],categories: 'real world' },
{ name: 'measuring-cup-fill',tags: ['baking', 'cooking','measure'],categories: 'real world' },
{ name: 'medium',tags: ['social'],categories: 'brand' },
{ name: 'megaphone',tags: ['loudspeaker', 'announcement'],categories: 'real world' },
{ name: 'megaphone-fill',tags: ['loudspeaker', 'announcement'],categories: 'real world' },
{ name: 'memory',tags: ['ram'],categories: 'devices' },
{ name: 'menu-app',tags: ['dropdown', 'menu','context','app','ui'],categories: 'controls' },
{ name: 'menu-app-fill',tags: ['dropdown', 'menu','context','app','ui'],categories: 'controls' },
{ name: 'menu-button',tags: ['dropdown', 'menu','context','app','ui'],categories: 'controls' },
{ name: 'menu-button-fill',tags: ['dropdown', 'menu','context','app','ui'],categories: 'controls' },
{ name: 'menu-button-wide',tags: ['dropdown', 'menu','context','app','ui'],categories: 'controls' },
{ name: 'menu-button-wide-fill',tags: ['dropdown', 'menu','context','app','ui'],categories: 'controls' },
{ name: 'menu-down',tags: ['dropdown', 'menu','context','app','ui'],categories: 'controls' },
{ name: 'menu-up',tags: ['dropdown', 'menu','context','app','ui'],categories: 'controls' },
{ name: 'messenger',tags: ['social', 'facebook','chat'],categories: 'brand' },
{ name: 'meta',tags: ['facebook', 'fb'],categories: 'brand' },
{ name: 'mic',tags: ['audio', 'video','av','sound','input','microphone'],categories: 'media' },
{ name: 'mic-fill',tags: ['audio', 'video','av','sound','input','microphone'],categories: 'media' },
{ name: 'mic-mute',tags: ['audio', 'video','av','sound','input'],categories: 'media' },
{ name: 'mic-mute-fill',tags: ['audio', 'video','av','sound','input'],categories: 'media' },
{ name: 'microsoft',tags: ['msft', 'windows'],categories: 'brand' },
{ name: 'microsoft-teams',tags: ['social', 'messaging'],categories: 'brand' },
{ name: 'minecart',tags: ['mining', 'cart'],categories: 'real world' },
{ name: 'minecart-loaded',tags: ['mining', 'cart'],categories: 'real world' },
{ name: 'modem',tags: ['internet', 'cable'],categories: 'devices' },
{ name: 'modem-fill',tags: ['internet', 'cable'],categories: 'devices' },
{ name: 'moisture',tags: ['water', 'droplets','humidity'],categories: 'weather' },
{ name: 'moon',tags: ['lunar', 'weather','night'],categories: 'real world' },
{ name: 'moon-fill',tags: ['night', 'sky'],categories: 'weather' },
{ name: 'moon-stars',tags: ['night', 'sky'],categories: 'weather' },
{ name: 'moon-stars-fill',tags: ['night', 'sky'],categories: 'weather' },
{ name: 'mortarboard',tags: ['graduation', 'cap'],categories: 'real world' },
{ name: 'mortarboard-fill',tags: ['graduation', 'cap'],categories: 'real world' },
{ name: 'motherboard',tags: ['mobo', 'computer','hardware'],categories: 'devices' },
{ name: 'motherboard-fill',tags: ['mobo', 'computer','hardware'],categories: 'devices' },
{ name: 'mouse',tags: ['mice', 'input'],categories: 'devices' },
{ name: 'mouse2',tags: ['mice', 'input'],categories: 'devices' },
{ name: 'mouse2-fill',tags: ['mice', 'input'],categories: 'devices' },
{ name: 'mouse3',tags: ['mice', 'input'],categories: 'devices' },
{ name: 'mouse3-fill',tags: ['mice', 'input'],categories: 'devices' },
{ name: 'mouse-fill',tags: ['mice', 'input'],categories: 'devices' },
{ name: 'music-note',tags: ['music', 'notes','audio','sound'],categories: 'media' },
{ name: 'music-note-beamed',tags: ['music', 'notes','audio','sound'],categories: 'media' },
{ name: 'music-note-list',tags: ['music', 'notes','audio','sound','playlist'],categories: 'media' },
{ name: 'music-player',tags: ['ipod', 'mp3'],categories: 'devices' },
{ name: 'music-player-fill',tags: ['ipod', 'mp3'],categories: 'devices' },
{ name: 'newspaper',tags: ['news', 'paper'],categories: 'real world' },
{ name: 'nintendo-switch',tags: ['gaming'],categories: 'brand' },
{ name: 'node-minus',tags: ['add', 'new','node','diagram'],categories: 'graphics' },
{ name: 'node-minus-fill',tags: ['add', 'new','node','diagram'],categories: 'graphics' },
{ name: 'node-plus',tags: ['add', 'new','node','diagram'],categories: 'graphics' },
{ name: 'node-plus-fill',tags: ['add', 'new','node','diagram'],categories: 'graphics' },
{ name: 'noise-reduction',tags: ['photo', 'editing','image','picture'],categories: 'graphics' },
{ name: 'nut',tags: ['nut', 'bolt','hexagon','tools'],categories: 'tools' },
{ name: 'nut-fill',tags: ['nut', 'bolt','hexagon','tools'],categories: 'tools' },
{ name: 'nvidia',tags: ['gpu'],categories: 'brand' },
{ name: 'nvme',tags: ['ssd', 'hard','drive','hard','disk'],categories: 'devices' },
{ name: 'nvme-fill',tags: ['ssd', 'hard','drive','hard','disk'],categories: 'devices' },
{ name: 'octagon',tags: ['shape', 'polygon'],categories: 'shapes' },
{ name: 'octagon-fill',tags: ['shape', 'polygon'],categories: 'shapes' },
{ name: 'octagon-half',tags: ['shape', 'polygon'],categories: 'shapes' },
{ name: 'openai',tags: ['ai', 'chatgpt','agent','artificial','intelligence'],categories: 'brand' },
{ name: 'opencollective',tags: ['social'],categories: 'brand' },
{ name: 'optical-audio',tags: ['port', 'plug','sound'],categories: 'devices' },
{ name: 'optical-audio-fill',tags: ['port', 'plug','sound'],categories: 'devices' },
{ name: 'option',tags: ['key', 'mac'],categories: 'ui and keyboard' },
{ name: 'outlet',tags: ['plug', 'power'],categories: 'real world' },
{ name: 'paint-bucket',tags: ['color', 'paint','fill','palette','color'],categories: 'graphics' },
{ name: 'palette',tags: ['color', 'paint','fill','palette','colour'],categories: 'graphics' },
{ name: 'palette2',tags: ['color', 'paint','fill','palette','colour'],categories: 'graphics' },
{ name: 'palette-fill',tags: ['color', 'paint','fill','palette','colour'],categories: 'graphics' },
{ name: 'paperclip',tags: ['attachment'],categories: 'real world' },
{ name: 'paragraph',tags: ['paragraph', 'text','body','content'],categories: 'typography' },
{ name: 'pass',tags: ['pass'],categories: 'real world' },
{ name: 'pass-fill',tags: ['pass'],categories: 'real world' },
{ name: 'passport',tags: ['visa', 'foreign'],categories: 'travel' },
{ name: 'passport-fill',tags: ['visa', 'foreign'],categories: 'travel' },
{ name: 'patch-check',tags: ['verified', 'checkmark','certified'],categories: 'badges' },
{ name: 'patch-check-fill',tags: ['verified', 'checkmark','certified'],categories: 'badges' },
{ name: 'patch-exclamation',tags: ['excited', 'certified'],categories: 'badges' },
{ name: 'patch-exclamation-fill',tags: ['excited', 'certified'],categories: 'badges' },
{ name: 'patch-minus',tags: ['subtract'],categories: 'badges' },
{ name: 'patch-minus-fill',tags: ['subtract'],categories: 'badges' },
{ name: 'patch-plus',tags: ['add'],categories: 'badges' },
{ name: 'patch-plus-fill',tags: ['add'],categories: 'badges' },
{ name: 'patch-question',tags: ['help', 'unknown'],categories: 'badges' },
{ name: 'patch-question-fill',tags: ['help', 'unknown'],categories: 'badges' },
{ name: 'pause',tags: ['audio', 'video','av'],categories: 'media' },
{ name: 'pause-btn',tags: ['audio', 'video','av'],categories: 'media' },
{ name: 'pause-btn-fill',tags: ['audio', 'video','av'],categories: 'media' },
{ name: 'pause-circle',tags: ['audio', 'video','av'],categories: 'media' },
{ name: 'pause-circle-fill',tags: ['audio', 'video','av'],categories: 'media' },
{ name: 'pause-fill',tags: ['audio', 'video','av'],categories: 'media' },
{ name: 'paypal',tags: ['social'],categories: 'brand' },
{ name: 'pc',tags: ['computer', 'workstation','desktop'],categories: 'devices' },
{ name: 'pc-display',tags: ['computer', 'workstation','desktop'],categories: 'devices' },
{ name: 'pc-display-horizontal',tags: ['computer', 'workstation','desktop'],categories: 'devices' },
{ name: 'pc-horizontal',tags: ['computer', 'workstation','desktop'],categories: 'devices' },
{ name: 'pci-card',tags: ['card', 'expansion'],categories: 'devices' },
{ name: 'pci-card-network',tags: ['card', 'expansion','ethernet','wifi','internet'],categories: 'devices' },
{ name: 'pci-card-sound',tags: ['card', 'expansion','audio'],categories: 'devices' },
{ name: 'p-circle',tags: ['parking'],categories: 'shapes' },
{ name: 'p-circle-fill',tags: ['parking'],categories: 'shapes' },
{ name: 'peace',tags: ['peace', 'love'],categories: 'miscellaneous' },
{ name: 'peace-fill',tags: ['peace', 'love'],categories: 'miscellaneous' },
{ name: 'pen',tags: ['edit', 'write','ballpoint'],categories: 'tools' },
{ name: 'pencil',tags: ['edit', 'write'],categories: 'tools' },
{ name: 'pencil-fill',tags: ['edit', 'write'],categories: 'tools' },
{ name: 'pencil-square',tags: ['edit', 'write'],categories: 'tools' },
{ name: 'pen-fill',tags: ['edit', 'write','ballpoint'],categories: 'tools' },
{ name: 'pentagon',tags: ['shape', 'polygon'],categories: 'shapes' },
{ name: 'pentagon-fill',tags: ['shape', 'polygon'],categories: 'shapes' },
{ name: 'pentagon-half',tags: ['shape', 'polygon'],categories: 'shapes' },
{ name: 'people',tags: ['member', 'humans','organization','avatar','users'],categories: 'people' },
{ name: 'people-fill',tags: ['member', 'humans','organization','avatar','users'],categories: 'people' },
{ name: 'percent',tags: ['percentage', 'math','fraction'],categories: 'typography' },
{ name: 'perplexity',tags: ['ai', 'agent','artificial','intelligence'],categories: 'brand' },
{ name: 'person',tags: ['member', 'human','individual','avatar','user','account'],categories: 'people' },
{ name: 'person-add',tags: ['member', 'human','individual','avatar','user'],categories: 'people' },
{ name: 'person-arms-up',tags: ['human', 'man','woman'],categories: 'people' },
{ name: 'person-badge',tags: ['member', 'human','individual','avatar','user','badge','id','card','account'],categories: 'people' },
{ name: 'person-badge-fill',tags: ['member', 'human','individual','avatar','user','badge','id','card'],categories: 'people' },
{ name: 'person-bounding-box',tags: ['member', 'human','individual','avatar','user','crop','account'],categories: 'people' },
{ name: 'person-check',tags: ['member', 'human','individual','avatar','user','verified','account'],categories: 'people' },
{ name: 'person-check-fill',tags: ['member', 'human','individual','avatar','user','verified'],categories: 'people' },
{ name: 'person-circle',tags: ['member', 'humans','organization','avatar','user','account'],categories: 'people' },
{ name: 'person-dash',tags: ['member', 'human','individual','avatar','user','remove','delete'],categories: 'people' },
{ name: 'person-dash-fill',tags: ['member', 'human','individual','avatar','user','remove','delete'],categories: 'people' },
{ name: 'person-down',tags: ['member', 'human','individual','avatar','user'],categories: 'people' },
{ name: 'person-exclamation',tags: ['member', 'human','individual','avatar','user'],categories: 'people' },
{ name: 'person-fill',tags: ['member', 'human','individual','avatar','user','account'],categories: 'people' },
{ name: 'person-fill-add',tags: ['member', 'human','individual','avatar','user'],categories: 'people' },
{ name: 'person-fill-check',tags: ['member', 'human','individual','avatar','user'],categories: 'people' },
{ name: 'person-fill-dash',tags: ['member', 'human','individual','avatar','user'],categories: 'people' },
{ name: 'person-fill-down',tags: ['member', 'human','individual','avatar','user'],categories: 'people' },
{ name: 'person-fill-exclamation',tags: ['member', 'human','individual','avatar','user'],categories: 'people' },
{ name: 'person-fill-gear',tags: ['member', 'human','individual','avatar','user'],categories: 'people' },
{ name: 'person-fill-lock',tags: ['member', 'human','individual','avatar','user'],categories: 'people' },
{ name: 'person-fill-slash',tags: ['member', 'human','individual','avatar','user'],categories: 'people' },
{ name: 'person-fill-up',tags: ['member', 'human','individual','avatar','user'],categories: 'people' },
{ name: 'person-fill-x',tags: ['member', 'human','individual','avatar','user'],categories: 'people' },
{ name: 'person-gear',tags: ['member', 'human','individual','avatar','user'],categories: 'people' },
{ name: 'person-heart',tags: ['member', 'human','individual','avatar','user','account','love','valentine'],categories: 'people' },
{ name: 'person-hearts',tags: ['member', 'human','individual','avatar','user','account','love','valentine'],categories: 'people' },
{ name: 'person-lines-fill',tags: ['member', 'human','individual','avatar','user','contact','list','account'],categories: 'people' },
{ name: 'person-lock',tags: ['member', 'human','individual','avatar','user'],categories: 'people' },
{ name: 'person-plus',tags: ['member', 'human','individual','avatar','user','new','add'],categories: 'people' },
{ name: 'person-plus-fill',tags: ['member', 'human','individual','avatar','user','new','add'],categories: 'people' },
{ name: 'person-raised-hand',tags: ['human', 'man','woman','question','help'],categories: 'people' },
{ name: 'person-rolodex',tags: ['member', 'human','individual','avatar','user','account'],categories: 'people' },
{ name: 'person-slash',tags: ['member', 'human','individual','avatar','user'],categories: 'people' },
{ name: 'person-square',tags: ['member', 'human','individual','avatar','user','account'],categories: 'people' },
{ name: 'person-standing',tags: ['human', 'man','woman'],categories: 'people' },
{ name: 'person-standing-dress',tags: ['human', 'man','woman'],categories: 'people' },
{ name: 'person-up',tags: ['member', 'human','individual','avatar','user'],categories: 'people' },
{ name: 'person-vcard',tags: ['human', 'individual','avatar','user','account'],categories: 'people' },
{ name: 'person-vcard-fill',tags: ['human', 'individual','avatar','user','account'],categories: 'people' },
{ name: 'person-video',tags: ['human', 'individual','avatar','user','wfh'],categories: 'people' },
{ name: 'person-video2',tags: ['human', 'individual','avatar','user','wfh'],categories: 'people' },
{ name: 'person-video3',tags: ['human', 'individual','avatar','user','wfh'],categories: 'people' },
{ name: 'person-walking',tags: ['human', 'man','woman'],categories: 'people' },
{ name: 'person-wheelchair',tags: ['human', 'man','woman','accessibility','a11y'],categories: 'people' },
{ name: 'person-workspace',tags: ['human', 'individual','avatar','user','wfh'],categories: 'people' },
{ name: 'person-x',tags: ['member', 'human','individual','avatar','user','remove','delete'],categories: 'people' },
{ name: 'person-x-fill',tags: ['member', 'human','individual','avatar','user','remove','delete'],categories: 'people' },
{ name: 'phone',tags: ['mobile', 'telephone'],categories: 'devices' },
{ name: 'phone-fill',tags: ['mobile', 'telephone'],categories: 'devices' },
{ name: 'phone-flip',tags: ['mobile', 'telephone'],categories: 'devices' },
{ name: 'phone-landscape',tags: ['mobile', 'telephone'],categories: 'devices' },
{ name: 'phone-landscape-fill',tags: ['mobile', 'telephone'],categories: 'devices' },
{ name: 'phone-vibrate',tags: ['mobile', 'telephone','haptic'],categories: 'devices' },
{ name: 'phone-vibrate-fill',tags: ['mobile', 'telephone','haptic'],categories: 'devices' },
{ name: 'pie-chart',tags: ['chart', 'graph','analytics'],categories: 'data' },
{ name: 'pie-chart-fill',tags: ['chart', 'graph','analytics'],categories: 'data' },
{ name: 'piggy-bank',tags: ['money', 'finance','banking','savings'],categories: 'commerce' },
{ name: 'piggy-bank-fill',tags: ['money', 'finance','banking','savings'],categories: 'commerce' },
{ name: 'pin',tags: ['pushpin', 'thumbtack'],categories: 'real world' },
{ name: 'pin-angle',tags: ['pushpin', 'thumbtack'],categories: 'real world' },
{ name: 'pin-angle-fill',tags: ['pushpin', 'thumbtack'],categories: 'real world' },
{ name: 'pin-fill',tags: ['pushpin', 'thumbtack'],categories: 'real world' },
{ name: 'pin-map',tags: ['geography', 'map','pin','location'],categories: 'geo' },
{ name: 'pin-map-fill',tags: ['geography', 'map','pin','location'],categories: 'geo' },
{ name: 'pinterest',tags: ['social'],categories: 'brand' },
{ name: 'pip',tags: ['picture', 'tv','television','display','nested'],categories: 'media' },
{ name: 'pip-fill',tags: ['picture', 'tv','television','display','nested'],categories: 'media' },
{ name: 'play',tags: ['audio', 'video','av'],categories: 'media' },
{ name: 'play-btn',tags: ['audio', 'video','av'],categories: 'media' },
{ name: 'play-btn-fill',tags: ['audio', 'video','av'],categories: 'media' },
{ name: 'play-circle',tags: ['audio', 'video','av'],categories: 'media' },
{ name: 'play-circle-fill',tags: ['audio', 'video','av'],categories: 'media' },
{ name: 'play-fill',tags: ['audio', 'video','av'],categories: 'media' },
{ name: 'playstation',tags: ['sony', 'gaming'],categories: 'brand' },
{ name: 'plug',tags: ['power', 'outlet'],categories: 'real world' },
{ name: 'plug-fill',tags: ['power', 'outlet'],categories: 'real world' },
{ name: 'plugin',tags: ['addon', 'software'],categories: 'ui and keyboard' },
{ name: 'plus',tags: ['add', 'new','math'],categories: 'alerts, warnings, and signs' },
{ name: 'plus-circle',tags: ['add', 'new'],categories: 'alerts, warnings, and signs' },
{ name: 'plus-circle-dotted',tags: ['add', 'new'],categories: 'alerts, warnings, and signs' },
{ name: 'plus-circle-fill',tags: ['add', 'new'],categories: 'alerts, warnings, and signs' },
{ name: 'plus-lg',tags: ['add', 'new','math'],categories: 'alerts, warnings, and signs' },
{ name: 'plus-slash-minus',tags: ['difference', 'add','subtract','math'],categories: 'typography' },
{ name: 'plus-square',tags: ['add', 'new'],categories: 'alerts, warnings, and signs' },
{ name: 'plus-square-dotted',tags: ['add', 'new'],categories: 'alerts, warnings, and signs' },
{ name: 'plus-square-fill',tags: ['add', 'new'],categories: 'alerts, warnings, and signs' },
{ name: 'postage',tags: ['stamp', 'shipping'],categories: 'real world' },
{ name: 'postage-fill',tags: ['mail', 'stamp','shipping'],categories: 'real world' },
{ name: 'postage-heart',tags: ['mail', 'stamp','love','valentine','romance'],categories: 'real world love' },
{ name: 'postage-heart-fill',tags: ['mail', 'stamp','love','valentine','romance'],categories: 'real world love' },
{ name: 'postcard',tags: ['mail', 'letter'],categories: 'real world' },
{ name: 'postcard-fill',tags: ['mail', 'letter'],categories: 'real world' },
{ name: 'postcard-heart',tags: ['mail', 'letter','love','valentine','romance'],categories: 'real world love' },
{ name: 'postcard-heart-fill',tags: ['mail', 'letter','love','valentine','romance'],categories: 'real world love' },
{ name: 'power',tags: ['false', 'true'],categories: 'ui and keyboard' },
{ name: 'prescription',tags: ['rx', 'pills','capsules','medicine'],categories: 'medical' },
{ name: 'prescription2',tags: ['rx', 'pills','capsules','medicine'],categories: 'medical' },
{ name: 'printer',tags: ['print'],categories: 'devices' },
{ name: 'printer-fill',tags: ['print'],categories: 'devices' },
{ name: 'projector',tags: ['projection', 'present','screen'],categories: 'devices' },
{ name: 'projector-fill',tags: ['projection', 'present','screen'],categories: 'devices' },
{ name: 'p-square',tags: ['parking'],categories: 'shapes' },
{ name: 'p-square-fill',tags: ['parking'],categories: 'shapes' },
{ name: 'puzzle',tags: ['puzzle', 'piece'],categories: 'miscellaneous' },
{ name: 'puzzle-fill',tags: ['puzzle', 'piece'],categories: 'miscellaneous' },
{ name: 'qr-code',tags: ['scan'],categories: 'communications' },
{ name: 'qr-code-scan',tags: ['scan'],categories: 'communications' },
{ name: 'question',tags: ['help', 'unknown'],categories: 'alerts, warnings, and signs' },
{ name: 'question-circle',tags: ['help', 'unknown'],categories: 'alerts, warnings, and signs' },
{ name: 'question-circle-fill',tags: ['help', 'unknown'],categories: 'alerts, warnings, and signs' },
{ name: 'question-diamond',tags: ['help', 'unknown'],categories: 'alerts, warnings, and signs' },
{ name: 'question-diamond-fill',tags: ['help', 'unknown'],categories: 'alerts, warnings, and signs' },
{ name: 'question-lg',tags: ['help', 'unknown'],categories: 'alerts, warnings, and signs' },
{ name: 'question-octagon',tags: ['help', 'unknown'],categories: 'alerts, warnings, and signs' },
{ name: 'question-octagon-fill',tags: ['help', 'unknown'],categories: 'alerts, warnings, and signs' },
{ name: 'question-square',tags: ['help', 'unknown'],categories: 'alerts, warnings, and signs' },
{ name: 'question-square-fill',tags: ['help', 'unknown'],categories: 'alerts, warnings, and signs' },
{ name: 'quora',tags: ['social'],categories: 'brand' },
{ name: 'quote',tags: ['blockquote', 'quotes','quotation'],categories: 'typography' },
{ name: 'radar',tags: ['geography', 'map','pin','location'],categories: 'geo' },
{ name: 'radioactive',tags: ['radiation', 'nuclear','danger'],categories: 'real world' },
{ name: 'rainbow',tags: ['colors', 'arches','colour'],categories: 'weather' },
{ name: 'r-circle',tags: ['registered', 'trademark'],categories: 'shapes' },
{ name: 'r-circle-fill',tags: ['registered', 'trademark'],categories: 'shapes' },
{ name: 'receipt',tags: ['receipt', 'invoice','sale','purchase','bill'],categories: 'commerce' },
{ name: 'receipt-cutoff',tags: ['receipt', 'invoice','sale','purchase','bill'],categories: 'commerce' },
{ name: 'reception-0',tags: ['reception', 'cellphone','mobile','carrier','network'],categories: 'communications' },
{ name: 'reception-1',tags: ['reception', 'cellphone','mobile','carrier','network'],categories: 'communications' },
{ name: 'reception-2',tags: ['reception', 'cellphone','mobile','carrier','network'],categories: 'communications' },
{ name: 'reception-3',tags: ['reception', 'cellphone','mobile','carrier','network'],categories: 'communications' },
{ name: 'reception-4',tags: ['reception', 'cellphone','mobile','carrier','network'],categories: 'communications' },
{ name: 'record',tags: ['audio', 'video','av'],categories: 'media' },
{ name: 'record2',tags: ['audio', 'video','av'],categories: 'media' },
{ name: 'record2-fill',tags: ['audio', 'video','av'],categories: 'media' },
{ name: 'record-btn',tags: ['audio', 'video','av'],categories: 'media' },
{ name: 'record-btn-fill',tags: ['audio', 'video','av'],categories: 'media' },
{ name: 'record-circle',tags: ['audio', 'video','av'],categories: 'media' },
{ name: 'record-circle-fill',tags: ['audio', 'video','av'],categories: 'media' },
{ name: 'record-fill',tags: ['audio', 'video','av'],categories: 'media' },
{ name: 'recycle',tags: ['recyling', 'trash'],categories: 'arrows' },
{ name: 'reddit',tags: ['social'],categories: 'brand' },
{ name: 'regex',tags: ['text', 'type','code','developer','development','software','regular'],categories: 'typography' },
{ name: 'repeat',tags: ['audio', 'video','av'],categories: 'media' },
{ name: 'repeat-1',tags: ['audio', 'video','av'],categories: 'media' },
{ name: 'reply',tags: ['mail', 'email'],categories: 'communications' },
{ name: 'reply-all',tags: ['mail', 'email'],categories: 'communications' },
{ name: 'reply-all-fill',tags: ['mail', 'email'],categories: 'communications' },
{ name: 'reply-fill',tags: ['mail', 'email'],categories: 'communications' },
{ name: 'rewind',tags: ['audio', 'video','av'],categories: 'media' },
{ name: 'rewind-btn',tags: ['audio', 'video','av'],categories: 'media' },
{ name: 'rewind-btn-fill',tags: ['audio', 'video','av'],categories: 'media' },
{ name: 'rewind-circle',tags: ['audio', 'video','av'],categories: 'media' },
{ name: 'rewind-circle-fill',tags: ['audio', 'video','av'],categories: 'media' },
{ name: 'rewind-fill',tags: ['audio', 'video','av'],categories: 'media' },
{ name: 'robot',tags: ['bot'],categories: 'devices' },
{ name: 'rocket',tags: ['ship', 'rocketship','spaceship'],categories: 'real world' },
{ name: 'rocket-fill',tags: ['ship', 'rocketship','spaceship'],categories: 'real world' },
{ name: 'rocket-takeoff',tags: ['ship', 'rocketship','spaceship','launch'],categories: 'real world' },
{ name: 'rocket-takeoff-fill',tags: ['ship', 'rocketship','spaceship','launch'],categories: 'real world' },
{ name: 'router',tags: ['wifi', 'internet','wireless','network'],categories: 'devices' },
{ name: 'router-fill',tags: ['wifi', 'internet','wireless','network'],categories: 'devices' },
{ name: 'r-square',tags: ['registered', 'trademark'],categories: 'shapes' },
{ name: 'r-square-fill',tags: ['registered', 'trademark'],categories: 'shapes' },
{ name: 'rss',tags: ['atom', 'feed','xml'],categories: 'communications' },
{ name: 'rss-fill',tags: ['atom', 'feed','xml'],categories: 'communications' },
{ name: 'rulers',tags: ['measure', 'guide'],categories: 'graphics' },
{ name: 'safe',tags: ['vault', 'bank','finance'],categories: 'real world' },
{ name: 'safe2',tags: ['vault', 'bank','finance'],categories: 'real world' },
{ name: 'safe2-fill',tags: ['vault', 'bank','finance'],categories: 'real world' },
{ name: 'safe-fill',tags: ['vault', 'bank','finance'],categories: 'real world' },
{ name: 'save',tags: ['save', 'floppy'],categories: 'ui and keyboard' },
{ name: 'save2',tags: ['save', 'floppy'],categories: 'ui and keyboard' },
{ name: 'save2-fill',tags: ['save', 'floppy'],categories: 'ui and keyboard' },
{ name: 'save-fill',tags: ['save', 'floppy'],categories: 'ui and keyboard' },
{ name: 'scissors',tags: ['cut', 'shears'],categories: 'real world' },
{ name: 'scooter',tags: ['riding'],categories: 'transportation' },
{ name: 'screwdriver',tags: ['tool'],categories: 'tools' },
{ name: 'sd-card',tags: ['storage', 'microsd','chip','memory'],categories: 'devices' },
{ name: 'sd-card-fill',tags: ['storage', 'microsd','chip','memory'],categories: 'devices' },
{ name: 'search',tags: ['magnifying-glass', 'look','lens'],categories: 'communications' },
{ name: 'search-heart',tags: ['magnifying-glass', 'look','love','romance','valentine'],categories: 'communications love' },
{ name: 'search-heart-fill',tags: ['magnifying-glass', 'look','love','romance','valentine'],categories: 'communications love' },
{ name: 'segmented-nav',tags: ['nav', 'tabs','tabbed','app','ui'],categories: 'controls' },
{ name: 'send',tags: ['message', 'sending','sent','paper-plane'],categories: 'communications' },
{ name: 'send-arrow-down',tags: ['message', 'sending','sent','paper-plane'],categories: 'communications' },
{ name: 'send-arrow-down-fill',tags: ['message', 'sending','sent','paper-plane'],categories: 'communications' },
{ name: 'send-arrow-up',tags: ['message', 'sending','sent','paper-plane'],categories: 'communications' },
{ name: 'send-arrow-up-fill',tags: ['message', 'sending','sent','paper-plane'],categories: 'communications' },
{ name: 'send-check',tags: ['message', 'sending','sent','paper-plane'],categories: 'communications' },
{ name: 'send-check-fill',tags: ['message', 'sending','sent','paper-plane'],categories: 'communications' },
{ name: 'send-dash',tags: ['message', 'sending','sent','paper-plane'],categories: 'communications' },
{ name: 'send-dash-fill',tags: ['message', 'sending','sent','paper-plane'],categories: 'communications' },
{ name: 'send-exclamation',tags: ['message', 'sending','sent','paper-plane'],categories: 'communications' },
{ name: 'send-exclamation-fill',tags: ['message', 'sending','sent','paper-plane'],categories: 'communications' },
{ name: 'send-fill',tags: ['message', 'sending','sent','paper-plane'],categories: 'communications' },
{ name: 'send-plus',tags: ['message', 'sending','sent','paper-plane'],categories: 'communications' },
{ name: 'send-plus-fill',tags: ['message', 'sending','sent','paper-plane'],categories: 'communications' },
{ name: 'send-slash',tags: ['message', 'sending','sent','paper-plane'],categories: 'communications' },
{ name: 'send-slash-fill',tags: ['message', 'sending','sent','paper-plane'],categories: 'communications' },
{ name: 'send-x',tags: ['message', 'sending','sent','paper-plane'],categories: 'communications' },
{ name: 'send-x-fill',tags: ['message', 'sending','sent','paper-plane'],categories: 'communications' },
{ name: 'server',tags: ['server', 'network'],categories: 'devices' },
{ name: 'shadows',tags: ['photo', 'editing','image','picture'],categories: 'graphics' },
{ name: 'share',tags: ['share', 'link'],categories: 'communications' },
{ name: 'share-fill',tags: ['share', 'link'],categories: 'communications' },
{ name: 'shield',tags: ['privacy', 'security'],categories: 'security' },
{ name: 'shield-check',tags: ['privacy', 'security'],categories: 'security' },
{ name: 'shield-exclamation',tags: ['privacy', 'security'],categories: 'security' },
{ name: 'shield-fill',tags: ['privacy', 'security'],categories: 'security' },
{ name: 'shield-fill-check',tags: ['privacy', 'security'],categories: 'security' },
{ name: 'shield-fill-exclamation',tags: ['privacy', 'security'],categories: 'security' },
{ name: 'shield-fill-minus',tags: ['privacy', 'security'],categories: 'security' },
{ name: 'shield-fill-plus',tags: ['privacy', 'security'],categories: 'security' },
{ name: 'shield-fill-x',tags: ['privacy', 'security','remove','delete'],categories: 'security' },
{ name: 'shield-lock',tags: ['privacy', 'security','lock'],categories: 'security' },
{ name: 'shield-lock-fill',tags: ['privacy', 'security','lock'],categories: 'security' },
{ name: 'shield-minus',tags: ['privacy', 'security'],categories: 'security' },
{ name: 'shield-plus',tags: ['privacy', 'security'],categories: 'security' },
{ name: 'shield-shaded',tags: ['privacy', 'security'],categories: 'security' },
{ name: 'shield-slash',tags: ['shield', 'badge'],categories: 'security' },
{ name: 'shield-slash-fill',tags: ['shield', 'badge'],categories: 'security' },
{ name: 'shield-x',tags: ['privacy', 'security','remove','delete'],categories: 'security' },
{ name: 'shift',tags: ['key'],categories: 'ui and keyboard' },
{ name: 'shift-fill',tags: ['key'],categories: 'ui and keyboard' },
{ name: 'shop',tags: ['shop', 'store','market','marketplace','shopping'],categories: 'commerce' },
{ name: 'shop-window',tags: ['shop', 'store','market','marketplace','shopping'],categories: 'commerce' },
{ name: 'shuffle',tags: ['shuffle', 'random'],categories: 'arrows' },
{ name: 'signal',tags: ['social'],categories: 'brand' },
{ name: 'sign-dead-end',tags: ['road', 'driving','directions'],categories: 'transportation' },
{ name: 'sign-dead-end-fill',tags: ['road', 'driving','directions'],categories: 'transportation' },
{ name: 'sign-do-not-enter',tags: ['road', 'driving','directions'],categories: 'transportation' },
{ name: 'sign-do-not-enter-fill',tags: ['road', 'driving','directions'],categories: 'transportation' },
{ name: 'sign-intersection',tags: ['road', 'driving','directions'],categories: 'transportation' },
{ name: 'sign-intersection-fill',tags: ['road', 'driving','directions'],categories: 'transportation' },
{ name: 'sign-intersection-side',tags: ['road', 'driving','directions'],categories: 'transportation' },
{ name: 'sign-intersection-side-fill',tags: ['road', 'driving','directions'],categories: 'transportation' },
{ name: 'sign-intersection-t',tags: ['road', 'driving','directions'],categories: 'transportation' },
{ name: 'sign-intersection-t-fill',tags: ['road', 'driving','directions'],categories: 'transportation' },
{ name: 'sign-intersection-y',tags: ['road', 'driving','directions'],categories: 'transportation' },
{ name: 'sign-intersection-y-fill',tags: ['road', 'driving','directions'],categories: 'transportation' },
{ name: 'sign-merge-left',tags: ['road', 'driving','directions'],categories: 'transportation' },
{ name: 'sign-merge-left-fill',tags: ['road', 'driving','directions'],categories: 'transportation' },
{ name: 'sign-merge-right',tags: ['road', 'driving','directions'],categories: 'transportation' },
{ name: 'sign-merge-right-fill',tags: ['road', 'driving','directions'],categories: 'transportation' },
{ name: 'sign-no-left-turn',tags: ['road', 'driving','directions'],categories: 'transportation' },
{ name: 'sign-no-left-turn-fill',tags: ['road', 'driving','directions'],categories: 'transportation' },
{ name: 'sign-no-parking',tags: ['road', 'driving','directions'],categories: 'transportation' },
{ name: 'sign-no-parking-fill',tags: ['road', 'driving','directions'],categories: 'transportation' },
{ name: 'sign-no-right-turn',tags: ['road', 'driving','directions'],categories: 'transportation' },
{ name: 'sign-no-right-turn-fill',tags: ['road', 'driving','directions'],categories: 'transportation' },
{ name: 'signpost',tags: ['milestone', 'sign','road','sign','street','sign'],categories: 'real world' },
{ name: 'signpost-2',tags: ['milestone', 'sign','road','sign','street','sign'],categories: 'real world' },
{ name: 'signpost-2-fill',tags: ['milestone', 'sign','road','sign','street','sign'],categories: 'real world' },
{ name: 'signpost-fill',tags: ['milestone', 'sign','road','sign','street','sign'],categories: 'real world' },
{ name: 'signpost-split',tags: ['milestone', 'sign','road','sign','street','sign'],categories: 'real world' },
{ name: 'signpost-split-fill',tags: ['milestone', 'sign','road','sign','street','sign'],categories: 'real world' },
{ name: 'sign-railroad',tags: ['road', 'driving','train','tracks'],categories: 'transportation' },
{ name: 'sign-railroad-fill',tags: ['road', 'driving','train','tracks'],categories: 'transportation' },
{ name: 'sign-stop',tags: ['stop', 'sign','intersection','road','driving'],categories: 'transportation' },
{ name: 'sign-stop-fill',tags: ['stop', 'sign','intersection','road','driving'],categories: 'transportation' },
{ name: 'sign-stop-lights',tags: ['stop', 'sign','intersection','road','driving'],categories: 'transportation' },
{ name: 'sign-stop-lights-fill',tags: ['stop', 'sign','intersection','road','driving'],categories: 'transportation' },
{ name: 'sign-turn-left',tags: ['road', 'driving','navigate','navigation','route'],categories: 'transportation' },
{ name: 'sign-turn-left-fill',tags: ['road', 'driving','navigate','navigation','route'],categories: 'transportation' },
{ name: 'sign-turn-right',tags: ['road', 'driving','navigate','navigation','route'],categories: 'transportation' },
{ name: 'sign-turn-right-fill',tags: ['road', 'driving','navigate','navigation','route'],categories: 'transportation' },
{ name: 'sign-turn-slight-left',tags: ['road', 'driving','navigate','navigation','route'],categories: 'transportation' },
{ name: 'sign-turn-slight-left-fill',tags: ['road', 'driving','navigate','navigation','route'],categories: 'transportation' },
{ name: 'sign-turn-slight-right',tags: ['road', 'driving','navigate','navigation','route'],categories: 'transportation' },
{ name: 'sign-turn-slight-right-fill',tags: ['road', 'driving','navigate','navigation','route'],categories: 'transportation' },
{ name: 'sign-yield',tags: ['road', 'driving','intersection'],categories: 'transportation' },
{ name: 'sign-yield-fill',tags: ['road', 'driving','intersection'],categories: 'transportation' },
{ name: 'sim',tags: ['mobile', 'carrier'],categories: 'devices' },
{ name: 'sim-fill',tags: ['mobile', 'carrier'],categories: 'devices' },
{ name: 'sim-slash',tags: ['mobile', 'carrier'],categories: 'devices' },
{ name: 'sim-slash-fill',tags: ['mobile', 'carrier'],categories: 'devices' },
{ name: 'sina-weibo',tags: ['social'],categories: 'brand' },
{ name: 'skip-backward',tags: ['audio', 'video','av'],categories: 'media' },
{ name: 'skip-backward-btn',tags: ['audio', 'video','av'],categories: 'media' },
{ name: 'skip-backward-btn-fill',tags: ['audio', 'video','av'],categories: 'media' },
{ name: 'skip-backward-circle',tags: ['audio', 'video','av'],categories: 'media' },
{ name: 'skip-backward-circle-fill',tags: ['audio', 'video','av'],categories: 'media' },
{ name: 'skip-backward-fill',tags: ['audio', 'video','av'],categories: 'media' },
{ name: 'skip-end',tags: ['audio', 'video','av'],categories: 'media' },
{ name: 'skip-end-btn',tags: ['audio', 'video','av'],categories: 'media' },
{ name: 'skip-end-btn-fill',tags: ['audio', 'video','av'],categories: 'media' },
{ name: 'skip-end-circle',tags: ['audio', 'video','av'],categories: 'media' },
{ name: 'skip-end-circle-fill',tags: ['audio', 'video','av'],categories: 'media' },
{ name: 'skip-end-fill',tags: ['audio', 'video','av'],categories: 'media' },
{ name: 'skip-forward',tags: ['audio', 'video','av'],categories: 'media' },
{ name: 'skip-forward-btn',tags: ['audio', 'video','av'],categories: 'media' },
{ name: 'skip-forward-btn-fill',tags: ['audio', 'video','av'],categories: 'media' },
{ name: 'skip-forward-circle',tags: ['audio', 'video','av'],categories: 'media' },
{ name: 'skip-forward-circle-fill',tags: ['audio', 'video','av'],categories: 'media' },
{ name: 'skip-forward-fill',tags: ['audio', 'video','av'],categories: 'media' },
{ name: 'skip-start',tags: ['audio', 'video','av'],categories: 'media' },
{ name: 'skip-start-btn',tags: ['audio', 'video','av'],categories: 'media' },
{ name: 'skip-start-btn-fill',tags: ['audio', 'video','av'],categories: 'media' },
{ name: 'skip-start-circle',tags: ['audio', 'video','av'],categories: 'media' },
{ name: 'skip-start-circle-fill',tags: ['audio', 'video','av'],categories: 'media' },
{ name: 'skip-start-fill',tags: ['audio', 'video','av'],categories: 'media' },
{ name: 'skype',tags: ['social', 'microsoft'],categories: 'brand' },
{ name: 'slack',tags: ['social'],categories: 'brand' },
{ name: 'slash',tags: ['shape', 'stop','ban','false','math'],categories: 'alerts, warnings, and signs' },
{ name: 'slash-circle',tags: ['shape', 'stop','ban','false'],categories: 'alerts, warnings, and signs' },
{ name: 'slash-circle-fill',tags: ['shape', 'stop','ban','false'],categories: 'alerts, warnings, and signs' },
{ name: 'slash-lg',tags: ['shape', 'stop','ban','false','math'],categories: 'alerts, warnings, and signs' },
{ name: 'slash-square',tags: ['shape', 'stop','ban','false'],categories: 'alerts, warnings, and signs' },
{ name: 'slash-square-fill',tags: ['shape', 'stop','ban','false'],categories: 'alerts, warnings, and signs' },
{ name: 'sliders',tags: ['equalizer', 'settings','preferences','dials'],categories: 'graphics' },
{ name: 'sliders2',tags: ['equalizer', 'settings','preferences','dials'],categories: 'graphics' },
{ name: 'sliders2-vertical',tags: ['equalizer', 'settings','preferences','dials'],categories: 'graphics' },
{ name: 'smartwatch',tags: ['watch', 'wearables'],categories: 'devices' },
{ name: 'snapchat',tags: ['social'],categories: 'brand' },
{ name: 'snow',tags: ['blizzard', 'flurries'],categories: 'weather' },
{ name: 'snow2',tags: ['blizzard', 'flurries'],categories: 'weather' },
{ name: 'snow3',tags: ['blizzard', 'flurries'],categories: 'weather' },
{ name: 'sort-alpha-down',tags: ['sort', 'filter','organize'],categories: 'sort and filter' },
{ name: 'sort-alpha-down-alt',tags: ['sort', 'filter','organize'],categories: 'sort and filter' },
{ name: 'sort-alpha-up',tags: ['sort', 'filter','organize'],categories: 'sort and filter' },
{ name: 'sort-alpha-up-alt',tags: ['sort', 'filter','organize'],categories: 'sort and filter' },
{ name: 'sort-down',tags: ['sort', 'filter','organize'],categories: 'sort and filter' },
{ name: 'sort-down-alt',tags: ['sort', 'filter','organize'],categories: 'sort and filter' },
{ name: 'sort-numeric-down',tags: ['sort', 'filter','organize'],categories: 'sort and filter' },
{ name: 'sort-numeric-down-alt',tags: ['sort', 'filter','organize'],categories: 'sort and filter' },
{ name: 'sort-numeric-up',tags: ['sort', 'filter','organize'],categories: 'sort and filter' },
{ name: 'sort-numeric-up-alt',tags: ['sort', 'filter','organize'],categories: 'sort and filter' },
{ name: 'sort-up',tags: ['sort', 'filter','organize'],categories: 'sort and filter' },
{ name: 'sort-up-alt',tags: ['sort', 'filter','organize'],categories: 'sort and filter' },
{ name: 'soundwave',tags: ['audio', 'sound','wave'],categories: 'media' },
{ name: 'sourceforge',tags: ['social', 'vcs','git'],categories: 'brand' },
{ name: 'speaker',tags: ['audio', 'sound'],categories: 'devices' },
{ name: 'speaker-fill',tags: ['audio', 'sound'],categories: 'devices' },
{ name: 'speedometer',tags: ['speed', 'tachometer','dashboard','gauge'],categories: 'real world' },
{ name: 'speedometer2',tags: ['speed', 'tachometer','dashboard','gauge'],categories: 'real world' },
{ name: 'spellcheck',tags: ['spelling', 'grammar'],categories: 'typography' },
{ name: 'spotify',tags: ['social'],categories: 'brand' },
{ name: 'square',tags: ['shape', 'rectangle'],categories: 'shapes' },
{ name: 'square-fill',tags: ['shape', 'rectangle'],categories: 'shapes' },
{ name: 'square-half',tags: ['shape', 'rectangle'],categories: 'shapes' },
{ name: 'stack',tags: ['layers'],categories: 'graphics' },
{ name: 'stack-overflow',tags: ['social'],categories: 'brand' },
{ name: 'star',tags: ['shape', 'like','favorite'],categories: 'shapes' },
{ name: 'star-fill',tags: ['shape', 'like','favorite'],categories: 'shapes' },
{ name: 'star-half',tags: ['shape', 'like','favorite'],categories: 'shapes' },
{ name: 'stars',tags: ['clear', 'skies','night'],categories: 'weather' },
{ name: 'steam',tags: ['gaming'],categories: 'brand' },
{ name: 'stickies',tags: ['postit', 'note','sticky'],categories: 'real world' },
{ name: 'stickies-fill',tags: ['postit', 'note','sticky'],categories: 'real world' },
{ name: 'sticky',tags: ['postit', 'note','sticky'],categories: 'real world' },
{ name: 'sticky-fill',tags: ['postit', 'note','sticky'],categories: 'real world' },
{ name: 'stop',tags: ['audio', 'video','av'],categories: 'media' },
{ name: 'stop-btn',tags: ['audio', 'video','av'],categories: 'media' },
{ name: 'stop-btn-fill',tags: ['audio', 'video','av'],categories: 'media' },
{ name: 'stop-circle',tags: ['audio', 'video','av'],categories: 'media' },
{ name: 'stop-circle-fill',tags: ['audio', 'video','av'],categories: 'media' },
{ name: 'stop-fill',tags: ['audio', 'video','av'],categories: 'media' },
{ name: 'stoplights',tags: ['traffic', 'lights','intersection'],categories: 'real world' },
{ name: 'stoplights-fill',tags: ['traffic', 'lights','intersection'],categories: 'real world' },
{ name: 'stopwatch',tags: ['time', 'timer','clock'],categories: 'devices' },
{ name: 'stopwatch-fill',tags: ['time', 'timer','clock'],categories: 'devices' },
{ name: 'strava',tags: ['social'],categories: 'brand' },
{ name: 'stripe',tags: ['payments', 'commerce'],categories: 'brand' },
{ name: 'subscript',tags: ['text', 'type'],categories: 'typography' },
{ name: 'substack',tags: ['social', 'blog'],categories: 'brand' },
{ name: 'subtract',tags: ['graphics', 'vector','merge','layers'],categories: 'graphics' },
{ name: 'suitcase',tags: ['luggage', 'bags','carry-on'],categories: 'travel' },
{ name: 'suitcase2',tags: ['luggage', 'bags','carry-on'],categories: 'travel' },
{ name: 'suitcase2-fill',tags: ['luggage', 'bags','carry-on'],categories: 'travel' },
{ name: 'suitcase-fill',tags: ['luggage', 'bags','carry-on'],categories: 'travel' },
{ name: 'suitcase-lg',tags: ['luggage', 'bags','carry-on'],categories: 'travel' },
{ name: 'suitcase-lg-fill',tags: ['luggage', 'bags','carry-on'],categories: 'travel' },
{ name: 'suit-club',tags: ['card', 'cards','suit','deck','gambling'],categories: 'entertainment' },
{ name: 'suit-club-fill',tags: ['card', 'cards','suit','deck','gambling'],categories: 'entertainment' },
{ name: 'suit-diamond',tags: ['card', 'cards','suit','deck','gambling'],categories: 'entertainment' },
{ name: 'suit-diamond-fill',tags: ['card', 'cards','suit','deck','gambling'],categories: 'entertainment' },
{ name: 'suit-heart',tags: ['card', 'cards','suit','deck','gambling'],categories: 'entertainment' },
{ name: 'suit-heart-fill',tags: ['card', 'cards','suit','deck','gambling'],categories: 'entertainment' },
{ name: 'suit-spade',tags: ['card', 'cards','suit','deck','gambling'],categories: 'entertainment' },
{ name: 'suit-spade-fill',tags: ['card', 'cards','suit','deck','gambling'],categories: 'entertainment' },
{ name: 'sun',tags: ['solar', 'weather'],categories: 'weather' },
{ name: 'sun-fill',tags: ['solar', 'weather'],categories: 'weather' },
{ name: 'sunglasses',tags: ['shades', 'cool','aviators'],categories: 'real world' },
{ name: 'sunrise',tags: ['dawn'],categories: 'weather' },
{ name: 'sunrise-fill',tags: ['dawn'],categories: 'weather' },
{ name: 'sunset',tags: ['dusk'],categories: 'weather' },
{ name: 'sunset-fill',tags: ['dusk'],categories: 'weather' },
{ name: 'superscript',tags: ['text', 'type'],categories: 'typography' },
{ name: 'symmetry-horizontal',tags: ['align', 'orientation','mirror'],categories: 'graphics' },
{ name: 'symmetry-vertical',tags: ['align', 'orientation','mirror'],categories: 'graphics' },
{ name: 'table',tags: ['spreadsheet'],categories: 'files and folders' },
{ name: 'tablet',tags: ['mobile'],categories: 'devices' },
{ name: 'tablet-fill',tags: ['mobile'],categories: 'devices' },
{ name: 'tablet-landscape',tags: ['mobile'],categories: 'devices' },
{ name: 'tablet-landscape-fill',tags: ['mobile'],categories: 'devices' },
{ name: 'tag',tags: ['price', 'category','taxonomy','label'],categories: 'real world' },
{ name: 'tag-fill',tags: ['price', 'category','taxonomy','label'],categories: 'real world' },
{ name: 'tags',tags: ['price', 'category','taxonomy','label'],categories: 'real world' },
{ name: 'tags-fill',tags: ['price', 'category','taxonomy','label'],categories: 'real world' },
{ name: 'taxi-front',tags: ['cab', 'uber','lyft'],categories: 'transportation' },
{ name: 'taxi-front-fill',tags: ['cab', 'uber','lyft'],categories: 'transportation' },
{ name: 'telegram',tags: ['social', 'chat'],categories: 'brand' },
{ name: 'telephone',tags: ['telephone', 'phone','call'],categories: 'communications' },
{ name: 'telephone-fill',tags: ['telephone', 'phone','call'],categories: 'communications' },
{ name: 'telephone-forward',tags: ['telephone', 'phone','call'],categories: 'communications' },
{ name: 'telephone-forward-fill',tags: ['telephone', 'phone','call'],categories: 'communications' },
{ name: 'telephone-inbound',tags: ['telephone', 'phone','call'],categories: 'communications' },
{ name: 'telephone-inbound-fill',tags: ['telephone', 'phone','call'],categories: 'communications' },
{ name: 'telephone-minus',tags: ['telephone', 'phone','call'],categories: 'communications' },
{ name: 'telephone-minus-fill',tags: ['telephone', 'phone','call'],categories: 'communications' },
{ name: 'telephone-outbound',tags: ['telephone', 'phone','call'],categories: 'communications' },
{ name: 'telephone-outbound-fill',tags: ['telephone', 'phone','call'],categories: 'communications' },
{ name: 'telephone-plus',tags: ['telephone', 'phone','call'],categories: 'communications' },
{ name: 'telephone-plus-fill',tags: ['telephone', 'phone','call'],categories: 'communications' },
{ name: 'telephone-x',tags: ['telephone', 'phone','call'],categories: 'communications' },
{ name: 'telephone-x-fill',tags: ['telephone', 'phone','call'],categories: 'communications' },
{ name: 'tencent-qq',tags: ['social'],categories: 'brand' },
{ name: 'terminal',tags: ['command-line', 'cli','command-prompt'],categories: 'apps' },
{ name: 'terminal-dash',tags: ['command-line', 'cli','command-prompt'],categories: 'apps' },
{ name: 'terminal-fill',tags: ['command-line', 'cli','command-prompt'],categories: 'apps' },
{ name: 'terminal-plus',tags: ['command-line', 'cli','command-prompt'],categories: 'apps' },
{ name: 'terminal-split',tags: ['command-line', 'cli','command-prompt'],categories: 'apps' },
{ name: 'terminal-x',tags: ['command-line', 'cli','command-prompt'],categories: 'apps' },
{ name: 'textarea',tags: ['text', 'insert','bounding-box'],categories: 'graphics' },
{ name: 'textarea-resize',tags: ['text', 'form'],categories: 'graphics' },
{ name: 'textarea-t',tags: ['text', 'insert','bounding-box'],categories: 'graphics' },
{ name: 'text-center',tags: ['text', 'type','justify','alignment','centre'],categories: 'typography' },
{ name: 'text-indent-left',tags: ['text', 'type','justify','alignment'],categories: 'typography' },
{ name: 'text-indent-right',tags: ['text', 'type','justify','alignment'],categories: 'typography' },
{ name: 'text-left',tags: ['text', 'type','justify','alignment'],categories: 'typography' },
{ name: 'text-paragraph',tags: ['text', 'type','paragraph','copy'],categories: 'typography' },
{ name: 'text-right',tags: ['text', 'type','justify','alignment'],categories: 'typography' },
{ name: 'text-wrap',tags: ['text', 'type','word','wrap'],categories: 'typography' },
{ name: 'thermometer',tags: ['temperature', 'weather'],categories: 'real world' },
{ name: 'thermometer-half',tags: ['temperature', 'weather'],categories: 'weather' },
{ name: 'thermometer-high',tags: ['temperature', 'weather'],categories: 'weather' },
{ name: 'thermometer-low',tags: ['temperature', 'weather'],categories: 'weather' },
{ name: 'thermometer-snow',tags: ['temperature', 'weather'],categories: 'weather' },
{ name: 'thermometer-sun',tags: ['temperature', 'weather'],categories: 'weather' },
{ name: 'threads',tags: ['social', 'chat','instagram','meta'],categories: 'brand' },
{ name: 'threads-fill',tags: ['social', 'chat','instagram','meta'],categories: 'brand' },
{ name: 'three-dots',tags: ['meatballs', 'more','ellipsis','overflow','menu'],categories: 'controls' },
{ name: 'three-dots-vertical',tags: ['kebab', 'more','ellipsis','overflow','menu'],categories: 'controls' },
{ name: 'thunderbolt',tags: ['plug', 'port'],categories: 'devices' },
{ name: 'thunderbolt-fill',tags: ['plug', 'port'],categories: 'devices' },
{ name: 'ticket',tags: ['tickets', 'admission'],categories: 'real world' },
{ name: 'ticket-detailed',tags: ['tickets', 'admission'],categories: 'real world' },
{ name: 'ticket-detailed-fill',tags: ['tickets', 'admission'],categories: 'real world' },
{ name: 'ticket-fill',tags: ['tickets', 'admission'],categories: 'real world' },
{ name: 'ticket-perforated',tags: ['tickets', 'admission'],categories: 'real world' },
{ name: 'ticket-perforated-fill',tags: ['tickets', 'admission'],categories: 'real world' },
{ name: 'tiktok',tags: ['social'],categories: 'brand' },
{ name: 'toggle2-off',tags: ['toggle', 'switch','checkbox'],categories: 'controls' },
{ name: 'toggle2-on',tags: ['toggle', 'switch','checkbox'],categories: 'controls' },
{ name: 'toggle-off',tags: ['toggle', 'switch','checkbox'],categories: 'controls' },
{ name: 'toggle-on',tags: ['toggle', 'switch','checkbox'],categories: 'controls' },
{ name: 'toggles',tags: ['toggle', 'switch','checkbox'],categories: 'controls' },
{ name: 'toggles2',tags: ['toggle', 'switch','checkbox'],categories: 'controls' },
{ name: 'tools',tags: ['tool', 'wrench','screwdriver'],categories: 'tools' },
{ name: 'tornado',tags: ['wind'],categories: 'weather' },
{ name: 'train-freight-front',tags: ['transit', 'public','rail'],categories: 'transportation' },
{ name: 'train-freight-front-fill',tags: ['transit', 'public','rail'],categories: 'transportation' },
{ name: 'train-front',tags: ['transit', 'public','rail'],categories: 'transportation' },
{ name: 'train-front-fill',tags: ['transit', 'public','rail'],categories: 'transportation' },
{ name: 'train-lightrail-front',tags: ['transit', 'public','rail'],categories: 'transportation' },
{ name: 'train-lightrail-front-fill',tags: ['transit', 'public','rail'],categories: 'transportation' },
{ name: 'translate',tags: ['translation', 'languages','language'],categories: 'communications' },
{ name: 'transparency',tags: ['photo', 'editing','image','picture'],categories: 'graphics' },
{ name: 'trash',tags: ['trash-can', 'garbage','delete','remove'],categories: 'ui and keyboard' },
{ name: 'trash2',tags: ['trash-can', 'garbage','delete','remove'],categories: 'ui and keyboard' },
{ name: 'trash2-fill',tags: ['trash-can', 'garbage','delete','remove'],categories: 'ui and keyboard' },
{ name: 'trash3',tags: ['trash-can', 'garbage','delete','remove'],categories: 'ui and keyboard' },
{ name: 'trash3-fill',tags: ['trash-can', 'garbage','delete','remove'],categories: 'ui and keyboard' },
{ name: 'trash-fill',tags: ['trash-can', 'garbage','delete','remove'],categories: 'ui and keyboard' },
{ name: 'tree',tags: ['tree', 'forrest'],categories: 'real world' },
{ name: 'tree-fill',tags: ['tree', 'forrest'],categories: 'real world' },
{ name: 'trello',tags: ['kanban', 'board'],categories: 'brand' },
{ name: 'triangle',tags: ['shape'],categories: 'shapes' },
{ name: 'triangle-fill',tags: ['shape'],categories: 'shapes' },
{ name: 'triangle-half',tags: ['shape'],categories: 'shapes' },
{ name: 'trophy',tags: ['prize', 'winning'],categories: 'real world' },
{ name: 'trophy-fill',tags: ['prize', 'winning'],categories: 'real world' },
{ name: 'tropical-storm',tags: ['hurricane'],categories: 'weather' },
{ name: 'truck',tags: ['trucking', 'shipping','shipment','transport','deliver','delivery'],categories: 'commerce' },
{ name: 'truck-flatbed',tags: ['trucking', 'shipping','shipment','transport','deliver','delivery'],categories: 'commerce' },
{ name: 'truck-front',tags: ['vehicle', 'shipping','transit','haul','freight'],categories: 'transportation' },
{ name: 'truck-front-fill',tags: ['vehicle', 'shipping','transit','haul','freight'],categories: 'transportation' },
{ name: 'tsunami',tags: ['wave'],categories: 'weather' },
{ name: 'tux',tags: ['linux', 'penguin'],categories: 'brand' },
{ name: 'tv',tags: ['television', 'monitor','display'],categories: 'devices' },
{ name: 'tv-fill',tags: ['television', 'monitor','display'],categories: 'devices' },
{ name: 'twitch',tags: ['social'],categories: 'brand' },
{ name: 'twitter',tags: ['social', 'chat'],categories: 'brand' },
{ name: 'twitter-x',tags: ['social', 'chat'],categories: 'brand' },
{ name: 'type',tags: ['text', 'type'],categories: 'typography' },
{ name: 'type-bold',tags: ['text', 'type'],categories: 'typography' },
{ name: 'type-h1',tags: ['text', 'type','heading'],categories: 'typography' },
{ name: 'type-h2',tags: ['text', 'type','heading'],categories: 'typography' },
{ name: 'type-h3',tags: ['text', 'type','heading'],categories: 'typography' },
{ name: 'type-h4',tags: ['text', 'type','heading'],categories: 'typography' },
{ name: 'type-h5',tags: ['text', 'type','heading'],categories: 'typography' },
{ name: 'type-h6',tags: ['text', 'type','heading'],categories: 'typography' },
{ name: 'type-italic',tags: ['text', 'type'],categories: 'typography' },
{ name: 'typescript',tags: ['frontend', 'js','ts','web'],categories: 'brand' },
{ name: 'type-strikethrough',tags: ['text', 'type'],categories: 'typography' },
{ name: 'type-underline',tags: ['text', 'type'],categories: 'typography' },
{ name: 'ubuntu',tags: ['linux', 'distro'],categories: 'brand' },
{ name: 'ui-checks',tags: ['checkbox', 'form'],categories: 'controls' },
{ name: 'ui-checks-grid',tags: ['checkbox', 'form'],categories: 'controls' },
{ name: 'ui-radios',tags: ['radio', 'form'],categories: 'controls' },
{ name: 'ui-radios-grid',tags: ['radio', 'form'],categories: 'controls' },
{ name: 'umbrella',tags: ['rain'],categories: 'weather' },
{ name: 'umbrella-fill',tags: ['rain'],categories: 'weather' },
{ name: 'unindent',tags: ['tab', 'indent'],categories: 'ui and keyboard' },
{ name: 'union',tags: ['graphics', 'vector','merge','layers'],categories: 'graphics' },
{ name: 'unity',tags: ['gaming'],categories: 'brand' },
{ name: 'universal-access',tags: ['accessibility', 'a11y'],categories: 'ui and keyboard' },
{ name: 'universal-access-circle',tags: ['accessibility', 'a11y'],categories: 'ui and keyboard' },
{ name: 'unlock',tags: ['privacy', 'security'],categories: 'security' },
{ name: 'unlock2',tags: ['privacy', 'security'],categories: 'security' },
{ name: 'unlock2-fill',tags: ['privacy', 'security'],categories: 'security' },
{ name: 'unlock-fill',tags: ['privacy', 'security'],categories: 'security' },
{ name: 'upc',tags: ['purchase', 'barcode'],categories: 'commerce' },
{ name: 'upc-scan',tags: ['purchase', 'barcode'],categories: 'commerce' },
{ name: 'upload',tags: ['arrow', 'network'],categories: 'miscellaneous' },
{ name: 'usb',tags: ['port', 'plug'],categories: 'devices' },
{ name: 'usb-c',tags: ['port', 'plug'],categories: 'devices' },
{ name: 'usb-c-fill',tags: ['port', 'plug'],categories: 'devices' },
{ name: 'usb-drive',tags: ['thumb-drive'],categories: 'devices' },
{ name: 'usb-drive-fill',tags: ['thumb-drive'],categories: 'devices' },
{ name: 'usb-fill',tags: ['port', 'plug'],categories: 'devices' },
{ name: 'usb-micro',tags: ['port', 'plug'],categories: 'devices' },
{ name: 'usb-micro-fill',tags: ['port', 'plug'],categories: 'devices' },
{ name: 'usb-mini',tags: ['port', 'plug'],categories: 'devices' },
{ name: 'usb-mini-fill',tags: ['port', 'plug'],categories: 'devices' },
{ name: 'usb-plug',tags: ['port', 'plug'],categories: 'devices' },
{ name: 'usb-plug-fill',tags: ['port', 'plug'],categories: 'devices' },
{ name: 'usb-symbol',tags: ['port', 'plug'],categories: 'devices' },
{ name: 'valentine',tags: ['love', 'romance','valentine'],categories: 'love' },
{ name: 'valentine2',tags: ['love', 'romance','valentine'],categories: 'love' },
{ name: 'vector-pen',tags: ['graphics', 'vector','pen'],categories: 'graphics' },
{ name: 'view-list',tags: ['view', 'rearrange'],categories: 'ui and keyboard' },
{ name: 'view-stacked',tags: ['view', 'rearrange'],categories: 'ui and keyboard' },
{ name: 'vignette',tags: ['photo', 'editing','image','picture'],categories: 'graphics' },
{ name: 'vimeo',tags: ['social'],categories: 'brand' },
{ name: 'vinyl',tags: ['audio', 'music','record'],categories: 'media' },
{ name: 'vinyl-fill',tags: ['audio', 'music','record'],categories: 'media' },
{ name: 'virus',tags: ['covid', 'viral'],categories: 'medical' },
{ name: 'virus2',tags: ['covid', 'viral'],categories: 'medical' },
{ name: 'voicemail',tags: ['voicemail', 'message','telephone'],categories: 'communications' },
{ name: 'volume-down',tags: ['audio', 'video','av','sound'],categories: 'media' },
{ name: 'volume-down-fill',tags: ['audio', 'video','av','sound'],categories: 'media' },
{ name: 'volume-mute',tags: ['audio', 'video','av','sound'],categories: 'media' },
{ name: 'volume-mute-fill',tags: ['audio', 'video','av','sound'],categories: 'media' },
{ name: 'volume-off',tags: ['audio', 'video','av','sound'],categories: 'media' },
{ name: 'volume-off-fill',tags: ['audio', 'video','av','sound'],categories: 'media' },
{ name: 'volume-up',tags: ['audio', 'video','av','sound'],categories: 'media' },
{ name: 'volume-up-fill',tags: ['audio', 'video','av','sound'],categories: 'media' },
{ name: 'vr',tags: ['divider', 'vertical-rule'],categories: 'typography' },
{ name: 'wallet',tags: ['cards', 'money','funds','cash','payment'],categories: 'commerce' },
{ name: 'wallet2',tags: ['cards', 'money','funds','cash','payment'],categories: 'commerce' },
{ name: 'wallet-fill',tags: ['cards', 'money','funds','cash','payment'],categories: 'commerce' },
{ name: 'watch',tags: ['wearables', 'clock'],categories: 'devices' },
{ name: 'water',tags: ['waves', 'levels'],categories: 'weather' },
{ name: 'webcam',tags: ['camera', 'video'],categories: 'devices' },
{ name: 'webcam-fill',tags: ['camera', 'video'],categories: 'devices' },
{ name: 'wechat',tags: ['social', 'messaging'],categories: 'brand' },
{ name: 'whatsapp',tags: ['social', 'facebook','chat'],categories: 'brand' },
{ name: 'wifi',tags: ['internet', 'network','wireless'],categories: 'communications' },
{ name: 'wifi-1',tags: ['internet', 'network','wireless'],categories: 'communications' },
{ name: 'wifi-2',tags: ['internet', 'network','wireless'],categories: 'communications' },
{ name: 'wifi-off',tags: ['internet', 'network','wireless'],categories: 'communications' },
{ name: 'wikipedia',tags: ['encyclopedia'],categories: 'brand' },
{ name: 'wind',tags: ['windy', 'breeze'],categories: 'weather' },
{ name: 'window',tags: ['app', 'application'],categories: 'layout' },
{ name: 'window-dash',tags: ['application', 'desktop','app'],categories: 'apps' },
{ name: 'window-desktop',tags: ['application', 'desktop','app'],categories: 'apps' },
{ name: 'window-dock',tags: ['application', 'desktop','os'],categories: 'apps' },
{ name: 'window-fullscreen',tags: ['application', 'desktop','app'],categories: 'apps' },
{ name: 'window-plus',tags: ['application', 'desktop','app'],categories: 'apps' },
{ name: 'windows',tags: ['microsoft', 'msft','windows'],categories: 'brand' },
{ name: 'window-sidebar',tags: ['application', 'desktop'],categories: 'apps' },
{ name: 'window-split',tags: ['application', 'desktop','app'],categories: 'apps' },
{ name: 'window-stack',tags: ['application', 'desktop','app','windows'],categories: 'apps' },
{ name: 'window-x',tags: ['application', 'desktop','app'],categories: 'apps' },
{ name: 'wordpress',tags: ['social'],categories: 'brand' },
{ name: 'wrench',tags: ['tool'],categories: 'tools' },
{ name: 'wrench-adjustable',tags: ['tool'],categories: 'tools' },
{ name: 'wrench-adjustable-circle',tags: ['tool'],categories: 'tools' },
{ name: 'wrench-adjustable-circle-fill',tags: ['tool'],categories: 'tools' },
{ name: 'x',tags: ['x', 'delete','remove','reset','clear','cancel','close','exit'],categories: 'alerts, warnings, and signs' },
{ name: 'xbox',tags: ['microsoft', 'gaming'],categories: 'brand' },
{ name: 'x-circle',tags: ['x', 'delete','remove','reset','clear','cancel','close'],categories: 'alerts, warnings, and signs' },
{ name: 'x-circle-fill',tags: ['x', 'delete','remove','reset','clear','cancel','close'],categories: 'alerts, warnings, and signs' },
{ name: 'x-diamond',tags: ['x', 'delete','reset','clear','cancel','close'],categories: 'alerts, warnings, and signs' },
{ name: 'x-diamond-fill',tags: ['x', 'delete','reset','clear','cancel','close'],categories: 'alerts, warnings, and signs' },
{ name: 'x-lg',tags: ['x', 'delete','remove','reset','clear','cancel','close','exit'],categories: 'alerts, warnings, and signs' },
{ name: 'x-octagon',tags: ['x', 'delete','remove','reset','clear','cancel','close'],categories: 'alerts, warnings, and signs' },
{ name: 'x-octagon-fill',tags: ['x', 'delete','remove','reset','clear','cancel','close'],categories: 'alerts, warnings, and signs' },
{ name: 'x-square',tags: ['x', 'delete','remove','reset','clear','cancel','close'],categories: 'alerts, warnings, and signs' },
{ name: 'x-square-fill',tags: ['x', 'delete','remove','reset','clear','cancel','close'],categories: 'alerts, warnings, and signs' },
{ name: 'yelp',tags: ['social', 'reviews'],categories: 'brand' },
{ name: 'yin-yang',tags: ['peace'],categories: 'real world' },
{ name: 'youtube',tags: ['social', 'video','google'],categories: 'brand' },
{ name: 'zoom-in',tags: ['magnify', 'scale'],categories: 'graphics' },
{ name: 'zoom-out',tags: ['minify', 'scale'],categories: 'graphics' }
];
            return icons;
        },
    };
    
export default Controller;
