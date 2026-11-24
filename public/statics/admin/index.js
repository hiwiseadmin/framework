/**
 * admin/index.js — 框架首页控制器（ESM）
 *
 * 依赖：jQuery/Bootstrap/AdminLTE（全局 script）、
 *       Framework/Navtabs/Layer/Broadcast/Cookie（AMD 桥接到 window）
 */
const $ = window.jQuery;
const Layer = window.Layer;
const Navtabs = window.navtab;
const Framework = window.Framework;
const Broadcast = window.Broadcast;

const Controller = {
    index() {
        // 处理导航栏溢出
        function handleNavOverflow() {
            // 排除"更多"按钮与启动台按钮（启动台始终固定可见）；临时插件 Tab 初始带 d-none，须保留其隐藏态（由 activateTempPlugin 激活时再显示）
            const $menuItems = $('#top-nav-menu > .nav-item:not(#nav-more-toggle):not(.nav-launcher-toggle):not(#temp-plugin-nav)');
            const $moreToggle = $('#nav-more-toggle');
            const $dropdown = $('#nav-more-dropdown');

            $('#top-nav-menu').addClass('overflow-hidden');
            $menuItems.removeClass('d-none');
            $moreToggle.addClass('d-none');
            $dropdown.html('');

            const navMenuWidth = $('#top-nav-menu').width() - 50;
            let totalWidth = 0;
            const moreItems = [];

            $menuItems.each(function () {
                const $item = $(this);
                totalWidth += $item.width();
                if (totalWidth > navMenuWidth) {
                    moreItems.push($item);
                    $item.addClass('d-none');
                } else {
                    $item.removeClass('d-none');
                }
            });

            if (moreItems.length > 0) {
                $moreToggle.removeClass('d-none');
                moreItems.forEach(function ($item) {
                    const $link = $item.find('a');
                    const $dropdownItem = $('<li></li>').append(
                        $('<a></a>', {
                            'class': $link.attr('class').replace('nav-link', 'dropdown-item').replace('active', ''),
                            'href': $link.attr('href'),
                            'html': $link.html(),
                            'data-id': $link.data('id'),
                            'data-url': $link.data('url'),
                        })
                    );
                    $dropdown.append($dropdownItem);
                });
            }
            $('#top-nav-menu').removeClass('overflow-hidden');
        }

        $(window).on('resize', handleNavOverflow);

        // ===== 菜单 / 侧边栏 / 标签页导航（实现下沉到 modules/wise-navmenu.js）=====
        //
        // 原先这里是 switchTopMenu / highlightSidebar / restoreNavState 的实现闭包，
        // iframe 子页面取不到，只能自己去 window.top.document 里翻 DOM 再 .click()
        // （见 statics/admin/dashboard.js 的历史实现）。现在实现统一在 Wise.nav：
        // 主框架与子页面（`data-wise-nav` 协议）共用同一份 URL→菜单解析与打开链路。
        // 下面保留同名薄封装 —— 调用点无需改动；模块缺失时给出明确告警而不是静默失效。
        const Nav = (window.Wise && window.Wise.nav) ? window.Wise.nav : null;
        if (!Nav) {
            console.error('[admin/index] Wise.nav 未加载：请执行 npm run build 重新构建 wise-bundle.js');
        }

        // 临时插件 Tab 的唯一 parentId（未固定的多顶级插件，启动台左侧，始终只保留一个）
        const TEMP_PLUGIN_PARENT = (Nav && Nav.TEMP_PLUGIN_PARENT) || 'temp-plugin';

        // 启动台插件完整数据（含未固定插件的菜单树）
        //
        // ⚠️ 必须在本函数**最前面**声明：`tab:activated` 处理器（下方注册）会经
        // findLooseLauncherByUrl() 读它，而标签激活发生在 loadSavedState()/Nav.open()/
        // Nav.restore() 期间 —— 早于函数的后半段执行。原先声明在启动台渲染一节（原 295 行）
        // 会形成 TDZ：`ReferenceError: Cannot access 'LAUNCHER_DATA' before initialization`。
        // 数据源 window.__launcherPlugins__ 由模板内联脚本直出，早于本 ESM 模块执行。
        const LAUNCHER_DATA = window.__launcherPlugins__ || [];

        function switchTopMenu(parentId, opts) { return Nav ? Nav.switchTop(parentId, opts) : null; }
        function highlightSidebar(url) { return Nav ? Nav.highlight(url) : null; }
        function restoreNavState() { return Nav ? Nav.restore() : null; }

        // 代办事项点击
        $('#todo-list>a').on('click', function (e) {
            e.preventDefault();
            const url = $(this).attr('href');
            if (url) console.log(url);
        });

        // 用户头背景切换
        $('#userHeader').on('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const bgClasses = $(this).data('bg-classes').split(',');
            const currentBg = $(this).attr('class').match(/[a-zA-Z-]+-bg/)[0];
            const currentIndex = Math.max(0, bgClasses.indexOf(currentBg));
            const nextBg = bgClasses[(currentIndex + 1) % bgClasses.length];
            $(this).removeClass(currentBg).addClass(nextBg);
        });

        // 锁屏
        $('[data-lte-widget="lockscreen"]').on('click', function () {
            if ($('#lockscreen-wrapper').hasClass('d-none')) {
                Controller.lockscreen();
                const bc = new Broadcast({ channelName: 'framework' });
                bc.send('lockscreen');
            }
        });

        // 颜色主题
        $('[data-lte-widget="color-scheme"]').on('click', function () {
            const $node = $('.sidebar-menu a[data-url="/admin/wise_config/index"]');
            const url = $node.data('url');
            const tabId = url.replace('.html', '');
            const tabTitle = $node.data('title') || $node.find('p').text();
            let icon = $node.find('i').attr('class');
            icon = icon ? icon.replace('nav-icon ', '') : '';
            Navtabs.api.addTab({ id: tabId, title: tabTitle, icon, url, content: '', params: { active_key: 'theme' } });
        });

        // 侧边栏菜单点击 → 打开标签（事件委托，兼容动态生成的临时插件侧边栏）
        // 全部副作用（切一级菜单、叶子高亮/展开、选中态持久化、开/复用标签、改写 hash）
        // 已统一由 Wise.nav.openLeaf 完成 —— 与「页面内 data-wise-nav 超链接」同一条链路。
        $(document).on('click', '.sidebar-menu a[data-url]', function (e) {
            e.preventDefault();
            if (Nav) {
                Nav.openLeaf($(this), { markRoot: true });
            } else if (Navtabs && Navtabs.api) {
                // 兜底：nav 模块缺失（bundle 未重新构建）时至少还能打开标签
                const url = $(this).data('url');
                Navtabs.api.addTab({
                    id: url, url: url, content: '',
                    title: $(this).data('title') || $(this).find('p').text(),
                    icon: '',
                });
                updateUrl('#' + url);
            }
            setTimeout(handleNavOverflow, 100);
        });

        // 标签激活 → 切换所属顶部 Tab（控制台/插件）+ 高亮对应菜单
        Navtabs.api.on('tab:activated', function (tab) {
            const url = tab.opts.sid;

            // 1) 定位该页面所属的侧边栏叶子
            //    （URL 归一 + 兼容查询参数，与 hash 恢复、data-wise-nav 打开共用同一份匹配实现）
            let $leaf = (url && Nav) ? Nav.matchLeaf(url) : $();

            // 1.5) 未命中服务端侧边栏 → 可能属于「未固定的多顶级插件」（含库包启动台容器）：
            //      这类插件的菜单树不在服务端模板里，临时 Tab 与临时侧边栏都是本次会话动态生成的
            //      空壳（刷新后 Tab 还带 d-none），必须据当前 url 反查并重建 —— 否则会出现
            //      「页面正常打开、顶部入口却隐藏」的错位状态。重建后再匹配一次。
            if (!$leaf.length && url && Nav) {
                const loose = findLooseLauncherByUrl(url);
                if (loose) {
                    showTempPluginNav(loose);
                    $leaf = Nav.matchLeaf(url);
                }
            }

            // 2) 同步切换顶部一级 Tab（控制台/插件/临时插件 Tab）
            if ($leaf.length) {
                const parentId = $leaf.closest('.sidebar-menu').data('parent');
                if (parentId != null) switchTopMenu(parentId);
                // 3) 高亮侧边栏叶子并展开所属分组
                highlightSidebar($leaf);
            }

            updateUrl('#' + url);
            setTimeout(handleNavOverflow, 100);
            document.title = (window.site?.name ? window.site.name + ' - ' : '') + tab.opts.title;
        });

        // 标签内容加载完成 → 通知 iframe
        Navtabs.api.on('tab:contentLoaded', function (tab) {
            Framework.api.sendMessageToIframe('con_' + tab.id, { type: 'tab:contentLoaded', opts: tab.opts });
        });

        // 根据 hash 打开初始标签
        //
        // URL → 菜单的解析与打开已统一在 Wise.nav.open()：命中菜单 → 切所属一级菜单 +
        // 展开高亮叶子 + 开/复用标签；未命中 → 普通标签，标题/图标复用 tab meta。
        //
        // 路径段约定（**只补不截**）：
        //   /admin/{controller}/{action}            框架默认三段式
        //   /admin/{module}/{controller}/{action}   member 等插件页面四段式
        //   /plugin/{ident}/{controller}/{action}   插件
        // 历史实现固定只取前 4 段，四段式页面刷新时末段 index 被吞掉、退化成无路由地址返回 403；
        // 现由 matchLeaf() 的候选匹配（原路径 → 补 /index）兜住，无需手工拼段。
        // 前缀判定保留在此：避免把任意 hash（锚点、第三方片段）当成页面地址去开标签。
        Navtabs.api.loadSavedState();
        const hashId = window.location.hash.replace('#', '');
        if (hashId.startsWith('/admin/') || (hashId.startsWith('/plugin/') && hashId.split('/').length >= 3)) {
            if (Nav) {
                Nav.open(hashId);
            } else {
                Navtabs.api.addTab({ id: hashId, title: '页面', icon: '', url: hashId });
            }
        } else if (!hashId) {
            Navtabs.api.loadSavedState($('.sidebar-menu a[data-url]').first().data('url'));
        }

        // 侧边栏切换
        $('a[data-widget="pushmenu"]').on('click', function () {
            setTimeout(handleNavOverflow, 600);
        });

        // 一级菜单切换（修复原 toggle 全量翻转 bug + 无标签页时自动打开第一个页面）
        // 注：切走时不移除/隐藏临时插件 Tab——它保留在启动台左侧，本次会话内可随时点回
        $('#top-nav-menu').on('click', '.top-menu-item', function (e) {
            e.preventDefault();
            switchTopMenu($(this).data('id'), { autoOpenFirst: true });
        });

        // 恢复上次的一级菜单 + 侧边栏选中态（替代原 Cookie 方案）
        restoreNavState();

        // 无刷新修改 URL
        function updateUrl(newURL, title = null) {
            if (history.replaceState) {
                history.replaceState(null, title, newURL);
            } else {
                window.location.hash = newURL;
            }
        }

        // 登录成功弹窗
        $('#securityLevel').on('click', function () {
            $('#loginSuccessModal').modal('show');
        });
        $('#loginSuccessModal').on('shown.bs.modal', function () {
            if (window.Cookie) window.Cookie.delete('loginSuccessModalShow');
            setTimeout(() => { $(this).modal('hide'); }, 10000);
        });
        function showLoginSuccessModal() {
            if (window.Cookie && window.Cookie.get('loginSuccessModalShow') === 'true') {
                $('#loginSuccessModal').modal('show');
            }
        }

        // 侧边栏搜索
        $('[data-lte-toggle="sidebar-search"]').on('click', 'a.list-group-item', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const href = e.target.getAttribute('href');
            updateUrl('#' + href);
            const tabLink = $('.sidebar-menu a[data-url="' + href + '"]');
            if (tabLink.length > 0) tabLink.click();
        });

        // 个人资料
        $('#profile').on('click', function (e) {
            e.preventDefault();
            Navtabs.api.addTab({ id: '/admin/profile/index', title: '个人资料', icon: 'bi bi-person-circle', url: '/admin/profile/index', content: '' });
        });

        // 头像更新广播
        (new Broadcast({ channelName: 'avatar' })).subscribe('updateAvatar', (payload) => {
            if (payload.avatar) $('.avatar').attr('src', payload.avatar);
        });

        // 初始化
        Framework.api.switchTheme();
        handleNavOverflow();
        showLoginSuccessModal();

        // 全局搜索初始化
        if (window.Wise && window.Wise.search && typeof window.Wise.search.init === 'function') {
            window.Wise.search.init();
        }

        // AI 助手初始化（顶栏图标 → 居中弹窗）
        if (window.Wise && window.Wise.agentChat && typeof window.Wise.agentChat.init === 'function') {
            window.Wise.agentChat.init();
        }

        // ===== 应用启动台 =====
        function openLauncher() {
            $('#launcher-search-input').val('').trigger('input');
            $('#launcherModal').modal('show');
            setTimeout(() => $('#launcher-search-input').focus(), 100);
        }
        $('#launcher-open-btn').on('click', function (e) { e.preventDefault(); openLauncher(); });

        // 搜索过滤
        $('#launcher-search-input').on('input', function () {
            const kw = $(this).val().trim().toLowerCase();
            $('#launcher-grid .launcher-item').each(function () {
                const $item = $(this);
                const match = !kw || ($item.find('.launcher-name').text().toLowerCase().indexOf(kw) !== -1);
                $item.closest('.col-6').toggleClass('d-none', !match);
            });
        });

        // 启动台数据见函数开头的 LAUNCHER_DATA（提前声明，避开标签激活链路的 TDZ）
        function getLauncherItem(ident) {
            return LAUNCHER_DATA.find(function (i) { return i.ident === ident; }) || null;
        }

        // ── 结构化图标渲染（bi / image / emoji）──
        // 服务端直出的 launcher-icon 仅保证 bi 回退；非 bi 形态在此按 iconType 分派。
        function applyLaunchIcon($icon, item) {
            item = item || {};
            const type = item.iconType || 'bi';
            const value = item.iconValue || 'bi bi-plugin';
            const color = item.iconColor || '';

            $icon.removeClass('is-bg-image').removeAttr('style').addClass('rounded-circle d-flex align-items-center justify-content-center').css('color', '#fff').empty();

            if (type === 'image') {
                // 图片（含被规整的 .svg 资源）：背景铺满圆形容器
                $icon.addClass('is-bg-image');
                if (color) $icon.css('background-color', color);
                $icon.css('background-image', 'url("' + String(value).replace(/["\\]/g, m => m === '"' ? '%22' : '\\\\') + '")');
                return;
            }
            if (color) $icon.css('background', color);

            if (type === 'emoji') {
                $icon.append($('<span>').addClass('launcher-emoji').text(value));
            } else {
                $icon.append($('<i>').attr('class', /^bi\s/i.test(value) ? value : 'bi bi-plugin'));
            }
        }

        // 对启动台九宫格已有条目统一渲染图标
        function renderLaunchIcons() {
            $('#launcher-grid .launcher-item').each(function () {
                const item = getLauncherItem($(this).data('ident'));
                if (item) applyLaunchIcon($(this).find('.launcher-icon').first(), item);
            });
        }

        // ── 启动台九宫格动态重建（安装/卸载插件后局部刷新，无需整页刷新）──
        // 结构与服务端模板 index/index.html 的 .launcher-item 保持一致，
        // 使既有的委托事件（点击打开 / pin 钉选）无需重新绑定即可继续生效。
        function buildLauncherColumn(item) {
            const canPin = parseInt(item.canPin, 10) === 1;
            const pinned = parseInt(item.pinned, 10) === 1;

            const $col = $('<div>').addClass('col-6 col-md-4 col-lg-3');
            const $item = $('<div>')
                .addClass('launcher-item position-relative d-flex flex-column align-items-center justify-content-center text-center py-3 rounded-3')
                .attr({
                    'data-url': item.path || '',
                    'data-first-url': item.firstUrl || '',
                    'data-ident': item.ident || '',
                    'data-can-pin': item.canPin || 0,
                    'data-pinned': item.pinned || 0,
                    'data-parent-id': item.parentMenuId || '',
                });

            if (canPin) {
                $item.append($('<button>')
                    .attr({
                        type: 'button',
                        'data-bs-toggle': 'tooltip',
                        title: pinned ? '取消钉选' : '钉选到常用区',
                    })
                    .addClass('btn btn-sm launcher-pin-btn position-absolute top-0 end-0 m-1 p-0 border-0')
                    .append($('<i>').addClass('bi ' + (pinned ? 'bi-pin-fill text-primary' : 'bi-pin'))));
            }

            $item.append($('<span>')
                .addClass('launcher-icon rounded-circle d-flex align-items-center justify-content-center mb-2')
                .append($('<i>').addClass(item.icon || 'bi bi-plugin')));
            $item.append($('<span>').addClass('launcher-name small text-truncate w-100 px-2').text(item.title || ''));

            return $col.append($item);
        }

        function renderLauncherGrid() {
            const $grid = $('#launcher-grid');
            $grid.empty();
            LAUNCHER_DATA.forEach(function (item) { $grid.append(buildLauncherColumn(item)); });

            // 空态提示：优先复用服务端直出的节点，缺失时补建
            let $empty = $('#launcher-empty');
            if (LAUNCHER_DATA.length) {
                $empty.addClass('d-none');
            } else {
                if (!$empty.length) {
                    $empty = $('<div class="text-center text-secondary py-5" id="launcher-empty">')
                        .append($('<i class="bi bi-inbox fs-1 d-block mb-2"></i>'))
                        .append(document.createTextNode('暂无可用插件'));
                    $grid.after($empty);
                }
                $empty.removeClass('d-none');
            }

            renderLaunchIcons();
            renderPinnedNavIcons();
        }

        /**
         * 重新拉取启动台数据并重建九宫格。
         * 失败时静默保留当前视图（不影响插件操作本身）。
         */
        function refreshLauncher(done) {
            $.ajax({
                url: '/admin/index/launcher',
                type: 'GET',
                dataType: 'json',
                success: function (res) {
                    if (res && res.code === 0 && res.data && Array.isArray(res.data.launcherPlugins)) {
                        // 原地替换：保持 window.__launcherPlugins__ 与其它消费方同引用
                        LAUNCHER_DATA.length = 0;
                        res.data.launcherPlugins.forEach(function (i) { LAUNCHER_DATA.push(i); });
                        window.__launcherPlugins__ = LAUNCHER_DATA;
                        renderLauncherGrid();
                    }
                    if (typeof done === 'function') done();
                },
                error: function () { if (typeof done === 'function') done(); },
            });
        }

        // 暴露启动台 API：供 iframe 内的页面控制器（如插件管理页安装/卸载成功后）调用，
        // 避免"装了插件必须手动刷新整个后台才看得到启动台入口"。
        window.Wise = window.Wise || {};
        window.Wise.launcher = Object.assign(window.Wise.launcher || {}, {
            open: openLauncher,
            reload: refreshLauncher,
        });

        // 渲染顶部一级菜单内的内联图标（小尺寸：bi / image / emoji）
        //
        // 临时插件 Tab 与「常用插件区」（pinned）入口共用：同一个应用无论「从启动台临时打开」
        // 还是「钉选后刷新页面」都必须显示同一个图标，数据源同为启动台结构化数据
        // （iconType/iconValue/iconColor，见 MenuLauncherService::resolveItemIcon()）。
        //
        // 与启动台九宫格的区别：不画圆形容器、**不强制白色** —— 颜色继承所在 .nav-link，
        // 随明暗主题自适应（顶栏为浅色背景时白字图标会看不见）。
        function applyNavIcon($icon, item) {
            if (!$icon || !$icon.length) return;
            item = item || {};
            const type = item.iconType || 'bi';
            const value = item.iconValue || 'bi bi-plugin';
            const color = item.iconColor || '';

            $icon.removeClass().removeAttr('style').empty();
            if (type === 'image') {
                $icon.css({
                    'background-image': 'url("' + String(value).replace(/["\\]/g, m => m === '"' ? '%22' : '\\\\') + '")',
                    'background-size': 'contain',
                    'background-repeat': 'no-repeat',
                    'background-position': 'center',
                    'display': 'inline-block',
                    'width': '1em',
                    'height': '1em',
                    'vertical-align': '-0.125em',
                });
                if (color) $icon.css('background-color', color);
                return;
            }
            if (color) $icon.css('color', color);
            if (type === 'emoji') {
                $icon.addClass('launcher-emoji').text(value);
            } else {
                $icon.addClass(/^bi\s/i.test(value) ? value : 'bi bi-plugin');
            }
        }

        // 顶部「常用插件区」（pinned）入口图标：服务端已直出 {$menu.iconBi} 作 bi 兜底，
        // 此处按结构化数据补齐 image / emoji 与配色。
        // 为什么需要：pinned 入口是**服务端直出**的，没有这段补偿就会在刷新后退化成纯文字，
        // 而同一应用在启动台/临时 Tab 里是有图标的。
        function renderPinnedNavIcons() {
            $('#top-nav-menu a.top-menu-item[data-pinned-ident]').each(function () {
                const $nav = $(this);
                const item = getLauncherItem($nav.attr('data-pinned-ident'));
                if (!item) return;
                applyNavIcon($nav.find('[data-pinned-nav-icon]').first(), item);
            });
        }

        // 初始化渲染启动台条目图标（bi/image/emoji）+ 常用插件区入口图标
        renderLaunchIcons();
        renderPinnedNavIcons();

        // 直接打开一个标签页（兜底：侧边栏无对应菜单时）
        function openDirectTab(tabId, title, icon, url) {
            if (Nav) return Nav.openTab(url || tabId, title, icon);
            window.location.href = url || tabId;   // nav 缺失兜底（不该发生）
            return null;
        }

        // 在当前顶级侧边栏内打开对应 url
        // 命中叶子 → 与「点一次菜单」完全一致（Wise.nav.openInSidebar 内部走 openLeaf）
        function openInSidebar(parentId, url, tabId, title, icon) {
            if (Nav) return Nav.openInSidebar(parentId, url, { tabId, title, icon });
            openDirectTab(tabId, title, icon, url);
            return null;
        }

        // 递归渲染插件菜单树为侧边栏 HTML（与模板 li 结构一致）
        function buildSidebarHtml(nodes) {
            let html = '';
            (nodes || []).forEach(function (node) {
                const name = node.name || '';
                const icon = node.icon || 'bi bi-circle';
                const path = node.path || '';
                const kids = node.childlist || node.children || [];
                if (kids.length) {
                    html += '<li class="nav-item">'
                        + '<a href="#" class="nav-link"><i class="nav-icon ' + icon + '"></i>'
                        + '<p>' + name + '<i class="nav-arrow bi bi-chevron-right"></i></p></a>'
                        + '<ul class="nav nav-treeview">' + buildSidebarHtml(kids) + '</ul></li>';
                } else {
                    html += '<li class="nav-item"><a href="' + path + '" class="nav-link"'
                        + ' data-url="' + path + '" data-title="' + name + '">'
                        + '<i class="nav-icon ' + icon + '"></i><p>' + name + '</p></a></li>';
                }
            });
            return html;
        }

        /**
         * 在启动台数据里反查「当前 url 属于哪个未固定的多顶级插件」
         *
         * 判据：条目 `canPin=1` 且未 `pinned`（= 未固定，侧边栏不在服务端模板里），
         * 且其菜单树（children）中存在该 url（比对路径，忽略 query / .html）。
         *
         * @param {string} url 标签页 url
         * @return {object|null} 命中的启动台条目
         */
        function findLooseLauncherByUrl(url) {
            const needle = String(url || '').split('?')[0].replace(/\.html$/, '');
            if (!needle) return null;

            const treeHas = function (nodes) {
                for (let i = 0; i < (nodes || []).length; i++) {
                    const node = nodes[i] || {};
                    const path = String(node.path || '').split('?')[0].replace(/\.html$/, '');
                    if (path && path === needle) return true;
                    const kids = node.childlist || node.children || [];
                    if (kids.length && treeHas(kids)) return true;
                }
                return false;
            };

            for (let i = 0; i < LAUNCHER_DATA.length; i++) {
                const item = LAUNCHER_DATA[i];
                if (parseInt(item.canPin, 10) !== 1) continue;      // 单分组插件挂在「插件扩展」下，无需临时 Tab
                if (parseInt(item.pinned, 10) === 1) continue;      // 已钉选：服务端已直出侧边栏
                if (treeHas(item.children)) return item;
            }
            return null;
        }

        /**
         * 显示/重建顶部「临时插件 Tab」+ 它对应的临时侧边栏（不做打开动作）
         *
         * 未固定的多顶级插件（含库包启动台容器）的菜单树不在服务端模板里，只由本次会话
         * 动态生成；因此刷新后必须能从「当前标签所属插件」反推重建，否则会出现
         * 「标签还在、顶部入口却隐藏（d-none）」的错位状态（见 tab:activated 的 1.5 步）。
         *
         * 幂等：可在每次标签激活时安全调用。
         *
         * @param {object} item 启动台条目（window.__launcherPlugins__ 中的一项）
         */
        function showTempPluginNav(item) {
            if (!item) return;

            // 1) 顶部临时 Tab：名称 + 图标（bi/image/emoji，与启动台可视化同源）
            applyNavIcon($('#temp-plugin-nav-icon'), item);
            $('#temp-plugin-nav-name').text(item.title || '');
            $('#temp-plugin-nav').removeClass('d-none');

            // 2) 重建临时侧边栏菜单树（刷新后是空 ul；点击/树形展开由全局委托处理，无需重新绑定）
            $('#temp-plugin-sidebar')
                .attr('data-parent', TEMP_PLUGIN_PARENT)
                .html(buildSidebarHtml(item.children || []));

            // 3) 切到临时插件 Tab（临时状态不持久化，刷新后仍回到真实菜单）
            switchTopMenu(TEMP_PLUGIN_PARENT, { autoOpenFirst: false });

            // 4) 溢出重算（临时 Tab 可能挤占导航宽度）
            setTimeout(handleNavOverflow, 100);
        }

        // 激活未固定的多顶级插件：显示临时 Tab/侧边栏 + 打开插件首页（始终只保留一个临时 Tab）
        function activateTempPlugin(ident, url, title, icon) {
            const item = getLauncherItem(ident) || { ident: ident, title: title, children: [] };
            showTempPluginNav(item);
            openInSidebar(TEMP_PLUGIN_PARENT, url, url.replace('.html', ''), item.title || title, icon);
        }

        // 点击启动台条目（排除 pin 按钮）→ 按插件类型切换顶部 Tab + 侧边栏
        $('#launcher-grid').on('click', '.launcher-item', function (e) {
            if ($(e.target).closest('.launcher-pin-btn').length) return; // 点 pin 按钮不触发打开
            e.preventDefault();
            const $item = $(this);
            const url = $item.data('first-url') || $item.data('url');
            if (!url) return;
            $('#launcherModal').modal('hide');

            const ident = $item.data('ident');
            const canPin = parseInt($item.data('can-pin'), 10) === 1;   // 多顶级插件
            const pinned = parseInt($item.data('pinned'), 10) === 1;    // 已钉选到常用区
            const parentId = $item.data('parent-id');                    // 多顶级=插件根id；单分组=插件扩展id
            const tabId = url.replace('.html', '');
            const title = $item.find('.launcher-name').text();
            // 图标以结构化数据为准（兼容 bi/非 bi 形态）；直接取**完整类名**供 Tab/侧边栏展示。
            // 注意不要剥掉根类 `bi`：Bootstrap Icons 的字体族挂在 `.bi` 上，剥成 `bi-shop`
            // 只剩 `.bi-shop::before` 而缺字体族 → Tab 里表现为无图标（降级直开 Tab 时会踩到）。
            const launcherItem = getLauncherItem(ident);
            const icon = (launcherItem && launcherItem.icon) ? launcherItem.icon : 'bi bi-plugin';

            if (canPin && !pinned) {
                // 未固定的多顶级插件 → 生成/替换临时 Tab
                activateTempPlugin(ident, url, title, icon);
                return;
            }

            // 单分组插件（插件扩展内）或 pinned 插件 → 切到其顶部 Tab + 显示对应侧边栏菜单
            // 注：临时插件 Tab 保留在启动台左侧，本次会话内可随时点回
            switchTopMenu(parentId, { autoOpenFirst: false });
            openInSidebar(parentId, url, tabId, title, icon);
        });

        // pin / unpin 插件（仅多顶级插件可钉选）
        $('#launcher-grid').on('click', '.launcher-pin-btn', function (e) {
            e.preventDefault();
            e.stopPropagation();
            const $item = $(this).closest('.launcher-item');
            const ident = $item.data('ident');
            const pinned = parseInt($item.data('pinned'), 10) === 1;
            const action = pinned ? 'unpin' : 'pin';
            if (!ident) return;
            $.post('/admin/plugin_menu_layout/' + action, { plugin: ident }, function (res) {
                if (window.Layer) window.Layer.toast({ content: res.msg || '操作成功', type: (res.code === 0 ? 'success' : 'error') });
                if (res.code === 0) window.location.reload();
            }).fail(function () {
                if (window.Layer) window.Layer.toast({ content: '网络错误', type: 'error' });
            });
        });

        // 快捷键 Shift + O 打开启动台（避免与 AI 助手 Shift+A、搜索 Shift+S 冲突）
        if (typeof window.HotKey !== 'undefined') {
            const launcherHotKey = new window.HotKey('launcher');
            launcherHotKey.register('shift+o', () => { openLauncher(); }, '应用启动台');
        }
    },

    lockscreen() {
        $('#lockscreen-wrapper').removeClass('d-none');
        $('#password').val('').focus();
        $('#errorMessage').hide();

        $.ajax({
            url: '/admin/index/lockscreen',
            success(res) {
                if (res.code != 200) Layer.toast({ content: res.msg, type: 'warning' });
            },
            error() {
                Layer.toast({ content: '网络错误，请重试', type: 'warning' });
            },
        });

        function updateTime() {
            const now = new Date();
            $('#currentTime').text(
                String(now.getHours()).padStart(2, '0') + ':' +
                String(now.getMinutes()).padStart(2, '0') + ':' +
                String(now.getSeconds()).padStart(2, '0')
            );
            const weekdays = ['星期日', '星期一', '星期二', '星期三', '星期四', '星期五', '星期六'];
            $('#currentDate').text(
                now.getFullYear() + '年' + (now.getMonth() + 1) + '月' + now.getDate() + '日 ' + weekdays[now.getDay()]
            );
        }
        updateTime();
        setInterval(updateTime, 1000);

        $('#unlockForm').on('submit', function (e) {
            e.preventDefault();
            const password = $('#password').val();
            const errorMessage = $('#errorMessage');
            errorMessage.text('解锁中，请稍候...').show();

            $.ajax({
                url: '/admin/index/unlock',
                type: 'post',
                data: { password },
                dataType: 'json',
                success(res) {
                    $('#password').val('');
                    if (res.code == 0) {
                        $('#lockscreen-wrapper').addClass('d-none');
                    } else if (res.code == 404) {
                        window.location.href = '/admin/index/login';
                    } else {
                        errorMessage.text(res.msg);
                        $('#password').focus().addClass('is-invalid');
                    }
                },
                error() {
                    errorMessage.text('网络错误，请重试');
                },
            });
        });

        $('#password').on('input', function () {
            $(this).removeClass('is-invalid');
            $('#errorMessage').hide();
        });
    },
};

export default Controller;
