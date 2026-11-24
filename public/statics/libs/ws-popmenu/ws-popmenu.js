/**
 * ws-popmenu.js - Bootstrap WsPopMenu 组件
 * @version 1.0.0
 * @author wiseAdmin
 * @license MIT
 */

// 样式：ws-popmenu.css
// 本模块刻意不 import CSS —— 浏览器原生 ESM 不支持导入 CSS，一旦 import，
// demo.html 等"直连源码"的场景会因 MIME 类型不匹配而整体加载失败。
// 生产：由 statics/modules/ws/ws-components.css 汇总，构建进 wise-bundle.css
// 调试：<link rel="stylesheet" href="/statics/libs/ws-popmenu/ws-popmenu.css">

// jQuery 走全局单例：本组件是 jQuery 插件，若 import 出第二份 jQuery 实例，
// 插件会注册在 A 实例上而业务用 B 实例调用，功能静默失效。
const $ = window.jQuery;
if (typeof $ === 'undefined') {
    throw new Error('WsPopMenu requires jQuery');
}

    // 默认配置
    var defaults = {
        trigger: 'click', // 'click', 'hover' 或 'contextmenu'
        placement: 'bottom-start', // 'top', 'bottom', 'left', 'right', 以及它们的-start和-end变体
        items: [],
        offsetX: 0, // 水平偏移
        offsetY: 0, // 垂直偏移
        autoClose: true, // 点击菜单外部是否自动关闭
        onShow: null,
        onHide: null,
        onItemClick: null
    };
    
    // 工具函数
    var utils = {
        // 获取元素在文档中的位置
        getOffset: function(element) {
            var rect = element.getBoundingClientRect();
            return {
                top: rect.top + window.pageYOffset,
                left: rect.left + window.pageXOffset,
                width: rect.width,
                height: rect.height
            };
        },
        
        // 检查元素是否可见
        isVisible: function(element) {
            return element.offsetWidth > 0 && element.offsetHeight > 0;
        },
        
        // 防抖函数
        debounce: function(func, wait) {
            var timeout;
            return function() {
                var context = this, args = arguments;
                clearTimeout(timeout);
                timeout = setTimeout(function() {
                    func.apply(context, args);
                }, wait);
            };
        },
        
        // 生成唯一ID
        generateId: function() {
            return 'ws-popmenu-' + Math.random().toString(36).substr(2, 9);
        }
    };
    
    // WsPopMenu 组件定义
    var WsPopMenu = function(options) {
        this.options = $.extend({}, defaults, options);
        this.$element = null;
        this.$trigger = null;
        this.isVisible = false;
        this.hideTimeout = null;
        this.submenuHideTimeouts = {};
        this.id = utils.generateId();
        this.isAttached = false;
        this._pendingFocus = false; // 本次 show 是否由键盘触发（需自动聚焦首项）
        
        this.init();
    };
    
    WsPopMenu.prototype = {
        constructor: WsPopMenu,
        
        init: function() {
            this.createElement();
            this.buildMenu(this.$element, this.options.items);
            this.bindEvents();
        },
        
        createElement: function() {
            this.$element = $('<div class="ws-popmenu" tabindex="-1"></div>');
            this.$element.attr('id', this.id);
            $('body').append(this.$element);
        },
        
        /* 
         * 构建菜单 
         * @param {jQuery} $container - 菜单容器元素
         * @param {Array} items - 菜单项数组
         *  - text {string} - 菜单文本
         *  - icon {string} - Bootstrap Icons 类名
         *  - id {string} - 可选，用于标识
         *  - type {string} - 可选，'divider' 或 'header'
         *  - shortcut {string} - 可选，快捷键
         *  - group {string} - 可选，分组名称
         *  - disabled {boolean} - 可选，是否禁用
         *  - children {Array} - 可选，子菜单数组
         */
        buildMenu: function($container, items) {
            $container.empty();
            // 无障碍语义：菜单容器 / 子菜单容器标记为 menu
            $container.attr('role', 'menu');
            
            var currentGroup = null;
            var self = this;
            
            items.forEach(function(item) {
                if (item.type === 'divider') {
                    $container.append('<div class="ws-popmenu-divider"></div>');
                } else if (item.type === 'header') {
                    $container.append('<div class="ws-popmenu-header">' + item.text + '</div>');
                } else {
                    // 检查是否需要新分组
                    if (item.group && item.group !== currentGroup) {
                        if (currentGroup !== null) {
                            $container.append('<div class="ws-popmenu-divider"></div>');
                        }
                        currentGroup = item.group;
                    }
                    
                    var $item = $(
                        '<button type="button" class="ws-popmenu-item" data-id="' + (item.id || '') + '" tabindex="-1" role="menuitem">' +
                            '<span class="d-flex align-items-center me-3">' +
                                (item.icon ? '<i class="' + item.icon + '"></i>' : '') +
                                item.text +
                            '</span>' +
                            (item.children ? '<i class="bi bi-chevron-right"></i>' : '') +
                            (item.shortcut ? '<span class="ws-popmenu-shortcut">' + item.shortcut + '</span>' : '') + 
                        '</button>'
                    );
                    
                    if (item.disabled) {
                        $item.addClass('disabled');
                        $item.attr('aria-disabled', 'true');
                    }
                    if (item.children && item.children.length > 0) {
                        $item.attr('aria-haspopup', 'menu');
                        $item.attr('aria-expanded', 'false');
                    }
                    
                    // 如果有子菜单，构建子菜单
                    if (item.children && item.children.length > 0) {
                        var $submenu = $('<div class="ws-popmenu ws-popmenu-submenu" tabindex="-1"></div>');
                        self.buildMenu($submenu, item.children);
                        $item.append($submenu);
                        
                        // 绑定子菜单的显示/隐藏事件
                        self.bindSubmenuEvents($item, $submenu);
                    }
                    
                    $container.append($item);
                }
            });
        },
        
        bindSubmenuEvents: function($item, $submenu) {
            var self = this;

            // 收起本项的子菜单（供定时器与清理调用）
            var closeSelf = function() {
                clearTimeout($item.data('wspmSubmenuTimer'));
                $submenu.removeClass('show');
                $item.attr('aria-expanded', 'false');
            };
            // 延迟收起：鼠标在父项与子菜单间穿梭时提供 150ms 缓冲
            var scheduleHide = function() {
                clearTimeout($item.data('wspmSubmenuTimer'));
                var timer = setTimeout(closeSelf, 150);
                $item.data('wspmSubmenuTimer', timer);
            };

            // 鼠标进入菜单项时显示子菜单（收起同层其它子菜单由 bindEvents 的
            // mouseenter 委托统一处理，避免旧子菜单残留展开）
            $item.on('mouseenter', function() {
                clearTimeout($item.data('wspmSubmenuTimer'));
                $submenu.addClass('show');
                $item.attr('aria-expanded', 'true');
            });

            $item.on('mouseleave', scheduleHide);

            // 鼠标进入子菜单时取消隐藏
            $submenu.on('mouseenter', function() {
                clearTimeout($item.data('wspmSubmenuTimer'));
            });

            $submenu.on('mouseleave', scheduleHide);
        },
        
        bindEvents: function() {
            var self = this;
            
            // 菜单项点击事件
            this.$element.on('click', '.ws-popmenu-item', function(e) {
                var $item = $(this);
                
                if ($item.hasClass('disabled')) return;
                
                // 如果有子菜单，不触发点击事件
                if ($item.find('.ws-popmenu-submenu').length > 0) {
                    e.stopPropagation();
                    return;
                }
                
                var id = $item.data('id');
                
                if (self.options.onItemClick) {
                    self.options.onItemClick(id, $item);
                }
                
                self.hide();
            });
            
            // 鼠标进入任意菜单项：清除键盘遗留的焦点高亮（避免某一项常亮、其子菜单
            // 因 `:focus` 而永不隐藏），并收起同容器内其它已展开的子菜单。
            this.$element.on('mouseenter', '.ws-popmenu-item', function() {
                var $item = $(this);
                var $menu = $item.closest('.ws-popmenu');
                
                self.clearStaleMenuFocus($item);
                
                $menu.children('.ws-popmenu-item').each(function() {
                    if (this === $item[0]) return;
                    var $sibling = $(this);
                    var $sub = $sibling.children('.ws-popmenu-submenu').first();
                    clearTimeout($sibling.data('wspmSubmenuTimer'));
                    if ($sub.length) {
                        $sub.removeClass('show');
                        $sibling.attr('aria-expanded', 'false');
                    }
                });
            });
            
            // 菜单项键盘事件（全键盘可达性）
            this.$element.on('keydown', '.ws-popmenu-item', function(e) {
                var $item = $(this);
                var key = e.key;

                // 子菜单挂在父菜单项内部，键盘事件从深层项冒泡时，委托会命中每个
                // 匹配的祖先 .ws-popmenu-item（子项 + 父项）。同一事件若每个祖先都
                // 处理，子菜单内的 ArrowDown/ArrowLeft 等会被父项覆盖成根层导航。
                // 因此做双重防护：
                // 1) 只有“真正持有焦点”的项才响应键盘；
                // 2) 由于处理器会把焦点移动到父级，父级在同一个事件轮次内也会变成
                //    活动元素从而通过第 1 条检查——必须在原始事件上打标，确保整个
                //    keydown 事件只处理一次（否则 ArrowLeft 会沿祖先链一次性跳回根层）。
                if ($item[0] !== document.activeElement) return;
                if (e.originalEvent && e.originalEvent._wspmKeyHandled) return;
                if (e.originalEvent) e.originalEvent._wspmKeyHandled = true;

                if (key === 'Enter' || key === ' ') {
                    e.preventDefault();
                    $item.trigger('click');
                    return;
                }

                // 当前项所在的菜单容器（根菜单或某级子菜单）
                var $menu = $item.closest('.ws-popmenu');
                var $parentItem = $menu.closest('.ws-popmenu-item');

                switch (key) {
                    case 'Escape':
                        e.preventDefault();
                        e.stopPropagation();
                        // 在子菜单中按 Esc 先回退一级；在根菜单则整体关闭
                        if ($parentItem.length) {
                            $menu.removeClass('show');
                            $parentItem.removeAttr('aria-expanded');
                            $parentItem.trigger('focus');
                        } else {
                            self.hide();
                        }
                        break;

                    case 'ArrowDown':
                    case 'ArrowUp':
                        e.preventDefault();
                        self.moveFocus($menu, $item, key === 'ArrowDown' ? 1 : -1);
                        break;

                    case 'ArrowRight':
                        // 注意不能用 find(':scope > ...')：当前 jQuery 环境下 :scope 匹配为空，
                        // 直接取直接子级中最靠近的子菜单容器。
                        var $submenu = $item.children('.ws-popmenu-submenu').first();
                        if ($submenu.length) {
                            e.preventDefault();
                            // 关闭同容器内其它已展开的子菜单，保证每层只有一条展开链
                            var $cont = $item.parent();
                            $cont.children('.ws-popmenu-item').each(function() {
                                if (this === $item[0]) return;
                                var $sibSub = $(this).children('.ws-popmenu-submenu').first();
                                if ($sibSub.length) {
                                    $sibSub.removeClass('show');
                                    $(this).attr('aria-expanded', 'false');
                                }
                            });
                            $submenu.addClass('show');
                            $item.attr('aria-expanded', 'true');
                            self.focusFirstItem($submenu);
                        }
                        break;

                    case 'ArrowLeft':
                        if ($parentItem.length) {
                            e.preventDefault();
                            $menu.removeClass('show');
                            $parentItem.attr('aria-expanded', 'false');
                            $parentItem.trigger('focus');
                        }
                        break;
                }
            });
            
            // 点击页面其他地方隐藏菜单
            if (this.options.autoClose) {
                $(document).on('click.' + this.id, function(e) {
                    if (self.isVisible && 
                        !self.$element.is(e.target) && 
                        self.$element.has(e.target).length === 0 &&
                        (!self.$trigger || (!self.$trigger.is(e.target) && self.$trigger.has(e.target).length === 0))) {
                        self.hide();
                    }
                });
                
                // 右键菜单的上下文菜单事件处理
                if (this.options.trigger === 'contextmenu') {
                    $(document).on('contextmenu.' + this.id, function(e) {
                        if (self.isVisible && 
                            !self.$element.is(e.target) && 
                            self.$element.has(e.target).length === 0 &&
                            (!self.$trigger || (!self.$trigger.is(e.target) && self.$trigger.has(e.target).length === 0))) {
                            self.hide();
                        }
                    });
                }
            }
        },
        
        attachTo: function($trigger) {
            if (this.isAttached) {
                this.detach();
            }
            
            this.$trigger = $trigger;
            var self = this;

            // 键盘支持：ArrowDown/ArrowUp 打开菜单并将焦点移入首项，Escape 关闭菜单
            $trigger.on('keydown.' + this.id, function(e) {
                if (e.key === 'Escape') {
                    if (self.isVisible) {
                        e.preventDefault();
                        self.hide();
                    }
                } else if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                    e.preventDefault();
                    if (self.isVisible) {
                        self.focusFirstItem(self.$element);
                    } else {
                        // 键盘打开：由 show() 在动画结束后把焦点交给首项
                        self._pendingFocus = true;
                        self.show();
                    }
                }
            });
            
            if (this.options.trigger === 'click') {
                $trigger.on('click.' + this.id, function(e) {
                    e.stopPropagation();
                    self.toggle();
                });
            } else if (this.options.trigger === 'hover') {
                $trigger.on('mouseenter.' + this.id, function() {
                    self.show();
                });
                
                $trigger.on('mouseleave.' + this.id, function() {
                    // 延迟隐藏，以便用户能够移动到菜单上
                    self.hideTimeout = setTimeout(function() {
                        if (!self.$element.is(':hover')) {
                            self.hide();
                        }
                    }, 100);
                });
                
                this.$element.on('mouseenter.' + this.id, function() {
                    // 鼠标在菜单上时保持显示
                    clearTimeout(self.hideTimeout);
                });
                
                this.$element.on('mouseleave.' + this.id, function() {
                    self.hide();
                });
            } else if (this.options.trigger === 'contextmenu') {
                $trigger.on('contextmenu.' + this.id, function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    
                    // 更新菜单位置到鼠标点击处
                    self.updatePosition(e.clientX, e.clientY);
                    self.show();
                });
            }
            
            this.isAttached = true;
            return this;
        },
        
        detach: function() {
            if (this.$trigger) {
                this.$trigger.off('.' + this.id);
            }
            
            this.$element.off('.' + this.id);
            $(document).off('.' + this.id);
            
            this.isAttached = false;
            return this;
        },
        
        show: function() {
            if (this.isVisible) return this;
            
            // 更新菜单位置
            if (this.options.trigger !== 'contextmenu') {
                this.updatePosition();
            }
            
            // 显示菜单
            this.$element.addClass('show');
            this.isVisible = true;

            // 键盘可达性：仅当本次是「键盘打开」（attachTo 中 Arrow 键置位 _pendingFocus）
            // 才把焦点移入首个可用菜单项，使方向键 / Esc 立即可用。
            // 鼠标（click/contextmenu/hover）打开时不得自动聚焦：否则首项 :focus 高亮常驻，
            // 且其 `:focus > 子菜单` 规则会让二级菜单一直展开、鼠标移到其它项也不隐藏。
            // 注意：菜单有 0.15s visibility 过渡动画，show 的瞬间元素仍处于
            // visibility:hidden 状态导致 focus() 失效，因此延后到动画结束后再聚焦。
            var pendingFocus = this._pendingFocus;
            this._pendingFocus = false;
            if (pendingFocus) {
                var self = this;
                setTimeout(function() {
                    if (self.isVisible) {
                        self.focusFirstItem(self.$element);
                    }
                }, 120);
            }
            
            // 触发显示回调
            if (this.options.onShow) {
                this.options.onShow();
            }
            
            return this;
        },
        
        hide: function() {
            if (!this.isVisible) return this;
            
            // 隐藏所有子菜单
            this.$element.find('.ws-popmenu-submenu').removeClass('show')
                .closest('.ws-popmenu-item').removeAttr('aria-expanded');
            
            // 隐藏菜单
            this.$element.removeClass('show');
            this.isVisible = false;

            // 焦点归还给触发元素，保证键盘用户停留在原地（下拉菜单行为规范）
            if (this.$trigger &&
                document.activeElement &&
                this.$element.has(document.activeElement).length) {
                this.$trigger.trigger('focus');
            }
            
            // 触发隐藏回调
            if (this.options.onHide) {
                this.options.onHide();
            }
            
            return this;
        },

        /**
         * 鼠标操作进入菜单项时，清除键盘遗留的焦点高亮。
         * 键盘导航会让某一项保持 :focus（其子菜单因 `:focus > 子菜单` 规则持续展开），
         * 此时鼠标移到其它项若不做处理，会出现"某一项始终高亮 / 旧子菜单不隐藏"。
         * @param {jQuery} [$keep] 保持焦点的菜单项（一般传当前 hover 项，不参与清除）
         */
        clearStaleMenuFocus: function($keep) {
            var active = document.activeElement;
            if (!active) return;
            var $active = $(active);
            if ($active.closest('.ws-popmenu').length &&
                (!$keep || active !== $keep[0])) {
                if (typeof active.blur === 'function') {
                    active.blur();
                }
            }
        },

        /**
         * 聚焦菜单内第一个可用的菜单项
         * @param {jQuery} $menu 菜单容器（根菜单或子菜单）
         */
        focusFirstItem: function($menu) {
            var $item = $menu.children('.ws-popmenu-item').not('.disabled').first();
            if ($item.length) {
                $item.trigger('focus');
            }
            return this;
        },

        /**
         * 在当前菜单内上下移动焦点（循环）
         * @param {jQuery} $menu 菜单容器
         * @param {jQuery} $current 当前聚焦的菜单项
         * @param {number} direction 1=向下 / -1=向上
         */
        moveFocus: function($menu, $current, direction) {
            var $items = $menu.children('.ws-popmenu-item').not('.disabled');
            if (!$items.length) return this;
            var index = $items.index($current);
            if (index < 0) index = direction > 0 ? -1 : 0;
            var next = (index + direction + $items.length) % $items.length;
            $items.eq(next).trigger('focus');
            return this;
        },
        
        toggle: function() {
            if (this.isVisible) {
                this.hide();
            } else {
                this.show();
            }
            return this;
        },
        
        updatePosition: function(x, y) {
            // 右键菜单直接使用鼠标位置
            if (this.options.trigger === 'contextmenu' && x !== undefined && y !== undefined) {
                var scrollX = window.pageXOffset;
                var scrollY = window.pageYOffset;
                
                this.$element.css({
                    top: y + scrollY + this.options.offsetY + 'px',
                    left: x + scrollX + this.options.offsetX + 'px'
                });
                
                // 边界检查
                this.adjustPosition();
                return this;
            }
            
            if (!this.$trigger) return this;
            
            var triggerRect = this.$trigger[0].getBoundingClientRect();
            // 用布局尺寸而非 getBoundingClientRect：菜单未显示时带 transform: scale(0.95)，
            // 取到的宽高是缩放后的值，会导致 center / -end / top / bottom 等定位系统性偏移。
            // offsetWidth/offsetHeight 不受 transform 影响，返回真实布局尺寸。
            var menuWidth = this.$element[0].offsetWidth;
            var menuHeight = this.$element[0].offsetHeight;
            var scrollX = window.pageXOffset;
            var scrollY = window.pageYOffset;
            
            var top, left;
            
            // 解析位置选项
            var placement = this.options.placement;
            var parts = placement.split('-');
            var primary = parts[0];
            var secondary = parts[1] || 'start';
            
            switch (primary) {
                case 'top':
                    top = triggerRect.top + scrollY - menuHeight - 5;
                    if (secondary === 'start') {
                        left = triggerRect.left + scrollX;
                    } else if (secondary === 'end') {
                        left = triggerRect.right + scrollX - menuWidth;
                    } else {
                        left = triggerRect.left + scrollX + (triggerRect.width - menuWidth) / 2;
                    }
                    break;
                case 'bottom':
                    top = triggerRect.bottom + scrollY + 5;
                    if (secondary === 'start') {
                        left = triggerRect.left + scrollX;
                    } else if (secondary === 'end') {
                        left = triggerRect.right + scrollX - menuWidth;
                    } else {
                        left = triggerRect.left + scrollX + (triggerRect.width - menuWidth) / 2;
                    }
                    break;
                case 'left':
                    left = triggerRect.left + scrollX - menuWidth - 5;
                    if (secondary === 'start') {
                        top = triggerRect.top + scrollY;
                    } else if (secondary === 'end') {
                        top = triggerRect.bottom + scrollY - menuHeight;
                    } else {
                        top = triggerRect.top + scrollY + (triggerRect.height - menuHeight) / 2;
                    }
                    break;
                case 'right':
                    left = triggerRect.right + scrollX + 5;
                    if (secondary === 'start') {
                        top = triggerRect.top + scrollY;
                    } else if (secondary === 'end') {
                        top = triggerRect.bottom + scrollY - menuHeight;
                    } else {
                        top = triggerRect.top + scrollY + (triggerRect.height - menuHeight) / 2;
                    }
                    break;
                default:
                    top = triggerRect.bottom + scrollY + 5;
                    left = triggerRect.left + scrollX;
            }
            
            this.$element.css({
                top: top + this.options.offsetY + 'px',
                left: left + this.options.offsetX + 'px'
            });
            
            // 边界检查
            this.adjustPosition();
            return this;
        },
        
        adjustPosition: function() {
            // 同样使用不受 transform 缩放影响的布局尺寸做视口边界夹取
            var menuWidth = this.$element[0].offsetWidth;
            var menuHeight = this.$element[0].offsetHeight;
            var viewportWidth = window.innerWidth;
            var viewportHeight = window.innerHeight;
            var scrollX = window.pageXOffset;
            var scrollY = window.pageYOffset;
            
            // 读取 inline 样式坐标做夹取，不能读 position()/offset()：
            // 元素显示前带 transform: scale(0.95)，jQuery 的 position() 经由
            // getBoundingClientRect 拿到的是被缩放偏移过的视觉坐标（约 +2.5% 宽/高），
            // 写回 style 后 scale 复原为 1 时，菜单会永久偏右 / 偏下。
            var top = parseFloat(this.$element.css('top')) || 0;
            var left = parseFloat(this.$element.css('left')) || 0;
            
            // 水平边界检查
            if (left + menuWidth > viewportWidth + scrollX) {
                left = viewportWidth + scrollX - menuWidth - 10;
            }
            
            if (left < scrollX) {
                left = scrollX + 10;
            }
            
            // 垂直边界检查
            if (top + menuHeight > viewportHeight + scrollY) {
                top = viewportHeight + scrollY - menuHeight - 10;
            }
            
            if (top < scrollY) {
                top = scrollY + 10;
            }
            
            this.$element.css({
                top: top + 'px',
                left: left + 'px'
            });
            return this;
        },
        
        updateItems: function(newItems) {
            this.options.items = newItems;
            this.buildMenu(this.$element, newItems);
            return this;
        },
        
        updateOptions: function(newOptions) {
            this.options = $.extend({}, this.options, newOptions);
            
            // 如果trigger或autoClose选项改变，需要重新绑定事件
            if (newOptions.trigger || newOptions.autoClose !== undefined) {
                this.detach();
                this.bindEvents();
                if (this.$trigger) {
                    this.attachTo(this.$trigger);
                }
            }
            
            return this;
        },
        
        destroy: function() {
            this.detach();
            this.$element.remove();
            
            // 清理所有timeout
            clearTimeout(this.hideTimeout);
            for (var key in this.submenuHideTimeouts) {
                clearTimeout(this.submenuHideTimeouts[key]);
            }
            
            return this;
        }
    };
    
    // jQuery 插件封装
    $.fn.wsPopMenu = function(options) {
        return this.each(function() {
            var $this = $(this);
            // 避免重复创建实例：若已存在实例，先销毁再重建
            var existing = $this.data('wsPopmenu');
            if (existing) {
                existing.destroy();
            }
            var instance = new WsPopMenu(options);
            instance.attachTo($this);
            
            // 将菜单实例存储到元素上，以便后续访问
            $this.data('wsPopmenu', instance);
        });
    };
    
    /* ============================================================
     * 声明式接入（data-role="wspopmenu"）
     *
     * ```html
     * <button type="button" class="btn btn-outline-secondary"
     *         data-role="wspopmenu"
     *         data-items='[{"id":"copy","text":"复制","icon":"bi bi-files"},
     *                       {"type":"divider"},
     *                       {"id":"del","text":"删除","icon":"bi bi-trash"}]'>更多</button>
     * ```
     *
     * ⚠️ 本组件**不是**表单控件：没有值、不参与提交。
     * 用途是表单工具栏「更多操作」与字段级操作菜单。
     * ============================================================ */

    /** 读布尔 data-*（缺省返回 fallback） */
    function readBoolAttr(el, attr, fallback) {
        if (!el.hasAttribute(attr)) return fallback;
        var raw = el.getAttribute(attr);
        return raw === '' || /^(true|1|yes|on)$/i.test(String(raw).trim());
    }

    /** 解析 data-* 配置 */
    function parseMenuConfig(el) {
        var config = {};

        if (el.hasAttribute('data-items')) {
            var raw = el.getAttribute('data-items');
            try {
                config.items = JSON.parse(raw);
            } catch (e) {
                console.warn('[ws-popmenu] data-items 不是合法 JSON，已忽略:', raw);
            }
        }

        var trigger = el.getAttribute('data-trigger');
        if (trigger) config.trigger = trigger;

        var placement = el.getAttribute('data-placement');
        if (placement) config.placement = placement;

        if (el.hasAttribute('data-offset-x')) config.offsetX = parseFloat(el.getAttribute('data-offset-x')) || 0;
        if (el.hasAttribute('data-offset-y')) config.offsetY = parseFloat(el.getAttribute('data-offset-y')) || 0;

        config.autoClose = readBoolAttr(el, 'data-auto-close', true);

        // 回调按函数名从 window 取
        ['onShow', 'onHide', 'onItemClick'].forEach(function (key) {
            var attr = 'data-' + key.replace(/[A-Z]/g, function (c) { return '-' + c.toLowerCase(); });
            if (!el.hasAttribute(attr)) return;
            var fnName = el.getAttribute(attr);
            if (typeof window[fnName] === 'function') config[key] = window[fnName];
        });

        return config;
    }

    /**
     * 扫描并初始化 `[data-role="wspopmenu"]`。
     * @param {*} [root] document / 元素 / 选择器
     * @returns {number} 初始化数量
     */
    WsPopMenu.autoInitialize = function(root) {
        var scope = !root ? document : (typeof root === 'string' ? document.querySelector(root) : root);
        if (!scope) return 0;

        var list = [];
        if (scope.nodeType === 1 && scope.matches && scope.matches('[data-role="wspopmenu"]')) list.push(scope);
        if (scope.querySelectorAll) {
            list = list.concat(Array.prototype.slice.call(scope.querySelectorAll('[data-role="wspopmenu"]')));
        }

        var count = 0;

        list.forEach(function(el) {
            var $el = $(el);
            if ($el.data('wsPopmenu')) return;

            var config = parseMenuConfig(el);

            // data-url：菜单项由服务端下发（权限不同 → 菜单不同）
            if (el.hasAttribute('data-url') && !config.items) {
                var url = el.getAttribute('data-url');
                fetch(url, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                })
                    .then(function(res) { return res.ok ? res.json() : Promise.reject(new Error('HTTP ' + res.status)); })
                    .then(function(json) {
                        var rows = json && json.data && json.data.rows ? json.data.rows : (Array.isArray(json) ? json : []);
                        if (Array.isArray(rows) && rows.length) {
                            var inst = $el.data('wsPopmenu');
                            if (inst) inst.updateItems(rows);
                        }
                    })
                    .catch(function(err) {
                        console.error('[ws-popmenu] 菜单项加载失败', err);
                    });

                // 先用空菜单占位，接口回来后 updateItems
                config.items = [];
            }

            try {
                $el.wsPopMenu(config);
                count++;
            } catch (err) {
                console.error('[ws-popmenu] 初始化失败', el, err);
            }
        });

        return count;
    };

// 全局挂载：统一使用 Ws* 标准名 window.WsPopMenu，存量调用零改造。
window.WsPopMenu = WsPopMenu;

export default WsPopMenu;