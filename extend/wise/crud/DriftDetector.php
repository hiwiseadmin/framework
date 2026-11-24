<?php
declare(strict_types=1);

namespace wise\crud;

/**
 * DriftDetector — 生成物「落后于表结构」的检测（**只读，绝不写盘**）。
 *
 * 为什么需要它：`--force` 之外，生成器对已存在文件一律跳过（不比对内容）。
 * 于是表加了列、重跑生成，四个产物全部 `=` 跳过、没有任何提示，实际后果是：
 *   - 控制器 `defineForm()` 里没有新字段 → 表单不显示；
 *   - 模型 `$allowWriteField` 里没有新字段 → **提交时被 filterDataGeneric 静默 unset**；
 *   - JS 列配置里没有新字段 → 列表不显示。
 * 三处都不会报错，只会「字段神秘消失」。本类把这件事显式化。
 *
 * 判定口径与生成器保持一致（同一份 plan、同一套 skip_form 规则），
 * 只比较**字段集合**，不比较内容 —— 内容是你手写的，生成器无权评判。
 */
class DriftDetector
{
    /**
     * JS 列配置里的固定列：`ck`（勾选框）、`id`（主键，模板里写死）、`operate`（操作列）。
     * 它们不由字段循环产出，比对时必须排除，否则每次都会误报。
     */
    protected const JS_RESERVED = ['ck', 'id', 'operate'];

    /**
     * @param string $root 项目根目录（含尾部分隔符）
     */
    public function __construct(protected string $root)
    {
    }

    /**
     * 检测一组产物文件。
     *
     * @param array<string, mixed>       $plan
     * @param array<string, string>      $files ['controller' => 相对路径, 'model' => ..., 'js' => ...]
     * @return array<int, array{file: string, kind: string, missing: array<int, string>, extra: array<int, string>}>
     *         仅返回有差异的条目；无差异返回空数组
     */
    public function detect(array $plan, array $files): array
    {
        $drift = [];

        foreach ($files as $kind => $relative) {
            if (!is_string($relative) || $relative === '') {
                continue;
            }

            $path = $this->root . str_replace('/', DIRECTORY_SEPARATOR, $relative);

            if (!is_file($path)) {
                continue;
            }

            $declared = $this->parseDeclared($kind, (string) file_get_contents($path));

            if ($declared === null) {
                continue;
            }

            $expected = $this->expectedFields($plan, $kind);

            $missing = array_values(array_diff($expected, $declared));
            $extra   = array_values(array_diff($declared, $expected));

            if ($missing === [] && $extra === []) {
                continue;
            }

            $drift[] = [
                'file'    => $relative,
                'kind'    => $kind,
                'missing' => $missing,
                'extra'   => $extra,
            ];
        }

        return $drift;
    }

    /**
     * 该产物「应该有」的字段集合（与渲染逻辑同源）。
     *
     * @param array<string, mixed> $plan
     * @return array<int, string>
     */
    public function expectedFields(array $plan, string $kind): array
    {
        $fields = [];

        foreach ((array) ($plan['fields'] ?? []) as $field) {
            $name   = (string) ($field['name'] ?? '');
            $system = (string) ($field['system'] ?? '');

            if ($name === '') {
                continue;
            }

            if ($kind === 'js') {
                if (empty($field['skip_form']) || in_array($system, ['create_time', 'update_time'], true)) {
                    $fields[] = $name;
                }

                continue;
            }

            if (empty($field['skip_form'])) {
                $fields[] = $name;
            }
        }

        return $fields;
    }

    /**
     * 从已有文件里解析出「实际声明的字段」，解析不到返回 null（不误报）。
     *
     * @return array<int, string>|null
     */
    protected function parseDeclared(string $kind, string $content): ?array
    {
        if ($kind === 'model') {
            if (preg_match('/\$allowWriteField\s*=\s*\[(.*?)\];/s', $content, $block) !== 1) {
                return null;
            }

            preg_match_all("/'([^']+)'/", $block[1], $matches);

            return array_values(array_unique($matches[1] ?? []));
        }

        if ($kind === 'js') {
            preg_match_all("/field:\\s*'([^']+)'/", $content, $matches);

            $fields = array_values(array_unique($matches[1] ?? []));

            return array_values(array_diff($fields, self::JS_RESERVED));
        }

        preg_match_all('/\$form->add\(\w+::make\(\'([^\']+)\'/', $content, $matches);

        if ($matches[1] === []) {
            return null;
        }

        return array_values(array_unique($matches[1]));
    }
}
