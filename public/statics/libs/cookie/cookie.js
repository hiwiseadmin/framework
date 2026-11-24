/**
 * Cookie — Cookie 读写工具（ESM 模块）
 *
 * 位置：public/statics/libs/cookie/cookie.js
 * 加载：随 wise-bundle.js 打包（wise-bootstrap.js 顶部静态 import），页面模板不再引入。
 *
 * ── 本文件是仓库内唯一的 Cookie 实现 ────────────────────────────────
 * 合并了此前散落在四处的写法，只保留各自真正有价值的部分：
 *   1. 原 statics/js/cookie/cookie.js（已删除）—— options 化（path/domain/secure/sameSite）、
 *      天/时/分三种时长单位、JSON 读写、会话/持久化语义、批量读取与清空。
 *      （原实现有 5 个方法引用了从未定义的 CookieManager / defaultOptions，
 *        调用即 ReferenceError；setJSON 里调用的 setWithSizeCheck 也不存在。均已修正）
 *   2. wise-util.js 的 utils.cookie —— 写入必 encodeURIComponent、读取必解码。
 *   3. wise-theme.js —— 用 max-age 而非 expires（不受客户端时钟偏差影响）、
 *      secure 按当前协议自动判定、以及在 Safari/iframe 下 document.cookie 可能直接抛错，
 *      必须整体 try/catch（本文件所有 document.cookie 访问都做了）。
 *   4. wise-framework.js / agent-chat.js 的 token 读取 —— 键名不拼 RegExp
 *      （名字里带 . * + 等元字符会静默匹配失败），本文件改为切分字符串查找，天然免疫。
 *
 * ── 关键约定（踩过的坑，勿改） ────────────────────────────────────
 * · 写入一律 encodeURIComponent，读取一律「能解码就解码，不能就返回原值」。
 *   服务端或历史代码可能写入未编码的值（如主题 dark），解码必须可失败降级，绝不能抛。
 * · 删除必须带与原写入一致的 path/domain，否则浏览器视作新 Cookie，删不掉。
 * · 单条 Cookie 超过 4096 字节会被浏览器整条静默丢弃，故写入前校验并返回 false（不装作成功）。
 * · SameSite=None 必须搭配 Secure，否则浏览器直接拒收；这里自动补 Secure 并告警。
 * · document.cookie 可能抛错（httpOnly、Safari ITP、sandbox iframe），工具方法自己不能抛。
 *
 * ── 兼容 ──────────────────────────────────────────────────────────
 * 模块求值末尾仍挂 window.Cookie，admin/*.js 等存量代码零改动。
 * 独立静态页：import Cookie from '/statics/libs/cookie/cookie.js';
 *
 * @module libs/cookie
 */

/** 单条 Cookie 的上限字节数（RFC 6265 事实标准，超出浏览器整条丢弃） */
const MAX_BYTES = 4096;

function _warn(...args) {
    try {
        console.warn('[Cookie]', ...args);
    } catch (_) {
        // 某些环境 console 不可用，忽略
    }
}

/** 读取 document.cookie 原文；不可用时返回空串（不抛） */
function _read() {
    try {
        if (typeof document === 'undefined' || typeof document.cookie !== 'string') return '';
        return document.cookie;
    } catch (e) {
        _warn('读取 Cookie 失败（可能被浏览器策略阻断）', e && e.message);
        return '';
    }
}

/** 写入一条 Set-Cookie 字符串；失败返回 false（不抛） */
function _write(str) {
    try {
        if (typeof document === 'undefined') return false;
        document.cookie = str;
        return true;
    } catch (e) {
        _warn('写入 Cookie 失败（可能被浏览器策略阻断）', e && e.message);
        return false;
    }
}

function _encode(value) {
    try {
        return encodeURIComponent(value);
    } catch (_) {
        return String(value);
    }
}

/** 宽容解码：值里没有 % 或解码失败都原样返回，绝不抛 */
function _decode(value) {
    if (!value || value.indexOf('%') === -1) return value;
    try {
        return decodeURIComponent(value);
    } catch (_) {
        return value;
    }
}

/** 当前页面是否安全上下文（https）—— secure: 'auto' 的判定依据 */
function _isSecurePage() {
    try {
        return typeof window !== 'undefined'
            && window.location
            && window.location.protocol === 'https:';
    } catch (_) {
        return false;
    }
}

/**
 * 解析 document.cookie 原文为普通对象（纯函数，便于复用与测试）
 *
 * 只按第一个 '=' 切分，故值里含 '=' 不会丢失；键名做宽容解码后比较，
 * 不用正则拼接键名（键名里的元字符不会再导致匹配失败）。
 * 同名 Cookie 因 path/domain 不同可能存在多条，浏览器按 path 由具体到宽泛排列，
 * 这里保留第一条（即最具体的那条），与 JS 侧可见行为一致。
 *
 * @param {string} cookieString
 * @returns {Object<string, string>}
 */
function parse(cookieString) {
    const out = {};

    if (!cookieString) return out;

    String(cookieString).split(';').forEach((pair) => {
        if (!pair || !pair.trim()) return;

        const idx = pair.indexOf('=');
        const rawName = (idx === -1 ? pair : pair.slice(0, idx)).trim();
        const rawValue = idx === -1 ? '' : pair.slice(idx + 1).trim();
        if (!rawName) return;

        const name = _decode(rawName);
        if (!(name in out)) out[name] = _decode(rawValue);
    });

    return out;
}

/**
 * 合并默认项并解析 secure / SameSite
 * @param {Object} options
 * @returns {Object}
 */
function _resolve(options) {
    const defaults = Cookie.defaults;
    const opt = Object.assign({
        path: defaults.path,
        domain: defaults.domain,
        sameSite: defaults.sameSite,
        secure: defaults.secure,
    }, options || {});

    if (opt.secure === 'auto') opt.secure = _isSecurePage();

    const sameSite = opt.sameSite === null || opt.sameSite === undefined ? '' : String(opt.sameSite);
    if (sameSite.toLowerCase() === 'none' && !opt.secure) {
        // 浏览器会直接丢弃 None 且无 Secure 的 Cookie，这里纠正而不是静默失败
        opt.secure = true;
        _warn('SameSite=None 必须搭配 Secure，已自动补上 secure');
    }

    return opt;
}

/**
 * 计算有效期部分
 *
 * 优先级：expires(Date) > maxAge(秒) > days/hours/minutes > 无（会话 Cookie）
 * maxAge 用相对时间，不受客户端时钟偏差影响，是更可靠的写法。
 *
 * @param {Object} opt
 * @returns {{maxAge:number}|{expires:Date}|null}
 */
function _lifeSpan(opt) {
    if (opt.expires instanceof Date) return { expires: opt.expires };

    if (opt.maxAge !== undefined && opt.maxAge !== null && opt.maxAge !== '') {
        const maxAge = Math.floor(Number(opt.maxAge));
        if (!isFinite(maxAge)) return { maxAge: NaN };
        return { maxAge };
    }

    const days = Number(opt.days) || 0;
    const hours = Number(opt.hours) || 0;
    const minutes = Number(opt.minutes) || 0;
    if (!days && !hours && !minutes) return null; // 会话 Cookie

    const expires = new Date();
    if (days) expires.setDate(expires.getDate() + days);
    if (hours) expires.setHours(expires.getHours() + hours);
    if (minutes) expires.setMinutes(expires.getMinutes() + minutes);

    return { expires };
}

const Cookie = {
    /** 默认项；secure: 'auto' 表示按当前协议自动判定 */
    defaults: {
        path: '/',
        domain: '',
        sameSite: 'Lax',
        secure: 'auto',
    },

    /** 单条 Cookie 的字节上限 */
    MAX_BYTES,

    /** 解析 document.cookie / Set-Cookie 字符串（纯函数） */
    parse,

    /**
     * 读取 Cookie
     * @param {string} name
     * @param {*} [defaultValue=null] 不存在时的返回值
     * @returns {string|null|*}
     */
    get(name, defaultValue = null) {
        if (!name) return defaultValue;
        const jar = parse(_read());
        return Object.prototype.hasOwnProperty.call(jar, name) ? jar[name] : defaultValue;
    },

    /**
     * 是否存在该 Cookie（值为空串也算存在）
     * @param {string} name
     * @returns {boolean}
     */
    has(name) {
        return this.get(name) !== null;
    },

    /**
     * 写入 Cookie
     * @param {string} name
     * @param {*} value 非字符串会被 String() 转换后编码
     * @param {Object} [options]
     * @param {number} [options.days] 过期天数
     * @param {number} [options.hours] 过期小时数
     * @param {number} [options.minutes] 过期分钟数
     * @param {number} [options.maxAge] 过期秒数（优先于 days/hours/minutes）
     * @param {Date}   [options.expires] 绝对过期时间（优先于 maxAge）
     * @param {string} [options.path] 默认 '/'
     * @param {string} [options.domain]
     * @param {boolean|'auto'} [options.secure]
     * @param {string} [options.sameSite] 'Lax' | 'Strict' | 'None'
     * @returns {boolean} 是否成功下发（超限/被策略阻断时 false）
     */
    set(name, value, options = {}) {
        if (name === undefined || name === null || name === '') {
            _warn('Cookie 名不能为空');
            return false;
        }

        const opt = _resolve(options);
        const stringValue = value === undefined || value === null ? '' : String(value);

        let str = _encode(name) + '=' + _encode(stringValue);

        const span = _lifeSpan(opt);
        if (span) {
            if (span.maxAge !== undefined) {
                if (isNaN(span.maxAge)) {
                    _warn('maxAge 不是合法数字，已忽略该 Cookie', options.maxAge);
                    return false;
                }
                str += '; max-age=' + span.maxAge;
            } else {
                str += '; expires=' + span.expires.toUTCString();
            }
        }

        if (opt.path) str += '; path=' + opt.path;
        if (opt.domain) str += '; domain=' + opt.domain;
        if (opt.secure) str += '; secure';
        if (opt.sameSite) str += '; samesite=' + opt.sameSite;

        // 值已整体 encodeURIComponent，故 length 即字节数
        if (str.length > MAX_BYTES) {
            _warn(`Cookie "${name}" 共 ${str.length} 字节，超过 ${MAX_BYTES} 上限，浏览器会整条丢弃，已拒绝写入`);
            return false;
        }

        return _write(str);
    },

    /**
     * 删除 Cookie
     *
     * 必须带与原写入一致的 path/domain，否则删不掉（本方法默认使用 defaults.path）。
     *
     * @param {string} name
     * @param {Object} [options] 仅 path/domain/secure/sameSite 有意义
     * @returns {boolean}
     */
    remove(name, options = {}) {
        return this.set(name, '', Object.assign({}, options, { maxAge: 0 }));
    },

    /** remove 的别名（保留原 API 名） */
    delete(name, options = {}) {
        return this.remove(name, options);
    },

    /**
     * 读取全部可见 Cookie
     * @returns {Object<string, string>}
     */
    getAll() {
        return parse(_read());
    },

    /**
     * 清空全部可见 Cookie（仅限当前 path/domain 可见的那些）
     * @param {Object} [options]
     */
    clearAll(options = {}) {
        Object.keys(this.getAll()).forEach((name) => this.remove(name, options));
    },

    /**
     * 以 JSON 形式写入（对象/数组）
     * @param {string} name
     * @param {*} data
     * @param {Object} [options]
     * @returns {boolean}
     */
    setJSON(name, data, options = {}) {
        let json;
        try {
            json = JSON.stringify(data);
        } catch (e) {
            _warn('无法序列化 JSON 数据', e && e.message);
            return false;
        }
        return this.set(name, json, options);
    },

    /**
     * 读取 JSON 形式的 Cookie
     * @param {string} name
     * @param {*} [defaultValue=null] 不存在或解析失败时的返回值
     * @returns {*}
     */
    getJSON(name, defaultValue = null) {
        const raw = this.get(name);
        if (raw === null || raw === '') return defaultValue;
        try {
            return JSON.parse(raw);
        } catch (e) {
            _warn(`Cookie "${name}" 不是合法 JSON，已返回默认值`, e && e.message);
            return defaultValue;
        }
    },

    /**
     * 写入会话 Cookie（浏览器关闭即失效）
     * @param {string} name
     * @param {*} value
     * @param {Object} [options]
     * @returns {boolean}
     */
    setSession(name, value, options = {}) {
        // 显式抹掉所有过期相关字段，避免调用方从别处透传上来
        const opt = Object.assign({}, options);
        delete opt.days;
        delete opt.hours;
        delete opt.minutes;
        delete opt.maxAge;
        delete opt.expires;
        return this.set(name, value, opt);
    },

    /**
     * 写入长期 Cookie
     * @param {string} name
     * @param {*} value
     * @param {number} [days=365]
     * @param {Object} [options]
     * @returns {boolean}
     */
    setPersistent(name, value, days = 365, options = {}) {
        return this.set(name, value, Object.assign({}, options, { days }));
    },
};

// 兼容挂载点：admin/*.js（login.js / index.js / plugin.js 等）仍以 window.Cookie 使用。
if (typeof window !== 'undefined') {
    window.Cookie = Cookie;
}

// 旧实现把默认项挂在 defaultOptions 上（但方法内部误用了裸变量，实际不可用）。
// 保留同名只读别名，避免存量代码读 Cookie.defaultOptions 时拿到 undefined。
if (!Object.prototype.hasOwnProperty.call(Cookie, 'defaultOptions')) {
    Object.defineProperty(Cookie, 'defaultOptions', {
        get() { return Cookie.defaults; },
        set(value) { Cookie.defaults = value; },
        enumerable: false,
        configurable: true,
    });
}

export default Cookie;
export { Cookie, parse };
