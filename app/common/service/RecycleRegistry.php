<?php
declare(strict_types=1);

namespace app\common\service;

use wise\support\contracts\RecycleProviderInterface;
use wise\support\ExtPoint;
use wise\support\ExtPoints;

/**
 * 回收站模型注册表＼�
 *
 * 消费 `ExtPoints::RECYCLE_PROVIDER`，把各来源（宿主 config / 包 / 插件）声明的
 * 软删模型汇总为一份清单，供全局回收站：
 *   - 按模型分组展示；
 *   - 还原 / 彻底删除 / 清空时的**白名单校验**（防 IDOR：模型必须在清单内）。
 *
 * 缓存策略：
 *   - **仅请求内缓存**（`$this->cache`），`flush()` 可清；
 *   - 不做跨请求缓存 —— 扩展点注册发生在 boot 阶段，FPM 每请求重算、
 *     Swoole 常驻需 reload 才生效；这是任务卡验收 #7「注册表变更立即可见」的要求。
 */
class RecycleRegistry
{
    /** @var array<string, array>|null 请求内缓存：模型 FQCN => 选项 */
    protected ?array $cache = null;

    /**
     * 全部回收站模型（模型 FQCN => 选项）
     *
     * 选项：
     *   - title  string   标题字段（用于列表展示，缺省 null = 回退主键）
     *   - group  string   分组名（缺省按模型短名）
     *   - fields string[] 列表额外展示字段（可选）
     *
     * @return array<string, array{title: ?string, group: string, fields: array<int, string>}>
     */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $this->cache = $this->collect();

        return $this->cache;
    }

    /** 清请求内缓存（测试 / 热更场景） */
    public function flush(): void
    {
        $this->cache = null;
    }

    /**
     * 按「模型 FQCN 或短名」查找注册项（白名单校验入口）
     *
     * @return array{class: string, options: array}|null 未注册返回 null
     */
    public function find(string $key): ?array
    {
        foreach ($this->all() as $class => $options) {
            if ($class === $key) {
                return ['class' => $class, 'options' => $options];
            }

            $short = (new \ReflectionClass($class))->getShortName();
            if (strcasecmp($short, $key) === 0) {
                return ['class' => $class, 'options' => $options];
            }
        }

        return null;
    }

    /** 已注册模型类名列表 */
    public function classes(): array
    {
        return array_keys($this->all());
    }


    protected function collect(): array
    {
        $models = [];

        try {
            if (!class_exists(ExtPoint::class) || !class_exists(ExtPoints::class)) {
                return [];
            }

            if (!ExtPoint::defined(ExtPoints::RECYCLE_PROVIDER)) {
                ExtPoint::definePoint(ExtPoints::RECYCLE_PROVIDER, ExtPoint::POLICY_MULTI);
            }

            foreach (ExtPoint::all(ExtPoints::RECYCLE_PROVIDER) as $item) {
                $list = $this->normalize($item['concrete'] ?? null);

                foreach ($list as $modelClass => $options) {
                    if (!is_string($modelClass) || !class_exists($modelClass)) {
                        continue;
                    }

                    $models[$modelClass] = $this->mergeOptions($modelClass, is_array($options) ? $options : []);
                }
            }
        } catch (\Throwable $e) {
        }

        return $models;
    }

    /**
     * 归一化扩展点实现（三种形态：类名 / 闭包 / 数组）
     *
     * @return array<string, array>
     */
    protected function normalize(mixed $concrete): array
    {
        try {
            if (is_array($concrete)) {
                return $concrete;
            }

            if (is_string($concrete) && class_exists($concrete)) {
                $instance = app($concrete);

                if ($instance instanceof RecycleProviderInterface) {
                    return $instance->recycleModels();
                }
            }

            if (is_callable($concrete)) {
                $result = $concrete();

                return is_array($result) ? $result : [];
            }
        } catch (\Throwable $e) {
        }

        return [];
    }

    /**
     * 合并选项并补默认值
     */
    protected function mergeOptions(string $modelClass, array $options): array
    {
        return [
            'title'  => is_string($options['title'] ?? null) && $options['title'] !== '' ? $options['title'] : null,
            'group'  => is_string($options['group'] ?? null) && $options['group'] !== '' ? $options['group'] : $this->guessGroup($modelClass),
            'fields' => array_values(array_filter((array) ($options['fields'] ?? []), 'is_string')),
        ];
    }

    /** 缺省分组名：模型短名 */
    protected function guessGroup(string $modelClass): string
    {
        try {
            return (new \ReflectionClass($modelClass))->getShortName();
        } catch (\Throwable $e) {
            return $modelClass;
        }
    }
}
