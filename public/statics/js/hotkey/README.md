# HotKey 快捷键管理

`hotkey.js` 是一个基于原生 JavaScript 的快捷键管理类，支持：

- 单页/多命名空间（namespace）的快捷键注册与注销
- 跨页面、跨 iframe 的全局快捷键（基于 `localStorage` 的 `storage` 事件通信）
- 启用/禁用状态持久化
- 事件发布/订阅机制

## 引入方式

```js
// 浏览器全局（UMD 自动挂载到 window.HotKey）
<script src="hotkey.js"></script>

// 或 CommonJS / AMD
const HotKey = require('./hotkey.js');
```

## 快速开始

```js
// 1. 创建实例（可传入命名空间，默认 'default'）
const hk = new HotKey('my-app');

// 也可传入配置项：crossPage 控制本页快捷键是否对外（跨页面/跨 iframe）共享，默认 true
// crossPage: false 时，本页注册的快捷键只在本页生效（不写入全局注册中心，不会被其它页面触发），
// 但本页仍会监听并接受其它页面注册的全局快捷键（入站不受影响）。
const hkLocal = new HotKey('my-app', { crossPage: false });

// 2. 注册快捷键：Ctrl + S 触发保存
hk.register('ctrl+s', (hotkey, event) => {
    console.log('触发了', hotkey);
    saveData();
}, '保存');

// 3. 注册带描述的快捷键：Alt + Enter 提交
const id = hk.register('alt+enter', onSubmit, '提交表单');

// 4. 取消注册
hk.unregister(id);
```

## 公开 API（下划线前缀为私有方法，请勿外部调用）

### `register(hotkey, callback, description = '')`
注册一个快捷键，返回该快捷键的唯一 `id`（用于 `unregister`）。

| 参数 | 类型 | 说明 |
| --- | --- | --- |
| `hotkey` | `string` | 组合键，例如 `'ctrl+s'`、`'alt+enter'`、`'a'` |
| `callback` | `Function` | 命中回调，签名为 `(hotkey, event) => void` |
| `description` | `string` | 可选，可读描述，用于展示/调试 |

### `unregister(id)`
取消注册指定快捷键。`id` 为 `register` 的返回值。返回 `true/false`。

### `subscribe(eventType, callback)`
订阅热键事件，返回取消订阅函数。

| 参数 | 类型 | 说明 |
| --- | --- | --- |
| `eventType` | `string` | 事件类型：`hotkey-triggered` / `hotkey-registered` / `hotkey-unregistered` / `hotkeys-enabled-changed` |
| `callback` | `Function` | 回调 `(data) => void` |

```js
const unsub = hk.subscribe('hotkey-triggered', (data) => {
    console.log('热键被触发：', data.hotkey, data.description);
});
// 取消订阅
unsub();
```

### `publish(eventType, data)`
向所有订阅者发布事件（发布/订阅机制的事件触发端）。

### `setEnabled(enabled)`
启用或禁用当前命名空间下所有热键，状态会持久化（刷新后仍生效）。

```js
hk.setEnabled(false); // 临时关闭所有快捷键
hk.setEnabled(true);  // 重新启用
```

### `getAllHotkeys()`
返回当前命名空间下已注册的所有热键配置数组。

## 跨框架 / 跨 iframe 全局快捷键

无需额外配置：任意框架内按下已注册的全局快捷键，即使焦点不在注册者页面，也会通过 `localStorage` 事件转发、由注册者框架执行回调。子页面（iframe）晚加载也能自动同步已有注册。

> 该能力由构造参数 `crossPage`（默认 `true`）控制，它只影响**出站**：`{ crossPage: false }` 时，本页注册的快捷键不会写入全局注册中心，因此不会被其它页面/iframe 触发（仅当前页面生效）；但本页**仍会接收**其它页面注册的全局快捷键与同 namespace 的跨页面广播（入站不受影响）。

## 快捷键格式约定

组合键使用 `+` 连接，修饰键在前、普通键在后（普通键自动转小写）：

- `ctrl+s`、`alt+enter`、`shift+f1`
- 可叠加多个修饰键：`ctrl+alt+delete`
- 单独按键：`a`、`enter`、`escape`

## 说明

- 文件内部以下划线 `_` 开头的方法为私有实现（如 `_handleKeyDown`、`_broadcast`、`_saveState` 等），外部请勿直接调用。
