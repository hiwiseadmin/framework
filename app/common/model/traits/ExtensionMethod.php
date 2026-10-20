<?php
namespace app\common\model\traits;

use think\db\Query;

/**
 * 提供通用扩展方法的 Trait
 *
 * 引入本 Trait 的模型（如 BaseModel 及其子类）可直接调用以下对外方法：
 *
 * 本 Trait 提供的方法：
 * @method bool        validateSave(array $data, array $rules = [], string $scene = '')    统一保存入口：先验证再保存，验证失败返回 false，保存成功返回 true
 * @method static int  batchUpdate(array $data, string $key = 'id', array $allowKeys = []) 一条 SQL 批量更新多行不同字段，返回影响行数（字段名走白名单校验防注入）
 * @method static \think\db\Query withOnly($with, $fields = [])                            预加载关联并限制关联模型字段，返回查询对象（自动附加关联主键）
 *
 * 依赖的模型 / 其它 Trait 方法（可一并使用）：
 * @method string getTable()              获取当前模型表名
 * @method string getPk()                 获取当前模型主键名
 * @method bool   validateData(array $data, array $rules = [], string $scene = '')  执行数据验证（详见 DataValidation Trait）
 * @method bool   validateOrFail(array $data, array $rules = [], string $scene = '') 执行数据验证，失败抛 ValidateException（详见 DataValidation Trait）
 * @method bool   save(array $data = [], array $allowField = [], bool $autoWriteTimestamp = true) 保存数据
 * @method static \think\db\Query with($with) 预加载关联
 *
 * 使用示例：
 * ```php
 * // 1. 先验证再保存（验证失败返回 false）
 * $model->validateSave($data, [], 'create');
 *
 * // 2. 一条 SQL 批量更新不同字段（$allowKeys 白名单防注入）
 * User::batchUpdate([
 *     ['id' => 1, 'sort' => 10, 'name' => '张三'],
 *     ['id' => 2, 'sort' => 20, 'name' => '李四'],
 * ], 'id', ['sort', 'name']);
 *
 * // 3. 预加载关联并限制字段
 * $user = User::withOnly('profile', ['avatar', 'nickname'])->find(1);
 * ```
 */
trait ExtensionMethod
{
    /**
     * 统一的数据保存入口（自动验证..\validate\目录下同名验证类）
     *
     * @param array $data
     * @param array $rules 验证规则（优先级最高）
     * @param string $scene 验证场景
     * @return bool 验证失败返回 false，保存成功返回 true
     */
    public function validateSave(array $data, array $rules = [], string $scene = ''): bool
    {
        if (!$this->validateData($data, $rules, $scene)) {
            return false;
        }
        return $this->save($data);
    }

    /**
     * 批量更新多条记录的不同字段（一条 SQL）
     *
     * 仅支持整数主键，字段名通过正则白名单校验防止 SQL 注入。
     *
     * @param array  $data      二维数组，必须包含主键字段，且更新字段必须保持一致
     * @param string $key       主键字段名（仅允许字母、数字、下划线）
     * @param array  $allowKeys 允许更新的字段列表（安全过滤）
     * @return int              影响行数
     *
     * 示例：批量更新用户排序和姓名，一条 SQL 完成
     * ```php
     * User::batchUpdate([
     *     ['id' => 1, 'sort' => 10, 'name' => '张三'],
     *     ['id' => 2, 'sort' => 20, 'name' => '李四'],
     * ], 'id', ['sort', 'name']);
     * ```
     */
    public static function batchUpdate(array $data, string $key = 'id', array $allowKeys = []): int
    {
        if (empty($data)) {
            return 0;
        }

        // 校验主键字段名，防止 SQL 注入
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $key)) {
            throw new \InvalidArgumentException('Invalid primary key field name: ' . $key);
        }

        $table = (new static())->getTable();

        // 取第一行获取字段名（排除主键）
        $first  = current($data);
        $fields = array_diff(array_keys($first), [$key]);

        if ($allowKeys) {
            $fields = array_intersect($fields, $allowKeys);
        }

        // 校验所有更新字段名，防止 SQL 注入
        foreach ($fields as $field) {
            if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $field)) {
                throw new \InvalidArgumentException('Invalid field name: ' . $field);
            }
        }

        // 无有效更新字段时直接返回，避免生成非法 SQL
        if (empty($fields)) {
            return 0;
        }

        // 复用同一个数据库连接，避免在字段循环内反复建连
        $pdo = app('db')->connect()->getPdo();

        $cases = [];
        foreach ($fields as $field) {
            $case = "`{$field}` = CASE `{$key}`";
            foreach ($data as $row) {
                $val    = $row[$field] ?? null;
                // 标量统一转字符串后 quote 防注入；NULL 单独处理
                $val    = (is_string($val) || is_int($val) || is_float($val)) ? $pdo->quote((string) $val) : 'NULL';
                $keyVal = (int) $row[$key];
                $case  .= " WHEN {$keyVal} THEN {$val}";
            }
            $case  .= " END";
            $cases[] = $case;
        }

        $ids = array_column($data, $key);
        $ids = implode(',', array_map('intval', $ids));

        $sql = "UPDATE `{$table}` SET " . implode(',', $cases) . " WHERE `{$key}` IN ({$ids})";

        return app('db')->execute($sql);
    }

    /**
     * 预加载关联并限制关联模型字段
     *
     * @param array|string $with    关联名，例如 'profile'，或 ['profile','orders']
     * @param array|string $fields 关联字段，字符串或数组
     * @return \think\model\relation\HasMany 等
     *
     * 示例：只取 profile 的 avatar,nickname 字段
     * $user = User::withOnly('profile', ['avatar', 'nickname'])->find(1);
     *
     * 示例：预加载用户关联的订单和 profile，分别查询不同的字段
     * ```php
     * $user = User::withOnly(['profile','orders'], [
     *     'profile' => ['avatar'],
     *     'orders'  => ['order_no','amount']
     * ])->find(1);
     * ```
     */
    public static function withOnly($with, $fields = [])
    {
        $query   = self::with($with);
        $withList = is_array($with) ? $with : [$with];

        if (is_array($fields) && array_keys($fields) === range(0, count($fields) - 1)) {
            $fields = array_fill_keys($withList, $fields);
        }

        foreach ($withList as $relationName) {
            $relationFields = $fields[$relationName] ?? [];
            if (!empty($relationFields)) {
                $relationModel = (new static())->$relationName()->getModel();
                $pk           = $relationModel->getPk();

                if (is_string($relationFields)) {
                    $relationFields = explode(',', $relationFields);
                }
                $relationFields = array_unique(array_merge([$pk], $relationFields));

                $query->with([$relationName => function (Query $q) use ($relationFields) {
                    $q->field($relationFields);
                }]);
            }
        }

        return $query;
    }
}
