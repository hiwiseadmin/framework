<?php
declare(strict_types=1);

namespace wise\crud;

/**
 * RecycleRegistrar — 把生成的软删模型并入宿主 `config/recycle.php`。
 *
 * 背景：全局回收站（`admin/recycle` + `app\common\service\RecycleRegistry`）是
 * **fail-closed** 的 —— 未在 `ExtPoints::RECYCLE_PROVIDER` 注册的模型一律不可见。
 * 因此「表里有 delete_time、模型也用了 SoftDelete」只解决了一半：
 * 不注册就永远查不到已删记录，等于数据在库里但界面上消失。
 *
 * 设计约束（与 {@see CrudMap::mergeInto} 同一套安全策略）：
 *   - **只增量合并**：已有条目原样保留、顺序不变；
 *   - **保留文件头**：`return [` 之前的注释/文档原样写回，只重排数组体；
 *   - **幂等**：同一模型重复注册不会产生重复项；
 *   - **插件模式不写**：插件的模型由插件自己的 ServiceProvider 注册，
 *     写进宿主 config 会在插件卸载后留下指向不存在类的悬空项（见 config/recycle.php 注释）。
 *
 * 局限（已在返回值 message 中提示）：数组体内部的**行内注释**会在重写时丢失，
 * 条目数据本身不受影响。
 */
class RecycleRegistrar
{
    /** 宿主回收站清单的相对路径 */
    public const CONFIG_FILE = 'config/recycle.php';

    /** 生成器写出的文件标记（手工删除后，生成器仍可用「保留文件头」的方式合并） */
    public const GENERATED_MARKER = '@wise:generated';

    /**
     * @param string $projectRoot 项目根目录（含尾部分隔符）
     */
    public function __construct(protected string $projectRoot)
    {
    }

    public function filePath(): string
    {
        return rtrim($this->projectRoot, '/\\') . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, self::CONFIG_FILE);
    }

    /**
     * 注册（或确认已注册）一个模型。
     *
     * @param string      $modelClass 模型 FQCN（不带前导反斜杠）
     * @param string|null $titleField 列表标题字段；null 表示回退主键
     * @return array{ok: bool, status: string, message: string, snippet: string}
     *         status: written（已写入）/ unchanged（已存在）/ error（失败）
     */
    public function register(string $modelClass, ?string $titleField = null): array
    {
        $modelClass = ltrim(trim($modelClass), '\\');

        if ($modelClass === '') {
            return $this->result(false, 'error', '模型类名为空，跳过回收站注册');
        }

        $path = $this->filePath();
        $models = [];

        if (is_file($path)) {
            $models = $this->readModels($path);

            if ($models === null) {
                return $this->result(false, 'error', sprintf(
                    '无法解析 %s（未找到 `return [`），已跳过回收站注册；请手工添加：%s',
                    self::CONFIG_FILE,
                    PHP_EOL . $this->snippet($modelClass, $titleField)
                ));
            }
        }

        if (isset($models[$modelClass])) {
            return $this->result(true, 'unchanged', sprintf(
                '%s 已在回收站清单中（%s），未改动',
                $modelClass,
                self::CONFIG_FILE
            ));
        }

        $models[$modelClass] = $this->entry($titleField);

        $dir = dirname($path);

        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return $this->result(false, 'error', '无法创建目录：' . $dir);
        }

        $written = file_put_contents($path, $this->compose($path, $models));

        if ($written === false) {
            return $this->result(false, 'error', '写入失败：' . self::CONFIG_FILE . '（请检查文件权限）');
        }

        return $this->result(true, 'written', sprintf(
            '已把 %s 注册进 %s（全局回收站可见；如需关闭：--no-recycle）',
            $modelClass,
            self::CONFIG_FILE
        ));
    }

    /**
     * 读取已有清单（`return [...]` 里的 `models`），解析失败返回 null。
     *
     * @return array<string, array<string, mixed>>|null
     */
    protected function readModels(string $path): ?array
    {
        $content = (string) file_get_contents($path);

        if (strpos($content, 'return [') === false) {
            return null;
        }

        try {
            $data = include $path;
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($data)) {
            return null;
        }

        $models = $data['models'] ?? [];

        return is_array($models) ? $models : [];
    }

    /**
     * @param string|null $titleField
     * @return array<string, mixed>
     */
    protected function entry(?string $titleField): array
    {
        $entry = [];

        if ($titleField !== null && $titleField !== '') {
            $entry['title'] = $titleField;
        }

        return $entry;
    }

    /**
     * 组装文件内容：**保留 `return [` 之前的全部内容**，只重排数组体。
     *
     * @param array<string, array<string, mixed>> $models
     */
    protected function compose(string $path, array $models): string
    {
        if (is_file($path)) {
            $content  = (string) file_get_contents($path);
            $position = strpos($content, 'return [');
            $prologue = $position === false ? "<?php\n\n" : substr($content, 0, (int) $position);
        } else {
            $prologue = "<?php\n\n"
                . "/**\n"
                . " * 回收站 —— 宿主侧注册清单\n"
                . " *\n"
                . ' * ' . self::GENERATED_MARKER . " 由 `php think wise:crud` 维护，可手工增删条目。\n"
                . " * 不注册的模型不会出现在全局回收站（fail-closed）。\n"
                . " */\n\n";
        }

        return $prologue . $this->renderArray($models);
    }

    /**
     * @param array<string, array<string, mixed>> $models
     */
    protected function renderArray(array $models): string
    {
        $lines = ["return [", "    'models' => ["];

        foreach ($models as $class => $options) {
            $lines[] = sprintf('        \%s::class => [', ltrim((string) $class, '\\'));

            foreach ((array) $options as $key => $value) {
                $lines[] = sprintf('            %s => %s,', var_export((string) $key, true), $this->export($value));
            }

            $lines[] = '        ],';
        }

        $lines[] = '    ],';
        $lines[] = '];';

        return implode("\n", $lines) . "\n";
    }

    /**
     * 值导出：字符串用单引号、数组用行内 `[...]`（与仓库既有手写风格一致）。
     */
    protected function export(mixed $value): string
    {
        if (is_array($value)) {
            $items = [];

            foreach ($value as $key => $item) {
                $items[] = is_int($key)
                    ? $this->export($item)
                    : sprintf('%s => %s', var_export((string) $key, true), $this->export($item));
            }

            return '[' . implode(', ', $items) . ']';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return var_export((string) $value, true);
    }

    /**
     * 可粘贴片段（解析失败时给出，保证开发者不会两头空）。
     */
    public function snippet(string $modelClass, ?string $titleField): string
    {
        return $this->renderArray([ltrim($modelClass, '\\') => $this->entry($titleField)]);
    }

    /**
     * @return array{ok: bool, status: string, message: string, snippet: string}
     */
    protected function result(bool $ok, string $status, string $message, string $snippet = ''): array
    {
        return ['ok' => $ok, 'status' => $status, 'message' => $message, 'snippet' => $snippet];
    }
}
