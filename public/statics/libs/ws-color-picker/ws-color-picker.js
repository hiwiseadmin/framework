// 样式：ws-color-picker.css
// 本模块刻意不 import CSS —— 浏览器原生 ESM 不支持导入 CSS，一旦 import，
// demo.html 等"直连源码"的场景会因 MIME 类型不匹配而整体加载失败。
// 生产：由 statics/modules/ws/ws-components.css 汇总，构建进 wise-bundle.css
// 调试：<link rel="stylesheet" href="/statics/libs/ws-color-picker/ws-color-picker.css">

// jQuery 走全局单例：本组件是 jQuery 插件，若 import 出第二份 jQuery 实例，
// 插件会注册在 A 实例上而业务用 B 实例调用，功能静默失效。
const $ = window.jQuery;
if (typeof $ === 'undefined') {
    throw new Error('WsColorPicker requires jQuery');
}

// 实例计数器，用于生成唯一ID
var _instanceCounter = 0;
    
    // 样式已改由 statics/modules/ws/ws-components.css 统一引入（见文件顶部说明）。
    // 保留空实现：组件内部存在 addStyles() 调用点，直接删除会抛 ReferenceError。
    function addStyles() {
        // no-op：样式已随 bundle 加载
    }
    // 定义渐变颜色节点
    const gradientColors = [
        { pos: 0,   color: [255, 0, 0] },     // 红
        { pos: 0.17, color: [255, 255, 0] },  // 黄
        { pos: 0.33, color: [0, 255, 0] },    // 绿
        { pos: 0.5,  color: [0, 255, 255] },  // 青
        { pos: 0.67, color: [0, 0, 255] },    // 蓝
        { pos: 0.83, color: [255, 0, 255] },  // 品红
        { pos: 1,   color: [255, 0, 0] }      // 红（循环回起点）
    ];
    // 默认标准调色板（32 色），可通过 options.standardColors 覆盖
    const DEFAULT_STANDARD_COLORS = [
        // 红色系
        '#ff0000', '#ff4000', '#ff8000', '#ffbf00', '#ffff00', '#bfff00', '#80ff00', '#40ff00',
        // 绿色系
        '#00ff00', '#00ff40', '#00ff80', '#00ffbf', '#00ffff', '#00bfff', '#0080ff', '#0040ff',
        // 蓝色系
        '#0000ff', '#4000ff', '#8000ff', '#bf00ff', '#ff00ff', '#ff00bf', '#ff0080', '#ff0040',
        // 中性色
        '#000000', '#404040', '#808080', '#c0c0c0', '#ffffff', '#ffcccc', '#ccffcc', '#ccccff'
    ];

    // 颜色选择器类
    function WsColorPicker(options) {
        this.options = $.extend({
            container: document.body,
            initialColor: '#007bff',
            onColorChange: null,
            onColorSelect: null,
            modalClass: 'ws-color-picker-modal',
            mode: 'default',                 // 弹窗布局：default(完整) / mini(迷你，仅标准色+滑块+最近使用)
            standardColors: null,            // 可选：自定义标准调色板颜色数组（覆盖默认 32 色）
            storagePrefix: 'ws-color-picker',// 最近使用/收藏颜色的 localStorage 命名空间
            maxRecent: 8,                    // 最近使用保留的最大数量
            resetOnCancel: true,             // 取消/关闭(未点确认)时，是否把组件内部颜色还原为打开时的初始色
            onCancel: null,                  // 取消回调：onCancel(当前颜色, 打开时初始色, { changed, lastColor })
            onClose: null                    // 关闭回调：onClose({ action:'confirm'|'cancel', confirmed, originalColor, color, lastColor, changed })
        }, options);
        
        // 校验 initialColor 和 modalClass，防止 XSS
        if (!/^#[0-9a-fA-F]{3,8}$/.test(this.options.initialColor)) {
            this.options.initialColor = '#007bff';
        }
        if (!/^[a-zA-Z][\w-]*$/.test(this.options.modalClass)) {
            this.options.modalClass = 'ws-color-picker-modal';
        }
        if (this.options.mode !== 'mini') {
            this.options.mode = 'default';
        }
        
        // 生成唯一实例ID
        this.uid = ++_instanceCounter;
        
        this.currentColor = {
            h: 210,
            s: 100,
            l: 50,
            a: 1
        };

        // 标准调色板：支持初始化配置自定义颜色。
        // 仅接受合法的 #RRGGBB 值（颜色值会进入 DOM data/style 属性，必须防注入）
        if (Array.isArray(this.options.standardColors) && this.options.standardColors.length) {
            this.standardColors = [];
            var self = this;
            this.options.standardColors.forEach(function(color) {
                if (/^#[0-9a-fA-F]{6}$/.test(String(color))) {
                    self.standardColors.push(String(color).toLowerCase());
                }
            });
            if (!this.standardColors.length) {
                this.standardColors = DEFAULT_STANDARD_COLORS.slice();
            }
        } else {
            this.standardColors = DEFAULT_STANDARD_COLORS.slice();
        }

        // 最近使用 / 收藏：从 localStorage 恢复（默认跨实例共享，可用 storagePrefix 隔离）
        this.recentColors = this._loadColorList(this.options.storagePrefix + ':recent');
        this.favoriteColors = this._loadColorList(this.options.storagePrefix + ':favorites');
        
        this.init();
    }
    
    WsColorPicker.prototype = {
        constructor: WsColorPicker,
        
        init: function() {
            addStyles();
            this.setInitialColor();
            this.createModal();
            this.bindEvents();
        },
        
        /*
         * 从 localStorage 读取颜色数组并做合法性过滤
         * @param {string} key localStorage 键
         * @returns {Array<string>} 合法的 #rrggbb 列表
         */
        _loadColorList: function(key) {
            var list = [];
            try {
                var raw = window.localStorage.getItem(key);
                if (!raw) return list;
                var parsed = JSON.parse(raw);
                if (!Array.isArray(parsed)) return list;
                parsed.forEach(function(color) {
                    // 颜色值会写入 DOM 的 data-color/style，仅放行严格 hex，防止存储被篡改注入
                    if (typeof color === 'string' && /^#[0-9a-fA-F]{6}$/.test(color)) {
                        list.push(color.toLowerCase());
                    }
                });
            } catch (e) {
                // 存储不可用或被破坏时静默降级为空列表
            }
            return list;
        },

        /*
         * 将颜色数组写入 localStorage
         * @param {string} key localStorage 键
         * @param {Array<string>} list 颜色列表
         */
        _saveColorList: function(key, list) {
            try {
                window.localStorage.setItem(key, JSON.stringify(list));
            } catch (e) {
                // 隐私模式 / 配额超限时静默失败，不影响正常使用
            }
        },

        /* 
         * 设置初始颜色
         */
        setInitialColor: function() {
            var initialHSL = this.hexToHsl(this.options.initialColor);
            this.currentColor = {
                h: initialHSL[0],
                s: initialHSL[1],
                l: initialHSL[2],
                a: 1
            };
        },

        /* 
         * 创建颜色选择器弹窗
         */
        createModal: function() {
            var modalHTML = this.options.mode === 'mini'
                ? this.renderMiniModal()
                : this.renderDefaultModal();

            $(this.options.container).append(modalHTML);
            this.initializeUI();
        },

        /*
         * 默认模式弹窗模板（完整布局：色相/饱和度面板 + 全部滑块 + 调色板/最近/收藏）
         */
        renderDefaultModal: function() {
            var uid = this.uid;
            return `
                <div class="modal fade ${this.options.modalClass}" id="colorPickerModal_${uid}" tabindex="-1" aria-labelledby="colorPickerModalLabel_${uid}" aria-hidden="true">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content ws-color-picker-container">
                            <div class="modal-header">
                                <h5 class="modal-title" id="colorPickerModalLabel_${uid}">
                                    <i class="bi bi-palette me-2"></i>颜色选择器
                                </h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body p-4">
                                <div class="row">
                                    <div class="col-md-8">
                                        <div class="d-flex justify-content-between align-items-center mb-4">
                                            <div class="d-flex align-items-center">
                                                <div class="ws-color-preview-bg"></div>
                                                <div id="colorPreview_${uid}" class="ws-color-preview me-3" style="background-color: ${this.options.initialColor};"></div>
                                                <div>
                                                    <select id="colorFormat_${uid}" class="form-select ws-color-format-select" aria-label="颜色格式">
                                                        <option value="hex">HEX</option>
                                                        <option value="rgb">RGB</option>
                                                        <option value="hsl">HSL</option>
                                                        <option value="hsv">HSV</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <button id="addToFavorites_${uid}" class="btn btn-outline-secondary favorite-toggle-btn" type="button" title="收藏 / 取消收藏当前颜色" aria-pressed="false">
                                                <i class="bi bi-star"></i>
                                            </button>
                                        </div>
                                        
                                        <div class="mb-4">
                                            <label class="form-label" id="hsLabel_${uid}">色相和饱和度</label>
                                            <div id="hueSaturationPicker_${uid}" class="ws-hue-saturation" role="application" aria-label="色相和饱和度">
                                                <div id="hueSaturationPointer_${uid}" class="ws-picker-pointer" style="top: 0%; left: 100%;"></div>
                                            </div>
                                        </div>
                                        
                                        <div class="ws-slider-container">
                                            <label for="lightnessSlider_${uid}" class="form-label">亮度</label>
                                            <div class="alpha-control">
                                            <input type="range" class="form-range alpha-slider" id="lightnessSlider_${uid}" min="0" max="100" value="50" aria-label="亮度">
                                            </div>
                                        </div>
                                        
                                        <div class="ws-slider-container">
                                            <label for="alphaSlider_${uid}" class="form-label">透明度</label>
                                            <div class="alpha-control">
                                                <div class="grid-bg"></div>
                                                <input type="range" class="form-range alpha-slider" id="alphaSlider_${uid}" min="0" max="100" value="100" aria-label="透明度">
                                            </div>
                                        </div>
                                        <div class="mt-4">
                                            <label for="hexInput_${uid}" class="form-label">十六进制颜色值</label>
                                            <div class="input-group">
                                                <span class="input-group-text">#</span>
                                                <input type="text" class="form-control ws-hex-input" id="hexInput_${uid}" value="${this.options.initialColor.replace('#', '')}" maxlength="7" placeholder="RRGGBB" autocomplete="off" aria-label="十六进制颜色值">
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="col-md-4">
                                        <div class="mb-4">
                                            <label class="form-label" id="stdLabel_${uid}">标准调色板</label>
                                            <div id="standardPalette_${uid}" class="ws-color-palette" role="listbox" aria-label="标准调色板"></div>
                                            <div class="ribbon-picker">
                                                <div class="ribbon-bg"></div>
                                                <input type="range" class="form-range alpha-slider ribbon-slider" id="ribbonSlider_${uid}" min="0" max="100" value="100" aria-label="色相条">
                                            </div>
                                        </div>
                                        <div class="mb-4">
                                            <label class="form-label">最近使用</label>
                                            <div id="recentColors_${uid}" class="ws-recent-colors" role="listbox" aria-label="最近使用"></div>
                                        </div>
                                        
                                        <div class="mb-4">
                                            <label class="form-label">收藏的颜色</label>
                                            <div id="favoriteColors_${uid}" class="ws-favorite-colors" role="listbox" aria-label="收藏的颜色"></div>
                                        </div>
                                        
                                        <div class="mt-4">
                                            <label class="form-label">当前颜色值</label>
                                            <div id="colorValueDisplay_${uid}" class="ws-color-value-display">${this.options.initialColor}</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                                <button type="button" class="btn btn-primary" id="confirmColor_${uid}">确认选择</button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        },

        /*
         * 迷你模式弹窗模板（紧凑：仅 标准调色板 + 色相/亮度/透明度滑块 + 最近使用，
         * 以图标代替文字尽量压缩高度）
         */
        renderMiniModal: function() {
            var uid = this.uid;
            return `
                <div class="modal fade ${this.options.modalClass}" id="colorPickerModal_${uid}" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-centered modal-sm ws-color-picker-mini-dialog">
                        <div class="modal-content ws-color-picker-container ws-color-picker-mini">
                            <div class="modal-header py-1 px-2">
                                <h6 class="modal-title mb-0"><i class="bi bi-palette me-1"></i>颜色</h6>
                                <button type="button" class="btn-close btn-sm" data-bs-dismiss="modal" aria-label="Close"></button>
                            </div>
                            <div class="modal-body p-2 pt-2">
                                <!-- 当前色预览 + HEX 快捷输入（预览必须为块级元素，行内 span 的宽高不生效导致不显示） -->
                                <div class="d-flex align-items-center gap-2 mini-current-row mb-2">
                                    <div class="position-relative mini-swatch-wrap">
                                        <div class="ws-color-preview-bg mini-bg"></div>
                                        <div id="colorPreview_${uid}" class="ws-color-preview mini-preview" style="background-color: ${this.options.initialColor};"></div>
                                    </div>
                                    <div class="input-group input-group-sm flex-grow-1">
                                        <span class="input-group-text px-2">#</span>
                                        <input type="text" class="form-control ws-hex-input" id="hexInput_${uid}" value="${this.options.initialColor.replace('#', '')}" maxlength="7" placeholder="RRGGBB" autocomplete="off" aria-label="十六进制颜色值">
                                    </div>
                                </div>

                                <!-- 标准调色板 -->
                                <div id="standardPalette_${uid}" class="ws-color-palette" role="listbox" aria-label="标准调色板"></div>

                                <!-- 滑块：色相条 / 亮度 / 透明度（图标代替文字） -->
                                <div class="mini-slider-row">
                                    <i class="bi bi-palette2" title="色相" aria-hidden="true"></i>
                                    <div class="ribbon-picker mini-ribbon flex-grow-1">
                                        <div class="ribbon-bg"></div>
                                        <input type="range" class="form-range alpha-slider ribbon-slider" id="ribbonSlider_${uid}" min="0" max="100" value="100" aria-label="色相">
                                    </div>
                                </div>
                                <div class="mini-slider-row">
                                    <i class="bi bi-brightness-high-fill" title="亮度" aria-hidden="true"></i>
                                    <div class="alpha-control">
                                        <input type="range" class="form-range alpha-slider" id="lightnessSlider_${uid}" min="0" max="100" value="50" aria-label="亮度">
                                    </div>
                                </div>
                                <div class="mini-slider-row">
                                    <i class="bi bi-circle-half" title="透明度" aria-hidden="true"></i>
                                    <div class="alpha-control">
                                        <div class="grid-bg"></div>
                                        <input type="range" class="form-range alpha-slider" id="alphaSlider_${uid}" min="0" max="100" value="100" aria-label="透明度">
                                    </div>
                                </div>

                                <!-- 最近使用 -->
                                <div id="recentColors_${uid}" class="ws-recent-colors" role="listbox" aria-label="最近使用"></div>
                            </div>
                            <div class="modal-footer py-1 px-2 justify-content-end">
                                <button type="button" class="btn btn-primary btn-sm" id="confirmColor_${uid}">
                                    <i class="bi bi-check-lg me-1"></i>确认
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        },

        /* 
         * 初始化UI组件
         */
        initializeUI: function() {
            // 生成标准调色板
            var standardPaletteHTML = '';
            this.standardColors.forEach(function(color) {
                standardPaletteHTML += '<div class="ws-color-swatch" data-color="' + color + '" style="background-color: ' + color + ';"></div>';
            });
            $('#standardPalette_' + this.uid).html(standardPaletteHTML);
            
            // 色带值
            this.updateRibbonSlider();

            // 生成最近使用颜色 (初始为空)
            this.updateRecentColorsUI();
            
            // 生成收藏颜色 (初始为空)
            this.updateFavoriteColorsUI();
            
            // 设置初始颜色
            this.updateColorFromHSLA(this.currentColor.h, this.currentColor.s, this.currentColor.l, this.currentColor.a);
            
            // 更新色相饱和度选择器背景
            this.updateHueSaturationBackground();

            // 更新选择状态
            this.updateSelectedState();

            // 同步收藏按钮（当前颜色已在收藏中则点亮星标）
            this.updateFavoriteButtonUI();
        },

        /*
         * 根据当前颜色是否在收藏中，同步顶部收藏按钮的图标与状态
         */
        updateFavoriteButtonUI: function() {
            var uid = this.uid;
            var $btn = $('#addToFavorites_' + uid);
            if (!$btn.length) return;
            var hex = this.hslToHex(this.currentColor.h, this.currentColor.s, this.currentColor.l);
            var isFav = this.favoriteColors.indexOf(hex) !== -1;
            var $icon = $btn.find('i').first();
            $icon.toggleClass('bi-star', !isFav).toggleClass('bi-star-fill', isFav);
            $btn.toggleClass('btn-outline-secondary', !isFav)
                .toggleClass('btn-warning', isFav)
                .attr('aria-pressed', isFav ? 'true' : 'false')
                .attr('title', isFav ? '取消收藏当前颜色' : '收藏当前颜色');
        },

        /**
         * 更新色带值
         */
        updateRibbonSlider: function() {
            var pos = this.getPositionFromColor(this.hslToRgb(this.currentColor.h, this.currentColor.s, this.currentColor.l));
            var percent = pos !== null ? parseInt(pos, 10) : 0;
            $('#ribbonSlider_' + this.uid).val(percent);
        },

        /* 
         * 更新最近使用颜色UI
         */
        updateRecentColorsUI: function() {
            var recentColorsHTML = '';
            this.recentColors.forEach(function(color) {
                recentColorsHTML += '<div class="ws-color-swatch" data-color="' + color + '" style="background-color: ' + color + ';"></div>';
            });
            $('#recentColors_' + this.uid).html(recentColorsHTML || '<div class="text-muted small">无最近使用的颜色</div>');
        },

        /* 
         * 更新收藏颜色UI
         */
        updateFavoriteColorsUI: function() {
            var favoriteColorsHTML = '';
            this.favoriteColors.forEach(function(color) {
                favoriteColorsHTML += '<div class="ws-color-swatch" data-color="' + color + '" style="background-color: ' + color + ';"></div>';
            });
            $('#favoriteColors_' + this.uid).html(favoriteColorsHTML || '<div class="text-muted small">无收藏的颜色</div>');
        },

        /* 
         * 更新颜色从HSLA值
         * @param {number} h - 色相（0-360）
         * @param {number} s - 饱和度（0-100）
         * @param {number} l - 亮度（0-100）
         * @param {number} a - 透明度（0-1）
         */
        updateColorFromHSLA: function(h, s, l, a) {
            this.currentColor.h = h;
            this.currentColor.s = s;
            this.currentColor.l = l;
            this.currentColor.a = a;
            
            // 更新UI
            this.updateColorUI();
            
            // 触发颜色改变回调
            if (typeof this.options.onColorChange === 'function') {
                this.options.onColorChange(this.getCurrentColor());
            }
        },

        /* 
         * 更新颜色UI
         */
        updateColorUI: function() {
            var uid = this.uid;
            var hexColor = this.hslToHex(this.currentColor.h, this.currentColor.s, this.currentColor.l);
            var rgbaColor = 'rgba(' + this.hslToRgb(this.currentColor.h, this.currentColor.s, this.currentColor.l).join(', ') + ', ' + this.currentColor.a + ')';
            var rgblsColor = 'rgba(' + this.hslToRgb(this.currentColor.h, this.currentColor.s, 0).join(', ') + ', ' + this.currentColor.a + ')';
            var rgbleColor = 'rgba(' + this.hslToRgb(this.currentColor.h, this.currentColor.s, 100).join(', ') + ', ' + this.currentColor.a + ')';
            var rgbasColor = 'rgba(' + this.hslToRgb(this.currentColor.h, this.currentColor.s, this.currentColor.l).join(', ') + ', 0)';
            var rgbaeColor = 'rgba(' + this.hslToRgb(this.currentColor.h, this.currentColor.s, this.currentColor.l).join(', ') + ', 100)';

            // 更新颜色预览
            $('#colorPreview_' + uid).css('background-color', rgbaColor);
            
            // 色带值
            this.updateRibbonSlider();

            // 更新颜色值显示
            this.updateColorValueDisplay();
            
            // 更新十六进制输入
            $('#hexInput_' + uid).val(hexColor.replace('#', ''));
            
            // 更新亮度和透明度滑块
            $('#lightnessSlider_' + uid).val(this.currentColor.l);
            $('#lightnessSlider_' + uid).css('background', 'linear-gradient(to right, ' + rgblsColor + ', ' + rgbleColor + ')');
            $('#alphaSlider_' + uid).val(this.currentColor.a * 100);
            $('#alphaSlider_' + uid).css('background', 'linear-gradient(to right, ' + rgbasColor + ', ' + rgbaeColor + ')');
            
            // 更新色相饱和度选择器指针位置
            this.updateHueSaturationPointer();
            
            // 更新色相饱和度选择器背景
            this.updateHueSaturationBackground();
            
            // 更新选择状态
            this.updateSelectedState();
        },

        /* 
         * 更新色相饱和度选择器背景
         */
        updateHueSaturationBackground: function() {
            var hueColor = 'hsl(' + this.currentColor.h + ', 100%, 50%)';
            $('#hueSaturationPicker_' + this.uid).css('background', 
                'linear-gradient(to right, #fff, ' + hueColor + '), linear-gradient(to top, #000, transparent)');
        },

        /* 
         * 更新颜色值显示
         */
        updateColorValueDisplay: function() {
            var uid = this.uid;
            var format = $('#colorFormat_' + uid).val();
            var colorValue = '';
            
            switch(format) {
                case 'hex':
                    colorValue = this.hslToHex(this.currentColor.h, this.currentColor.s, this.currentColor.l);
                    if (this.currentColor.a < 1) {
                        // 如果有透明度，添加alpha通道
                        var alphaHex = Math.round(this.currentColor.a * 255).toString(16).padStart(2, '0');
                        colorValue += alphaHex;
                    }
                    break;
                case 'rgb':
                    var rgb = this.hslToRgb(this.currentColor.h, this.currentColor.s, this.currentColor.l);
                    if (this.currentColor.a === 1) {
                        colorValue = 'rgb(' + rgb[0] + ', ' + rgb[1] + ', ' + rgb[2] + ')';
                    } else {
                        colorValue = 'rgba(' + rgb[0] + ', ' + rgb[1] + ', ' + rgb[2] + ', ' + this.currentColor.a + ')';
                    }
                    break;
                case 'hsl':
                    if (this.currentColor.a === 1) {
                        colorValue = 'hsl(' + this.currentColor.h + ', ' + this.currentColor.s + '%, ' + this.currentColor.l + '%)';
                    } else {
                        colorValue = 'hsla(' + this.currentColor.h + ', ' + this.currentColor.s + '%, ' + this.currentColor.l + '%, ' + this.currentColor.a + ')';
                    }
                    break;
                case 'hsv':
                    var hsv = this.hslToHsv(this.currentColor.h, this.currentColor.s, this.currentColor.l);
                    colorValue = 'hsv(' + hsv[0] + ', ' + hsv[1] + '%, ' + hsv[2] + '%)';
                    break;
            }
            
            $('#colorValueDisplay_' + uid).text(colorValue);
        },

        /* 
         * 更新选择状态
         */
        updateSelectedState: function() {
            var uid = this.uid;
            // 只在本实例的标准调色板、最近使用、收藏颜色中更新选中状态
            var $modal = $('#colorPickerModal_' + uid);
            $modal.find('.ws-color-swatch').removeClass('selected');
            var hex = this.hslToHex(this.currentColor.h, this.currentColor.s, this.currentColor.l);
            // 添加当前选择的类
            $modal.find('.ws-color-swatch[data-color="' + hex + '"]').addClass('selected');
        },
        /* 
         * 获取滑块当前位置的颜色
         * @param {number} percent - 滑块位置百分比 (0-100)
         * @returns {object} - 包含十六进制、RGB和位置的颜色对象
         */
        getColorAtPosition: function(percent) {
            // 确保百分比在0-100之间
            const pos = Math.max(0, Math.min(100, percent)) / 100;

            // 找到对应的颜色段
            for (let i = 0; i < gradientColors.length - 1; i++) {
                const start = gradientColors[i];
                const end = gradientColors[i + 1];

                if (pos >= start.pos && pos <= end.pos) {
                    // 计算在该段中的相对位置 (0-1)
                    const segmentPos = (pos - start.pos) / (end.pos - start.pos);

                    // 线性插值计算颜色
                    const r = Math.round(start.color[0] + (end.color[0] - start.color[0]) * segmentPos);
                    const g = Math.round(start.color[1] + (end.color[1] - start.color[1]) * segmentPos);
                    const b = Math.round(start.color[2] + (end.color[2] - start.color[2]) * segmentPos);

                    // 转换为十六进制
                    return {hex: this.rgbToHex(r, g, b), rgb: [r, g, b], position: percent};
                }
            }

            // 如果不在任何段中，返回第一个颜色
            return {hex: this.rgbToHex(gradientColors[0].color[0], gradientColors[0].color[1], gradientColors[0].color[2]), rgb: gradientColors[0].color, position: 0};
        },

        /* 
         * 查找颜色所在的具体颜色段和精确位置
         * @param {Array} targetRgb - 目标颜色的RGB值数组 [r, g, b]
         * @returns {number|null} - 颜色所在的百分比位置 (0-100)，如果未找到则返回null
         */
        getPositionFromColor: function (targetRgb) {
            for (let i = 0; i < gradientColors.length - 1; i++) {
                const start = gradientColors[i];
                const end = gradientColors[i + 1];

                // 检查目标颜色是否在当前颜色段的直线上
                const t = this.findProjectionOnLine(targetRgb, start.color, end.color);

                if (t >= 0 && t <= 1) {
                    // 计算投影点颜色
                    const projR = Math.round(start.color[0] + (end.color[0] - start.color[0]) * t);
                    const projG = Math.round(start.color[1] + (end.color[1] - start.color[1]) * t);
                    const projB = Math.round(start.color[2] + (end.color[2] - start.color[2]) * t);

                    // 检查投影点颜色与目标颜色的距离是否足够小
                    if (this.colorDistance(targetRgb, [projR, projG, projB]) < 20) {
                        const segmentPos = start.pos + (end.pos - start.pos) * t;
                        return segmentPos * 100; // 返回百分比
                    }
                }
            }

            return null; // 未找到
        },

        /* 
         * 计算两个颜色之间的欧几里得距离
         * @param {Array} rgb1 - 第一个颜色的RGB值数组 [r, g, b]
         * @param {Array} rgb2 - 第二个颜色的RGB值数组 [r, g, b]
         * @returns {number} - 两个颜色之间的距离值
         */
        colorDistance: function (rgb1, rgb2) {
            const dr = rgb1[0] - rgb2[0];
            const dg = rgb1[1] - rgb2[1];
            const db = rgb1[2] - rgb2[2];
            return Math.sqrt(dr * dr + dg * dg + db * db);
        },

        /* 
         * 计算点在直线上的投影参数t
         * @param {Array} point - 目标点的RGB值数组 [r, g, b]
         * @param {Array} lineStart - 直线起点的RGB值数组 [r, g, b]
         * @param {Array} lineEnd - 直线终点的RGB值数组 [r, g, b]
         * @returns {number} - 投影参数t (0-1)，表示点在线段上的位置比例
         */
        findProjectionOnLine: function (point, lineStart, lineEnd) {
            const lineVec = [
                lineEnd[0] - lineStart[0],
                lineEnd[1] - lineStart[1],
                lineEnd[2] - lineStart[2]
            ];

            const pointVec = [
                point[0] - lineStart[0],
                point[1] - lineStart[1],
                point[2] - lineStart[2]
            ];

            // 计算点积
            const lineLengthSq = lineVec[0] * lineVec[0] + lineVec[1] * lineVec[1] + lineVec[2] * lineVec[2];
            if (lineLengthSq === 0) return 0; // 线段长度为0
            const dotProduct = pointVec[0] * lineVec[0] + pointVec[1] * lineVec[1] + pointVec[2] * lineVec[2];
            return dotProduct / lineLengthSq;
        },

        /* 
         * 更新色相饱和度选择器指针位置
         */
        updateHueSaturationPointer: function() {
            var x = this.currentColor.s; // 饱和度对应X轴 (0-100%)
            var y = 100 - (this.currentColor.l / 100 * 100); // 亮度对应Y轴 (反向，因为顶部是白色)
            
            $('#hueSaturationPointer_' + this.uid).css({
                left: x + '%',
                top: y + '%'
            });
        },
        
        /* 
         * RGB转十六进制
         * @param {number} r - 红色（0-255）
         * @param {number} g - 绿色（0-255）
         * @param {number} b - 蓝色（0-255）
         * @returns {string} - 十六进制颜色字符串（例如：#FF0000）
         */
        rgbToHex: function (r, g, b) {
            return "#" + ((1 << 24) + (r << 16) + (g << 8) + b).toString(16).slice(1);
        },

        /* 
         * 十六进制转RGB
         * @param {string} hex - 十六进制颜色字符串（例如：#FF0000）
         * @returns {array} - RGB颜色数组，包含红色（0-255）、绿色（0-255）、蓝色（0-255）
         */
        hexToRgb: function (hex) {
            const result = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
            return result ? [
                parseInt(result[1], 16),
                parseInt(result[2], 16),
                parseInt(result[3], 16)
            ] : [0, 0, 0];
        },

        /* 
         * 将HSL颜色转换为十六进制
         * @param {number} h - 色相（0-360）
         * @param {number} s - 饱和度（0-100）
         * @param {number} l - 亮度（0-100）
         * @returns {string} - 十六进制颜色字符串（例如：#FF0000）
         */
        hslToHex: function(h, s, l) {
            var rgb = this.hslToRgb(h, s, l);
            return '#' + rgb.map(function(x) {
                var hex = Math.round(x).toString(16);
                return hex.length === 1 ? '0' + hex : hex;
            }).join('');
        },

        /* 
         * 将HSL颜色转换为RGB
         * @param {number} h - 色相（0-360）
         * @param {number} s - 饱和度（0-100）
         * @param {number} l - 亮度（0-100）
         * @returns {array} - RGB颜色数组，包含红色（0-255）、绿色（0-255）、蓝色（0-255）
         */
        hslToRgb: function(h, s, l) {
            h /= 360;
            s /= 100;
            l /= 100;
            
            var r, g, b;
            
            if (s === 0) {
                r = g = b = l;
            } else {
                var hue2rgb = function(p, q, t) {
                    if (t < 0) t += 1;
                    if (t > 1) t -= 1;
                    if (t < 1/6) return p + (q - p) * 6 * t;
                    if (t < 1/2) return q;
                    if (t < 2/3) return p + (q - p) * (2/3 - t) * 6;
                    return p;
                };
                
                var q = l < 0.5 ? l * (1 + s) : l + s - l * s;
                var p = 2 * l - q;
                
                r = hue2rgb(p, q, h + 1/3);
                g = hue2rgb(p, q, h);
                b = hue2rgb(p, q, h - 1/3);
            }
            
            return [Math.round(r * 255), Math.round(g * 255), Math.round(b * 255)];
        },
        
        /* 
         * 将HSL颜色转换为HSV
         * @param {number} h - 色相（0-360）
         * @param {number} s - 饱和度（0-100）
         * @param {number} l - 亮度（0-100）
         * @returns {array} - HSV颜色数组，包含色相（0-360）、饱和度（0-100）、明度（0-100）
         */
        hslToHsv: function(h, s, l) {
            s /= 100;
            l /= 100;
            
            var v = l + s * Math.min(l, 1 - l);
            var sv = v === 0 ? 0 : 2 * (1 - l / v);
            
            return [h, Math.round(sv * 100), Math.round(v * 100)];
        },

        /* 
         * 将十六进制颜色转换为HSL
         * @param {string} hex - 十六进制颜色字符串（例如：#FF0000）
         * @returns {array} - HSL颜色数组，包含色相（0-360）、饱和度（0-100）、亮度（0-100）
         */
        hexToHsl: function(hex) {
            // 移除#号
            hex = hex.replace('#', '');
            
            // 处理3位缩写
            if (hex.length === 3) {
                hex = hex[0] + hex[0] + hex[1] + hex[1] + hex[2] + hex[2];
            }
            
            // 解析RGB
            var r = parseInt(hex.substr(0, 2), 16) / 255;
            var g = parseInt(hex.substr(2, 2), 16) / 255;
            var b = parseInt(hex.substr(4, 2), 16) / 255;
            
            var max = Math.max(r, g, b);
            var min = Math.min(r, g, b);
            var h, s, l = (max + min) / 2;
            
            if (max === min) {
                h = s = 0; // 无色
            } else {
                var d = max - min;
                s = l > 0.5 ? d / (2 - max - min) : d / (max + min);
                
                switch (max) {
                    case r: h = (g - b) / d + (g < b ? 6 : 0); break;
                    case g: h = (b - r) / d + 2; break;
                    case b: h = (r - g) / d + 4; break;
                }
                
                h /= 6;
            }
            
            return [Math.round(h * 360), Math.round(s * 100), Math.round(l * 100)];
        },

        /* 
         * 绑定事件
         */
        bindEvents: function() {
            var self = this;
            var uid = this.uid;

            // 监听弹窗关闭事件：区分「点击确认」与「取消/关闭」。
            // 所有退出路径（确认按钮、✕/取消按钮、Esc、点击遮罩、close()/API）最终都会触发
            // bootstrap 的 hidden.bs.modal，在此统一分流。
            $('#colorPickerModal_' + uid).on('hidden.bs.modal', function() {
                self._handleModalClosed();
            });
            
            // 色相饱和度选择器点击事件
            $('#hueSaturationPicker_' + uid).on('mousedown', function(e) {
                e.preventDefault();
                
                var $picker = $(this);
                
                function handleMove(e) {
                    var offset = $picker.offset();
                    var width = $picker.width();
                    var height = $picker.height();
                    
                    var x = Math.max(0, Math.min(1, (e.pageX - offset.left) / width));
                    var y = Math.max(0, Math.min(1, (e.pageY - offset.top) / height));
                    
                    // 计算饱和度和亮度
                    var s = Math.round(x * 100);
                    var l = Math.round((1 - y) * 100);
                    
                    self.updateColorFromHSLA(self.currentColor.h, s, l, self.currentColor.a);
                }
                
                function handleUp() {
                    $(document).off('mousemove', handleMove);
                    $(document).off('mouseup', handleUp);
                }
                
                // 初始处理
                handleMove(e);
                
                $(document).on('mousemove', handleMove);
                $(document).on('mouseup', handleUp);
            });
            
            // 亮度滑块事件
            $('#lightnessSlider_' + uid).on('input', function() {
                var l = parseInt($(this).val());
                self.updateColorFromHSLA(self.currentColor.h, self.currentColor.s, l, self.currentColor.a);
            });
            
            // 透明度滑块事件
            $('#alphaSlider_' + uid).on('input', function() {
                var a = parseInt($(this).val()) / 100;
                self.updateColorFromHSLA(self.currentColor.h, self.currentColor.s, self.currentColor.l, a);
            });
            
            // 十六进制输入事件
            $('#hexInput_' + uid).on('input', function() {
                var hex = $(this).val().trim().replace(/^#/, '');

                // 支持 3 位简写（#abc -> #aabbcc）
                if (/^[0-9a-f]{3}$/i.test(hex)) {
                    hex = hex.replace(/[0-9a-f]/gi, function(ch) {
                        return ch + ch;
                    });
                }

                // 验证十六进制格式（6 位），合法才更新颜色并规范化回写输入框
                if (/^[0-9A-F]{6}$/i.test(hex)) {
                    var hsl = self.hexToHsl('#' + hex);
                    self.updateColorFromHSLA(hsl[0], hsl[1], hsl[2], self.currentColor.a);
                    $(this).val(hex.toUpperCase());
                }
            });
            
            // 颜色格式选择事件
            $('#colorFormat_' + uid).on('change', function() {
                self.updateColorValueDisplay();
            });

            // 色带(色相)选择事件：使用 input 而非 change，保证拖动过程中
            // 实时更新颜色并连续触发 onColorChange 回调（与亮度/透明度滑块一致）
            $('#ribbonSlider_' + uid).on('input', function() {
                var color = self.getColorAtPosition($(this).val()).hex;
                var hsl = self.hexToHsl(color);
                self.updateColorFromHSLA(hsl[0], hsl[1], hsl[2], self.currentColor.a);
            });
            
            // 标准调色板点击事件（限定在本实例模态框内）
            $(document).on('click', '#standardPalette_' + uid + ' .ws-color-swatch', function() {
                var color = $(this).data('color');
                var hsl = self.hexToHsl(color);
                self.updateColorFromHSLA(hsl[0], hsl[1], hsl[2], self.currentColor.a);
            });
            
            // 最近使用颜色点击事件（限定在本实例模态框内）
            $(document).on('click', '#recentColors_' + uid + ' .ws-color-swatch', function() {
                var color = $(this).data('color');
                var hsl = self.hexToHsl(color);
                self.updateColorFromHSLA(hsl[0], hsl[1], hsl[2], self.currentColor.a);
            });
            
            // 收藏颜色点击事件（限定在本实例模态框内）
            $(document).on('click', '#favoriteColors_' + uid + ' .ws-color-swatch', function() {
                var color = $(this).data('color');
                var hsl = self.hexToHsl(color);
                self.updateColorFromHSLA(hsl[0], hsl[1], hsl[2], self.currentColor.a);
            });
            
            // 收藏 / 取消收藏当前颜色（对已收藏颜色再次点击可取消收藏）
            $('#addToFavorites_' + uid).on('click', function() {
                var $btn = $(this);
                var currentHex = self.hslToHex(self.currentColor.h, self.currentColor.s, self.currentColor.l);
                var idx = self.favoriteColors.indexOf(currentHex);

                if (idx === -1) {
                    self.favoriteColors.unshift(currentHex);
                    // 上限保护，避免列表无限增长（超出后丢弃最早收藏）
                    if (self.favoriteColors.length > 60) {
                        self.favoriteColors.pop();
                    }
                } else {
                    self.favoriteColors.splice(idx, 1);
                }

                // 持久化收藏颜色
                self._saveColorList(self.options.storagePrefix + ':favorites', self.favoriteColors);
                self.updateFavoriteColorsUI();
                self.updateFavoriteButtonUI();

                // 视觉反馈
                $btn.addClass('fav-pulse');
                setTimeout(function() {
                    $btn.removeClass('fav-pulse');
                }, 500);
            });
            
            // 确认选择颜色
            $('#confirmColor_' + uid).on('click', function() {
                var currentHex = self.hslToHex(self.currentColor.h, self.currentColor.s, self.currentColor.l);

                // 标记本会话为「确认」退出（hidden 事件据此区分 confirm / cancel）
                self._confirmFlag = true;

                // 加入最近使用（已存在则移到最前），并持久化
                var recentIdx = self.recentColors.indexOf(currentHex);
                if (recentIdx !== -1) {
                    self.recentColors.splice(recentIdx, 1);
                }
                self.recentColors.unshift(currentHex);
                if (self.recentColors.length > self.options.maxRecent) {
                    self.recentColors.pop();
                }
                self._saveColorList(self.options.storagePrefix + ':recent', self.recentColors);
                self.updateRecentColorsUI();
                
                // 触发颜色选择回调
                if (typeof self.options.onColorSelect === 'function') {
                    self.options.onColorSelect(self.getCurrentColor());
                }
                
                // 关闭模态框
                self.close();
            });
        },

        /*
         * 弹窗关闭后的会话分流：判断本次是「点击确认」还是「取消/关闭」，
         * 并按需还原颜色、触发 onCancel / onClose 回调。
         */
        _handleModalClosed: function() {
            if (!this._sessionOpen) return;
            this._sessionOpen = false;

            var confirmed = !!this._confirmFlag;
            this._confirmFlag = false;

            var lastColor = this.getCurrentColor();
            var originalColor = this._sessionOriginalColor || lastColor;
            var changed = !this._sameColor(lastColor, originalColor);

            if (!confirmed && this.options.resetOnCancel && changed) {
                // 取消：把组件内部颜色还原为打开时的颜色（会触发一次 onColorChange，
                // 使依赖实时预览的业务（如演示页背景）随之还原）
                this.updateColorFromHSLA(
                    originalColor.h, originalColor.s, originalColor.l, originalColor.a
                );
            }

            if (!confirmed && typeof this.options.onCancel === 'function') {
                this.options.onCancel(this.getCurrentColor(), originalColor, {
                    changed: changed,
                    lastColor: lastColor
                });
            }

            if (typeof this.options.onClose === 'function') {
                this.options.onClose({
                    action: confirmed ? 'confirm' : 'cancel',
                    confirmed: confirmed,
                    originalColor: originalColor,
                    color: this.getCurrentColor(),
                    lastColor: lastColor,
                    changed: changed
                });
            }
        },

        /*
         * 比较两个颜色对象（hex 与透明度）是否相同
         */
        _sameColor: function(a, b) {
            return !!a && !!b &&
                String(a.hex).toLowerCase() === String(b.hex).toLowerCase() &&
                Math.abs(Number(a.a) - Number(b.a)) < 1e-6;
        },

        /* 
         * 获取当前颜色
         * @returns {object} - 当前颜色的对象，包含十六进制、RGB、RGBA、HSL、HSLA、H、S、L、A等属性
         */
        getCurrentColor: function() {
            var hex = this.hslToHex(this.currentColor.h, this.currentColor.s, this.currentColor.l);
            var rgb = this.hslToRgb(this.currentColor.h, this.currentColor.s, this.currentColor.l);
            var rgba = 'rgba(' + rgb[0] + ', ' + rgb[1] + ', ' + rgb[2] + ', ' + this.currentColor.a + ')';
            
            return {
                hex: hex,
                rgb: 'rgb(' + rgb[0] + ', ' + rgb[1] + ', ' + rgb[2] + ')',
                rgba: rgba,
                hsl: 'hsl(' + this.currentColor.h + ', ' + this.currentColor.s + '%, ' + this.currentColor.l + '%)',
                hsla: 'hsla(' + this.currentColor.h + ', ' + this.currentColor.s + '%, ' + this.currentColor.l + '%, ' + this.currentColor.a + ')',
                h: this.currentColor.h,
                s: this.currentColor.s,
                l: this.currentColor.l,
                a: this.currentColor.a
            };
        },

        /* 
         * 打开颜色选择器
         */
        open: function() {
            // 重新读取持久化的最近使用/收藏：同一页面多实例或跨标签页都可能
            // 更新 localStorage，打开时同步可保证列表始终最新
            this.recentColors = this._loadColorList(this.options.storagePrefix + ':recent');
            this.favoriteColors = this._loadColorList(this.options.storagePrefix + ':favorites');
            this.updateRecentColorsUI();
            this.updateFavoriteColorsUI();
            this.updateFavoriteButtonUI();

            // 会话状态：记录本次打开时的颜色，作为「取消还原」与 onClose 的参照
            this._sessionOpen = true;
            this._confirmFlag = false;
            this._sessionOriginalColor = this.getCurrentColor();

            new bootstrap.Modal('#colorPickerModal_' + this.uid).show();
            // 更新选择状态
            this.updateSelectedState();
        },

        /* 
         * 关闭颜色选择器
         */
        close: function() {
            $('#colorPickerModal_' + this.uid).modal('hide');
        },
        /* 
         * 设置颜色
         * @param {string} color - 要设置的颜色，格式为十六进制字符串（例如：#FF0000）
         */
        setColor: function(color) {
            var hsl = this.hexToHsl(color);
            this.updateColorFromHSLA(hsl[0], hsl[1], hsl[2], this.currentColor.a);
        },
        // 销毁实例
        destroy: function() {
            var uid = this.uid;
            // 解绑所有通过 $(document) 注册的委托事件，防止内存泄漏
            $(document).off('click', '#standardPalette_' + uid + ' .ws-color-swatch');
            $(document).off('click', '#recentColors_' + uid + ' .ws-color-swatch');
            $(document).off('click', '#favoriteColors_' + uid + ' .ws-color-swatch');
            // 移除 DOM 元素
            $('#colorPickerModal_' + uid).remove();
            $('#ws-color-picker-styles').remove();
        }
    };
    
// 保留全局挂载：wise-ui.js 与插件业务代码通过 window.WsColorPicker 访问，存量调用零改造。
window.WsColorPicker = WsColorPicker;

export default WsColorPicker;
