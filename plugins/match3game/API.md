# match3game 插件 API 接口文档

> 适用版本：插件 `match3game`（命名空间 `plugin\match3game`）
> 端点总数：**49**（含 2026-09-12 缺口补充：`shop/inventory`、`social/invite`、`analytics/attribution`）
> 生成依据：`controller/api/*.php` 源码逐方法核对（2026-09-12）
> 相关文档：`docs/match3/06-integration-guide.md`（接入指南）、`docs/match3/10-seed-algorithm-confirm-list.md`（棋盘算法契约）

---

## 一、通用约定

### 1.1 请求

| 项 | 约定 |
|---|---|
| Base URL | `/api/match3game/{controller}/{action}` |
| HTTP 方法 | **任意**（路由为 `Route::any` 兜底）。建议统一用 `POST` |
| 请求体 | `application/json`。服务端 `Base::payload()` 读 `$this->request->getInput()`，**不支持 form-data** |
| 鉴权 | `Authorization: Bearer <JWT>` |
| 鉴权中间件 | `\wise\jwt\middleware\JwtAuth`（路由组）+ `\wise\member\middleware\JwtAuth`（控制器基类） |
| 认证后对象 | `$request->member`，用户 ID 由 `Base::userId()` 取 |

### 1.2 响应信封

```json
{ "code": 0, "msg": "ok", "data": {} }
```

| 字段 | 类型 | 说明 |
|---|---|---|
| `code` | int | **`0` 表示成功**；非 0 为业务错误码（见第十章） |
| `msg` | string | 人类可读描述 |
| `data` | mixed | 业务数据；错误时可能为 `[]` 或 `null` |

### 1.3 路由机制

插件**没有** `route/api.php`（`docs/match3/07-plugin-upgrade-plan.md` §5.4 明确记录不需要）。走全局 catch-all：

```
src/app/api/route/api.php
  Route::any('/:plugin/[:version]/[:controller]/[:action]', ApiDispatch::class)
  → plugin\match3game\controller\api\{Studly(controller)}::{action}
```

因此 `05` 文档里的 REST 风格路径（如 `/api/game/match3/battle/create`）在本仓库中**不存在**，实际端点是 `/api/match3game/social/battleCreate` 这类形式。

### 1.4 参数命名

部分社交接口**同时接受 camelCase 与 snake_case**（代码里写的是 `payload('camel', payload('snake'))`）。本文档在每个接口的参数表中都标注了两种写法。

---

## 二、接口总览（49）

| # | 模块 | 端点 | 说明 |
|---|---|---|---|
| 1 | Home | `POST /api/match3game/home/index` | 首页聚合（体力 + 金币钻石 + 当前关） |
| 2 | Home | `POST /api/match3game/home/firstGuideDone` | 新手引导完成上报 |
| 3 | Map | `POST /api/match3game/map/index` | 关卡地图（解锁态 / 星数 / 当前关） |
| 4 | Map | `POST /api/match3game/map/refresh` | 刷新当前关与解锁态 |
| 5 | Battle | `POST /api/match3game/battle/start` | 开始对局 |
| 6 | Battle | `POST /api/match3game/battle/submit` | 提交结算 |
| 7 | Battle | `POST /api/match3game/battle/latest` | 断线恢复 |
| 8 | Shop | `POST /api/match3game/shop/props` | 道具列表 |
| 9 | Shop | `POST /api/match3game/shop/buy` | 购买道具 |
| 10 | Shop | `POST /api/match3game/shop/inventory` | 背包查询（货币 + 道具库存）**【新增】** |
| 11 | Ad | `POST /api/match3game/ad/scene` | 申请广告场景 |
| 12 | Ad | `POST /api/match3game/ad/revive` | 消耗道具复活 |
| 13–30 | Rogue | `POST /api/match3game/rogue/{action}` | 肉鸽 18 个端点 |
| 31–48 | Social | `POST /api/match3game/social/{action}` | 社交 18 个端点（含 `invite`） |
| 49 | Analytics | `POST /api/match3game/analytics/attribution` | 买量归因上报 **【新增】** |

---

## 三、Home / Map

### 3.1 `POST /api/match3game/home/index`

首页聚合，一次拉齐当前关 + 货币 + 体力。

**请求参数**：无（仅 JWT）

**响应 `data`**

| 字段 | 类型 | 说明 |
|---|---|---|
| `current_level` | string \| null | 当前应挑战的关卡编码；新手为 `null` |
| `coins` | int | 金币 |
| `diamond` | int | 钻石 |
| `energy` | object \| null | 体力钱包，来自 `EnergyService::getWallet`；**服务未接入时为 `null`** |

**备注**：钱包/体力服务不可用时降级为 `0` / `null`，**不报错**。

### 3.2 `POST /api/match3game/home/firstGuideDone`

**请求参数**：无 ｜ **响应**：`{ "done": true }`

### 3.3 `POST /api/match3game/map/index` ・ `POST /api/match3game/map/refresh`

两个端点返回结构相同（`LevelProgressService::getMap()`）。

**请求参数**：无

**响应 `data`**

```jsonc
{
  "game_id": "match3_game",
  "current_level": "M3-0102",     // 第一个「已解锁但未通关」的关；全通则为最后一关
  "levels": [
    {
      "code": "M3-0101",
      "name": "关卡名",
      "chapter": "ch1",
      "difficulty": 1,
      "unlocked": true,
      "stars": 3,
      "play_count": 5
    }
  ]
}
```

---

## 四、对局 Battle

### 4.1 `POST /api/match3game/battle/start`

**请求参数**

| 字段 | 类型 | 必填 | 默认 | 校验 |
|---|---|---|---|---|
| `level_code` | string | 是 | `''` | 必须存在于 `match3_level_def.code` 且 `status=1` |
| `use_props` | array | 否 | `[]` | 元素形如 `{"item":"hammer","count":1}`；受 `per_battle_limit` 限制 |
| `battle_id` | string | 否 | `null` | 存在时进入 PvP 模式，`seed` 由服务端锁定 |

**响应 `data`**

| 字段 | 类型 | 说明 |
|---|---|---|
| `play_token` | string | **一次性结算幂等键**（= `battle_token`） |
| `seed` | int | 棋盘种子，区间 `[1, 2147483647]` |
| `level` | object | 完整 Level Schema v1（见第九章），**额外带 `reward`** |
| `energy` | object \| null | `{ current, max }`，未接入时为 `null` |
| `props_left` | object | 本局道具剩余次数，如 `{"hammer": 1}` |
| `server_ts` | int | 服务端毫秒时间戳，用于校准本地时钟 |
| `fill_version` | string | **棋盘算法版本**（当前 `v2`，单一来源 `match3game/src/core/schema.ts::FILL_VERSION`）。客户端用 `checkFillVersion()` 比对：`mismatch` → 提示强更并**拒绝开局**（否则本局成绩在 `strict` 下必被拒）；未下发视为旧版服务端，不阻断 |
| `is_battle` | bool | 仅 PvP |
| `battle_id` | string | 仅 PvP |
| `battle_mode` | string | 仅 PvP：`friend` / `ladder` / `group` / `daily` |
| `battle_role` | string | 仅 PvP：`challenger` / `opponent` |
| `opponent_score` | int \| null | 仅 PvP；**盲打：我方未完成前恒为 `null`** |

**服务端流程**：对战校验 → 关卡存在且启用 → 解锁校验（对战局豁免）→ 预扣道具 → 扣体力（`cost_per_battle=1`）→ 签发 `seed` + `play_token` → 写会话 → `play_count+1` → 下发 schema

**错误码**：`10001` 关卡不存在 ｜ `10002` 未解锁 ｜ `10003` 已停用 ｜ `10004` 体力不足 ｜ `10005` 道具不足 ｜ `10006` 对战无效

### 4.2 `POST /api/match3game/battle/submit`

**请求体 = 整个 JSON body 即 submission**（无外层包装）

| 字段 | 类型 | 必填 | 说明 |
|---|---|---|---|
| `play_token` | string | 是 | 开局下发，一次性 |
| `seed` | int | 是 | 服务端会与存档比对 |
| `ops` | array | 条件 | `require_ops=true` 时必填，**上限 500** |
| `checkpoints` | array | 否 | `require_checkpoints=false`；`t` 严格递增，条数 ≤ `rules.moves`；`h` = 该回合结束时的棋盘颜色层指纹（`colorFingerprint`，与服务端重放同一算法） |
| `fill_version` | string | 否 | 客户端棋盘算法版本（灰度共存用；缺省按服务端最新版处理） |
| `client_version` | string | 否 | 小游戏包版本（如 `1.2.0`）。S5-4 分歧率监控的分桶维度；**不传也能用**（回落按 `fill_version` 分桶） |
| `claim` | object | 是 | `{ score, movesUsed, passed, goals, maxCombo }` |
| `items_used` | array | 否 | `[{item, count}]`，结算时按 `props.costOnUse` / `freePerTry` 扣费 |
| `duration_ms` | int | 建议 | 低于 `validator.min_duration_ms`（30000）会记 `duration_too_short` |
| `battle_id` | string | 否 | PvP 成绩记录 |
| `level_code` | string | 否 | 仅埋点 |

**响应 `data`**

| 字段 | 类型 | 说明 |
|---|---|---|
| `passed` | bool | 是否通关 |
| `stars` | int | 0–3 |
| `score` | int | **最终分（服务端仲裁值）** |
| `replayed` | bool | L3 是否真跑了重放。`mode=node` + `compare_mode=strict`（2026-09-19 起为默认）下通关提交**恒为 `true`**；引擎降级（非 down 重力 / 传送带 / behaviours / actors / `rainbow` 道具）时回落弱校验为 `false` |
| `replay_mode` | string | `strict` / `upper_bound` / `off` |
| `replay_suspect` | bool | 上报分超过重放上界（**仅 `upper_bound` 口径下会为 `true`**；`strict` 直接拒单，不会走到这里） |
| `replay_score` | int | 重放得分 |
| `claim_score` | int | 客户端上报得分 |
| `rewards` | array | `[{ "item_id": "coins", "count": 300 }]` |
| `unlock_next` | object | `{ code, unlocked }` |
| `level_star_total` | int | 累计星数 |
| `coins` | int | **结算后金币余额快照（新增）**；无需再调 `home/index` |
| `diamond` | int | **结算后钻石余额快照（新增）** |
| `battle` | object \| null | PvP 状态，非空时结构同 `presentBattle` |

**错误码**：`10006` 对局无效/已结算 ｜ `10007` 对局过期（TTL 1800s）｜ `10008` 结算校验不通过（未细分类）｜ `11001`–`11005` 提交校验细分码（见 §10.2）

> **2026-09-19 起**：三层校验的失败原因会**如实映射**成 11001~11005 返回，不再一律回 `10008`。
> 客户端据此区分处置：`11001`/`11003`/`11004` 是**成绩不予采纳**（提示玩家）；
> `11005` 是 checkpoint 非法（**可选字段，去掉后静默重提即可**）；
> `11002` 是分数离谱（L2 粗筛）。映射实现：`Match3ErrorCode::fromSubmitError()`。

### 4.3 `POST /api/match3game/battle/latest`

**请求参数**：无

**响应 `data`**（两种形态）

```jsonc
{ "battle": null, "recoverable": false }
```

```jsonc
{
  "battle": {
    "play_token": "…", "level_code": "M3-0101", "seed": 1736438400,
    "started_at": "2026-09-09 10:00:00", "remaining_timeout": 1500
  },
  "recoverable": true,
  "fill_version": "v2"
}
```

> `fill_version` 与 `battle/start` 同义。**续局前同样要握手**：客户端算法若已升级，
> 续局成绩在 `strict` 下必被拒 —— 客户端应在恢复对局前检查。

---

## 五、商店与广告

### 5.1 `POST /api/match3game/shop/props`

**请求参数**：无 ｜ **响应 `data`**：数组

| 字段 | 类型 | 说明 |
|---|---|---|
| `code` | string | 道具编码 |
| `name` | string | 名称 |
| `kind` | int | `1` 开局 ｜ `2` 局内 ｜ `3` 资源包 |
| `effect` | object | 如 `{"type":"add_moves","count":3}` |
| `price` | object | `{coin:100}` 或 `{diamond:10}` 或 `{ad:1}` |
| `per_battle_limit` | int | 单局使用上限 |
| `icon` | string | 图标资源标识 |

### 5.2 `POST /api/match3game/shop/buy`

| 参数 | 类型 | 必填 | 默认 |
|---|---|---|---|
| `code` | string | 是 | — |
| `count` | int | 否 | `1` |

**响应**：`{ "code": "hammer", "count": 1 }`

**错误码**：`10001` 编码非法/道具不存在 ｜ `10010` 余额不足，**或该道具仅能通过看广告获得**

### 5.3 `POST /api/match3game/shop/inventory`

道具背包查询（补齐「无道具背包查询接口」缺口）。

**请求参数**：无（仅 JWT）

**响应 `data`**

| 字段 | 类型 | 说明 |
|---|---|---|
| `currency` | object | `{ coins, diamond, energy, spirit_stone }`；InventoryService 未接入时全 0 |
| `items` | array | 全部启用道具，元素含 `code` / `name` / `kind` / `effect` / `per_battle_limit` / `icon` / `count` |

```jsonc
{
  "currency": { "coins": 1200, "diamond": 30, "energy": 42, "spirit_stone": 500 },
  "items": [
    { "code": "hammer", "name": "锤子", "kind": 2, "effect": { "type": "remove_one" }, "per_battle_limit": 2, "icon": "hammer", "count": 3 }
  ]
}
```

**备注**：`count` 为 0 的道具也会返回，前端可一次性渲染背包。**InventoryService 不可用时降级为全 0，不报错**（与 `props_left` 互补：前者是长期库存，后者是本局剩余次数）。

### 5.4 `POST /api/match3game/ad/scene`

| 参数 | 类型 | 必填 | 说明 |
|---|---|---|---|
| `scene` | string | 是 | 见下表 |
| `battle_token` | string | 条件 | `revive` / `add_moves` 场景**必填**，且对局须处于 `PLAYING` |

**响应**：`{ "scene": "...", "ad_unit_id": "adunit_match3_xxx", "max_count_per_day": 3, "used_count_today": 1, "remain_count_today": 2 }`

**已配置场景**（`config/match3.php` → `ad.scenes`）

| scene | ad_unit_id | max_count_per_day | 附加 |
|---|---|---|---|
| `revive` | `adunit_match3_revive` | 3 | — |
| `add_moves` | `adunit_match3_addmoves` | 3 | `add_moves: 3` |
| `coins` | `adunit_match3_coins` | 5 | `coins: 200` |

**错误码**：`10001` 未知场景 ｜ `10006` 对局无效（非 PLAYING）

**注意**：响应新增 `used_count_today` / `remain_count_today`（`max - used`），前端可直接显示「今日剩余 N 次」。计数在**激励视频回调发奖成功后**自增（`Match3AdRewardHandler`），与真实发奖同一个计数源；Redis 不可用时降级为 0（不阻断看广告）。

### 5.5 `POST /api/match3game/ad/revive`

**非广告路径**：直接用 `prop.revive` 道具复活。

| 参数 | 类型 | 必填 |
|---|---|---|
| `battle_token` | string | 是 |

**响应**：`{ "revived": true }` ｜ **错误码**：`10006` 对局无效

---

## 六、肉鸽 Rogue（18 个端点）

统一前缀 `/api/match3game/rogue/`。

### 6.1 端点索引

| # | action | 参数 | 返回 |
|---|---|---|---|
| 1 | `start` | `start_option`(默认 `default`)、`mode`(默认 `normal`)、`start_relics`(默认 `[]`) | `{run, startNodes, startRelicSlots}` |
| 2 | `daily` | 无 | `{day, layers, stoneReward, relicPool, myAttempts, myBestScore, cleared, playingRun}` |
| 3 | `current` | 无 | `null` 或 `{run, available, cleared, current, startRelicSlots}` |
| 4 | `abandon` | `run_id` | 无 data |
| 5 | `enter` | `run_id`、`node_id` | `enterNode()` 返回（见 6.2） |
| 6 | `result` | `run_id`、`node_id`、`submission{play_token,seed,ops,checkpoints,claim}` | `{passed, replayed, unmet, reward, run}` |
| 7 | `fail` | `run_id`、`node_id` | `{lives, runEnded, nextNodes}` |
| 8 | `pick` | `run_id`、`node_id`、`relic_id` | `{relics, picked}` |
| 9 | `reroll` | `run_id`、`node_id`、`paid`(默认 `false`) | `{relicChoices}` |
| 10 | `relics` | 无 | `{list[], progress{unlocked,total,percent}}` |
| 11 | `unlock` | `unlock_key` | 成功 `{stone, bonus}`；失败 → 码 `41007` |
| 12 | `meta` | 无 | `match3_meta_progress` 行 |
| 13 | `shop` | `run_id`、`node_id` | `{coins, stock[], bought[]}` |
| 14 | `buy` | `run_id`、`node_id`、`item_id` | ShopService 返回 |
| 15 | `leave` | `run_id`、`node_id` | ShopService 返回 |
| 16 | `rest` | `run_id`、`node_id`、`choice`(?)、`relic_id`(?) | 不传 `choice` → 选项列表；传了 → 结算 |
| 17 | `event` | `run_id`、`node_id` | `{event{event_id,title,desc,options[]}, settled, chosen}` |
| 18 | `choose` | `run_id`、`node_id`、`option_id` | EventService 返回 |

### 6.2 `rogue/enter` 返回结构

`data` 直接来自 `Match3RunService::enterNode()`，字段含：

| 字段 | 说明 |
|---|---|
| `nodeType` | `battle` / `shop` / `rest` / `event` |
| `D` | 难度系数（float） |
| `stage` | **完整 Level Schema v1**（已应用遗物 patch），步数在 `stage.rules.moves` |
| `playToken` | **一次性**，用于 `rogue/result` |
| `fill_version` | **棋盘算法版本**（同 §4.1）。肉鸽节点同样走 L3 重放，客户端同样要握手 |
| `lives` / `coins` | 局内资源快照 |
| `relicChoices` | 遗物三选一候选（可能为空） |
| `appliedRelics` | 本节点生效的遗物 |
| `schemaFingerprint` | schema 指纹，用于两端一致性校验 |
| `autoCleared` / `nextNodes` / `quality` | 自动通过标记 / 后继节点 / 质量分 |

### 6.3 `rogue/result` 的三层校验顺序

源码 ①~⑤（`Rogue::result()`）：

| 序号 | 校验 | 失败错误码 |
|---|---|---|
| ① | `play_token` 能取回节点 | `41002` play_token 无效或已使用 |
| ② | `run_id` / `node_id` 与 token 匹配 | `41002` play_token 与节点不匹配 |
| ③ | 节点 `status === STATUS_PLAYING` | `41008` 该节点已结算 |
| ④ | 节点 schema 非空 | `41004` 节点关卡数据缺失 |
| ⑤ | `seed` 与存档一致 | `11004` seed 与服务端不一致 |
| ⑥ | `count(ops) <= 500` | `11001` ops 条数超限 |
| ⑦ | 三层校验（L1/L2/L3） | `11001`–`11005` / `11009` |

**通关判定**：`replayed ? GoalJudge::met(...) : (bool)$claim['passed']`

### 6.4 `rogue/unlock` 的 `unlock_key` 取值

| key | 灵石成本 |
|---|---|
| `start_move_1` | 500 |
| `start_move_2` | 1000 |
| `start_move_3` | 2000 |
| `character` | 1500 |
| `relic_slot` | 2500 |
| `relic:<relicId>` | `match3_relic.unlock_cost`，缺省 300 |

失败 `reason`：`unknown_relic` / `unknown_unlock_key` / `already_unlocked` / `insufficient_stone` → **统一返回码 `41007`**。

---

## 七、社交 Social（18 个端点）

统一前缀 `/api/match3game/social/`。参数**同时支持 camelCase 与 snake_case**。

### 7.1 端点索引

| # | action | 参数 | 返回 |
|---|---|---|---|
| 1 | `battleCreate` | `mode`(默认 `friend`)、`stageCode`/`stage_code`(必填)、`opponentId`/`opponent_id`、`groupId`/`group_id`、`relics[]` | `{battleId, stageCode, seed, mode, status, expiresAt, shareCard}` |
| 2 | `battleAccept` | `battleId`/`battle_id` | `presentBattle` |
| 3 | `battleRefuse` | `battleId`/`battle_id` | `presentBattle` |
| 4 | `battleList` | `box`(`all`/`incoming`/`outgoing`，默认 `all`)、`page`(1)、`size`(20，上限 50) | `{list, total, page, size}` |
| 5 | `battleDetail` | `battleId`/`battle_id` | `presentBattle` |
| 6 | `rankFriend` | `friend_ids`/`friendIds`(int[]，上限 100)、`limit`(50) | `{list, myRank, myScore}` |
| 7 | `rankDaily` | `day`(`YYYY-MM-DD`，默认今天)、`limit` | `{list, myRank, myScore, day}` |
| 8 | `rankGroup` | `groupId`/`group_id`(**必填**)、`periodId`/`period_id`(默认当周)、`limit` | `{periodId, members[], board[]}` |
| 9 | `rankSeason` | `seasonId`/`season_id`(默认当前赛季)、`limit` | `{list, myRank, myScore}` |
| 10 | `giftSend` | `targetId`/`target_id`(必填)、`type`(`energy`/`prop`)、`count`(1)、`prop`(默认 `prop.hammer`) | `{giftId, type, payload, remainSend, senderReward, expireAt}` |
| 11 | `giftList` | `status`(0)、`limit`(50) | FriendService::gifts |
| 12 | `giftClaim` | `ids[]` | 领取结果 |
| 13 | `rescue` | `stageCode`/`stage_code`、`helperId`/`helper_id` | 求助结果（需同关失败 3 次） |
| 14 | `seasonCurrent` | 无 | 见 7.3 |
| 15 | `seasonClaim` | 无 | 段位奖励发放结果 |
| 16 | `groupCurrent` | `groupId`/`group_id`(**必填**)、`periodId`/`period_id` | 见 7.4 |
| 17 | `share` | `scene`(默认 `battle`)、`nonce`(可选，幂等键) | 见 7.5 |
| 18 | `invite` | `inviteCode`/`invite_code`(可选，传了则接受邀请)、`scene`(默认 `invite`) | 见 7.6 |

> `invite` 端点已实现（原类注释提及但无实现）。不传邀请码时仅返回**我的邀请码 + 分享卡**，不消耗任何额度。

### 7.2 `presentBattle` 结构

`battleAccept` / `battleRefuse` / `battleDetail` / `battle/submit` 的 `data.battle` 共用：

```jsonc
{
  "battleId": "bt_ab12cd34", "mode": "friend", "stageCode": "M3-0101", "status": 1,
  "statusText": "进行中",
  "challengerId": 1, "opponentId": 2, "winnerId": 0,
  "seed": 1736438400,
  "myScore": 8640, "myStatus": 2, "myStatusText": "已完成", "myPointsDelta": 30,
  "opponentScore": null,          // 盲打：我方未完成时恒为 null
  "isCheat": 0,
  "expiresAt": "…", "createdAt": "…", "finishedAt": null
}
```

### 7.3 `seasonCurrent` 返回

```jsonc
{
  "seasonId": "S2026-13", "name": "…", "startAt": "…", "endAt": "…",
  "status": 1, "statusText": "进行中",
  "me": {
    "points": 620, "tier": "gold", "tierName": "黄金",
    "wins": 12, "losses": 5, "bestScore": 9800, "rank": 37,
    "rewardClaimed": false, "nextTier": "platinum", "pointsToNext": 380
  },
  "tiers": [...], "rank": [...]
}
```

**段位表**（`SocialRules::TIERS`）：`bronze` 0–199 ｜ `silver` 200–499 ｜ `gold` 500–999 ｜ `platinum` 1000–1799 ｜ `diamond` 1800–2999 ｜ `king` 3000+
**积分**：大胜 +30（分差 >20%）、小胜 +25、负 −15、超时 −20、每日首胜 +10
**周期**：赛季 14 天一期，ID 形如 `S2026-13`

### 7.4 `groupCurrent` 返回

```jsonc
{
  "groupId": "…", "periodId": "2026-W37", "startAt": "…", "endAt": "…",
  "seed": 1736438400, "stagePool": [...],
  "groupRank": 12, "groupScore": 86400, "memberCnt": 28,
  "rules": { "topN": 5, "dailyPlays": 3 },
  "me": { "score": 8640, "stagePlays": 2, "remain": 1 },
  "members": [...], "board": [...]
}
```

### 7.5 `share` 返回（**三种形态，注意区分**）

| 情形 | HTTP 信封 | `data` |
|---|---|---|
| 正常发奖 | `code=0` | `{rewarded:true, coin:20, coinItem:"coins", remainToday, cooldown:10}` |
| 今日配额用尽 | `code=0` | `{rewarded:false, reason:"daily_limit", remainToday:0, message:"今日分享奖励已领完（不影响继续分享）"}` |
| 冷却中 | `code=0` | `{rewarded:false, reason:"cooldown", remainToday, cooldown:10, message:"分享太频繁，请 10 秒后再试"}` |
| 重复上报 | `code=0` | 首次结果的**原样返回** + `idempotent:true`（同一 `nonce`，不发奖、不再占配额） |

**请求体**：`{ scene?, nonce? }`

`nonce` = 客户端在**拉起分享面板前**生成的幂等键（`scene:时间戳:随机`），同一次分享复用同一个值。
服务端对同一 `nonce` 只结算一次（Redis `share:once:{uid}:{md5(nonce)}`，TTL 2 天）。
老客户端不带 `nonce` → 走原逻辑，完全兼容。

> **合规要求**：分享是**可选**的，不调此接口照样能正常玩。Redis 不可用时降级为不阻断分享。
>
> **冷却 / 配额可配（2026-10-04）**：`match3.share.cooldown`（秒，缺省 10）、`match3.share.daily_limit`（次，缺省 3），
> 改配置**不需要发版**；非法值（0/负/非数字）一律回落到常量。
>
> **为什么冷却从 60s 降到 10s**：微信小游戏 `wx.shareAppMessage` 的 `success` 只在**分享面板拉起**时触发
> （用户随后取消没有回调），客户端据此判定"已分享"并立即上报 → "拉起"就被记一次并上锁。
> 60s 会让"连发 2~3 个好友"这个正常行为必然撞冷却，玩家看到「分享太频繁」却只真发了一次。10s 只防连点。
> **契约统一（2026-09-12）**：三种情形均返回 `code=0`，由 `data.rewarded` + `data.reason`（`daily_limit` / `cooldown`）区分，前端无需再处理 `30009` 错误码分支。

### 7.6 `invite` 返回（邀请）

```jsonc
{
  "inviteCode": "3QVX7A",          // 我的邀请码（可反解，含混淆盐）
  "accepted": false,               // 本次是否成功接受了他人的邀请
  "inviterId": 0,                  // 邀请者 uid（accepted 时 > 0）
  "inviteeReward": 0,              // 我获得的奖励（金币）
  "inviterReward": 0,              // 邀请者获得的奖励（金币）
  "alreadyBound": false,           // 我此前是否已绑定过邀请者
  "scene": "invite",
  "shareTitle": "一起来玩消消乐，领金币啦！",
  "sharePath": "/pages/index/index?inviteCode=3QVX7A",
  "shareImage": ""
}
```

**规则**：受邀者绑定成功一次 → 发 **+50 金币**；邀请者每人 **+50 金币**，**每日最多奖励 5 人**（`SocialRules::INVITE_DAILY_LIMIT`）。
受邀者**终生只能绑定一次**（Redis `match3:invite:bound:{uid}`，90 天）；重复绑定返回 `accepted:false, alreadyBound:true`（不报错）。
邀请码非法 → **`code=30001`**。Redis 不可用时 fail-open（不阻断）。

> **合规**：邀请是**可选**的，不影响正常玩法；邀请码由 uid + 混淆盐可逆编码（`SocialRules::encodeInviteCode`），无需建表即可反解邀请者。

---

## 八、提交协议

### 8.1 `ops`

```jsonc
// 交换（坐标为 [列, 行]，注意不是 [row, col]）
{ "t": "swap", "a": [c, r], "b": [c, r] }
// 使用道具
{ "t": "prop", "p": "hammer", "at": [c, r] }
```

约束：**上限 500 条**（`submission.max_ops`）；必须相邻、同 region、交换后形成匹配；由 `Match3OpsValidator` 做 L1 校验。

### 8.2 `checkpoints`

```jsonc
[ { "t": 0, "h": "a3f2c1" }, { "t": 1, "h": "7b19de" } ]
```

`t` 严格递增，条数 ≤ `rules.moves`，`h` 为棋盘摘要。当前 `require_checkpoints=false`，**可不传**。

### 8.3 `claim`

```jsonc
{ "score": 8640, "movesUsed": 24, "passed": true, "goals": { "ice": 30 }, "maxCombo": 5 }
```

`goals` 的键名映射（见 `Match3Validator`）：

| `rules.goals[].type` | `rules.goals[].target` | `claim.goals` 键名 | 判定 |
|---|---|---|---|
| `collect` | 元素码 | `goals[target]` | ≥ `count` |
| `clear` | 层名（如 `ice`） | `goals[layer]` | ≥ `count` |
| `score` / `boss` / `spread` / `survive` | — | — | 由分数或后续阶段判定 |

---

## 九、Level Schema v1

`battle/start` 的 `level` 与 `rogue/enter` 的 `stage` 均为该结构（`LevelSchemaFactory::fromRow()` 产物）。

```jsonc
{
  "schema": "match3/level@1", "code": "M3-0101", "name": "…", "chapter": "ch1",
  "difficulty": 1, "version": 1,
  "board": { "cols": 9, "rows": 9, "cellSize": 76, "origin": { "c": 0, "r": 0 } },
  "regions": [{
    "id": "main", "origin": { "c": 0, "r": 0 }, "gravity": "down",
    "layers": { "terrain": ["ooooooooo", …], "entity": [], "overlay": [] },
    "spawn": { "mode": "top", "weights": [1,1,1,1,1,1], "excludeInitialMatch": true }
  }],
  "rules": {
    "mode": "moves", "minMatch": 3, "autoShuffle": true, "moves": 25,
    "goals": [ { "type": "collect", "target": "red_gem", "count": 25 } ],
    "starScores": [1000, 3000, 6000],
    "specials": { "match4": "stripe", "match5Line": "rainbow", "match5Cross": "bomb" },
    "scoreMul": 1, "coinMul": 1, "goalMul": 1, "comboMul": 1
  },
  "random": { "seedPolicy": "server", "seed": 1736438400 },
  "reward": { "coins": [100, 200, 300] }     // BattleService 追加；[首通, 二星, 三星]
}
```

**可选字段**（非空才下发）：`portals`、`palette`、`behaviours`、`actors`、`props`、`generation`

**地形字符**：可站立 `o D P > < ^ v`（`STANDABLE_TERRAIN`）；生成器行 `S`、空 `.`、障碍 `#` 不参与填充

**普通棋盘内核**：`props` 协议为 `{"allowed":[...], "costOnUse":{"hammer":1}, "freePerTry":1}`

> **棋盘如何生成**：服务端**只下发 `seed`**，客户端必须按 `Match3Board` + `Match3Random` 的确定性算法本地复现（mulberry32 + `deriveSeed`）。完整规范与 crc32 盐值常量见 `docs/match3/10-seed-algorithm-confirm-list.md`。TS 参考实现见 `match3game/src/core/match3-board.ts`。

---

## 十、错误码全集

### 10.1 `Match3ErrorCode`（固定关卡）

| 码 | 常量 | 含义 |
|---|---|---|
| 10001 | `LEVEL_NOT_FOUND` | 参数错误或关卡不存在 |
| 10002 | `LEVEL_LOCKED` | 关卡未解锁 |
| 10003 | `LEVEL_DISABLED` | 关卡已停用 |
| 10004 | `ENERGY_NOT_ENOUGH` | 体力不足 |
| 10005 | `PROP_NOT_ENOUGH` | 背包道具不足 |
| 10006 | `BATTLE_INVALID` | 对局不存在或已结算 |
| 10007 | `BATTLE_EXPIRED` | 对局已过期（TTL 1800s） |
| 10008 | `SETTLE_INVALID` | 结算校验不通过 |
| 10009 | `AD_LIMIT_REACHED` | 广告今日次数已达上限 |
| 10010 | `BALANCE_NOT_ENOUGH` | 兑换或购买余额不足 |
| 10011 | `LEVEL_SCHEMA_INVALID` | 关卡数据校验不通过 |

### 10.2 提交校验错误码

| 码 | errorCode | 含义 |
|---|---|---|
| 11001 | `invalid_op` | 非法操作（ops 不合法 / 条数超限） |
| 11002 | `invalid_claim` | 分数离谱 |
| 11003 | `result_mismatch` | 重放不符（作弊） |
| 11004 | `board_mismatch` | 棋盘版本分歧 / seed 不一致 |
| 11005 | `invalid_checkpoint` | checkpoint 结构非法 |
| 11009 | 默认 | 其他（含 `moves_exhausted`） |

> **实现状态（2026-09-19）**
>
> | 码 | 何时返回 | 客户端该怎么做 |
> |---|---|---|
> | 11001 | L1 结构非法，**或 L3 重放判定 op 非法**（如"相邻但不成线的交换"——L1 看不出来） | 不重试，提示"成绩校验未通过" |
> | 11002 | L2 粗筛（分数/步数/目标结构离谱） | 不重试 |
> | 11003 | `strict` 模式下重放分与上报分不符 | 不重试 |
> | 11004 | 棋盘分歧（seed 不符 / 客户端算法版本不一致） | 不重试；配合版本强更策略 |
> | 11005 | `checkpoints` 结构非法（该字段可选） | **去掉 checkpoints 静默重提一次** |
> | 11009 | — | **当前未使用**：未识别的 reason（`duration_too_short` / `score_limit` / `too_many_ops` 等）与业务类失败（`level_not_found`…）一律回落 `10008`，避免把业务错误误标成"提交校验失败" |
>
> 映射实现：`exception/Match3ErrorCode.php::fromSubmitError()`；
> 漂移护栏：`tests/verify-strict-rollout.php` §5（任何 `ERR_*` 改名/新增都会在那里红）。

### 10.3 `SocialException`

| 码 | 常量 | 含义 |
|---|---|---|
| 30001 | `CODE_BAD_REQUEST` | 参数错误（如缺 `groupId`） |
| 30002 | `CODE_NOT_FOUND` | 对战不存在 |
| 30003 | `CODE_FORBIDDEN` | 无权限 |
| 30004 | `CODE_BATTLE_CLOSED` | 对战已关闭 |
| 30005 | `CODE_ALREADY_DONE` | 已处理过 |
| 30006 | `CODE_LIMIT` | 次数受限 |
| 30007 | `CODE_GROUP_ID_INVALID` | 群标识无效 |
| 30008 | `CODE_ENERGY_FULL` | 好友体力已满 |
| 30009 | `CODE_COOLDOWN` | 冷却中 |
| 30010 | `CODE_SEASON_CLOSED` | 赛季已关闭 |

### 10.4 `Match3NodeException`（肉鸽）

| 码 | 常量 | 含义 |
|---|---|---|
| 41001 | `CODE_RUN_NOT_FOUND` | 对局不存在 |
| 41002 | `CODE_NODE_NOT_FOUND` | 节点 / play_token 无效或已使用 |
| 41003 | `CODE_LEVEL_NOT_FOUND` | 关卡不存在 |
| 41004 | `CODE_LEVEL_INVALID` | 节点关卡数据缺失 |
| 41005 | `CODE_RUN_FINISHED` | 对局已结束 |
| 41006 | `CODE_RELIC_MISSING` | 遗物缺失 |
| 41007 | `CODE_STONE_NOT_ENOUGH` | 灵石不足 / 解锁失败（**所有 unlock 失败原因统一归到此码**） |
| 41008 | `CODE_NODE_SETTLED` | 该节点已结算 |

---

## 十一、归因上报 Analytics

### 11.1 `POST /api/match3game/analytics/attribution`

买量归因上报（补齐「无买量归因上报接口」缺口）。客户端在小游戏启动 / 进入归因场景时上报一次。

**请求参数**（至少提供一个归因字段；`camelCase` 与 `snake_case` 均可）

| 字段 | 类型 | 必填 | 说明 |
|---|---|---|---|
| `channel` | string | 条件 | 渠道标识，如 `tencent` / `douyin` |
| `click_id` | string | 条件 | 点击 id（`clickId` 亦可） |
| `adgroup_id` | string | 否 | 广告组 id（`adgroupId` 亦可） |
| `campaign_id` | string | 否 | 计划 id（`campaignId` 亦可） |
| `creative_id` | string | 否 | 创意 id（`creativeId` 亦可） |
| `promoter_id` | string | 否 | 推广人 id |
| `cid` / `gdt_vid` | string | 否 | 腾讯广告参数 |
| `scene` | string | 否 | 默认 `launch` |

**响应**：`{ "received": true, "ts": 1736438400000 }`

**错误码**：`10001` 归因参数全部为空

**行为**：不落库、不存用户画像；仅构造 `match3.attribution` 埋点（`Match3Funnel::emit`），落入 `[match3][funnel]` 结构化日志并转发 `wise-game-ops` 分析服务。所有字段经 `sanitize()` 清洗（截断 64 字符、键上限 24）。

---

## 十二、已知缺口（接口层面）

> **2026-09-12 更新**：缺口 **1 / 2 / 3 / 6 / 7 / 10** 已在接口层补实现（见上面对应章节）；**4 / 5 / 8 / 9 / 11–14** 仍待产品/引擎确认或超出本插件职责。

| # | 缺口 | 影响 | 建议 | 状态 |
|---|---|---|---|---|
| 1 | **无道具背包查询接口** | 前端拿不到库存，只能靠 `shop/buy` 与 `props_left` 反推 | 新增 `shop/inventory` | ✅ 已实现（5.3） |
| 2 | **`ad/scene` 不返回今日已用次数** | 无法显示「今日剩余 N 次」 | 追加 `used_count_today` | ✅ 已实现（5.4） |
| 3 | **`battle/submit` 不返回货币快照** | 结算后需额外调 `home/index` | 追加 `coins` / `diamond` | ✅ 已实现（4.2） |
| 4 | **排行榜不返回昵称头像** | 榜单只能显示 `userId` | 前端用微信开放数据域自行渲染，或后端补字段 | ⏳ 待产品确认 |
| 5 | **无登录换取 JWT 的端点** | 插件内不含 | 由 `wise-jwt` 或 member 模块提供 | ⏳ 插件外 |
| 6 | **`share` 冷却返回的是错误码而非 `code=0`** | 与「配额用尽返回 `code=0`」不一致 | 统一为 `code=0` + `data` 区分 | ✅ 已实现（7.5） |
| 7 | **`invite` 端点不存在** | 类注释提及但无实现 | 补实现 | ✅ 已实现（7.6） |
| 8 | **`rogue` 的 `shop/buy/leave/rest/choose` 直接透传 Service 返回值** | 返回字段未在控制器层收窄 | 建议在文档或代码中固化契约 | ⏳ 待固化 |
| 9 | **无每日签到 / 任务 / 成瘾度接口** | 首页暂无相关入口 | 产品确认是否排期 | ⏳ 待产品确认 |
| 10 | **无买量归因上报接口** | IAA 无法归因 | 接 `Match3Funnel` 或独立端点 | ✅ 已实现（十一） |
| 11 | **`rules.specials` 无运行时实现** | `LevelSchemaFactory` 会规范化并预置 `{match4:'stripe',match5Line:'rainbow',match5Cross:'bomb'}`（遗物 patch 会改它），但 `Match3Board` **从不创建特殊元素**。客户端若自行合成 stripe/rainbow/bomb，重放必然分歧 | 要么在 `Match3Board` 实现特殊元素合成，要么从 schema 移除该字段以免误导客户端 | ⏳ 引擎 |
| 12 | **重放引擎没有「自动洗牌」** | `rules.autoShuffle` 存在，但 `Match3PhpReplayEngine` 只在遇到 `{t:'prop',p:'shuffle'}` op 时才洗牌。客户端检测到无解后自动洗牌，必须**记成 shuffle op** 才能被重放；且不能计入 `items_used`（玩家没消耗道具） | 确认「自动洗牌记为 shuffle op」是既定契约；否则重放会与客户端分歧 | ⏳ 引擎 |
| 13 | **`rainbow` / `paint` 道具语义未定** | `Match3OpsValidator` 的 `$needPos` 含 `rainbow`/`paint`，但 `Match3PhpReplayEngine::SUPPORTED_PROPS` **不含**它们——提交后 L3 会整体降级为弱校验 | 补齐重放实现，或在 L1 直接拒绝 | ⏳ 引擎 |
| 14 | **ops 上限两处不一致** | `config.submission.max_ops = 500` vs `Match3PhpReplayEngine::MAX_OPS = 2000` | 统一到 config |
| 15 | **重复提交拿不到结算详情** | `submit` 的 `play_token` 一次性，网络重试导致二次提交会返回 `10006`，此时**响应里没有任何结算数据**（`stars` / `rewards` / `unlock_next` 全无）。客户端无法展示结算卡片，只能刷新资产后给通用提示 | 建议 `battle/submit` 在命中已结算 token 时**返回首次结算结果**（幂等响应），而不是报错。这样客户端重试才能真正安全 | ⏳ 待统一 |
