<?php
declare(strict_types=1);

namespace app\common\service;

use think\facade\Config;
use wise\support\context\ContextualLogger;

/**
 * 服务端数据导出服务＼�
 *
 * 职责边界：
 *   - 只负责「把查询结果流式写成文件」与「列集合的安全解析」；
 *   - 不负责权限、路由、审计（由控制器 `CrudAction::_export()` 编排）；
 *   - 不做业务格式化（列标题/取值映射由调用方通过 $columns 传入，后端只做白名单校验）。
 *
 * 设计要点：
 *   1. **流式 + 分批**：按 `page($page, $chunk)` 拉取，禁止一次性 `select()` 全量进内存；
 *   2. **顺序一致**：分页沿用列表查询的 order，保证与页面显示顺序一致（验收 #2/#7）；
 *   3. **零依赖**：仅 CSV（UTF-8 BOM），XLSX 作为后续可选驱动；
 *   4. **敏感字段**：列解析阶段按黑名单剔除，任何情况下不进入文件（DoD 硬性）；
 *   5. **CSV 注入防护**：以 `= + - @` 等开头的文本加单引号前缀。
 *
 * 无状态：不持有请求级数据，无 static 缓存，可在 FPM / Swoole 下安全复用。
 */
class ExportService
{
    /**
     * 敏感字段名单（按「字段名包含」匹配，宁可多剔除）
     *
     * 覆盖 password / salt / token / secret 及其下划线变体（如 access_token、api_key）。
     *
     * @var array<int, string>
     */
    public const SENSITIVE_FIELDS = [
        'password', 'passwd', 'pwd', 'salt', 'token', 'secret',
        'private_key', 'access_key', 'api_key', 'app_secret',
    ];

    /**
     * 永不出现在导出中的系统字段（软删/乐观锁等）
     *
     * @var array<int, string>
     */
    public const SYSTEM_BLOCKED_FIELDS = [
        'delete_time', 'deleted_at', 'deletetime',
        'version', 'lock_version',
    ];

    /** 每批读取行数 */
    protected int $chunk;

    /** 单文件最大行数（超出直接拒绝，不落半截文件） */
    protected int $maxRows;

    public function __construct(?array $config = null)
    {
        $config = $config ?? (array) Config::get('crud.export', []);

        $this->chunk   = max(50, (int) ($config['chunk'] ?? 500));
        $this->maxRows = max(1, (int) ($config['max_rows'] ?? 50000));
    }


    /**
     * 解析导出列集合
     *
     * 规则（安全优先，不信任前端）：
     *   - 只允许模型真实表字段；
     *   - 剔除敏感字段与系统字段；
     *   - 顺序以调用方传入为准（与列表页列顺序一致），未传则用表字段原序；
     *   - 标题缺省回退为字段名。
     *
     * @param array<int, string>                          $tableFields 模型表字段
     * @param array<int, array{field?: string, title?: string}> $requested 前端声明的列
     * @return array<string, string> field => title（有序）
     */
    public function resolveColumns(array $tableFields, array $requested = []): array
    {
        $allowed = $this->filterAllowedFields($tableFields);

        $columns = [];
        foreach ($requested as $item) {
            if (!is_array($item)) {
                continue;
            }

            $field = trim((string) ($item['field'] ?? ''));
            if ($field === '' || !isset($allowed[$field])) {
                continue;
            }

            $title = trim((string) ($item['title'] ?? ''));
            $columns[$field] = $title !== '' ? $title : $field;
        }

        if (empty($columns)) {
            foreach (array_keys($allowed) as $field) {
                $columns[$field] = $field;
            }
        }

        return $columns;
    }

    /**
     * 过滤出可导出的表字段
     *
     * @param array<int, string> $tableFields
     * @return array<string, true>
     */
    public function filterAllowedFields(array $tableFields): array
    {
        $blocked = array_merge(self::SYSTEM_BLOCKED_FIELDS, self::SENSITIVE_FIELDS);
        $allowed = [];

        foreach ($tableFields as $field) {
            $field = (string) $field;
            if ($field === '' || $this->isBlocked($field, $blocked)) {
                continue;
            }
            $allowed[$field] = true;
        }

        return $allowed;
    }

    /**
     * 判断字段是否命中黑名单（全名相等 或 包含敏感词）
     *
     * @param array<int, string> $blocked
     */
    public function isBlocked(string $field, array $blocked = []): bool
    {
        $blocked = $blocked ?: array_merge(self::SYSTEM_BLOCKED_FIELDS, self::SENSITIVE_FIELDS);
        $name    = strtolower($field);

        foreach ($blocked as $item) {
            $item = strtolower((string) $item);
            if ($item === '') {
                continue;
            }
            if ($name === $item || str_contains($name, $item)) {
                return true;
            }
        }

        return false;
    }


    /**
     * 流式写出 CSV 文件
     *
     * @param string   $path         目标文件绝对路径（目录需已存在）
     * @param callable $queryFactory 每次调用返回**新的**查询对象（分页不互相污染）
     * @param array<string, string> $columns field => title
     * @param array{ids?: array<int, int>, pk?: string} $options
     * @return int 实际写入行数（不含表头）
     *
     * @throws \RuntimeException 打开文件失败或超过行数上限
     */
    public function csv(string $path, callable $queryFactory, array $columns, array $options = []): int
    {
        if (empty($columns)) {
            throw new \RuntimeException('导出列不能为空');
        }

        $handle = $this->openCsv($path, $columns);

        $rows   = 0;
        $page   = 1;
        $ids    = array_values(array_filter(array_map('intval', (array) ($options['ids'] ?? []))));
        $pk     = (string) ($options['pk'] ?? 'id');

        try {
            while (true) {
                $query = $queryFactory();

                if (!empty($ids)) {
                    $query = $query->whereIn($pk, $ids);
                }

                $list = $query->page($page, $this->chunk)->select();

                if ($list->isEmpty()) {
                    break;
                }

                foreach ($list as $row) {
                    $this->writeRow($handle, $this->rowToArray($row), $columns);
                    $rows++;
                    $this->guardMaxRows($rows);
                }

                if (count($list) < $this->chunk) {
                    break;
                }

                $page++;
            }
        } finally {
            fclose($handle);
        }

        return $rows;
    }

    /**
     * 按主键集合流式写出 CSV（异步导出任务用）
     *
     * 与 `csv()` 的差异：不依赖请求上下文（队列 worker 里没有 `Request`），
     * 订单与筛选结果在提交任务时已固化为**主键快照**（保证与列表完全一致，
     * 包括排序与自定义 `buildSearchQuery()` 钩子的结果）。
     *
     * @param string   $path         目标文件绝对路径
     * @param callable $modelFactory 返回模型实例（每次调用新建，避免查询状态污染）
     * @param array<int, int|string> $ids 主键快照（按列表顺序）
     * @param array<string, string>  $columns field => title
     * @param string   $pk           主键字段
     * @param callable|null $onProgress fn(int $done, int $total): void
     * @param callable|null $checkpoint fn(): void —— 抛异常表示已取消
     * @return int 实际写入行数
     */
    public function csvByIds(
        string $path,
        callable $modelFactory,
        array $ids,
        array $columns,
        string $pk = 'id',
        ?callable $onProgress = null,
        ?callable $checkpoint = null
    ): int {
        if (empty($columns)) {
            throw new \RuntimeException('导出列不能为空');
        }

        $handle = $this->openCsv($path, $columns);
        $total  = count($ids);
        $rows   = 0;

        try {
            foreach (array_chunk($ids, $this->chunk) as $chunk) {
                if ($checkpoint !== null) {
                    $checkpoint();
                }

                $model = $modelFactory();
                $list  = $model->whereIn($pk, $chunk)->select();

                $indexed = [];
                foreach ($list as $row) {
                    $data = $this->rowToArray($row);
                    $indexed[(string) ($data[$pk] ?? '')] = $data;
                }

                foreach ($chunk as $id) {
                    $key = (string) $id;
                    if (!isset($indexed[$key])) {
                        continue;
                    }

                    $this->writeRow($handle, $indexed[$key], $columns);
                    $rows++;
                    $this->guardMaxRows($rows);
                }

                if ($onProgress !== null) {
                    $onProgress($rows, $total);
                }
            }
        } finally {
            fclose($handle);
        }

        return $rows;
    }

    /**
     * 打开 CSV 并写 BOM + 表头
     *
     * @param array<string, string> $columns
     * @return resource
     */
    protected function openCsv(string $path, array $columns)
    {
        $handle = fopen($path, 'w');
        if ($handle === false) {
            throw new \RuntimeException('无法创建导出文件，请检查 runtime 目录权限');
        }

        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, array_values($columns), ',', '"', '');

        return $handle;
    }

    /**
     * 写一行数据（按列顺序取值并清洗）
     *
     * @param resource              $handle
     * @param array<string, mixed>  $data
     * @param array<string, string> $columns
     */
    protected function writeRow($handle, array $data, array $columns): void
    {
        $line = [];
        foreach (array_keys($columns) as $field) {
            $line[] = $this->sanitizeCell($data[$field] ?? null);
        }

        fputcsv($handle, $line, ',', '"', '');
    }

    /** 模型/数组行统一转数组（保留原始值，避免获取器改写） */
    protected function rowToArray(mixed $row): array
    {
        return is_object($row) && method_exists($row, 'getData') ? (array) $row->getData() : (array) $row;
    }

    /** 行数上限守卫 */
    protected function guardMaxRows(int $rows): void
    {
        if ($rows > $this->maxRows) {
            throw new \RuntimeException(
                sprintf('数据量超过单次导出上限（%d 行），请缩小筛选范围', $this->maxRows)
            );
        }
    }

    /**
     * 单元格取值清洗
     *
     * - null          → ''
     * - bool          → 1 / 0
     * - 数组/对象     → JSON
     * - 以公式字符开头 → 前缀单引号，避免 CSV 注入
     */
    public function sanitizeCell(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            $value = $value === false ? '' : $value;
        }

        $text = (string) $value;

        if ($text !== '' && preg_match('/^[=+\-@\t\r]/', $text) === 1) {
            $text = "'" . $text;
        }

        return $text;
    }


    /**
     * 生成本次导出的落地路径（runtime/export/{adminId}/）
     *
     * @return array{path: string, filename: string}
     */
    public function makeTarget(string $baseName, int $adminId = 0, string $format = 'csv'): array
    {
        $dir = runtime_path() . 'export' . DIRECTORY_SEPARATOR . ($adminId > 0 ? $adminId : 0) . DIRECTORY_SEPARATOR;

        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException('无法创建导出目录，请检查 runtime 权限');
        }

        $base     = $this->sanitizeFilename($baseName);
        $filename = $base . '_' . date('YmdHis') . '.' . $format;

        return ['path' => $dir . $filename, 'filename' => $filename];
    }

    /**
     * 文件名清洗（去掉路径分隔符与危险字符，保留中英文数字下划线短横线）
     */
    public function sanitizeFilename(string $name): string
    {
        $name = trim($name);
        $name = preg_replace('/[^\p{Han}\w\-]+/u', '_', $name) ?? '';
        $name = trim($name, '_');

        return $name !== '' ? mb_substr($name, 0, 60) : 'export';
    }

    /**
     * 清理过期导出文件
     *
     * @return int 删除数量
     */
    public function cleanExpired(int $days = 7): int
    {
        $root = runtime_path() . 'export' . DIRECTORY_SEPARATOR;
        if (!is_dir($root)) {
            return 0;
        }

        $threshold = time() - max(1, $days) * 86400;
        $deleted   = 0;

        foreach (glob($root . '*', GLOB_ONLYDIR) ?: [] as $dir) {
            foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                if (is_file($file) && filemtime($file) < $threshold) {
                    if (@unlink($file)) {
                        $deleted++;
                    }
                }
            }
        }

        if ($deleted > 0) {
            ContextualLogger::channel('export')->info('清理过期导出文件', ['deleted' => $deleted, 'days' => $days]);
        }

        return $deleted;
    }
}
