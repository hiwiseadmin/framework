<?php
namespace app\common\model\query;

class Query extends \think\db\Query
{
    /**
     * 当条件为真时，执行数组定义的多个条件或闭包
     * @param bool        $condition
     * @param array|Closure $where  例如 ['status' => 1, 'is_del' => 0]
     * @return $this
     *
     * 示例：根据状态查询用户
     * ```php
     * $list = User::whenIf(true, ['status' => 1])->select();
     * ```
     */
    public function whenIf($condition, $where)
    {
        if ($condition) {
            if (is_array($where)) {
                $this->where($where);
            } elseif ($where instanceof \Closure) {
                $where($this, $condition);
            }
        }
        return $this;
    }
}