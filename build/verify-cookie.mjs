/**
 * Cookie 工具自检（npm run verify:cookie）
 *
 * 在 Node 里用一个「可控的假 Cookie 罐」跑真实语义，覆盖：
 *   编码/解码、过期策略（days/hours/minutes/maxAge/expires）、删除与 path 陷阱、
 *   JSON 往返、超长拒绝、SameSite=None 强制 Secure、受限环境不抛错、键名元字符与前缀误判。
 *
 * 假罐刻意复刻了几条关键浏览器行为（这些正是真实踩过的坑）：
 *   · 单条 > 4096 字节整条丢弃；
 *   · SameSite=None 且无 Secure 直接拒收；
 *   · max-age<=0 / expires 已过期 → 删除；
 *   · Secure Cookie 在 http 页面不可见；
 *   · document.cookie 按 path 由具体到宽泛排序（同名时第一条胜出）。
 *   · 简化项：不做 path/domain 的可见性匹配（get 能看到所有已存条目），
 *     故「remove 带错 path 删不掉」这一条测的是键空间语义而非可见性。
 *
 * 用法：node build/verify-cookie.mjs
 */

import assert from 'node:assert/strict';

const MAX_BYTES = 4096;
const DAY = 86400000;
const HOUR = 3600000;
const MINUTE = 60000;
/** 时间断言容差：避免 CI 抖动 */
const SLACK = 5 * MINUTE;

// ============================================================
// 假 Cookie 罐
// ============================================================
function createEnv({ https = true, blocked = false } = {}) {
    const jar = new Map();
    const rejected = [];

    const documentStub = {
        get cookie() {
            if (blocked) throw new Error('SecurityError: cookie access denied');

            const now = Date.now();

            return [...jar.values()]
                .filter((c) => c.expiresAt === null || c.expiresAt > now)
                .filter((c) => https || !c.attrs.secure)
                .sort((a, b) => b.path.length - a.path.length)
                .map((c) => `${c.name}=${c.value}`)
                .join('; ');
        },

        set cookie(str) {
            if (blocked) throw new Error('SecurityError: cookie access denied');

            const [pair, ...attrParts] = String(str).split(';');
            const eq = pair.indexOf('=');
            const name = (eq === -1 ? pair : pair.slice(0, eq)).trim();
            const value = eq === -1 ? '' : pair.slice(eq + 1);

            const attrs = {};
            for (const part of attrParts) {
                const i = part.indexOf('=');
                const key = (i === -1 ? part : part.slice(0, i)).trim().toLowerCase();
                if (!key) continue;
                attrs[key] = i === -1 ? true : part.slice(i + 1).trim();
            }

            if (value.length > MAX_BYTES) {
                rejected.push('too-large');
                return;
            }
            if (String(attrs.samesite || '').toLowerCase() === 'none' && !attrs.secure) {
                rejected.push('samesite-none-without-secure');
                return;
            }

            const path = attrs.path || '/';
            const domain = String(attrs.domain || '').toLowerCase();
            const key = `${name};${path};${domain}`;

            let expiresAt = null;
            if (attrs['max-age'] !== undefined) {
                expiresAt = Date.now() + Number(attrs['max-age']) * 1000;
            } else if (attrs.expires !== undefined) {
                expiresAt = Date.parse(attrs.expires);
            }

            if (expiresAt !== null && !(expiresAt > Date.now())) {
                jar.delete(key);
                return;
            }

            jar.set(key, { name, value, path, domain, attrs, expiresAt });
        },
    };

    return {
        document: documentStub,
        window: { location: { protocol: https ? 'https:' : 'http:' } },
        get entries() {
            return [...jar.values()];
        },
        find(name) {
            return [...jar.values()].find((c) => c.name === name) || null;
        },
        seed(name, value, { path = '/', secure = false } = {}) {
            jar.set(`${name};${path};`, {
                name,
                value,
                path,
                domain: '',
                attrs: secure ? { path, secure: true } : { path },
                expiresAt: null,
            });
        },
        rejected,
    };
}

// ============================================================
// 被测模块
// ============================================================
const { default: Cookie, parse } = await import('../public/statics/libs/cookie/cookie.js');

/** 每个用例开始前重置全局环境与默认项 */
function useEnv(options) {
    const env = createEnv(options);
    globalThis.document = env.document;
    globalThis.window = env.window;
    Cookie.defaults = { path: '/', domain: '', sameSite: 'Lax', secure: 'auto' };
    return env;
}

/** 捕获 console.warn，避免测试输出被告警刷屏，同时断言"是否告警" */
function captureWarn(fn) {
    const original = console.warn;
    const calls = [];
    console.warn = (...args) => calls.push(args.join(' '));
    try {
        return { result: fn(), calls };
    } finally {
        console.warn = original;
    }
}

function near(actual, expected, slack = SLACK) {
    assert.ok(
        typeof actual === 'number' && Math.abs(actual - expected) <= slack,
        `时间偏差过大：actual=${actual} expected≈${expected} slack=${slack}`,
    );
}

// ============================================================
// 用例
// ============================================================
const cases = [];
const test = (name, fn) => cases.push([name, fn]);

test('常量与默认项符合约定', () => {
    useEnv();
    assert.equal(Cookie.MAX_BYTES, MAX_BYTES);
    assert.deepEqual(Cookie.defaults, { path: '/', domain: '', sameSite: 'Lax', secure: 'auto' });
    // 旧实现的公开属性名保留为别名
    assert.equal(Cookie.defaultOptions, Cookie.defaults);
    assert.equal(Cookie.defaultOptions.path, '/');
});

test('写入后可原样读回（空格/分号/等号/中文/%）', () => {
    const env = useEnv();
    const value = 'a b;c=d 中文 & # 100%';

    assert.equal(Cookie.set('token', value), true);
    assert.equal(Cookie.get('token'), value);

    // 落库的值必须是编码后的（原有 utils.cookie 的优良做法）
    const stored = env.find('token');
    assert.notEqual(stored.value, value);
    assert.equal(stored.value, encodeURIComponent(value));
});

test('默认属性：path=/、SameSite=Lax、Secure 按协议自动判定', () => {
    const secureEnv = useEnv({ https: true });
    Cookie.set('a', '1');
    const onHttps = secureEnv.find('a');
    assert.equal(onHttps.path, '/');
    assert.equal(String(onHttps.attrs.samesite).toLowerCase(), 'lax');
    assert.equal(onHttps.attrs.secure, true);

    const plainEnv = useEnv({ https: false });
    Cookie.set('b', '1');
    assert.equal(plainEnv.find('b').attrs.secure, undefined);
});

test('中文 Cookie 名可写可读', () => {
    useEnv();
    assert.equal(Cookie.set('主题', 'dark'), true);
    assert.equal(Cookie.get('主题'), 'dark');
    assert.equal(Cookie.has('主题'), true);
});

test('不存在时返回默认值', () => {
    useEnv();
    assert.equal(Cookie.get('nope'), null);
    assert.equal(Cookie.get('nope', 'fallback'), 'fallback');
    assert.equal(Cookie.has('nope'), false);
});

test('键名前缀不误判（get("oo") 不能命中 foo）', () => {
    useEnv();
    Cookie.set('foo', '1');
    assert.equal(Cookie.get('oo'), null);
    assert.equal(Cookie.get('fo'), null);
    assert.equal(Cookie.get('foo'), '1');
});

test('未编码的历史值（服务端/旧代码直写 raw）可读', () => {
    const env = useEnv();
    env.seed('theme', 'dark');
    assert.equal(Cookie.get('theme'), 'dark');
});

test('损坏的百分号值不抛错、原样返回', () => {
    const env = useEnv();
    env.seed('broken', '%E0%A4%A');
    const { result, calls } = captureWarn(() => Cookie.get('broken'));
    assert.equal(result, '%E0%A4%A');
    assert.equal(calls.length, 0, '宽容解码不应告警');
});

test('值里含 = 不丢内容', () => {
    useEnv();
    Cookie.set('jwt', 'a=b=c');
    assert.equal(Cookie.get('jwt'), 'a=b=c');
    assert.equal(Cookie.getAll().jwt, 'a=b=c');
});

test('days / hours / minutes 换算正确', () => {
    const env = useEnv();

    Cookie.set('d', '1', { days: 7 });
    near(env.find('d').expiresAt, Date.now() + 7 * DAY);

    Cookie.set('h', '1', { hours: 3 });
    near(env.find('h').expiresAt, Date.now() + 3 * HOUR);

    Cookie.set('m', '1', { minutes: 30 });
    near(env.find('m').expiresAt, Date.now() + 30 * MINUTE);
});

test('maxAge 优先于 days，且用相对时间', () => {
    const env = useEnv();
    Cookie.set('m', '1', { days: 7, maxAge: 60 });
    const c = env.find('m');
    assert.equal(c.attrs['max-age'], '60');
    assert.equal(c.attrs.expires, undefined);
    near(c.expiresAt, Date.now() + 60 * 1000);
});

test('maxAge 非法值拒绝写入并告警', () => {
    const env = useEnv();
    const { result, calls } = captureWarn(() => Cookie.set('bad', '1', { maxAge: 'abc' }));
    assert.equal(result, false);
    assert.equal(env.find('bad'), null);
    assert.ok(calls.length > 0);
});

test('会话 Cookie 不带任何过期属性（即使调用方透传了 days）', () => {
    const env = useEnv();
    Cookie.setSession('s1', '1');
    assert.equal(env.find('s1').attrs.expires, undefined);
    assert.equal(env.find('s1').attrs['max-age'], undefined);
    assert.equal(env.find('s1').expiresAt, null);

    Cookie.setSession('s2', '1', { days: 7, maxAge: 100 });
    assert.equal(env.find('s2').expiresAt, null);
});

test('setPersistent 默认 365 天', () => {
    const env = useEnv();
    Cookie.setPersistent('p', '1');
    near(env.find('p').expiresAt, Date.now() + 365 * DAY, DAY);
});

test('delete 能删掉（默认 path=/）', () => {
    const env = useEnv();
    Cookie.set('x', '1');
    assert.equal(Cookie.delete('x'), true);
    assert.equal(Cookie.get('x'), null);
    assert.deepEqual(env.entries, []);
});

test('remove 带错 path 删不掉（path 必须与原写入一致）', () => {
    const env = useEnv();
    Cookie.set('x', '1', { path: '/admin' });
    Cookie.remove('x'); // 默认 path=/
    assert.equal(Cookie.get('x'), '1', '路径不一致时不该删除成功');
    assert.equal(env.entries.length, 1);

    Cookie.remove('x', { path: '/admin' });
    assert.equal(Cookie.get('x'), null);
});

test('JSON 往返（嵌套 + 中文 + 数字）', () => {
    useEnv();
    const data = { dark: true, level: 3, name: '主题', list: [1, 2, { k: 'v' }] };
    assert.equal(Cookie.setJSON('pref', data, { days: 1 }), true);
    assert.deepEqual(Cookie.getJSON('pref'), data);
});

test('JSON 解析失败回退默认值 + 告警', () => {
    const env = useEnv();
    env.seed('pref', 'not-json');
    const { result, calls } = captureWarn(() => Cookie.getJSON('pref', { fallback: true }));
    assert.deepEqual(result, { fallback: true });
    assert.ok(calls.length > 0);
    assert.equal(Cookie.getJSON('missing', 'dflt'), 'dflt');
});

test('超长值拒绝写入并告警，不留"写成功"假象', () => {
    const env = useEnv();
    const { result, calls } = captureWarn(() => Cookie.set('big', 'x'.repeat(MAX_BYTES)));
    assert.equal(result, false);
    assert.equal(env.find('big'), null);
    assert.equal(env.rejected.length, 0, '应该在写入前就被拦住，不依赖浏览器丢弃');
    assert.ok(calls.join(' ').includes('4096'));
});

test('SameSite=None 自动补 Secure（否则浏览器直接拒收）', () => {
    // https 下 secure:'auto' 本就为 true，无需补，也不该告警
    const secureEnv = useEnv({ https: true });
    const httpsRun = captureWarn(() => Cookie.set('n1', '1', { sameSite: 'None' }));
    assert.equal(httpsRun.result, true);
    assert.equal(secureEnv.find('n1').attrs.secure, true);
    assert.deepEqual(secureEnv.rejected, []);
    assert.equal(httpsRun.calls.length, 0, 'https 下 secure 本就成立，不该告警');

    // http 下 secure:'auto' 为 false，必须补上 Secure 并告警，否则浏览器拒收
    const plainEnv = useEnv({ https: false });
    const httpRun = captureWarn(() => Cookie.set('n2', '1', { sameSite: 'None' }));
    assert.equal(httpRun.result, true);
    assert.equal(plainEnv.find('n2').attrs.secure, true, '应被强制补上 Secure');
    assert.deepEqual(plainEnv.rejected, []);
    assert.ok(httpRun.calls.length > 0, '补 Secure 时应告警');

    // 对照组：绕过封装直接写，假罐按浏览器行为拒收
    plainEnv.document.cookie = 'raw=1; path=/; samesite=None';
    assert.deepEqual(plainEnv.rejected, ['samesite-none-without-secure']);
});

test('受限环境（document.cookie 抛错）不向调用方抛', () => {
    useEnv({ blocked: true });
    const { calls } = captureWarn(() => {
        assert.equal(Cookie.get('a'), null);
        assert.equal(Cookie.get('a', 'd'), 'd');
        assert.equal(Cookie.has('a'), false);
        assert.equal(Cookie.set('a', '1'), false);
        assert.deepEqual(Cookie.getAll(), {});
        Cookie.clearAll();
    });
    assert.ok(calls.length > 0, '被阻断时应有告警');
});

test('无 document 环境（Node/SSR）不抛', () => {
    useEnv();
    const saved = globalThis.document;
    delete globalThis.document;
    try {
        assert.equal(Cookie.get('a'), null);
        assert.equal(Cookie.set('a', '1'), false);
        assert.deepEqual(Cookie.getAll(), {});
        assert.deepEqual(parse(''), {});
    } finally {
        globalThis.document = saved;
    }
});

test('clearAll 清空当前可见的全部 Cookie', () => {
    const env = useEnv();
    Cookie.set('a', '1');
    Cookie.set('b', '2');
    Cookie.set('c', '3');
    assert.equal(Object.keys(Cookie.getAll()).length, 3);
    Cookie.clearAll();
    assert.deepEqual(env.entries, []);
});

test('同名不同 path 时取最具体的一条', () => {
    useEnv();
    // 浏览器按 path 由具体到宽泛排列：先 /admin，再 /
    const seedEnv = createEnv();
    seedEnv.seed('k', 'root', { path: '/' });
    seedEnv.seed('k', 'admin', { path: '/admin' });
    assert.equal(parse(seedEnv.document.cookie).k, 'admin');
});

test('parse 是纯函数且容错', () => {
    assert.deepEqual(parse('a=1; b=2'), { a: '1', b: '2' });
    assert.deepEqual(parse(''), {});
    assert.deepEqual(parse(undefined), {});
    assert.deepEqual(parse('   '), {});
    assert.deepEqual(parse('lonely'), { lonely: '' });
    assert.deepEqual(parse('empty=;'), { empty: '' });
});

// ============================================================
// 执行
// ============================================================
let failed = 0;
for (const [name, fn] of cases) {
    try {
        fn();
        console.log(`  \u2713 ${name}`);
    } catch (error) {
        failed++;
        console.log(`  \u2717 ${name}`);
        console.log(`      ${(error && error.message) || error}`);
    }
}

console.log('');
if (failed === 0) {
    console.log(`[verify:cookie] 全部通过（${cases.length} 项）`);
} else {
    console.log(`[verify:cookie] 失败 ${failed} / ${cases.length} 项`);
    process.exit(1);
}
