<?php
namespace app\common\model\collection;

class Collection extends \think\model\Collection
{
    /**
     * 获取多个字段，并可选地以某个字段为键
     *
     * @param  string|array $fields 字段列表，字符串用逗号分隔
     * @param  string|null  $key    作为键的字段名，不传则返回普通索引数组；传了则按该字段分组，返回二维数组
     * @return array
     */
    public function columns($fields, ?string $key = null): array
    {
        $isAllFields = false;
        if (is_string($fields) && trim($fields) === '*') {
            $isAllFields = true;
        } elseif (is_array($fields) && in_array('*', $fields)) {
            $isAllFields = true;
        }

        if (!$isAllFields) {
            if (is_string($fields)) {
                $fields = array_map('trim', explode(',', $fields));
            } elseif (!is_array($fields)) {
                throw new \InvalidArgumentException('fields 参数必须是字符串、数组或"*"');
            }
        }

        $result = [];
        foreach ($this->items as $item) {
            if ($isAllFields) {
                if (is_array($item)) {
                    $data = $item;
                } elseif (is_object($item)) {
                    $data = method_exists($item, 'toArray') ? $item->toArray() : get_object_vars($item);
                } else {
                    $data = [];
                }
            } else {
                $data = [];
                foreach ($fields as $field) {
                    if (is_array($item)) {
                        $data[$field] = $item[$field] ?? null;
                    } elseif (is_object($item)) {
                        $data[$field] = $item->$field ?? null;
                    }
                }
            }

            if ($key !== null) {
                $keyValue = is_array($item) ? ($item[$key] ?? '') : ($item->$key ?? '');
                $result[$keyValue][] = $data;
            } else {
                $result[] = $data;
            }
        }

        return $result;
    }

    /**
     * 按指定字段对集合进行分组（新增）
     * @param  string $field
     * @return static[]
     */
    public function group(string $field): array
    {
        $groups = [];
        foreach ($this->items as $item) {
            $value = is_array($item) ? ($item[$field] ?? null) : ($item->$field ?? null);
            $groups[$value][] = $item;
        }

        $result = [];
        foreach ($groups as $key => $items) {
            $result[$key] = new static($items);
        }
        return $result;
    }

    /**
     * 按字段分组统计数量
     * @param string $field 分组字段名
     * @return array
     *
     * 示例：根据状态统计订单数量
     * ```php
     * $orders = Order::where('date', '2025-01-01')->select();
     * $statusCounts = $orders->groupCount('status');
     * ```
     * 结果： ['待付款' => 5, '已付款' => 12, '已完成' => 30]
     */
    public function groupCount(string $field): array
    {
        $groups = $this->group($field);
        $counts = [];
        foreach ($groups as $key => $group) {
            $counts[$key] = count($group);
        }
        return $counts;
    }
}
