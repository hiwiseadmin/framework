/**
 * WiseEditor 示例页脚本 · 实例 API（api.html）
 *
 * 逐个调用 EditorInstance 契约上的方法并把结果打到页面上，
 * 用于回答「前端要联动/校验时该怎么读编辑器」。
 */

const out = document.getElementById('out');
const log = [];

function show(title, detail) {
    log.push(title + (detail === undefined ? '' : '\n    ' + detail));
    out.textContent = log.join('\n');
}

/** EditorInstance 契约上的方法；实例不存在时返回 null */
function inst() {
    return (window.WiseEditor && window.WiseEditor.get('editor_content')) || null;
}

function guarded(label, fn) {
    const it = inst();
    if (!it) {
        show(label, '✗ 实例不存在（可能已被 destroy，请刷新页面）');
        return;
    }
    try {
        show(label, '✓ ' + JSON.stringify(fn(it)));
    } catch (e) {
        show(label, '✗ ' + e.message);
    }
}

document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('btnGet').addEventListener('click', () => {
        guarded('getContent()', (it) => it.getContent());
    });

    document.getElementById('btnSet').addEventListener('click', () => {
        guarded('setContent()', (it) => {
            it.setContent('<h3>由 setContent 写入</h3>'
                + '<table><tbody><tr><td><p>A</p></td><td><p>B</p></td></tr></tbody></table>');
            return '已写入，内容长度 = ' + it.getContent().length;
        });
    });

    document.getElementById('btnDirty').addEventListener('click', () => {
        guarded('isDirty()', (it) => it.isDirty());
    });

    document.getElementById('btnFocus').addEventListener('click', () => {
        guarded('focus()', (it) => {
            it.focus();
            return '已聚焦';
        });
    });

    document.getElementById('btnDestroy').addEventListener('click', () => {
        guarded('destroy()', (it) => {
            it.destroy();
            return 'WiseEditor.get() 现在返回 = ' + String(window.WiseEditor.get('editor_content'));
        });
    });

    // 首次进入时把就绪状态与引擎信息打出来
    const timer = setInterval(() => {
        const it = inst();
        if (!it) return;
        clearInterval(timer);
        show('实例就绪', JSON.stringify({
            engine: it.engine,
            version: it.version,
            tiptapVersion: window.TipTap ? window.TipTap.version : null,
        }));
    }, 100);
    setTimeout(() => clearInterval(timer), 10000);
});
