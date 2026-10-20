/**
 * WiseEditor 示例页脚本 · Markdown（markdown.html）
 *
 * 与富文本示例共用同一套 window.WiseEditor API，差别只在驱动实现。
 * 这里额外打印一项「TipTap bundle 是否已加载」—— 用于说明两个驱动的引擎是
 * **按需加载**的：本页只用 milkdown，vendor/tiptap.bundle.js 理应没被请求。
 */

function dump() {
    const out = document.getElementById('out');
    const inst = window.WiseEditor && window.WiseEditor.get('editor_body');

    const preview = document.querySelector('#editor_body .wiseeditor-md-preview');

    out.textContent = JSON.stringify({
        '实例已挂载': !!inst,
        '实例方法': inst
            ? Object.keys(inst).filter((k) => typeof inst[k] === 'function')
            : [],
        '实例内容 getContent()': inst && inst.getContent ? inst.getContent() : null,
        '字段值 #editor_body_textarea': document.getElementById('editor_body_textarea').value,
        'TipTap bundle 是否已加载': !!window.TipTap,
        // 预览栏是"看不到就发现不了"的一环，直接把渲染结果摊开
        'Markdown 渲染器': window.Wise && window.Wise.ws && window.Wise.ws.WsMarkdown
            ? 'window.Wise.ws.WsMarkdown（内核）'
            : (window.WsMarkdown ? 'window.WsMarkdown（组件全局）' : '(缺失 → 预览退化为纯文本)'),
        '预览栏产出': preview ? preview.innerHTML.slice(0, 120) : '(未挂载)',
    }, null, 2);
}

document.addEventListener('DOMContentLoaded', () => {
    setTimeout(dump, 300);
    document.getElementById('btnShow').addEventListener('click', dump);

    document.getElementById('mdForm').addEventListener('submit', (e) => {
        e.preventDefault();
        dump();
    });

    const html = document.documentElement;
    document.getElementById('btnTheme').addEventListener('click', () => {
        html.dataset.bsTheme = html.dataset.bsTheme === 'dark' ? 'light' : 'dark';
    });
});
