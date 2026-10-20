/**
 * WiseEditor 示例页脚本 · 直开版（standalone.html）
 *
 * 把 index.html / markdown.html / api.html 三个页面的交互并到一个文件里，
 * 方便「双击就测全套」。
 *
 * 刻意写成 **classic 脚本**（无 import / export）：
 * file:// 下 <script type="module"> 会被浏览器按 CORS 拦掉，模块永远不执行。
 * 因此这里只能通过全局 window.WiseEditor / window.TipTap 访问编辑器。
 */
(function () {
    'use strict';

    /** 取一个实例，不存在返回 null（destroy 之后就会是 null） */
    function inst(id) {
        return (window.WiseEditor && window.WiseEditor.get(id)) || null;
    }

    /** 读表单字段的原始提交值 —— 后端真正收到的东西 */
    function fieldValue(el) {
        return el ? el.value : '(元素不存在)';
    }

    function el(id) {
        return document.getElementById(id);
    }

    /**
     * 一次性打印全部关键状态。
     * 「实例内容」与「字段值」应当一致 —— 两者不一致就是表单同步坏了。
     */
    function dump() {
        const out = el('out');
        if (!out) return;

        const rich = inst('editor_content');
        const ro = inst('editor_ro');
        const md = inst('editor_body');
        const mdPreview = document.querySelector('#editor_body .wiseeditor-md-preview');

        out.textContent = JSON.stringify({
            'TipTap bundle 版本': window.TipTap ? window.TipTap.version : '(未加载)',
            '已注册适配器': window.WiseEditor ? Array.from(window.WiseEditor.adapters.keys()) : [],
            '已挂载实例': window.WiseEditor ? Array.from(window.WiseEditor.instances.keys()) : [],

            '富文本 engine': rich ? rich.engine : '(未初始化)',
            '富文本 getContent()': rich ? rich.getContent() : null,
            '富文本字段 #editor_content_input': fieldValue(el('editor_content_input')),

            '只读已挂载': !!ro,
            '只读可编辑（应为 false）': ro ? ro.getInternal().editor.isEditable : null,

            'Markdown 已挂载': !!md,
            'Markdown getContent()': md ? md.getContent() : null,
            'Markdown 字段 #editor_body_textarea': fieldValue(el('editor_body_textarea')),
            // 预览栏是"看不到就发现不了"的一环，直接把渲染器与产出摊开
            'Markdown 渲染器': (window.Wise && window.Wise.ws && window.Wise.ws.WsMarkdown)
                ? 'window.Wise.ws.WsMarkdown（内核）'
                : (window.WsMarkdown ? 'window.WsMarkdown（组件全局）' : '(缺失 → 退化为纯文本)'),
            'Markdown 预览栏产出': mdPreview ? mdPreview.innerHTML.slice(0, 120) : '(未挂载)',
        }, null, 2);
    }

    document.addEventListener('DOMContentLoaded', () => {
        // 挂载是异步的，等一会儿再首屏打印；失败也不影响页面（内容以字段值为准）
        setTimeout(dump, 400);

        // ---- 表单 ----
        const form = el('demoForm');

        el('btnDump').addEventListener('click', dump);

        form.addEventListener('submit', (e) => {
            e.preventDefault();
            // 这里就是后端拿到的东西：form 直接提交，富文本内容已经在隐藏域里
            const entries = [];
            new FormData(form).forEach((v, k) => {
                entries.push(k + ' = ' + String(v).replace(/\s+/g, ' ').slice(0, 160));
            });
            el('out').textContent = '【即将提交的 FormData】\n  ' + entries.join('\n  ')
                + '\n\n—— 无需再调 WiseEditor 取值，隐藏域/textarea 与编辑器实时同步。';
        });

        // ---- 主题：核对 ProseMirror、表格、占位符在两种主题下的可读性 ----
        const html = document.documentElement;
        el('btnTheme').addEventListener('click', () => {
            html.dataset.bsTheme = html.dataset.bsTheme === 'dark' ? 'light' : 'dark';
        });

        // ---- 实例 API：逐个调契约方法 ----
        const log = [];

        function show(title, detail) {
            log.push(title + (detail === undefined ? '' : '\n    ' + detail));
            el('out').textContent = log.join('\n');
        }

        function guarded(label, fn) {
            const it = inst('editor_content');
            if (!it) {
                show(label, '✗ 实例不存在（可能已被 destroy，刷新页面重建）');
                return;
            }
            try {
                show(label, '✓ ' + JSON.stringify(fn(it)));
            } catch (e) {
                show(label, '✗ ' + e.message);
            }
        }

        el('btnGet').addEventListener('click', () => {
            guarded('getContent()', (it) => it.getContent());
        });

        el('btnSet').addEventListener('click', () => {
            guarded('setContent()', (it) => {
                it.setContent('<h3>由 setContent 写入</h3>'
                    + '<table><tbody><tr><td><p>A</p></td><td><p>B</p></td></tr></tbody></table>');
                return '已写入，内容长度 = ' + it.getContent().length;
            });
        });

        el('btnDirty').addEventListener('click', () => {
            guarded('isDirty()', (it) => it.isDirty());
        });

        el('btnFocus').addEventListener('click', () => {
            guarded('focus()', (it) => {
                it.focus();
                return '已聚焦';
            });
        });

        el('btnDestroy').addEventListener('click', () => {
            guarded('destroy()', (it) => {
                it.destroy();
                return 'WiseEditor.get() 现在返回 = ' + String(inst('editor_content'));
            });
        });
    });
})();
