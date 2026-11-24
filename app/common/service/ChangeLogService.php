<?php
declare(strict_types=1);

namespace app\common\service;

use think\facade\Db;
use wise\support\changelog\ChangeLogManager;
use wise\support\contracts\ChangeLogSinkInterface;
use wise\support\tools\TextDiff;
use wise\support\traits\ChangeLoggable;

/**
 * 字段级变更记录＼�—— 宿主侧 sink 实现
 *
 * 分工：
 *   - 采集：`wise\support\traits\ChangeLoggable`（模型内一行接入）；
 *   - 分发：`wise\support\changelog\ChangeLogManager`（sink 注册 + no-op 兜底）；
 *   - 落地：本类（黑名单 / 截断 / 队列 / 查询 / 回滚 / 归档）。
 *
 * 安全边界：
 *   - **主体判定**：只有 `config/change_log.php` 的 `actors` 白名单命中的操作主体才落库，
 *     会员自助 / 系统任务 / 匿名前端在 {@see shouldRecord()} 处直接丢弃（审计 ≠ 全量流水）；
 *   - 敏感字段（password / token / secret …）**整字段不落库**，不是打码；
 *   - 单值超 4KB 截断并打 `truncated` 标记，截断字段禁止回滚（避免把残缺值写回）；
 *   - 回滚模型白名单 = 显式 `use ChangeLoggable` 的模型（`class_uses_recursive` 判定），
 *     杜绝用变更表里的 model 字段构造任意表写入。
 */
class ChangeLogService implements ChangeLogSinkInterface
{
    /** 变更表名（不含前缀） */
    public const TABLE = 'operation_log_changes';

    /** 单次落库批量 */
    private const INSERT_BATCH = 200;

    /** 允许的动作 */
    public const ACTIONS = ['update', 'create', 'rollback'];

    /** 敏感字段正则（首次使用时编译） */
    private ?array $sensitiveRegex = null;

    /**
     * 操作人快照的 RequestContext 键
     *
     * 不能存实例属性：sink 随 boot 只创建一次，Swoole 常驻进程下会把首个请求的
     * 操作人身份带到后续请求（串号）。RequestContext 按请求/协程隔离且 HttpEnd 清理。
     */
    private const OPERATOR_CACHE_KEY = 'change_log:operator';


    /**
     * 接收一条模型变更
     *
     * 队列模式：投递 `changelog:persist`（轻量 task()，不落 async_tasks 注册表）；
     * 投递失败：按配置降级为同步落库，绝不静默丢记录。
     */
    public function push(array $entry): void
    {
        if (!(bool) config('change_log.enable', true)) {
            return;
        }

        try {
            if (!$this->shouldRecord()) {
                return;
            }

            $rows = $this->buildRows($entry);
            if (empty($rows)) {
                return;
            }

            if ($this->mode() === 'sync') {
                $this->persist($rows);
                return;
            }

            try {
                (new QueueService())->task(ChangeLogJobService::HANDLER, ['rows' => $rows], [
                    'queue' => config('change_log.queue') ?: null,
                ]);
            } catch (\Throwable $e) {
                if ((bool) config('change_log.fallback_sync', true)) {
                    $this->persist($rows);
                    return;
                }
                $this->logError('变更记录入队失败: ' . $e->getMessage());
            }
        } catch (\Throwable $e) {
            $this->logError('变更记录采集失败: ' . $e->getMessage());
        }
    }

    /**
     * 批量落库（队列 handler 与同步模式共用）
     *
     * @param array<int, array<string, mixed>> $rows
     */
    public function persist(array $rows): int
    {
        if (empty($rows)) {
            return 0;
        }

        $saved = 0;
        foreach (array_chunk($rows, self::INSERT_BATCH) as $chunk) {
            try {
                $saved += (int) Db::name(self::TABLE)->insertAll($chunk);
            } catch (\Throwable $e) {
                $this->logError('变更记录落库失败: ' . $e->getMessage());
            }
        }

        return $saved;
    }


    /**
     * 已采集模型清单（页面下拉）
     *
     * @return array<int, array{model: string, label: string, count: int}>
     */
    public function models(): array
    {
        try {
            $rows = Db::name(self::TABLE)
                ->field('model, model_label, COUNT(*) AS total')
                ->group('model, model_label')
                ->order('total', 'desc')
                ->limit(200)
                ->select()
                ->toArray();
        } catch (\Throwable $e) {
            return [];
        }

        return array_map(static fn (array $row): array => [
            'model' => (string) $row['model'],
            'label' => (string) ($row['model_label'] ?: $row['model']),
            'count' => (int) $row['total'],
        ], $rows);
    }

    /**
     * 变更组列表（按 change_set 聚合，一次保存 = 一行）
     *
     * @param array<string, mixed> $filters
     * @return array{total:int, list:array<int,array<string,mixed>>, page:int, limit:int}
     */
    public function queryList(array $filters, int $page, int $limit): array
    {
        $page  = max(1, $page);
        $limit = max(1, min(200, $limit));

        $build = function () use ($filters) {
            $query = Db::name(self::TABLE);

            if (!empty($filters['model'])) {
                $query->where('model', (string) $filters['model']);
            }
            if (!empty($filters['pk_value'])) {
                $query->where('pk_value', (string) $filters['pk_value']);
            }
            if (!empty($filters['field'])) {
                $query->where('field', (string) $filters['field']);
            }
            if (!empty($filters['operator'])) {
                $query->whereLike('operator_name', '%' . addcslashes((string) $filters['operator'], '%_\\') . '%');
            }
            if (!empty($filters['start']) && !empty($filters['end'])) {
                $query->whereBetween('create_time', [(string) $filters['start'], (string) $filters['end']]);
            }

            return $query;
        };

        $total = (int) ($build()->field('COUNT(DISTINCT change_set) AS total')->find()['total'] ?? 0);

        $sets = $build()
            ->field('change_set, MIN(id) AS first_id')
            ->group('change_set')
            ->order('first_id', 'desc')
            ->page($page, $limit)
            ->select()
            ->toArray();

        $changeSets = array_column($sets, 'change_set');
        if (empty($changeSets)) {
            return ['total' => $total, 'list' => [], 'page' => $page, 'limit' => $limit];
        }

        $firstIds = array_column($sets, 'first_id', 'change_set');

        $rows = Db::name(self::TABLE)
            ->whereIn('change_set', $changeSets)
            ->order('id', 'desc')
            ->select()
            ->toArray();

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row['change_set']][] = $row;
        }

        $list = [];
        foreach ($changeSets as $set) {
            $items = $grouped[$set] ?? [];
            if (empty($items)) {
                continue;
            }

            $head   = $items[0];
            $labels = [];
            foreach ($items as $item) {
                $labels[] = (string) ($item['field_label'] ?: $item['field']);
            }

            $list[] = [
                'id'            => (int) ($firstIds[$set] ?? $head['id'] ?? 0),
                'change_set'    => (string) $set,
                'model'         => (string) $head['model'],
                'model_label'   => (string) ($head['model_label'] ?: $head['model']),
                'pk_value'      => (string) $head['pk_value'],
                'title'         => (string) $head['title'],
                'action'        => (string) $head['action'],
                'source'        => (string) ($head['source'] ?? 'model'),
                'operator_name' => (string) $head['operator_name'],
                'create_time'   => (string) $head['create_time'],
                'field_count'   => count($items),
                'fields'        => implode('、', array_slice($labels, 0, 8)) . (count($labels) > 8 ? ' …' : ''),
            ];
        }

        return ['total' => $total, 'list' => $list, 'page' => $page, 'limit' => $limit];
    }

    /**
     * 单条记录的时间线（按 change_set 倒序分组，含 diff HTML）
     *
     * @return array<int, array<string, mixed>>
     */
    public function timeline(string $model, string $pkValue): array
    {
        $maxRows = (int) config('change_log.timeline.max_rows', 500);
        $context = (int) config('change_log.timeline.context', 3);
        $diff    = new TextDiff((int) config('change_log.timeline.max_lines', TextDiff::DEFAULT_MAX_LINES));

        $rows = Db::name(self::TABLE)
            ->where('model', $model)
            ->where('pk_value', $pkValue)
            ->order('id', 'desc')
            ->limit($maxRows)
            ->select()
            ->toArray();

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row['change_set']][] = $row;
        }

        $timeline = [];
        foreach ($grouped as $set => $items) {
            $head    = $items[0];
            $changes = [];

            foreach ($items as $item) {
                $old = $item['old_value'];
                $new = $item['new_value'];

                $changes[] = [
                    'field'     => (string) $item['field'],
                    'label'     => (string) ($item['field_label'] ?: $item['field']),
                    'old'       => $old,
                    'new'       => $new,
                    'truncated' => (int) $item['truncated'] === 1,
                    'html'      => $this->renderDiff($diff, $old, $new, $context),
                ];
            }

            $timeline[] = [
                'change_set'    => (string) $set,
                'action'        => (string) $head['action'],
                'source'        => (string) ($head['source'] ?? 'model'),
                'operator_name' => (string) $head['operator_name'],
                'ip'            => (string) $head['ip'],
                'route'         => (string) $head['route'],
                'create_time'   => (string) $head['create_time'],
                'changes'       => $changes,
            ];
        }

        return $timeline;
    }

    /**
     * 取一组变更（回滚前展示 / 执行）
     *
     * @return array<int, array<string, mixed>>
     */
    public function findChangeSet(string $changeSet): array
    {
        if ($changeSet === '') {
            return [];
        }

        return Db::name(self::TABLE)
            ->where('change_set', $changeSet)
            ->order('id', 'asc')
            ->select()
            ->toArray();
    }


    /**
     * 回滚到该组变更发生之前的字段值
     *
     * 只回滚「该组记录到的字段」，不做整表/跨表回滚（任务卡 2B Out）。
     *
     * @return array{model: string, pk_value: string, fields: array<int,string>}
     * @throws \RuntimeException
     */
    public function rollback(string $changeSet): array
    {
        $rows = $this->findChangeSet($changeSet);
        if (empty($rows)) {
            throw new \RuntimeException('变更记录不存在');
        }

        $modelClass = $this->resolveRollbackModel((string) $rows[0]['model']);
        if ($modelClass === null) {
            throw new \RuntimeException('该模型未接入变更记录，禁止回滚');
        }

        $instance = new $modelClass();
        $pkField  = (string) $instance->getPk();

        if ((string) $rows[0]['pk_field'] !== $pkField) {
            throw new \RuntimeException('主键字段不匹配，拒绝回滚');
        }

        $pkValue = (string) $rows[0]['pk_value'];
        $table   = $instance->getName();

        $current = Db::name($table)->where($pkField, $pkValue)->find();
        if (empty($current)) {
            throw new \RuntimeException('原记录已不存在，无法回滚');
        }

        try {
            $columns = Db::name($table)->getTableFields();
        } catch (\Throwable $e) {
            $columns = [];
        }

        $ignore = array_merge(
            ['create_time', 'update_time', 'delete_time'],
            (array) config('change_log.rollback.ignore_fields', [])
        );

        $update  = [];
        $changes = [];

        foreach ($rows as $row) {
            $field = (string) $row['field'];

            if ($field === $pkField || in_array($field, $ignore, true)) {
                continue;
            }
            if (!empty($columns) && !in_array($field, $columns, true)) {
                continue;
            }
            if ((int) $row['truncated'] === 1) {
                throw new \RuntimeException("字段「{$field}」当时已截断存储，回滚会写入残缺数据，已拒绝");
            }

            $value = $row['old_value'] === null ? null : (string) $row['old_value'];

            $update[$field]  = $value;
            $changes[$field] = ['old' => $current[$field] ?? null, 'new' => $value];
        }

        if (empty($update)) {
            throw new \RuntimeException('该组变更没有可回滚字段');
        }

        Db::name($table)->where($pkField, $pkValue)->update($update);

        $this->recordRollback(
            $modelClass,
            (string) $rows[0]['model_label'],
            $pkField,
            $pkValue,
            (string) $rows[0]['title'],
            $changes
        );

        return ['model' => $modelClass, 'pk_value' => $pkValue, 'fields' => array_keys($update)];
    }

    /**
     * 写入回滚审计记录（独立 change_set，便于与原变更区分）
     *
     * 直接走 persist()，**故意不过 push() 的主体判定**：回滚本身是显式审计动作，
     * 无论由后台还是 CLI 发起都必须留痕（否则「谁回滚的」就查不到了）。
     *
     * @param array<string, array{old: mixed, new: mixed}> $changes
     */
    protected function recordRollback(
        string $modelClass,
        string $label,
        string $pkField,
        string $pkValue,
        string $title,
        array $changes
    ): void {
        $entry = [
            'model'        => $modelClass,
            'label'        => $label !== '' ? $label : $modelClass,
            'pk_field'     => $pkField,
            'pk_value'     => $pkValue,
            'title'        => $title,
            'action'       => 'rollback',
            'changes'      => $changes,
            'field_labels' => [],
            'time'         => date('Y-m-d H:i:s'),
            'source'       => 'rollback',
        ];

        $this->persist($this->buildRows($entry));
    }

    /**
     * 回滚模型白名单：必须显式 use ChangeLoggable
     */
    protected function resolveRollbackModel(string $modelClass): ?string
    {
        if ($modelClass === '' || !class_exists($modelClass)) {
            return null;
        }

        if (!is_subclass_of($modelClass, \think\Model::class)) {
            return null;
        }

        $traits = class_uses_recursive($modelClass);
        if (!isset($traits[ChangeLoggable::class])) {
            return null;
        }

        return $modelClass;
    }


    /**
     * 入口数据 → 可落库行（黑名单 / 截断 / 操作人快照 / change_set）
     *
     * @param array<string, mixed> $entry
     * @return array<int, array<string, mixed>>
     */
    protected function buildRows(array $entry): array
    {
        $changes = $entry['changes'] ?? [];
        if (!is_array($changes) || empty($changes)) {
            return [];
        }

        $model   = (string) ($entry['model'] ?? '');
        $pkField = (string) ($entry['pk_field'] ?? 'id');
        $pkValue = (string) ($entry['pk_value'] ?? '');
        $action  = in_array($entry['action'] ?? '', self::ACTIONS, true) ? (string) $entry['action'] : 'update';

        if ($model === '' || $pkValue === '') {
            return [];
        }

        $operator = $this->operator();
        $trace    = [
            'change_set'    => bin2hex(random_bytes(16)),
            'model'         => $model,
            'model_label'   => (string) ($entry['label'] ?? ''),
            'pk_field'      => $pkField,
            'pk_value'      => $pkValue,
            'title'         => mb_substr((string) ($entry['title'] ?? ''), 0, 200, 'UTF-8'),
            'action'        => $action,
            'source'        => (string) ($entry['source'] ?? 'model'),
            'operator_id'   => $operator['id'],
            'operator_name' => $operator['name'],
            'ip'            => $operator['ip'],
            'route'         => $operator['route'],
            'create_time'   => (string) ($entry['time'] ?? date('Y-m-d H:i:s')),
        ];

        $labels  = (array) ($entry['field_labels'] ?? []);
        $ignore  = array_flip((array) config('change_log.ignore_fields', []));
        $rows    = [];

        foreach ($changes as $field => $pair) {
            $field = (string) $field;

            if (isset($ignore[$field]) || $this->isSensitive($field)) {
                continue;
            }

            $oldTruncated = false;
            $newTruncated = false;
            $old = $this->toStoreValue($pair['old'] ?? null, $oldTruncated);
            $new = $this->toStoreValue($pair['new'] ?? null, $newTruncated);

            if ($old === null && $new === null) {
                continue;
            }
            if ($old === $new) {
                continue;
            }

            $rows[] = $trace + [
                'field'       => mb_substr($field, 0, 100, 'UTF-8'),
                'field_label' => mb_substr((string) ($labels[$field] ?? ''), 0, 100, 'UTF-8'),
                'old_value'   => $old,
                'new_value'   => $new,
                'truncated'   => ($oldTruncated || $newTruncated) ? 1 : 0,
            ];
        }

        return $rows;
    }

    /**
     * 值 → 存储字符串（null 保真；超长截断并标记）
     */
    protected function toStoreValue(mixed $value, bool &$truncated): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            $text = $value ? '1' : '0';
        } elseif (is_scalar($value)) {
            $text = (string) $value;
        } else {
            $text = (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $max = (int) config('change_log.max_value_bytes', 4096);
        if ($max > 0 && strlen($text) > $max) {
            $text = function_exists('mb_strcut')
                ? mb_strcut($text, 0, $max, 'UTF-8')
                : substr($text, 0, $max);
            $truncated = true;
        }

        return $text;
    }

    /** 是否敏感字段（字段名整字段丢弃） */
    protected function isSensitive(string $field): bool
    {
        if ($field === '') {
            return false;
        }

        if ($this->sensitiveRegex === null) {
            $patterns = (array) config('change_log.sensitive_fields', []);
            $regex    = [];

            foreach ($patterns as $pattern) {
                $pattern = trim((string) $pattern);
                if ($pattern === '') {
                    continue;
                }
                $regex[] = str_replace('\\*', '.*', preg_quote($pattern, '/'));
            }

            $this->sensitiveRegex = empty($regex) ? [] : $regex;
        }

        $field = strtolower($field);
        foreach ($this->sensitiveRegex as $pattern) {
            if (preg_match('/^' . strtolower($pattern) . '$/', $field) === 1) {
                return true;
            }
        }

        return false;
    }

    /** 队列 / 同步 */
    protected function mode(): string
    {
        return strtolower((string) config('change_log.mode', 'queue')) === 'sync' ? 'sync' : 'queue';
    }

    /**
     * 当前操作人快照（请求内缓存；CLI / 队列上下文为空）
     *
     * 同时产出「操作主体」`actor`，供 {@see shouldRecord()} 决定是否落库。
     *
     * @return array{id:int, name:string, ip:string, route:string, actor:string}
     */
    protected function operator(): array
    {
        $cached = \wise\support\context\RequestContext::get(self::OPERATOR_CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }

        $id    = 0;
        $name  = 'system';
        $ip    = '';
        $route = '';
        $actor = '';

        try {
            $guard   = \wise\support\context\RequestContext::getGuard();
            $ctxId   = \wise\support\context\RequestContext::getUserId();
            $ctxUser = \wise\support\context\RequestContext::getUser();

            if ($ctxUser !== null || $ctxId !== null) {
                $actor = $guard !== '' ? $guard : 'unknown';
                $id    = (int) ($ctxId ?? 0);
                $name  = (string) ($ctxUser->nickname ?? $ctxUser->username ?? $ctxUser->name ?? '');
            }
        } catch (\Throwable) {
        }

        if ($actor === '') {
            try {
                $user = app('auth')->guard('admin')->user();
                if ($user) {
                    $id    = (int) ($user->id ?? 0);
                    $name  = (string) ($user->username ?? $user->nickname ?? '');
                    $actor = 'admin';
                }
            } catch (\Throwable) {
            }
        }

        if ($actor === 'admin' && $name === '') {
            try {
                $user = app('auth')->guard('admin')->user();
                if ($user) {
                    $name = (string) ($user->username ?? $user->nickname ?? '');
                    $id   = $id > 0 ? $id : (int) ($user->id ?? 0);
                }
            } catch (\Throwable) {
            }
        }

        try {
            $request = request();
            if ($request && (string) $request->pathinfo() !== '') {
                $ip    = (string) $request->ip();
                $route = mb_substr((string) $request->pathinfo(), 0, 255, 'UTF-8');

                if ($actor === '') {
                    $actor = 'guest';
                }
            }
        } catch (\Throwable) {
        }

        if ($actor === '') {
            $actor = 'cli';
        }

        $snapshot = [
            'id'    => $id,
            'name'  => $name !== '' ? $name : 'system',
            'ip'    => $ip,
            'route' => $route,
            'actor' => strtolower($actor),
        ];

        \wise\support\context\RequestContext::set(self::OPERATOR_CACHE_KEY, $snapshot);

        return $snapshot;
    }

    /**
     * 主体判定：本次写入是否值得进入审计表
     *
     * 判定口径见 `config/change_log.php` 的 `actors`。设计取舍：
     *   - 用「认证主体」而不是「路由白名单」：主体是封闭集合（admin/member/cli/guest），
     *     路由是开放集合，枚举不全就会静默漏审计；
     *   - 判定放在 push() 最前面：命中即完全短路，会员自助/批量导入零落地开销。
     *
     * 注意：`rollback()` 的自身留痕走 {@see recordRollback()} → persist()，
     * 不经过本判定 —— 回滚是显式审计动作，必须留痕（即便在 CLI 下执行）。
     */
    protected function shouldRecord(): bool
    {
        $config = (array) config('change_log');
        $actors = array_key_exists('actors', $config) ? $config['actors'] : ['admin'];

        if ($actors === null || $actors === []) {
            return true;
        }

        $allow = [];
        foreach ((array) $actors as $actor) {
            $actor = strtolower(trim((string) $actor));
            if ($actor !== '') {
                $allow[] = $actor;
            }
        }

        if (in_array('*', $allow, true)) {
            return true;
        }

        if ($allow === []) {
            $this->logError('config change_log.actors 配置非法（空列表或全空值），采集已全部关闭');
            return false;
        }

        $operator = $this->operator();

        if (in_array($operator['actor'], $allow, true)) {
            return true;
        }

        $this->logSkipped($operator['actor'], $operator['route']);

        return false;
    }

    /**
     * 过滤命中日志（限流，避免会员高频自助操作刷爆日志）
     *
     * 审计系统最怕「静默失效」：配置写错、guard 变更导致主体判定全丢时，
     * 这里的一条 info 是唯一线索（本项目 `config/log.php` 不落 debug/warning）。
     */
    protected function logSkipped(string $actor, string $route): void
    {
        if (!(bool) config('change_log.log_skipped', true)) {
            return;
        }

        try {
            $onceKey = 'change_log:skip_logged:' . $actor;
            if (\wise\support\context\RequestContext::get($onceKey)) {
                return;
            }
            \wise\support\context\RequestContext::set($onceKey, 1);

            $key = 'change_log:skip:' . md5($actor . '|' . $route);
            if (\think\facade\Cache::get($key)) {
                return;
            }
            \think\facade\Cache::set($key, 1, 300);

            \wise\support\context\ContextualLogger::info(sprintf(
                '变更记录已按主体过滤 actor=%s route=%s',
                $actor,
                $route !== '' ? $route : '-'
            ));
        } catch (\Throwable) {
        }
    }

    /**
     * 渲染行级 diff HTML
     *
     * 注意：`TextDiff::diffText()` 返回 `['ops' => ..., 'degraded' => bool]` 包装结构，
     * 而 `toHtml()` 接收的是 **ops 列表本身**，两者不能直接串起来。
     */
    protected function renderDiff(TextDiff $diff, ?string $old, ?string $new, int $context): string
    {
        if (!$this->shouldRenderDiff($old, $new)) {
            return '';
        }

        $result = $diff->diffText((string) $old, (string) $new);
        if (empty($result['ops'])) {
            return '';
        }

        return $diff->toHtml($result['ops'], $context);
    }

    /** 是否需要渲染行级 diff（长文本 / 多行 / HTML） */
    protected function shouldRenderDiff(?string $old, ?string $new): bool
    {
        $old = (string) $old;
        $new = (string) $new;

        if ($old === '' && $new === '') {
            return false;
        }

        return strlen($old) > 200
            || strlen($new) > 200
            || str_contains($old, "\n")
            || str_contains($new, "\n")
            || str_contains($old . $new, '<p')
            || str_contains($old . $new, '<div');
    }

    /** 限流错误日志（避免采集异常刷爆日志） */
    protected function logError(string $message): void
    {
        try {
            $key = 'change_log:err:' . md5($message);
            if (\think\facade\Cache::get($key)) {
                return;
            }
            \think\facade\Cache::set($key, 1, 60);
            \wise\support\context\ContextualLogger::error($message, ['change_log']);
        } catch (\Throwable) {
        }
    }
}
