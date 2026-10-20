/**
 * WiseEditor 示例页脚本 · 富文本（index.html）
 *
 * 仅演示「页面侧怎么用」，不参与框架运行时。
 * 两条读取路径都可用，按场景选：
 *   - 表单提交 → 读隐藏域（#editor_content_input），后端拿到的就是它；
 *   - 前端联动/校验 → 读实例 API（getContent / setContent / isDirty）。
 *
 * window.WiseEditor 由 wiseeditor-init.js 挂载，暴露：
 *   init / mount / get / destroy / destroyAll / registerAdapter / adapters / instances
 */

function dump() {
    const out = document.getElementById('out');
    const rich = window.WiseEditor && window.WiseEditor.get('editor_content');
    const ro = window.WiseEditor && window.WiseEditor.get('editor_ro');

    out.textContent = JSON.stringify({
        engine: rich ? rich.engine : '(未初始化)',
        tiptapVersion: window.TipTap ? window.TipTap.version : '(vendor bundle 未加载)',
        '实例内容 getContent()': rich ? rich.getContent() : null,
        '字段值 #editor_content_input': document.getElementById('editor_content_input').value,
        '只读实例已挂载': !!ro,
        '只读实例可编辑': ro ? ro.getInternal().editor.isEditable : null,
    }, null, 2);
}

document.addEventListener('DOMContentLoaded', () => {
    // 等挂载完成再首次输出；失败也不影响页面（内容以字段值为准）
    setTimeout(dump, 300);

    document.getElementById('btnShow').addEventListener('click', dump);

    document.getElementById('demoForm').addEventListener('submit', (e) => {
        e.preventDefault();
        dump();
    });

    // 暗色 / 亮色：用于核对 ProseMirror、表格、占位符在两种主题下的可读性
    const html = document.documentElement;
    document.getElementById('btnTheme').addEventListener('click', () => {
        html.dataset.bsTheme = html.dataset.bsTheme === 'dark' ? 'light' : 'dark';
    });

    // 销毁富文本：验证 destroy() 的清理与内存回收路径
    document.getElementById('btnDestroy').addEventListener('click', () => {
        if (!window.WiseEditor) return;
        window.WiseEditor.destroy('editor_content');
        dump();
    });
});
