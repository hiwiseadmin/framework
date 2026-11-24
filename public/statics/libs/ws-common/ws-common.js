/**
 * ws-common — ws-* 组件共用的基础设施
 *
 * ⚠️ 这不是封装层，也不是表单适配器。
 *
 * 定位：把 7 个 ws-* 组件**都必须做**的重复动作（读 data-* / 建隐藏值载体 /
 * 派发变更事件 / 远程取选项 / 归一化选项结构 / 扫描初始化）收敛成一份工具函数，
 * 由组件自己 `import` 并使用。组件的对外用法不变，模板作者只会看到 `data-*`。
 *
 * 为什么要有它：`ws-date-picker` 的 `autoInit` 用 `$el.data()` 全量 dump，
 * 导致 `data-id`/`data-index` 这类业务属性被当成配置传进组件 —— 这是"每个组件
 * 各写各的解析"必然产生的结果。统一到这里之后，白名单只有一份。
 *
 * 设计约束：
 * - **零框架依赖**：不 import jQuery / Bootstrap，只用原生 DOM + fetch。
 *   （组件内部该用 jQuery 还用它，本文件不改变这点）
 * - **零业务语义**：不涉及任何具体组件的字段含义，只做「取 → 转 → 放」。
 * - **可直连**：`demo.html` 这类不走打包的场景能直接 `import` 使用。
 *
 * @module ws-common
 */

/* ============================================================
 * 1. data-* 解析
 * ============================================================ */

/**
 * 值类型（决定 data-* 字符串如何被消费）
 * @enum {string}
 */
export const T = {
    RAW: 'raw',   // 原样字符串
    BOOL: 'bool', // '' / 'true' / '1' / 'yes' → true
    NUM: 'num',   // Number()
    JSON: 'json', // JSON.parse，失败时回退为原字符串（兼容 data-value="12"）
    FN: 'fn',     // 按函数名从 window 取，取不到为 null
};

/** 驼峰 key → DOM 属性名（`pageSize` → `data-page-size`） */
export function attrName(key) {
    return 'data-' + String(key).replace(/[A-Z]/g, (c) => '-' + c.toLowerCase());
}

function coerce(raw, type) {
    if (raw === null || raw === undefined) return undefined;

    switch (type) {
        case T.BOOL:
            return raw === '' || /^(true|1|yes|on)$/i.test(String(raw).trim());

        case T.NUM: {
            const n = parseFloat(raw);
            return isNaN(n) ? undefined : n;
        }

        case T.JSON:
            if (typeof raw !== 'string') return raw;
            try {
                return JSON.parse(raw);
            } catch (e) {
                // data-value="12" / "abc" 这类非 JSON 字面量按字符串处理
                return raw;
            }

        case T.FN: {
            const fn = window[raw];
            return typeof fn === 'function' ? fn : null;
        }

        default:
            return raw;
    }
}

/**
 * 按白名单解析元素的 data-* 配置。
 *
 * 与 `$el.data()` 全量 dump 的区别：**只取白名单内的键**，业务自定义属性
 * （`data-id`、`data-index`、`data-role`…）不会被误当成组件配置。
 *
 * @param {HTMLElement} el
 * @param {Object<string,string>} schema `{ 配置键: T.RAW|T.BOOL|T.NUM|T.JSON|T.FN }`
 * @returns {Object} 仅包含元素上真实存在的属性
 */
export function parseDataConfig(el, schema) {
    const out = {};
    if (!el || !el.getAttribute) return out;

    for (const key of Object.keys(schema)) {
        const attr = attrName(key);
        if (!el.hasAttribute(attr)) continue;
        const val = coerce(el.getAttribute(attr), schema[key]);
        if (val !== undefined) out[key] = val;
    }

    return out;
}

/**
 * 按优先级取第一个存在的 data-*（用于别名，如 `url` / `remote-url`）。
 *
 * @param {HTMLElement} el
 * @param {string[]} keys 按优先级排列的配置键
 * @param {string} [type=T.RAW]
 * @returns {*} 第一个命中的值，全部缺失时返回 undefined
 */
export function pickData(el, keys, type = T.RAW) {
    if (!el || !el.getAttribute) return undefined;

    for (const key of keys) {
        const attr = attrName(key);
        if (el.hasAttribute(attr)) {
            return coerce(el.getAttribute(attr), type);
        }
    }

    return undefined;
}

/**
 * 把宿主上的校验属性复制到值载体上。
 *
 * ws-form-validator 按 `[name][data-validation]` 找规则，而真值在隐藏载体里，
 * 所以校验属性必须跟着载体走，不能只留在宿主上。
 *
 * @param {HTMLElement} host
 * @param {HTMLElement} carrier
 * @param {boolean} [consume=false] 复制后是否从宿主移除（避免同 name 双份规则）
 */
export function copyValidation(host, carrier, consume = false) {
    if (!host || !carrier || host === carrier) return;

    const toCopy = ['data-validation', 'data-required'];

    for (const attr of toCopy) {
        if (host.hasAttribute(attr)) {
            carrier.setAttribute(attr, host.getAttribute(attr));
            if (consume) host.removeAttribute(attr);
        }
    }

    // data-validation-message-* 数量不固定，按前缀遍历
    for (const attr of Array.from(host.attributes)) {
        if (attr.name.indexOf('data-validation-message-') === 0) {
            carrier.setAttribute(attr.name, attr.value);
            if (consume) host.removeAttribute(attr.name);
        }
    }
}

/* ============================================================
 * 2. 值载体（carrier）
 * ============================================================ */

export const CARRIER_ATTR = 'data-ws-carrier';
export const CARRIER_CLASS = 'ws-carrier';
export const CARRIER_SELECTOR = '[' + CARRIER_ATTR + ']';

const NATIVE_TAGS = ['INPUT', 'SELECT', 'TEXTAREA'];

/**
 * 判定宿主自身是否就是值载体（原生控件且已有 name）。
 *
 * ws-date-picker / ws-hint-input 属于这一类：组件只包装原生控件，
 * 原生控件的 `name` 天然参与 `serialize()`，不需要额外隐藏域。
 */
export function isNativeCarrier(host) {
    return !!host && NATIVE_TAGS.indexOf(host.tagName) !== -1 && !!host.getAttribute('name');
}

/**
 * 取或建值载体。幂等：宿主内已存在 `[data-ws-carrier]` 时直接复用。
 *
 * 约定（全组件统一）：
 * - 原生控件 → 宿主自己就是载体，标记 `data-ws-carrier="native"`
 * - 其余 → 宿主内 `input[type=hidden].ws-carrier`，标记 `data-ws-carrier="hidden"`
 * - 多值：`json=true` 时单个载体存 JSON；否则 `name[]` 多载体
 *
 * @param {HTMLElement} host
 * @param {Object} [spec]
 * @param {string} [spec.name]       提交字段名
 * @param {*}      [spec.value]      初始值
 * @param {boolean}[spec.multiple]   多值
 * @param {boolean}[spec.json]       多值用 JSON 单载体
 * @returns {HTMLElement|null}
 */
export function ensureCarrier(host, spec = {}) {
    if (!host) return null;

    const name = spec.name || host.getAttribute('data-name') || '';

    // 1) 原生控件：自己就是载体
    if (isNativeCarrier(host)) {
        host.setAttribute(CARRIER_ATTR, 'native');
        copyValidation(host, host);
        return host;
    }

    // 2) 复用已存在的载体（render() 后重复调用不产生第二个隐藏域）
    let carrier = host.querySelector(CARRIER_SELECTOR);

    if (!carrier) {
        if (!name) return null;

        carrier = document.createElement('input');
        carrier.type = 'hidden';
        carrier.className = CARRIER_CLASS;
        carrier.setAttribute(CARRIER_ATTR, 'hidden');
        carrier.setAttribute('name', spec.multiple && !spec.json ? name + '[]' : name);
        host.appendChild(carrier);
    }

    if (name) {
        carrier.setAttribute('name', spec.multiple && !spec.json ? name + '[]' : name);
    }

    // consume=true：校验属性**迁移**到载体，不从宿主保留一份。
    // 保留两份会让 ws-form-validator 扫到宿主 div 并按 name='' 做一次空跑校验，
    // 规则来源歧义且增加排查成本。
    copyValidation(host, carrier, true);

    if (spec.value !== undefined) {
        writeCarrier(carrier, spec.value, spec);
    }

    return carrier;
}

/**
 * 把值写入 carrier 并派发变更事件。**这是组件通知外界的唯一出口。**
 *
 * 派发顺序：
 * 1. carrier 上 `input` + `change`（bubbles）→ ws-form-validator 的实时校验靠这个
 * 2. host 上 `ws-field:change`（detail: `{name, value}`）→ 业务自定义监听
 *
 * @param {HTMLElement} carrier
 * @param {*} value
 * @param {Object} [spec]
 * @param {boolean} [spec.multiple]
 * @param {boolean} [spec.json]       多值序列化为 JSON 串
 * @param {string}  [spec.separator]  多值分隔符（默认 `,`）
 * @param {HTMLElement} [host]        事件目标，默认取 carrier.parentElement
 */
export function writeCarrier(carrier, value, spec = {}, host) {
    if (!carrier) return;

    const multiple = !!spec.multiple;
    const useJson = !!spec.json;
    let serialized = '';

    if (multiple) {
        const list = Array.isArray(value) ? value : (value === null || value === undefined || value === '' ? [] : [value]);
        serialized = useJson ? JSON.stringify(list) : list.join(spec.separator || ',');
    } else {
        serialized = value === null || value === undefined ? '' : String(value);
    }

    carrier.value = serialized;

    const target = host || carrier.parentElement || carrier;
    emitChange(target, {
        name: carrier.getAttribute('name') || '',
        value: multiple ? (Array.isArray(value) ? value : (serialized === '' ? [] : [value])) : serialized,
    });
}

/**
 * 触发变更事件（carrier 原生事件 + host 自定义事件）。
 * @param {HTMLElement} target 宿主
 * @param {{name:string, value:*}} detail
 */
export function emitChange(target, detail = {}) {
    if (!target) return;

    const carrier = target.querySelector ? target.querySelector(CARRIER_SELECTOR) : null;
    const fireOn = carrier && carrier !== target ? carrier : target;

    try {
        fireOn.dispatchEvent(new Event('input', { bubbles: true }));
        fireOn.dispatchEvent(new Event('change', { bubbles: true }));
    } catch (e) {
        // 极老环境兜底：用 jQuery 触发（组件本身依赖 jQuery）
        if (window.jQuery) window.jQuery(fireOn).trigger('input').trigger('change');
    }

    if (fireOn !== target) {
        target.dispatchEvent(new CustomEvent('ws-field:change', { bubbles: true, detail }));
    }
}

/**
 * 读取 carrier 当前值。
 * @param {HTMLElement} host
 * @param {Object} [spec] `{multiple, json, separator}`
 */
export function readCarrier(host, spec = {}) {
    if (!host) return spec.multiple ? [] : '';

    const carrier = isNativeCarrier(host) ? host : host.querySelector(CARRIER_SELECTOR);
    if (!carrier) return spec.multiple ? [] : '';

    const raw = carrier.value == null ? '' : String(carrier.value);

    if (!spec.multiple) return raw;

    if (spec.json) {
        try {
            const parsed = JSON.parse(raw || '[]');
            return Array.isArray(parsed) ? parsed : [];
        } catch (e) {
            return [];
        }
    }

    return raw === '' ? [] : raw.split(spec.separator || ',');
}

/** 移除宿主内的载体（destroy 用） */
export function removeCarrier(host) {
    if (!host) return;
    host.querySelectorAll(CARRIER_SELECTOR).forEach((el) => {
        if (el !== host) el.remove();
    });
}

/* ============================================================
 * 3. 选项归一化
 * ============================================================ */

/**
 * 点号路径取值（`data.rows` / `data.total`）。
 * @param {*} obj
 * @param {string} path
 */
export function getByPath(obj, path) {
    if (!obj || !path) return undefined;
    return String(path).split('.').reduce((cur, key) => (cur == null ? undefined : cur[key]), obj);
}

/**
 * 把任意形态的选项数据归一化为 `[{value, label, ...rest}]`。
 *
 * 支持三种入参（覆盖现有所有后端输出）：
 * 1. `[{id, name}]` + mapping → 按 mapping 取值
 * 2. `[{value, label}]` → 直接使用
 * 3. `{"1": "启用"}`（wisecms `normalizeOptions()` 的产物）→ 转数组
 *
 * @param {*} raw
 * @param {Object} [mapping] `{value:'id', label:'name', secondary:'code'}`
 * @returns {Array<Object>}
 */
export function normalizeOptions(raw, mapping = {}) {
    const valueField = mapping.value || 'value';
    const labelField = mapping.label || 'label';
    const secondaryField = mapping.secondary || 'secondary';

    if (!raw) return [];

    let list = raw;

    // `{k: v}` 映射表 → 数组
    if (!Array.isArray(list) && typeof list === 'object') {
        list = Object.keys(list).map((k) => ({ value: k, label: list[k] }));
        // 映射表形态已经规整过，不再套 mapping
        return list.map((item) => ({ value: String(item.value), label: String(item.label) }));
    }

    if (!Array.isArray(list)) return [];

    return list.map((item) => {
        if (item === null || item === undefined) return { value: '', label: '' };

        // 标量数组：['a','b'] → value=label='a'
        if (typeof item !== 'object') {
            return { value: String(item), label: String(item) };
        }

        const value = item[valueField] !== undefined ? item[valueField] : item.value;
        const label = item[labelField] !== undefined ? item[labelField] : (item.label !== undefined ? item.label : item.name);
        const out = {
            value: value === undefined || value === null ? '' : String(value),
            label: label === undefined || label === null ? '' : String(label),
        };

        if (item[secondaryField] !== undefined) out.secondary = item[secondaryField];
        if (item.disabled !== undefined) out.disabled = !!item.disabled;
        if (item.icon !== undefined) out.icon = item.icon;
        if (item.children !== undefined) out.children = normalizeOptions(item.children, mapping);

        return out;
    });
}

/** 在选项数组里按值找 label（远程回填用） */
export function findLabel(options, value) {
    if (!Array.isArray(options)) return '';
    const target = String(value);
    const hit = options.find((o) => o && String(o.value) === target);
    return hit ? hit.label : '';
}

/* ============================================================
 * 4. 远程加载（缓存 + 并发去重）
 * ============================================================ */

/** url + params → { promise, ts } */
const _optionCache = new Map();
const DEFAULT_TTL = 30000;

function cacheKey(url, params) {
    const keys = Object.keys(params || {}).sort();
    return url + '?' + keys.map((k) => k + '=' + String(params[k])).join('&');
}

/**
 * 按 data-url 拉取选项。
 *
 * 统一响应契约（与框架 getList 一致）：
 * ```json
 * { "code": 0, "data": { "rows": [{"value":1,"label":"张三"}], "total": 1 } }
 * ```
 *
 * 并发去重：同一 url+params 在 TTL 内只发一次请求 —— 同页 3 个字段
 * 拉同一个接口时不会打出 3 个请求。
 *
 * @param {Object} spec
 * @param {string} spec.url
 * @param {Object} [spec.params]          固定附加参数
 * @param {string} [spec.search]          搜索词
 * @param {string} [spec.searchParam='q']
 * @param {number} [spec.page=1]
 * @param {number} [spec.pageSize]
 * @param {string} [spec.pageParam='page']
 * @param {string} [spec.pageSizeParam='limit']
 * @param {string} [spec.responsePath='data.rows']
 * @param {string} [spec.totalPath='data.total']
 * @param {Object} [spec.mapping]         选项字段映射
 * @param {boolean}[spec.cache=true]
 * @param {number} [spec.cacheTtl=30000]
 * @param {Object} [spec.headers]
 * @param {boolean}[spec.withTotal=false] 返回 `{rows,total}` 而非裸数组
 * @returns {Promise<Array|{rows:Array,total:number}>}
 */
export function fetchOptions(spec) {
    const url = spec.url;
    if (!url) return Promise.resolve(spec.withTotal ? { rows: [], total: 0 } : []);

    const params = Object.assign({}, spec.params || {});

    if (spec.search) params[spec.searchParam || 'q'] = spec.search;
    if (spec.page) params[spec.pageParam || 'page'] = spec.page;
    if (spec.pageSize) params[spec.pageSizeParam || 'limit'] = spec.pageSize;

    const useCache = spec.cache !== false;
    const key = cacheKey(url, params);
    const ttl = typeof spec.cacheTtl === 'number' ? spec.cacheTtl : DEFAULT_TTL;

    if (useCache) {
        const hit = _optionCache.get(key);
        if (hit && Date.now() - hit.ts < ttl) {
            return hit.promise;
        }
    }

    const promise = fetch(url, {
        method: 'GET',
        headers: Object.assign({ 'X-Requested-With': 'XMLHttpRequest' }, spec.headers || {}),
        credentials: 'same-origin',
    })
        .then((res) => {
            if (!res.ok) throw new Error('HTTP ' + res.status);
            return res.json();
        })
        .then((json) => {
            if (json && json.code !== undefined && json.code !== 0) {
                throw new Error(json.msg || '加载失败');
            }

            const rows = getByPath(json, spec.responsePath || 'data.rows')
                || (Array.isArray(json) ? json : (json && (json.rows || json.data)));

            const options = normalizeOptions(rows, spec.mapping);

            if (!spec.withTotal) return options;

            const total = getByPath(json, spec.totalPath || 'data.total');
            return { rows: options, total: parseInt(total, 10) || options.length };
        });

    if (useCache) {
        _optionCache.set(key, { promise, ts: Date.now() });
        // 失败不缓存，避免把一次网络抖动固化 30 秒
        promise.catch(() => _optionCache.delete(key));
    }

    return promise;
}

/**
 * 失效远程缓存。
 * @param {string} [urlPrefix] 传 url 前缀则只清除命中项，省略则全清
 */
export function clearOptionsCache(urlPrefix) {
    if (!urlPrefix) {
        _optionCache.clear();
        return;
    }
    for (const key of Array.from(_optionCache.keys())) {
        if (key.indexOf(urlPrefix) === 0) _optionCache.delete(key);
    }
}

/* ============================================================
 * 5. 声明式初始化扫描
 * ============================================================ */

/** 把 root / selector / null 统一成元素数组 */
export function toElements(root) {
    if (!root) return [];
    if (typeof root === 'string') return Array.from(document.querySelectorAll(root));
    if (root.nodeType === 1 || root.nodeType === 9) return [root];
    if (Array.isArray(root) || root.length !== undefined) return Array.from(root);
    return [];
}

/**
 * 扫描并初始化 `[data-role="xxx"]` 元素（幂等）。
 *
 * 统一约定的三个动作：
 * 1. 只处理未初始化的元素（用 `instanceKey` 在元素上打标）
 * 2. 支持传入 DOM 根（弹窗/列表动态插入的节点必须能局部补挂）
 * 3. 返回实际初始化数量，便于排查"为什么没生效"
 *
 * @param {*} root  document / 元素 / 选择器字符串
 * @param {string} role `data-role` 的值，如 `wsselect`
 * @param {string} instanceKey 元素上的实例标记，如 `wsSelect`
 * @param {Function} init `(el) => instance`
 * @returns {number} 初始化数量
 */
export function scan(root, role, instanceKey, init) {
    let count = 0;
    const selector = '[data-role="' + role + '"]';

    for (const node of toElements(root)) {
        const list = [];

        // root 自身也可能就是目标元素
        if (node.nodeType === 1 && node.matches && node.matches(selector)) list.push(node);
        if (node.querySelectorAll) list.push(...node.querySelectorAll(selector));

        for (const el of list) {
            if (el.dataset && el.dataset[instanceKey]) continue;
            try {
                init(el);
                count++;
            } catch (err) {
                console.error('[ws] ' + role + ' 初始化失败', el, err);
            }
        }
    }

    return count;
}

/**
 * 声明式模式下统一的 `autoInitialize` 包装：兼容 `(selector字符串)` 与 `(root元素)`。
 * @param {*} rootOrSelector
 * @param {string} role
 * @param {string} instanceKey
 * @param {Function} init
 */
export function autoInitialize(rootOrSelector, role, instanceKey, init) {
    return scan(rootOrSelector === undefined ? document : rootOrSelector, role, instanceKey, init);
}

/* ============================================================
 * 4. 复位（回到服务端渲染时的默认值）
 * ============================================================ */

/**
 * 对容器内已初始化的 ws-* 组件执行复位。
 *
 * 为什么必须集中在一处：`form.reset()` 只恢复 **attribute 默认值**，并在 form 上
 * 派发**一个** reset 事件 —— 组件内部状态（显示文本、选中项、日期高亮、搜索词）
 * 不会跟着变；而各组件的复位细节（清空 vs 回填、多值载体、远程 label 占位）
 * 只有组件自己知道。统一约定：组件实现 `reset(silent)`，业务层只调本函数。
 *
 * 幂等：同一元素只复位一次；未实现 `reset()` 的组件直接跳过（向后兼容）。
 *
 * @param {*} root document / 元素 / 选择器
 * @param {Object<string,string>} registry 选择器 → 实例 key（jQuery data 的 key）
 * @param {Object} [options]
 * @param {boolean} [options.silent=true] 静默复位：组件不派发 change
 * @returns {number} 实际复位的组件数
 */
export function resetControls(root, registry = {}, options = {}) {
    const jq = typeof window !== 'undefined' ? window.jQuery : null;
    if (!jq || !root) return 0;

    const silent = options.silent !== false;
    const done = new Set();
    let count = 0;

    for (const selector of Object.keys(registry)) {
        const key = registry[selector];
        if (!key) continue;

        for (const scope of toElements(root)) {
            const list = [];

            if (scope.nodeType === 1 && scope.matches && scope.matches(selector)) list.push(scope);
            if (scope.querySelectorAll) list.push(...scope.querySelectorAll(selector));

            for (const el of list) {
                if (done.has(el)) continue;

                const inst = jq(el).data(key);
                if (!inst || typeof inst.reset !== 'function') continue;

                try {
                    inst.reset(silent);
                    done.add(el);
                    count++;
                } catch (err) {
                    console.error('[ws] 复位失败：' + selector, el, err);
                }
            }
        }
    }

    return count;
}

export default {
    T,
    attrName,
    parseDataConfig,
    pickData,
    copyValidation,
    CARRIER_ATTR,
    CARRIER_CLASS,
    CARRIER_SELECTOR,
    isNativeCarrier,
    ensureCarrier,
    writeCarrier,
    readCarrier,
    removeCarrier,
    emitChange,
    getByPath,
    normalizeOptions,
    findLabel,
    fetchOptions,
    clearOptionsCache,
    toElements,
    scan,
    autoInitialize,
    resetControls,
};
