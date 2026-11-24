// 样式：ws-date-picker.css
// 本模块刻意不 import CSS —— 浏览器原生 ESM 不支持导入 CSS，一旦 import，
// demo.html 等"直连源码"的场景会因 MIME 类型不匹配而整体加载失败。
// 生产：由 statics/modules/ws/ws-components.css 汇总，构建进 wise-bundle.css
// 调试：<link rel="stylesheet" href="/statics/libs/ws-date-picker/ws-date-picker.css">

// jQuery 走全局单例：本组件是 jQuery 插件，若 import 出第二份 jQuery 实例，
// 插件会注册在 A 实例上而业务用 B 实例调用，功能静默失效。
const $ = window.jQuery;
if (typeof $ === 'undefined') {
    throw new Error('WsDatePicker requires jQuery');
}

    // 默认配置
    const defaults = {
        type: 'date',           // 日期类型：date, datetime, time, year, month
        // 日期格式
        format: 'yyyy-MM-dd',   // 日期格式
        value: null,            // 初始值
        min: null,              // 最小日期，格式为字符串或 Date 对象
        max: null,              // 最大日期，格式为字符串或 Date 对象
        range: false,           // 是否启用日期范围选择
        rangeSeparator: ' - ',  // 日期范围分隔符
        mark: {},               // 标记日期函数，格式为 { 'yyyy-MM-dd': '标记内容' }
        disabledDate: null,     // 禁用日期函数，格式为函数，返回 true 禁用该日期
        disabledTime: null,     // 禁用时间，格式为函数，返回 true 禁用该时间
        timeFormat: 'HH:mm',    // 时间格式
        timeSteps: [1, 1, 1],   // 时间步长，[时, 分, 秒]
        popup: true,            // 日期选择器位置：true（弹窗）, false（静态）
        // 语言
        lang: 'zh-CN',          // 语言：zh-CN, en
        weekStart: 0,           // 周开始：0（周日），1（周一）
        showToday: true,        // 是否显示“今天”按钮
        showClear: true,        // 是否显示“清空”按钮
        showConfirm: true,      // 是否显示“确定”按钮
        showQuickSelect: true,  // 是否显示快速选择按钮
        // 事件回调
        onChange: null,         // 日期改变回调函数
        onSelect: null,         // 选择日期回调函数
        onOpen: null,           // 打开日期选择器回调函数
        onClose: null           // 关闭日期选择器回调函数
    };
    
    // 语言包
    const languages = {
        'zh-CN': {
            days: ['日', '一', '二', '三', '四', '五', '六'],
            daysShort: ['日', '一', '二', '三', '四', '五', '六'],
            months: ['一月', '二月', '三月', '四月', '五月', '六月', '七月', '八月', '九月', '十月', '十一月', '十二月'],
            monthsShort: ['1月', '2月', '3月', '4月', '5月', '6月', '7月', '8月', '9月', '10月', '11月', '12月'],
            today: '今天',
            yesterday: '昨天',
            tomorrow: '明天',
            thisWeek: '本周',
            lastWeek: '上周',
            thisMonth: '本月',
            lastMonth: '上月',
            thisYear: '今年',
            lastYear: '去年',
            clear: '清空',
            confirm: '确定',
            selectDate: '选择日期',
            selectTime: '选择时间',
            startDate: '开始日期',
            endDate: '结束日期',
            hour: '时',
            minute: '分',
            second: '秒'
        },
        'en': {
            days: ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'],
            daysShort: ['S', 'M', 'T', 'W', 'T', 'F', 'S'],
            months: ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
            monthsShort: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
            today: 'Today',
            yesterday: 'Yesterday',
            tomorrow: 'Tomorrow',
            thisWeek: 'This Week',
            lastWeek: 'Last Week',
            thisMonth: 'This Month',
            lastMonth: 'Last Month',
            thisYear: 'This Year',
            lastYear: 'Last Year',
            clear: 'Clear',
            confirm: 'OK',
            selectDate: 'Select Date',
            selectTime: 'Select Time',
            startDate: 'Start Date',
            endDate: 'End Date',
            hour: 'Hour',
            minute: 'Minute',
            second: 'Second'
        }
    };
    
    // 工具函数
    const utils = {
        /**
         * 格式化日期为指定格式字符串
         * @param {Date} date - 日期对象
         * @param {string} format - 日期格式，默认 'yyyy-MM-dd'
         * @returns {string} 格式化后的日期字符串
         */
        formatDate: function(date, format) {
            if (!date || !(date instanceof Date)) return '';
            
            const pad = function(num) {
                return num < 10 ? '0' + num : num;
            };
            
            const year = date.getFullYear();
            const month = date.getMonth() + 1;
            const day = date.getDate();
            const hours = date.getHours();
            const minutes = date.getMinutes();
            const seconds = date.getSeconds();
            
            return format
                .replace('yyyy', year)
                .replace('yy', String(year).slice(-2))
                .replace('MM', pad(month))
                .replace('M', month)
                .replace('dd', pad(day))
                .replace('d', day)
                .replace('HH', pad(hours))
                .replace('H', hours)
                .replace('mm', pad(minutes))
                .replace('m', minutes)
                .replace('ss', pad(seconds))
                .replace('s', seconds);
        },
        
        /**
         * 解析日期字符串为 Date 对象
         * 支持格式：yyyy-mm-dd, yyyy-mm-dd hh:mm, yyyy-mm-dd hh:mm:ss
         * @param {string|Date} value - 日期字符串或 Date 对象
         * @param {string} format - 日期格式，默认 'yyyy-MM-dd'
         * @returns {Date|null} 解析后的 Date 对象，失败返回 null
         */
        parseDate: function(value, format) {
            if (!value) return null;
            
            // 尝试解析常见格式
            if (typeof value === 'string') {
                // 尝试解析 yyyy-mm-dd 格式
                const match = value.match(/^(\d{4})-(\d{1,2})-(\d{1,2})(?:\s+(\d{1,2}):(\d{1,2})(?::(\d{1,2}))?)?$/);
                if (match) {
                    const year = parseInt(match[1]);
                    const month = parseInt(match[2]) - 1;
                    const day = parseInt(match[3]);
                    const hours = match[4] ? parseInt(match[4]) : 0;
                    const minutes = match[5] ? parseInt(match[5]) : 0;
                    const seconds = match[6] ? parseInt(match[6]) : 0;
                    
                    return new Date(year, month, day, hours, minutes, seconds);
                }
                
                // 尝试解析其他格式
                const date = new Date(value);
                if (!isNaN(date.getTime())) {
                    return date;
                }
            } else if (value instanceof Date) {
                return value;
            }
            
            return null;
        },
        
        /**
         * 检查两个日期是否为同一天
         * @param {Date} date1 - 日期对象1
         * @param {Date} date2 - 日期对象2
         * @returns {boolean} 如果是同一天则返回 true，否则返回 false
         */
        isSameDay: function(date1, date2) {
            if (!date1 || !date2) return false;
            return date1.getFullYear() === date2.getFullYear() &&
                   date1.getMonth() === date2.getMonth() &&
                   date1.getDate() === date2.getDate();
        },
        
        /**
         * 获取指定月份的天数
         * @param {number} year - 年份
         * @param {number} month - 月份（0-11）
         * @returns {number} 该月的天数
         */
        getDaysInMonth: function(year, month) {
            return new Date(year, month + 1, 0).getDate();
        },

        /**
         * 向日期添加指定天数
         * @param {Date} date - 日期对象
         * @param {number} days - 要添加的天数
         * @returns {Date} 新的日期对象
         */
        addDays: function(date, days) {
            const result = new Date(date);
            result.setDate(result.getDate() + days);
            return result;
        },
        
        /**
         * 向日期添加指定月份
         * @param {Date} date - 日期对象
         * @param {number} months - 要添加的月份数
         * @returns {Date} 新的日期对象
         */
        addMonths: function(date, months) {
            const result = new Date(date);
            result.setMonth(result.getMonth() + months);
            return result;
        },
        
        addYears: function(date, years) {
            const result = new Date(date);
            result.setFullYear(result.getFullYear() + years);
            return result;
        },
        
        getWeekStart: function(date, weekStart) {
            const day = date.getDay();
            const diff = (day < weekStart ? 7 : 0) + day - weekStart;
            return utils.addDays(date, -diff);
        },
        
        getWeekEnd: function(date, weekStart) {
            const start = utils.getWeekStart(date, weekStart);
            return utils.addDays(start, 6);
        },
        
        // 将日期格式化为 yyyy-MM-dd 字符串，用于 mark 键
        toYMD: function(date) {
            const year = date.getFullYear();
            const month = (date.getMonth() + 1).toString().padStart(2, '0');
            const day = date.getDate().toString().padStart(2, '0');
            return `${year}-${month}-${day}`;
        },

        // 检查日期是否在 min/max 范围内
        isDateInRange: function(date, min, max) {
            if (!date) return false;
            const time = date.getTime();
            if (min && min.getTime && time < min.getTime()) return false;
            if (max && max.getTime && time > max.getTime()) return false;
            return true;
        },
        
        // 解析 min/max 配置为 Date 对象
        parseRangeLimit: function(limit) {
            if (!limit) return null;
            if (limit instanceof Date) return new Date(limit);
            if (typeof limit === 'string') {
                const d = new Date(limit);
                return isNaN(d.getTime()) ? null : d;
            }
            return null;
        },

        /**
         * 严格解析用户手动输入的日期字符串。
         * 与 parseDate 不同，这里会拒绝 2026-13-45 / 2026-02-30 这类
         * 被 new Date() 自动进位"修正"的非法日期。
         * @param {string} value 手动输入值
         * @returns {Date|null} 合法返回 Date，否则返回 null
         */
        parseStrict: function(value) {
            if (!value) return null;
            const str = String(value).trim();

            // 形如 yyyy-M-d[ HH:mm[:ss]]（分隔符支持 - / .）
            const m = str.match(/^(\d{4})[-/.](\d{1,2})[-/.](\d{1,2})(?:[ T](\d{1,2}):(\d{1,2})(?::(\d{1,2}))?)?$/);
            if (m) {
                const year = parseInt(m[1], 10);
                const month = parseInt(m[2], 10);
                const day = parseInt(m[3], 10);
                if (month < 1 || month > 12) return null;
                // new Date(year, month, 0) 得到 month 月最后一天
                const maxDay = new Date(year, month, 0).getDate();
                if (day < 1 || day > maxDay) return null;

                const hours = m[4] !== undefined ? parseInt(m[4], 10) : 0;
                const minutes = m[5] !== undefined ? parseInt(m[5], 10) : 0;
                const seconds = m[6] !== undefined ? parseInt(m[6], 10) : 0;
                if (hours > 23 || minutes > 59 || seconds > 59) return null;

                return new Date(year, month - 1, day, hours, minutes, seconds);
            }

            // 兜底：交给 Date 解析（覆盖 MM/dd/yyyy 等本地化输入）
            const d = new Date(str);
            return isNaN(d.getTime()) ? null : d;
        }
    };
    
    // 全局变量跟踪当前打开的选择器
    let currentOpenPicker = null;
    
    // 主组件类
    class WsDatePicker {
        constructor(element, options) {
            this.$element = $(element);
            this.options = $.extend({}, defaults, options);
            this.options.value = this.options.value || this.$element.val() || '';
            this.lang = languages[this.options.lang] || languages['zh-CN'];
            // 视图名与 options.type 含义不同：'date'/'datetime' 都从「日期」视图开始
            // （datetime 的时间部分内嵌在日期视图底部），仅 'time' 直接进入时间视图。
            // 之前误把 options.type（如 'datetime'）当作视图名，导致渲染时
            // currentView === 'date' 的判定不成立，datetime 的时间控件/快捷栏都不渲染。
            this.currentView = this.options.type === 'time' ? 'time' : 'date';
            this.currentDate = new Date();
            this.minDate = utils.parseRangeLimit(this.options.min);
            this.maxDate = utils.parseRangeLimit(this.options.max);
            this.selectedDate = null;
            this.rangeStart = null;
            this.rangeEnd = null;
            // 范围模式双面板的“浏览月份”（当月1号 00:00）。与已选边界解耦：
            // 导航按钮只翻浏览月份，已选值 rangeStart/rangeEnd 仅在点击日期格时被写入。
            this._rViewStart = null;
            this._rViewEnd = null;
            this.isSelectingRange = false;
            this.selectedTime = { hours: 0, minutes: 0, seconds: 0 };
            this.isOpen = false;
            this.init();
        }
        
        // 初始化组件
        init() {
            // 处理初始值
            if (this.options.value) {
                const date = utils.parseDate(this.options.value, this.options.format);
                if (date) {
                    this.selectedDate = date;
                    this.currentDate = new Date(date);
                    this.selectedTime = {
                        hours: date.getHours(),
                        minutes: date.getMinutes(),
                        seconds: date.getSeconds()
                    };
                    // 初始化回写已存在的值，不派发 change —— 否则一进页面就触发校验
                    this.updateInput(true);
                }
            }
            
            // 处理范围初始值
            if (this.options.range && this.options.value) {
                const parts = String(this.options.value).split(this.options.rangeSeparator);
                if (parts.length === 2) {
                    this.rangeStart = utils.parseDate(parts[0].trim(), this.options.format);
                    this.rangeEnd = utils.parseDate(parts[1].trim(), this.options.format);
                    if (this.rangeStart) {
                        this.currentDate = new Date(this.rangeStart);
                    }
                }
            }
            
            // 没有初始值时**不**预选「今天」。
            // 预选会让空值的必填字段在「打开面板 → 直接点确定」后被写成今天的日期，
            // 必填校验因此永远通过，表现为「日期必填形同虚设」。
            // 面板默认展示的月份由 currentDate = new Date() 决定，不依赖 selectedDate。
            
            // 创建容器
            this.$container = $('<div class="ws-date-container"></div>');
            
            if (this.options.popup === false) {
                this.$container.addClass('ws-date-static');
                this.$element.after(this.$container);
                this.render();
            } else {
                this.$element.wrap(this.$container);
                this.$container = this.$element.parent();
                this.bindEvents();
            }
        }
        
        /**
        * 设置选项
        * @param {string} name - 选项名
        * @param {*} value - 选项值
        */
        setOption(name, value) {
            this.options[name] = value;
            
            // 如果是范围选择器，需要更新日期格式
            if (this.options.range) {
                this.options.format = this.options.rangeFormat || this.options.format;
            }
            
            // 重新初始化
            this.init();
        }
        
        /**
        * 绑定事件
        */
        bindEvents() {
            const self = this;
            
            // 点击输入框显示选择器
            this.$element.on('click focus', function(e) {
                e.stopPropagation();
                if (!self.isOpen) {
                    self.show();
                }
            });
            
            // 点击外部关闭选择器
            $(document).on('click.wsdate touchstart.wsdate', function(e) {
                if (self.isOpen && !$(e.target).closest('.ws-date-container').length) {
                    self.hide();
                }
            });
            
            // 阻止选择器内部点击事件冒泡
            this.$container.on('click touchstart', function(e) {
                e.stopPropagation();
            });
            
            // ESC键关闭
            $(document).on('keydown.wsdate', function(e) {
                if (e.key === 'Escape' && self.isOpen) {
                    self.hide();
                }
            });

            // 支持键盘直接输入：失焦 / 回车时校验并规范化，
            // 非法日期（2026-13-45 / 2026-02-30）会被回滚并给出提示
            this.$element.on('blur', function() {
                self.validateManualInput();
            });
            this.$element.on('keydown', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    self.validateManualInput();
                    // 回车等价于确认：收起弹出面板
                    if (self.isOpen) {
                        self.hide();
                    }
                }
            });
        }

        /**
        * 渲染选择器
        */
        render() {
            if (this.$dropdown) {
                this.$dropdown.remove();
            }
            
            this.$dropdown = $('<div class="ws-date-dropdown"></div>');
            
            // 根据类型渲染
            if (this.options.range && this.currentView === 'date') {
                this.renderRangeView();
            } else {
                switch (this.currentView) {
                    case 'year':
                        this.renderYearView();
                        break;
                    case 'month':
                        this.renderMonthView();
                        break;
                    case 'time':
                        this.renderTimeView();
                        break;
                    default:
                        this.renderDateView();
                }
            }
            
            this.$container.append(this.$dropdown);
            
            // 渲染快速选择栏（仅日期视图）
            if (this.options.showQuickSelect && this.currentView === 'date') {
                this.renderQuickSelect();
            }
            // 说明：datetime 采用两段式向导——第一步在此渲染日期视图（不内嵌时间控件），
            // 选定日期后进入第二步时间视图（renderTimeView），时间控件已废弃为独立步骤。
        }
        
        /**
        * 渲染快速选择栏
        */
        renderQuickSelect() {
            const $quickSelect = $('<div class="ws-date-quick-select"></div>');
            const $buttons = $('<div class="ws-date-quick-buttons"></div>');
            
            if (this.options.range) {
                // 范围选择快速选项
                const rangeOptions = [
                    { text: this.lang.today, value: 'today' },
                    { text: this.lang.yesterday, value: 'yesterday' },
                    { text: this.lang.thisWeek, value: 'thisWeek' },
                    { text: this.lang.lastWeek, value: 'lastWeek' },
                    { text: this.lang.thisMonth, value: 'thisMonth' },
                    { text: this.lang.lastMonth, value: 'lastMonth' }
                ];
                
                rangeOptions.forEach(option => {
                    $buttons.append(
                        $('<button class="btn btn-sm btn-outline-secondary" type="button"></button>')
                            .text(option.text)
                            .on('click', () => this.handleQuickSelect(option.value))
                    );
                });
            } else {
                // 单日期快速选项
                const dateOptions = [
                    { text: this.lang.today, value: 'today' },
                    { text: this.lang.yesterday, value: 'yesterday' },
                    { text: this.lang.tomorrow, value: 'tomorrow' },
                    { text: this.lang.thisWeek, value: 'thisWeek' },
                    { text: this.lang.lastWeek, value: 'lastWeek' }
                ];
                
                dateOptions.forEach(option => {
                    $buttons.append(
                        $('<button class="btn btn-sm btn-outline-secondary" type="button"></button>')
                            .text(option.text)
                            .on('click', () => this.handleQuickSelect(option.value))
                    );
                });
            }
            
            $quickSelect.append($buttons);
            this.$dropdown.prepend($quickSelect);
        }
        
        /**
        * 处理快速选择
        * @param {string} type - 快速选择类型
        */
        handleQuickSelect(type) {
            const today = new Date();
            let startDate, endDate;
            
            switch (type) {
                case 'today':
                    startDate = endDate = today;
                    break;
                    
                case 'yesterday':
                    startDate = endDate = utils.addDays(today, -1);
                    break;
                    
                case 'tomorrow':
                    startDate = endDate = utils.addDays(today, 1);
                    break;
                    
                case 'thisWeek':
                    startDate = utils.getWeekStart(today, this.options.weekStart);
                    endDate = utils.getWeekEnd(today, this.options.weekStart);
                    break;
                    
                case 'lastWeek':
                    const lastWeek = utils.addDays(today, -7);
                    startDate = utils.getWeekStart(lastWeek, this.options.weekStart);
                    endDate = utils.getWeekEnd(lastWeek, this.options.weekStart);
                    break;
                    
                case 'thisMonth':
                    startDate = new Date(today.getFullYear(), today.getMonth(), 1);
                    endDate = new Date(today.getFullYear(), today.getMonth() + 1, 0);
                    break;
                    
                case 'lastMonth':
                    const lastMonth = utils.addMonths(today, -1);
                    startDate = new Date(lastMonth.getFullYear(), lastMonth.getMonth(), 1);
                    endDate = new Date(lastMonth.getFullYear(), lastMonth.getMonth() + 1, 0);
                    break;
            }
            
            if (this.options.range) {
                // 范围面板对起止边界按“午夜零点”做严格时间比较（rangeStart/rangeEnd 与
                // 日期格的 getTime() 相等才标 start/end）。快捷值源自 new Date()，会携带当前
                // 时分秒，导致首日(0点 < 带时刻的 start)标不上、末日被误判为区间内。
                // 因此范围快捷把起止归零到当日 00:00:00，保证首日/末日能正确高亮。
                if (startDate) startDate.setHours(0, 0, 0, 0);
                if (endDate) endDate.setHours(0, 0, 0, 0);
                this.rangeStart = startDate;
                this.rangeEnd = endDate;
                // 快捷范围项整体替换已选值，面板视图对齐到新范围所在月份
                this._rViewStart = startDate
                    ? new Date(startDate.getFullYear(), startDate.getMonth(), 1)
                    : null;
                this._rViewEnd = endDate
                    ? new Date(endDate.getFullYear(), endDate.getMonth(), 1)
                    : null;
            } else {
                this.selectedDate = startDate;
                this.selectedTime = {
                    hours: startDate.getHours(),
                    minutes: startDate.getMinutes(),
                    seconds: startDate.getSeconds()
                };
            }

            // 让日历自动跳转到所选择日期所在的月份，并高亮选中的日期。
            // 否则选择「上周 / 上个月」等跨月快捷项后，面板仍停留在当前月份，
            // 选中的日期在当月视图里根本看不到。
            this.currentDate = new Date(startDate);

            // 两段式向导（datetime）：快捷选定日期后同样进入「选择时间」步骤
            if (this.options.type === 'datetime' && !this.options.range) {
                this.currentView = 'time';
                this.render();
                return;
            }

            this.updateContent();
            // 静态模式忽略showConfirm，直接确认
            if (this.options.showConfirm && this.options.popup !== false) {
                return;
            }
            if (!this.options.range && type !== 'thisWeek' && type !== 'lastWeek' && type !== 'thisMonth' && type !== 'lastMonth') {
                this.confirm();
            } else if (this.options.range && !this.isSelectingRange) {
                this.confirm();
            }
        }
        
        /**
        * 更新选中的日期时间
        */
        updateSelectedDateTime() {
            if (this.selectedDate) {
                this.selectedDate.setHours(this.selectedTime.hours);
                this.selectedDate.setMinutes(this.selectedTime.minutes);
                this.selectedDate.setSeconds(this.selectedTime.seconds);
            }
            
            // 触发onSelect回调
            if (this.options.onSelect && this.selectedDate) {
                const value = utils.formatDate(this.selectedDate, this.options.format);
                this.options.onSelect(this.selectedDate, value);
            }
        }
        
        /**
        * 渲染日期选择器视图
        */
        renderDateView() {
            const self = this;
            const today = new Date();
            const year = this.currentDate.getFullYear();
            const month = this.currentDate.getMonth();
            
            // 头部
            const $header = $('<div class="ws-date-header"></div>');
            
            // 左侧导航
            const $navStart = $('<div class="ws-date-nav-start"></div>');
            $navStart.append(
                $('<button class="btn btn-sm btn-outline-secondary" type="button" title="上一年"><i class="bi bi-chevron-double-left"></i></button>')
                    .on('click', (e) => { 
                        e.stopPropagation();
                        this.currentDate.setFullYear(this.currentDate.getFullYear() - 1);
                        this.updateContent();
                    }),
                $('<button class="btn btn-sm btn-outline-secondary" type="button" title="上一月"><i class="bi bi-chevron-left"></i></button>')
                    .on('click', (e) => { 
                        e.stopPropagation();
                        this.currentDate.setMonth(this.currentDate.getMonth() - 1);
                        this.updateContent();
                    })
            );
            
            // 标题
            const $title = $(`<h6 class="ws-date-title">${year}年 ${month + 1}月</h6>`)
                .on('click', () => {
                    this.currentView = 'month';
                    this.render();
                });
            
            // 右侧导航
            const $navEnd = $('<div class="ws-date-nav-end"></div>');
            $navEnd.append(
                $('<button class="btn btn-sm btn-outline-secondary" type="button" title="下一月"><i class="bi bi-chevron-right"></i></button>')
                    .on('click', (e) => { 
                        e.stopPropagation();
                        this.currentDate.setMonth(this.currentDate.getMonth() + 1);
                        this.updateContent();
                    }),
                $('<button class="btn btn-sm btn-outline-secondary" type="button" title="下一年"><i class="bi bi-chevron-double-right"></i></button>')
                    .on('click', (e) => { 
                        e.stopPropagation();
                        this.currentDate.setFullYear(this.currentDate.getFullYear() + 1);
                        this.updateContent();
                    })
            );
            
            $header.append($navStart, $title, $navEnd);
            
            // 日历主体
            const $body = $('<div class="ws-date-body"></div>');
            const $table = $('<table class="ws-date-calendar"></table>');
            const $thead = $('<thead></thead>');
            const $tbody = $('<tbody></tbody>');
            
            // 星期标题
            const $trHead = $('<tr></tr>');
            const days = this.lang.daysShort;
            for (let i = 0; i < 7; i++) {
                const dayIndex = (i + this.options.weekStart) % 7;
                $trHead.append($('<th></th>').text(days[dayIndex]));
            }
            $thead.append($trHead);
            
            // 计算日期
            const firstDay = new Date(year, month, 1);
            const lastDay = new Date(year, month + 1, 0);
            const startDay = (firstDay.getDay() - this.options.weekStart + 7) % 7;
            const daysInMonth = lastDay.getDate();
            const totalCells = startDay + daysInMonth;
            const rows = Math.ceil(totalCells / 7);
            
            let date = 1;
            
            for (let i = 0; i < rows; i++) {
                const $tr = $('<tr></tr>');
                
                for (let j = 0; j < 7; j++) {
                    const $td = $('<td></td>');
                    let dayClass = 'ws-date-day';
                    let dayText = '';
                    let dayDate = null;
                    let isDisabled = false;
                    let navigateMonth = 0; // 0=当月, -1=上月, +1=下月
                    
                    if (i === 0 && j < startDay) {
                        // 上个月的日期
                        dayClass += ' other-month';
                        navigateMonth = -1;
                        dayDate = new Date(year, month - 1, lastDay.getDate() - startDay + j + 1);
                        dayText = dayDate.getDate();
                        isDisabled = true; // 跨月默认禁用，仅可点击导航
                    } else if (date > daysInMonth) {
                        // 下个月的日期
                        dayText = date - daysInMonth;
                        dayClass += ' other-month';
                        navigateMonth = 1;
                        dayDate = new Date(year, month + 1, date - daysInMonth);
                        isDisabled = true; // 跨月默认禁用，仅可点击导航
                        date++;
                    } else {
                        // 当前月的日期
                        dayText = date;
                        dayDate = new Date(year, month, date);
                        
                        // 检查是否在 min/max 范围内
                        const inRange = utils.isDateInRange(dayDate, this.minDate, this.maxDate);
                        if (!inRange) {
                            isDisabled = true;
                        }
                        
                        // 检查 disabledDate 函数
                        if (!isDisabled && typeof this.options.disabledDate === 'function' && this.options.disabledDate(dayDate)) {
                            isDisabled = true;
                        }
                        
                        // 标记今天
                        if (today.getFullYear() === year && 
                            today.getMonth() === month && 
                            today.getDate() === date) {
                            dayClass += ' today';
                        }
                        
                        // 标记选中日期
                        if (this.selectedDate && utils.isSameDay(this.selectedDate, dayDate)) {
                            dayClass += ' selected';
                        }
                        
                        // 标记 mark 日期
                        const ymd = utils.toYMD(dayDate);
                        if (this.options.mark && this.options.mark[ymd]) {
                            dayClass += ' ws-date-marked';
                            $td.data('mark', this.options.mark[ymd]);
                        }
                        
                        date++;
                    }
                    
                    // 跨月日期添加可点击样式提示
                    if (navigateMonth !== 0) {
                        dayClass += ' navigate-month';
                    }
                    
                    // 禁用状态必须体现在样式上，否则用户点击后毫无反应且看不到原因。
                    // 跨月日期用于切换月份，不属于禁用，不添加 disabled 样式。
                    if (isDisabled && navigateMonth === 0) {
                        dayClass += ' disabled';
                    }

                    const $dayDiv = $(`<div class="${dayClass}">${dayText}</div>`);
                    if (dayDate) {
                        $dayDiv.data('date', dayDate.toISOString());
                    }
                    $td.append($dayDiv);
                    
                    // 绑定点击事件
                    if (navigateMonth !== 0 && dayDate) {
                        // 跨月日期：点击后选中该日期并跳转到所在月份。
                        // 此前仅做 setMonth 切月不选中，导致面板跳到目标月却没有
                        // 选中用户点的那一天（看起来像"跳转的日期不正确"）。
                        (function(d) {
                            $td.on('click', function() {
                                self.handleDateClick(d);
                            });
                        })(dayDate);
                    } else if (dayDate && !isDisabled) {
                        // 当月可选日期：点击选择
                        $td.on('click', function() {
                            self.handleDateClick(dayDate);
                        });
                    }
                    
                    $tr.append($td);
                }
                
                $tbody.append($tr);
            }
            
            $table.append($thead, $tbody);
            $body.append($table);
            
            // 底部
            const $footer = this.createFooter();
            
            this.$dropdown.append($header, $body, $footer);
        }

        /**
        * 渲染日期范围选择器视图
        */
        renderRangeView() {
            // 面板“浏览月份”与已选边界解耦：导航只翻视图，值仅在点击日期格时写入。
            // 视图未初始化时对齐已选边界月份；两边都为空则从今天开始（右面板默认显示下一月）。
            if (!this._rViewStart) {
                // 起点未选时左面板显示今天所在月（右面板再按已选 end / 起始+1月展示）
                this._rViewStart = this._viewMonthOf(this.rangeStart || new Date());
            }
            if (!this._rViewEnd) {
                this._rViewEnd = this.rangeEnd
                    ? this._viewMonthOf(this.rangeEnd)
                    : new Date(this._rViewStart.getFullYear(), this._rViewStart.getMonth() + 1, 1);
            }
            // 左/右面板浏览月份完全独立：允许任一方向翻越，甚至两面板月份倒序/同月。
            // 值的顺序只在点选日期后按 handleRangeDateClick 的规则修正，翻月绝不牵动另一侧。
            
            const startDate = new Date(this._rViewStart);
            const endDate = new Date(this._rViewEnd);
            
            const $rangeContainer = $('<div class="ws-date-range"></div>');
            
            // 渲染开始日期面板
            const $startPanel = this.createRangePanel(startDate, 'start');
            const $endPanel = this.createRangePanel(endDate, 'end');
            
            $rangeContainer.append($startPanel, $endPanel);
            const $footer = this.createFooter();
            
            this.$dropdown.append($rangeContainer, $footer);
        }
        
        /**
        * 取日期所在月的面板视图基准（当月 1 号 00:00）
        */
        _viewMonthOf(date) {
            return new Date(date.getFullYear(), date.getMonth(), 1);
        }
        
        /**
        * 创建日期范围选择器面板
        * @param {Date} date - 面板显示的日期
        * @param {string} type - 面板类型（start或end）
        * @returns {jQuery} - 包含面板HTML的jQuery对象
        */
        createRangePanel(date, type) {
            const self = this;
            const today = new Date();
            const year = date.getFullYear();
            const month = date.getMonth();
            
            const $panel = $('<div class="ws-date-calendar-panel"></div>');
            
            // 面板类型标签
            const $panelType = $('<div class="ws-date-panel-type"></div>')
                .text(type === 'start' ? self.lang.startDate : self.lang.endDate);
            $panel.append($panelType);
            
            // 头部：与单日期面板保持一致，支持 上一年/上一月 | 下一月/下一年
            const $header = $('<div class="ws-date-header"></div>');
            const $title = $(`<h6 class="ws-date-title">${year}年 ${month + 1}月</h6>`);

            // 范围面板年/月切换：只移动本面板的“浏览月份”视图，不改写已选边界值，
            // 也不牵动另一侧面板（两侧完全独立翻月）。
            // 视图基准恒为当月 1 号，new Date() 跨月/跨年构造天然安全，无 31 号进位问题。
            const shiftMonthYear = (dm, dy) => {
                const field = type === 'start' ? '_rViewStart' : '_rViewEnd';
                const cur = self[field] || new Date(year, month, 1);
                self[field] = new Date(cur.getFullYear() + (dy || 0), cur.getMonth() + (dm || 0), 1);
                self.updateContent();
            };

            const $navStart = $('<div class="ws-date-nav-start"></div>');
            $navStart.append(
                $('<button class="btn btn-sm btn-outline-secondary" type="button" title="上一年"><i class="bi bi-chevron-double-left"></i></button>')
                    .on('click', (e) => { e.stopPropagation(); shiftMonthYear(0, -1); }),
                $('<button class="btn btn-sm btn-outline-secondary" type="button" title="上一月"><i class="bi bi-chevron-left"></i></button>')
                    .on('click', (e) => { e.stopPropagation(); shiftMonthYear(-1, 0); })
            );

            const $navEnd = $('<div class="ws-date-nav-end"></div>');
            $navEnd.append(
                $('<button class="btn btn-sm btn-outline-secondary" type="button" title="下一月"><i class="bi bi-chevron-right"></i></button>')
                    .on('click', (e) => { e.stopPropagation(); shiftMonthYear(1, 0); }),
                $('<button class="btn btn-sm btn-outline-secondary" type="button" title="下一年"><i class="bi bi-chevron-double-right"></i></button>')
                    .on('click', (e) => { e.stopPropagation(); shiftMonthYear(0, 1); })
            );

            $header.append($navStart, $title, $navEnd);
            
            // 日历主体
            const $body = $('<div class="ws-date-body"></div>');
            const $table = $('<table class="ws-date-calendar"></table>');
            const $thead = $('<thead></thead>');
            const $tbody = $('<tbody></tbody>');
            
            // 星期标题
            const $trHead = $('<tr></tr>');
            const days = this.lang.daysShort;
            for (let i = 0; i < 7; i++) {
                const dayIndex = (i + this.options.weekStart) % 7;
                $trHead.append($('<th></th>').text(days[dayIndex]));
            }
            $thead.append($trHead);
            
            // 计算日期
            const firstDay = new Date(year, month, 1);
            const lastDay = new Date(year, month + 1, 0);
            const startDay = (firstDay.getDay() - this.options.weekStart + 7) % 7;
            const daysInMonth = lastDay.getDate();
            const totalCells = startDay + daysInMonth;
            const rows = Math.ceil(totalCells / 7);
            
            let dateNum = 1;
            
            for (let i = 0; i < rows; i++) {
                const $tr = $('<tr></tr>');
                
                for (let j = 0; j < 7; j++) {
                    const $td = $('<td></td>');
                    let dayClass = 'ws-date-day';
                    let dayText = '';
                    let dayDate = null;
                    let isDisabled = false;
                    let navigateMonth = 0;
                    
                    if (i === 0 && j < startDay) {
                        dayClass += ' other-month navigate-month';
                        navigateMonth = -1;
                        dayDate = new Date(year, month - 1, lastDay.getDate() - startDay + j + 1);
                        dayText = dayDate.getDate();
                        isDisabled = true;
                    } else if (dateNum > daysInMonth) {
                        dayText = dateNum - daysInMonth;
                        dayClass += ' other-month navigate-month';
                        navigateMonth = 1;
                        dayDate = new Date(year, month + 1, dateNum - daysInMonth);
                        isDisabled = true;
                        dateNum++;
                    } else {
                        dayText = dateNum;
                        dayDate = new Date(year, month, dateNum);
                        
                        // 检查范围
                        const inRange = utils.isDateInRange(dayDate, this.minDate, this.maxDate);
                        if (!inRange) {
                            isDisabled = true;
                        }
                        
                        // disabledDate 函数
                        if (!isDisabled && this.options.disabledDate && this.options.disabledDate(dayDate)) {
                            isDisabled = true;
                        }

                        // 标记今天
                        if (today.getFullYear() === year && 
                            today.getMonth() === month && 
                            today.getDate() === dateNum) {
                            dayClass += ' today';
                        }
                        
                        // 单个选中高亮
                        if (this.rangeStart && !this.rangeEnd && dayDate.getTime() === this.rangeStart.getTime()) {
                            dayClass += ' selected';
                        }
                        if (!this.rangeStart && this.rangeEnd && dayDate.getTime() === this.rangeEnd.getTime()) {
                            dayClass += ' selected';
                        }

                        // 标记范围
                        if (this.rangeStart && this.rangeEnd) {
                            const dayTime = dayDate.getTime();
                            const startTime = this.rangeStart.getTime();
                            const endTime = this.rangeEnd.getTime();
                            
                            if (dayTime === startTime) {
                                dayClass += ' ws-date-range-start';
                            } else if (dayTime === endTime) {
                                dayClass += ' ws-date-range-end';
                            } else if (dayTime > startTime && dayTime < endTime) {
                                dayClass += ' ws-date-in-range';
                            }
                        }

                        // 标记 mark 日期
                        const ymd = utils.toYMD(dayDate);
                        if (this.options.mark && this.options.mark[ymd]) {
                            dayClass += ' ws-date-marked';
                            $td.data('mark', this.options.mark[ymd]);
                        }

                        dateNum++;
                    }
                    
                    if (isDisabled && navigateMonth === 0) {
                        dayClass += ' disabled';
                    }
                    
                    const $dayDiv = $(`<div class="${dayClass}">${dayText}</div>`);
                    if (dayDate) {
                        $dayDiv.data('date', dayDate.toISOString());
                    }
                    $td.append($dayDiv);
                    
                    if (navigateMonth !== 0) {
                        // 跨月补位日期：视为“翻到相邻月份浏览”的快捷入口，只移动本面板
                        // 浏览月份，不改写已选边界、也不牵动另一侧面板。
                        $td.on('click', function() {
                            const field = type === 'start' ? '_rViewStart' : '_rViewEnd';
                            const cur = self[field] || new Date(year, month, 1);
                            self[field] = new Date(cur.getFullYear(), cur.getMonth() + navigateMonth, 1);
                            self.updateContent();
                        });
                    } else if (dayDate && !isDisabled) {
                        $td.on('click', function() {
                            self.handleRangeDateClick(dayDate, type);
                        });
                    }
                    $tr.append($td);
                }
                
                $tbody.append($tr);
            }
            
            $table.append($thead, $tbody);
            $body.append($table);
            $panel.append($header, $body);
            
            return $panel;
        }

        /**
        * 渲染年份选择器视图
        */
        renderYearView() {
            const year = this.currentDate.getFullYear();
            const $body = $('<div class="ws-date-body"></div>');
            const $grid = $('<div class="ws-date-year-month-view"></div>');
            
            const startYear = Math.floor(year / 10) * 10 - 1;
            
            for (let i = 0; i < 12; i++) {
                const currentYear = startYear + i;
                const $yearItem = $('<div class="ws-date-year-month-item"></div>')
                    .text(currentYear)
                    .on('click', () => {
                        this.currentDate.setFullYear(currentYear);
                        if (this.options.type === 'year') {
                            this.selectedDate = new Date(currentYear, 0, 1);
                            this.confirm();
                        } else {
                            this.currentView = 'month';
                            this.render();
                        }
                    });
                
                if (currentYear === year) {
                    $yearItem.addClass('selected');
                }
                
                $grid.append($yearItem);
            }
            
            $body.append($grid);
            
            // 头部
            const $header = this.createHeader('year', year);
            const $footer = this.createFooter();
            
            this.$dropdown.append($header, $body, $footer);
        }
        
        /**
        * 渲染月份选择器视图
        */
        renderMonthView() {
            const year = this.currentDate.getFullYear();
            const $body = $('<div class="ws-date-body"></div>');
            const $grid = $('<div class="ws-date-year-month-view"></div>');
            
            for (let i = 0; i < 12; i++) {
                const $monthItem = $('<div class="ws-date-year-month-item"></div>')
                    .text(this.lang.monthsShort[i])
                    .on('click', () => {
                        this.currentDate.setMonth(i);
                        if (this.options.type === 'month') {
                            this.selectedDate = new Date(year, i, 1);
                            this.confirm();
                        } else {
                            this.currentView = 'date';
                            this.render();
                        }
                    });
                
                if (i === this.currentDate.getMonth()) {
                    $monthItem.addClass('selected');
                }
                
                $grid.append($monthItem);
            }
            
            $body.append($grid);
            
            // 头部
            const $header = this.createHeader('month', year);
            const $footer = this.createFooter();
            
            this.$dropdown.append($header, $body, $footer);
        }
        
        /**
        * 渲染时间选择器视图
        */
        renderTimeView() {
            const self = this;
            const $body = $('<div class="ws-date-body"></div>');
            
            // 创建类似laydate的时间选择界面
            const $timeDisplay = $('<div class="ws-date-time-display"></div>');
            const $timeGridContainer = $('<div class="ws-date-time-grid-container"></div>');
            
            // 使用当前选择的时间或当前时间
            const currentDate = this.selectedDate || new Date();
            const hours = this.selectedTime.hours !== undefined ? this.selectedTime.hours : currentDate.getHours();
            const minutes = this.selectedTime.minutes !== undefined ? this.selectedTime.minutes : currentDate.getMinutes();
            
            // 更新时间显示（使用 timeFormat）
            const updateDisplay = function() {
                const tempDate = new Date();
                tempDate.setHours(self.selectedTime.hours, self.selectedTime.minutes, 0);
                const displayStr = utils.formatDate(tempDate, self.options.timeFormat);
                $timeDisplay.text(displayStr);
            };
            
            updateDisplay();
            
            // 小时网格（根据 timeSteps[0] 步长）
            const hourStep = this.options.timeSteps[0] || 1;
            const $hourGrid = $('<div class="ws-date-time-grid mb-2"></div>');
            for (let i = 0; i < 24; i += hourStep) {
                // 检查小时是否被禁用
                let disabled = false;
                if (this.options.disabledTime) {
                    disabled = this.options.disabledTime({ hour: i, minute: minutes, second: 0 });
                }
                
                const $cell = $(`<div class="ws-date-time-cell ${disabled ? 'disabled' : ''}" data-hour="${i}">${i.toString().padStart(2, '0')}</div>`)
                    .on('click', function() {
                        if (disabled) return;
                        self.selectedTime.hours = parseInt($(this).data('hour'));
                        $hourGrid.find('.ws-date-time-cell').removeClass('selected');
                        $(this).addClass('selected');
                        updateDisplay();
                        self.updateSelectedDateTime();
                    });
                
                if (i === hours) {
                    $cell.addClass('selected');
                }
                
                $hourGrid.append($cell);
            }
            
            // 分钟网格（根据 timeSteps[1] 步长）
            const minuteStep = this.options.timeSteps[1] || 1;
            const $minuteGrid = $('<div class="ws-date-time-grid"></div>');
            for (let i = 0; i < 60; i += minuteStep) {
                // 检查分钟是否被禁用
                let disabled = false;
                if (this.options.disabledTime) {
                    disabled = this.options.disabledTime({ hour: hours, minute: i, second: 0 });
                }
                
                const $cell = $(`<div class="ws-date-time-cell ${disabled ? 'disabled' : ''}" data-minute="${i}">${i.toString().padStart(2, '0')}</div>`)
                    .on('click', function() {
                        if (disabled) return;
                        self.selectedTime.minutes = parseInt($(this).data('minute'));
                        $minuteGrid.find('.ws-date-time-cell').removeClass('selected');
                        $(this).addClass('selected');
                        updateDisplay();
                        self.updateSelectedDateTime();
                    });
                
                // 选中最近的有效分钟
                const closestMinute = Math.floor(minutes / minuteStep) * minuteStep;
                if (i === closestMinute) {
                    $cell.addClass('selected');
                }
                
                $minuteGrid.append($cell);
            }
            
            // 小时/分钟两列并排，各自内部滚动，压缩弹层高度避免超出视口
            const $hourCol = $('<div class="ws-date-time-col"></div>');
            const $minuteCol = $('<div class="ws-date-time-col"></div>');
            $hourCol.append('<div class="text-center mb-1"><small class="text-muted">' + this.lang.hour + '</small></div>', $hourGrid);
            $minuteCol.append('<div class="text-center mb-1"><small class="text-muted">' + this.lang.minute + '</small></div>', $minuteGrid);
            $timeGridContainer.addClass('ws-date-time-grid-container').append($hourCol, $minuteCol);
            
            $body.append($timeDisplay, $timeGridContainer);
            
            // 头部
            const $header = this.createHeader('time');

            // 两段式向导（datetime）：标题展示已选日期，并提供「返回日历」重选日期
            if (this.options.type === 'datetime') {
                const $back = $('<button class="btn btn-sm btn-outline-secondary" type="button" title="返回选择日期"><i class="bi bi-arrow-left"></i></button>');
                $back.on('click', (e) => {
                    e.stopPropagation();
                    this.currentView = 'date';
                    this.render();
                });
                $header.find('.ws-date-nav-start').append($back);

                if (this.selectedDate) {
                    const dateStr = utils.formatDate(this.selectedDate, 'yyyy-MM-dd');
                    $header.find('.ws-date-title').text(`${dateStr} · ${this.lang.selectTime}`);
                }
            }

            const $footer = this.createFooter();
            
            this.$dropdown.append($header, $body, $footer);
        }

        /**
        * 创建日期选择器头部
        * @param {string} type - 头部类型（date、month、year、time）
        * @param {number} year - 显示的年份（仅在month和year类型时使用）
        * @returns {jQuery} - 包含头部HTML的jQuery对象
        */
        createHeader(type, year) {
            const currentYear = this.currentDate.getFullYear();
            const currentMonth = this.currentDate.getMonth();
            
            const $header = $('<div class="ws-date-header"></div>');
            let title = '';
            
            switch (type) {
                case 'year':
                    title = `${Math.floor(currentYear / 10) * 10 - 1} - ${Math.floor(currentYear / 10) * 10 + 10}`;
                    break;
                case 'month':
                    title = currentYear.toString();
                    break;
                case 'time':
                    title = this.lang.selectTime;
                    break;
                default:
                    title = `${currentYear}年 ${currentMonth + 1}月`;
            }
            
            const $title = $(`<h6 class="ws-date-title">${title}</h6>`);
            const $navStart = $('<div class="ws-date-nav-start"></div>');
            const $navEnd = $('<div class="ws-date-nav-end"></div>');
            
            if (type === 'date') {
                $navStart.append(
                    $('<button class="btn btn-sm btn-outline-secondary" type="button" title="上一年"><i class="bi bi-chevron-double-left"></i></button>')
                        .on('click', (e) => { 
                            e.stopPropagation();
                            this.currentDate.setFullYear(this.currentDate.getFullYear() - 1);
                            this.updateContent();
                        }),
                    $('<button class="btn btn-sm btn-outline-secondary" type="button" title="上一月"><i class="bi bi-chevron-left"></i></button>')
                        .on('click', (e) => { 
                            e.stopPropagation();
                            this.currentDate.setMonth(this.currentDate.getMonth() - 1);
                            this.updateContent();
                        })
                );
                
                $navEnd.append(
                    $('<button class="btn btn-sm btn-outline-secondary" type="button" title="下一月"><i class="bi bi-chevron-right"></i></button>')
                        .on('click', (e) => { 
                            e.stopPropagation();
                            this.currentDate.setMonth(this.currentDate.getMonth() + 1);
                            this.updateContent();
                        }),
                    $('<button class="btn btn-sm btn-outline-secondary" type="button" title="下一年"><i class="bi bi-chevron-double-right"></i></button>')
                        .on('click', (e) => { 
                            e.stopPropagation();
                            this.currentDate.setFullYear(this.currentDate.getFullYear() + 1);
                            this.updateContent();
                        })
                );
            } else if (type === 'month') {
                $navStart.append(
                    $('<button class="btn btn-sm btn-outline-secondary" type="button"><i class="bi bi-chevron-left"></i></button>')
                        .on('click', (e) => { 
                            e.stopPropagation();
                            this.currentDate.setFullYear(this.currentDate.getFullYear() - 1);
                            this.updateContent();
                        })
                );
                
                $navEnd.append(
                    $('<button class="btn btn-sm btn-outline-secondary" type="button"><i class="bi bi-chevron-right"></i></button>')
                        .on('click', (e) => { 
                            e.stopPropagation();
                            this.currentDate.setFullYear(this.currentDate.getFullYear() + 1);
                            this.updateContent();
                        })
                );
            } else if (type === 'year') {
                $navStart.append(
                    $('<button class="btn btn-sm btn-outline-secondary" type="button"><i class="bi bi-chevron-left"></i></button>')
                        .on('click', (e) => { 
                            e.stopPropagation();
                            this.currentDate.setFullYear(this.currentDate.getFullYear() - 10);
                            this.updateContent();
                        })
                );
                
                $navEnd.append(
                    $('<button class="btn btn-sm btn-outline-secondary" type="button"><i class="bi bi-chevron-right"></i></button>')
                        .on('click', (e) => { 
                            e.stopPropagation();
                            this.currentDate.setFullYear(this.currentDate.getFullYear() + 10);
                            this.updateContent();
                        })
                );
            }
            
            $header.append($navStart, $title, $navEnd);
            return $header;
        }
        
        /**
        * 创建日期选择器底部
        * @returns {jQuery} - 包含底部HTML的jQuery对象
        */
        createFooter() {
            const $footer = $('<div class="ws-date-footer"></div>');
            const $buttons = $('<div class="ws-date-buttons"></div>');
            
            if (this.options.showToday && !this.options.range && this.currentView === 'date') {
                $buttons.append(
                    $('<button class="btn btn-sm btn-outline-secondary" type="button"></button>')
                        .text(this.lang.today)
                        .on('click', () => { 
                            const today = new Date();
                            this.handleDateClick(today);
                        })
                );
            }
            
            if (this.options.showClear) {
                $buttons.append(
                    $('<button class="btn btn-sm btn-outline-secondary" type="button"></button>')
                        .text(this.lang.clear)
                        .on('click', () => { this.clear(); })
                );
            }
            
            // 显示确定按钮条件：
            // 1) 弹窗模式 + showConfirm=true
            // 2) 静态模式(popup=false) + datetime/time类型（需要确认时间选择）
            let needConfirm = (this.options.showConfirm && this.options.popup) ||
                (!this.options.popup && (this.options.type === 'datetime' || this.options.type === 'time'));
            // 两段式向导（datetime）：第一步「选择日期」不提供确定，
            // 确认操作收敛到第二步「选择时间」完成
            if (this.options.type === 'datetime' && this.currentView === 'date') {
                needConfirm = false;
            }
            if (needConfirm) {
                $buttons.append(
                    $('<button class="btn btn-sm btn-primary" type="button"></button>')
                        .text(this.lang.confirm)
                        .on('click', () => { this.confirm(); })
                );
            }
            
            $footer.append($buttons);
            return $footer;
        }
        
        /**
        * 处理日期点击事件
        * @param {Date} date - 点击的日期对象
        */
        handleDateClick(date) {
            // 更新时间部分
            date.setHours(this.selectedTime.hours);
            date.setMinutes(this.selectedTime.minutes);
            date.setSeconds(this.selectedTime.seconds);
            
            this.selectedDate = date;
            // 自动切换到选中日期所在月份
            this.currentDate = new Date(date);
            
            // 触发onSelect回调
            if (this.options.onSelect) {
                const value = utils.formatDate(date, this.options.format);
                this.options.onSelect(date, value);
            }

            // 两段式向导（datetime）：完成「选择日期」后自动进入「选择时间」步骤
            if (this.options.type === 'datetime') {
                this.currentView = 'time';
                this.render();
                return;
            }

            // 更新视图高亮
            this.updateContent();
            
            // 静态模式(popup=false)忽略showConfirm，直接确认
            // 弹窗模式才尊重showConfirm配置
            if (this.options.showConfirm && this.options.popup !== false) {
                return;
            }
            // time 类型不自动确认（需等时间选择完成）
            if (this.options.type === 'time') {
                return;
            }
            this.confirm();
        }
        
        /**
        * 处理日期范围点击事件
        * @param {Date} date - 点击的日期对象
        * @param {string} type - 范围类型（start或end）
        */
        handleRangeDateClick(date, type) {
            if (type === 'start') {
                this.rangeStart = date;
                this.isSelectingRange = true;
                
                // 如果结束日期早于新的开始日期，清除结束日期
                if (this.rangeEnd && this.rangeStart > this.rangeEnd) {
                    this.rangeEnd = null;
                }
            } else {
                this.rangeEnd = date;
                this.isSelectingRange = false;
                
                // 确保开始日期在结束日期之前
                if (this.rangeStart && this.rangeEnd && this.rangeStart > this.rangeEnd) {
                    const temp = this.rangeStart;
                    this.rangeStart = this.rangeEnd;
                    this.rangeEnd = temp;
                }
            }
            
            // 面板视图跟随已选边界月份，确保刚点的日期立即可见并高亮
            this._rViewStart = this._viewMonthOf(this.rangeStart || this.rangeEnd || this._rViewStart || new Date());
            this._rViewEnd = this.rangeEnd
                ? this._viewMonthOf(this.rangeEnd)
                : new Date(this._rViewStart.getFullYear(), this._rViewStart.getMonth() + 1, 1);
            
            this.updateContent();
            
            // 静态模式忽略showConfirm，直接确认
            if (this.options.showConfirm && this.options.popup !== false) {
                return;
            }
            // 没有确定按钮时，选完起止日期自动确认
            if (this.rangeStart && this.rangeEnd && !this.isSelectingRange) {
                this.confirm();
            }
        }
        
        /**
        * 更新日期选择器内容
        */
        updateContent() {
            // 保存显示状态
            const wasVisible = this.isOpen;
            
            // 重新渲染
            this.render();
            
            // 恢复显示状态
            if (wasVisible) {
                this.$dropdown.show();
            }
        }
        
        /**
        * 派发**原生** change 事件
        *
        * 不能用 `this.$element.trigger('change')`：jQuery 的 trigger 只走 jQuery 自己的
        * 事件系统，`HTMLInputElement` 上没有 `change()` 方法，jQuery 也就不会补一次
        * `dispatchEvent`，于是元素的 `addEventListener('change', ...)` 完全收不到。
        * 而 ws-form-validator 正是用原生 addEventListener 绑的实时校验 ——
        * 结果就是「点确定后必填不校验、红框不出现，直到点提交才报错」。
        *
        * 原生 dispatchEvent 会同时命中 jQuery 的 `.on('change')`（jQuery 内部也是原生监听），
        * 因此存量 jQuery 写法无需改动。
        */
        _emitChange() {
            const el = this.$element[0];
            if (el && typeof el.dispatchEvent === 'function') {
                el.dispatchEvent(new Event('change', { bubbles: true }));
            } else {
                this.$element.trigger('change');
            }
        }

        /**
        * 更新日期选择器输入框值
        * @param {boolean} [silent] 静默回写：不派发 change（初始化时用，避免进页面即触发校验）
        */
        updateInput(silent) {
            let value = '';
            
            if (this.options.range && this.rangeStart && this.rangeEnd) {
                const startStr = utils.formatDate(this.rangeStart, this.options.format);
                const endStr = utils.formatDate(this.rangeEnd, this.options.format);
                value = startStr + this.options.rangeSeparator + endStr;
            } else if (this.selectedDate) {
                value = utils.formatDate(this.selectedDate, this.options.format);
            }
            
            this.options.value = value;
            this.$element.val(value);

            // 值变更必须通知表单。
            // 弹层点选走的是回调（onChange），**不会**产生浏览器原生 change，
            // 导致 ws-form-validator 的实时校验（绑原生 input/change）在点选后完全不触发，
            // 表现为「必填提示要等到点提交才出现」。
            if (!silent) {
                this._emitChange();
            }
        }
        
        /**
        * 清空日期选择器值
        */
        clear(silent) {
            this.selectedDate = null;
            this.rangeStart = null;
            this.rangeEnd = null;
            this._rViewStart = null;
            this._rViewEnd = null;
            this.selectedTime = { hours: 0, minutes: 0, seconds: 0 };
            this.options.value = '';
            this.$element.val('');
            this.hide();

            // 静默清空：reset 用（回写默认值不该被当成用户操作，否则触发"变更即查"）
            if (silent === true) return;

            // 同 updateInput()：清空也要通知表单，否则「必填」红框不会即时出现
            this._emitChange();
            
            if (this.options.onChange) {
                this.options.onChange(null, '');
            }
            
            if (this.options.onSelect) {
                this.options.onSelect(null, '');
            }
        }

        /**
        * 复位到渲染时的默认值（宿主 input 的 `value` 属性）。
        *
        * 为什么不能只改 `el.value`：内部 `selectedDate` / `selectedTime` 不跟着回退，
        * 再次展开面板仍会高亮旧日期；而 `setValue('')` 是空操作（内部对空值直接 return），
        * 清空必须走 `clear()`。
        *
        * @param {boolean} [silent=true] 静默回写：不派发 change
        */
        reset(silent) {
            const el = this.$element[0];
            const def = el && el.defaultValue != null ? el.defaultValue : '';
            const quiet = silent !== false;

            if (def === '') {
                this.clear(quiet);
                return this;
            }

            this.setValue(def, quiet);
            return this;
        }

        /**
        * 确认日期选择器值
        */
        confirm() {
            this.updateInput();
            
            if (this.options.onChange) {
                let value = this.$element.val();
                if (this.options.range) {
                    this.options.onChange([this.rangeStart, this.rangeEnd], value);
                } else {
                    this.options.onChange(this.selectedDate, value);
                }
            }
            
            this.hide();
        }

        /**
         * 校验并应用手动输入（失焦 / 回车时触发）。
         * 合法输入会被规范化回写并触发 onChange；
         * 非法输入（2026-13-45 / 2026-02-30 等）会回滚到最近一次合法值并给出视觉提示。
         */
        validateManualInput() {
            const self = this;
            const raw = String(this.$element.val() || '').trim();
            // 空值：视为用户主动清空，保留为空
            if (!raw) return;

            let start = null;
            let end = null;

            if (this.options.range) {
                const parts = raw.split(this.options.rangeSeparator).map(function(s) {
                    return s.trim();
                });
                if (parts.length === 2) {
                    start = utils.parseStrict(parts[0]);
                    end = utils.parseStrict(parts[1]);
                }
                if (!start || !end || start > end) {
                    this._revertManualInput();
                    return;
                }
                this.rangeStart = start;
                this.rangeEnd = end;
                // 手动输入生效后，弹层若打开，面板视图同步对齐到新范围月份
                this._rViewStart = new Date(start.getFullYear(), start.getMonth(), 1);
                this._rViewEnd = new Date(end.getFullYear(), end.getMonth(), 1);
                this.selectedDate = new Date(start);
                this.currentDate = new Date(start);
            } else if (this.options.type === 'time') {
                const tm = raw.match(/^(\d{1,2}):(\d{1,2})(?::(\d{1,2}))?$/);
                if (!tm || parseInt(tm[1], 10) > 23 || parseInt(tm[2], 10) > 59 ||
                    (tm[3] !== undefined && parseInt(tm[3], 10) > 59)) {
                    this._revertManualInput();
                    return;
                }
                const base = this.selectedDate || new Date();
                this.selectedDate = new Date(
                    base.getFullYear(), base.getMonth(), base.getDate(),
                    parseInt(tm[1], 10), parseInt(tm[2], 10), tm[3] !== undefined ? parseInt(tm[3], 10) : 0
                );
                this.selectedTime = {
                    hours: parseInt(tm[1], 10),
                    minutes: parseInt(tm[2], 10),
                    seconds: tm[3] !== undefined ? parseInt(tm[3], 10) : 0
                };
            } else {
                start = utils.parseStrict(raw);
                if (!start) {
                    this._revertManualInput();
                    return;
                }
                this.selectedDate = start;
                this.currentDate = new Date(start);
                this.selectedTime = {
                    hours: start.getHours(),
                    minutes: start.getMinutes(),
                    seconds: start.getSeconds()
                };
            }

            // 规范化后的值与最近一次已确认值相同则跳过（避免 blur 时重复触发 onChange）
            const fmt = this.options.range
                ? (this.options.rangeFormat || this.options.format)
                : this.options.format;
            const normalized = this.options.range
                ? utils.formatDate(this.rangeStart, fmt) + this.options.rangeSeparator + utils.formatDate(this.rangeEnd, fmt)
                : utils.formatDate(this.selectedDate, fmt);
            if (normalized === this.options.value) {
                return;
            }

            this.updateInput();

            if (this.options.onChange) {
                const value = this.$element.val();
                if (this.options.range) {
                    this.options.onChange([this.rangeStart, this.rangeEnd], value);
                } else {
                    this.options.onChange(this.selectedDate, value);
                }
            }

            // 弹层打开时同步高亮
            if (this.isOpen) {
                this.updateContent();
            }
        }

        /**
         * 非法输入回滚：恢复最近一次合法值并闪烁提示
         */
        _revertManualInput() {
            const self = this;
            this.$element.val(this.options.value);
            this.$element.addClass('ws-date-input-invalid');
            setTimeout(function() {
                self.$element.removeClass('ws-date-input-invalid');
            }, 1500);
        }

        /**
        * 显示日期选择器
        */
        show() {
            // 关闭其他打开的选择器
            if (currentOpenPicker && currentOpenPicker !== this) {
                currentOpenPicker.hide();
            }
            currentOpenPicker = this;

            // 每次重新打开都从「选择日期」第一步开始
            // （datetime 两段式：避免上次停留在时间步骤时，重新打开后没有快捷栏/无法直接改期）
            if (this.options.type === 'datetime' && this.currentView === 'time') {
                this.currentView = 'date';
            }

            // 范围模式：清除上次会话的“浏览月份”光标，让双面板重新对齐已选边界所在月份，
            // 避免重新打开后停留在上次翻看的位置而看不到已选值。
            if (this.options.range) {
                this._rViewStart = null;
                this._rViewEnd = null;
            }
            
            if (this.options.onOpen) {
                this.options.onOpen();
            }
            
            if (!this.$dropdown) {
                this.render();
            } else {
                this.updateContent();
            }
            
            this.$dropdown.show();
            this.isOpen = true;
        }

        /**
        * 隐藏日期选择器
        */
        hide() {
            if (this.options.onClose) {
                this.options.onClose();
            }
            
            if (this.$dropdown && this.options.popup) {
                this.$dropdown.hide();
            }
            
            this.isOpen = false;
            if (currentOpenPicker === this) {
                currentOpenPicker = null;
            }
        }
        
        /**
        * 切换日期选择器显示状态
        */
        toggle() {
            if (this.isOpen) {
                this.hide();
            } else {
                this.show();
            }
        }

        /**
        * 设置日期选择器值
        * @param {string} value - 日期值，格式根据options.format而定
        * @param {boolean} [silent] 静默回写：不派发 change（reset 用）
        */
        setValue(value, silent) {
            if (!value) return;
            
            if (this.options.range) {
                const parts = String(value).split(this.options.rangeSeparator);
                if (parts.length === 2) {
                    this.rangeStart = utils.parseDate(parts[0].trim(), this.options.format);
                    this.rangeEnd = utils.parseDate(parts[1].trim(), this.options.format);
                }
            } else {
                const date = utils.parseDate(value, this.options.format);
                if (date) {
                    this.selectedDate = date;
                    this.currentDate = new Date(date);
                    this.selectedTime = {
                        hours: date.getHours(),
                        minutes: date.getMinutes(),
                        seconds: date.getSeconds()
                    };
                }
            }
            
            this.updateInput(silent === true);
        }
        
        /**
        * 获取日期选择器当前值
        * @returns {string} - 日期值，格式根据options.format而定
        */
        getValue() {
            return this.$element.val();
        }
        
        /**
        * 销毁日期选择器实例
        */
        destroy() {
            this.hide();
            
            if (this.$dropdown) {
                this.$dropdown.remove();
            }
            
            if (this.options.popup !== false) {
                this.$element.unwrap();
            } else {
                this.$container.remove();
            }
            
            this.$element.off('click focus');
            $(document).off('click.wsdate touchstart.wsdate keydown.wsdate');
        }

    }
    
    // jQuery插件封装
    $.fn.wsDatePicker = function(options) {
        return this.each(function() {
            if (!$(this).data('wsDatePicker')) {
                $(this).data('wsDatePicker', new WsDatePicker(this, options));
            }
        });
    };
    
    /**
     * data-* 白名单。
     *
     * 早前 `autoInit` 用 `$el.data()` 全量 dump 当 options，会把 `data-id`、
     * `data-index`、`data-role` 这类业务/语义属性一起塞进组件配置 ——
     * 既污染 options，也让「哪些属性有效」变得不可枚举。这里显式声明。
     */
    var DP_SCHEMA = {
        type: 'raw', format: 'raw', value: 'raw', min: 'raw', max: 'raw',
        range: 'bool', rangeSeparator: 'raw', mark: 'json',
        timeFormat: 'raw', timeSteps: 'json', popup: 'bool', lang: 'raw',
        weekStart: 'num', showToday: 'bool', showClear: 'bool',
        showConfirm: 'bool', showQuickSelect: 'bool', disabled: 'bool',
        placeholder: 'raw',
        onChange: 'fn', onSelect: 'fn', onOpen: 'fn', onClose: 'fn',
        disabledDate: 'fn', disabledTime: 'fn'
    };

    /**
     * 按白名单解析 data-* 配置。
     * @param {HTMLElement} el
     * @returns {Object} 仅包含元素上真实存在、且类型正确的键
     */
    function parseDatePickerConfig(el) {
        var out = {};

        for (var key in DP_SCHEMA) {
            if (!Object.prototype.hasOwnProperty.call(DP_SCHEMA, key)) continue;

            var attr = 'data-' + key.replace(/[A-Z]/g, function (c) {
                return '-' + c.toLowerCase();
            });
            if (!el.hasAttribute(attr)) continue;

            var raw = el.getAttribute(attr);

            switch (DP_SCHEMA[key]) {
                case 'bool':
                    out[key] = raw === '' || /^(true|1|yes|on)$/i.test(String(raw).trim());
                    break;
                case 'num': {
                    var n = parseFloat(raw);
                    if (!isNaN(n)) out[key] = n;
                    break;
                }
                case 'json':
                    try {
                        out[key] = JSON.parse(raw);
                    } catch (e) {
                        console.warn('[ws-date-picker] data-' + key + ' 不是合法 JSON，已忽略:', raw);
                    }
                    break;
                case 'fn':
                    if (typeof window[raw] === 'function') out[key] = window[raw];
                    break;
                default:
                    out[key] = raw;
            }
        }

        return out;
    }

    // 全局方法
    $.wsDatePicker = {
        setDefaults: function(options) {
            $.extend(defaults, options);
        },
        
        version: '2.0.0',

        /**
         * 自动初始化所有带有 data-role="wsdatepicker" 属性的元素
         *
         * 白名单解析，不做 `$el.data()` 全量 dump（见 parseDatePickerConfig）。
         *
         * @param {*} [root] document / 元素 / 选择器；省略则全文档扫描。
         *                   弹窗、动态行等后插入的节点需显式传入根节点补挂。
         */
        autoInit: function(root) {
            var $scope = root ? $(root) : $(document);
            var selector = '[data-role="wsdatepicker"]';

            var $els = $scope.find(selector);
            if ($scope.is(selector)) {
                $els = $els.addBack(selector);
            }

            $els.each(function() {
                var $this = $(this);
                // 避免重复初始化
                if ($this.data('wsDatePicker')) return;

                var options = parseDatePickerConfig(this);

                // 值优先级：data-value（服务端权威）> 原生 input 当前值。
                // 早前是「input 有值就无条件覆盖 options.value」，两者冲突时
                // 服务端下发的 data-value 会被静默丢弃。
                if (options.value) {
                    $this.val(options.value);
                } else if ($this.val()) {
                    options.value = $this.val();
                }

                $this.wsDatePicker(options);
            });
        }
    };
    
    // 页面加载完成后自动初始化
    $(function() {
        $.wsDatePicker.autoInit();
    });

// 统一初始化入口：与其余 ws-* 组件保持同一签名 `autoInitialize(root)`，
// 供 modules/ws/index.js 的 autoInitializeAll() 统一调度。
WsDatePicker.autoInitialize = function(root) {
    return $.wsDatePicker.autoInit(root);
};

// 保留全局挂载：wise-ui.js 与插件业务代码通过 window.WsDatePicker 访问，存量调用零改造。
window.WsDatePicker = WsDatePicker;

export default WsDatePicker;