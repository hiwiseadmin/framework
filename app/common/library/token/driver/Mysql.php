<?php
namespace app\common\library\token\driver;

use app\common\library\token\DriverInterface;
use think\db\Query;
use think\facade\Db;
use think\Exception;

/**
 * MySQL驱动
 * 使用数据库存储Token
 */
class Mysql implements DriverInterface
{
    /**
     * 数据库查询对象
     * @var Query
     */
    protected $query;

    /**
     * 表名
     * @var string
     */
    protected $table;

    /**
     * 构造方法
     * @param array $config 配置参数
     * @throws Exception
     */
    public function __construct(array $config)
    {
        // 检查表名配置
        if (empty($config['table'])) {
            throw new Exception("MySQL Token驱动缺少表名配置");
        }

        $this->table = $config['table'];
        $this->query = Db::name($this->table);
    }

    /**
     * 保存Token
     * @param array $data Token数据
     * @return bool
     */
    public function save(array $data): bool
    {
        // 先删除可能存在的相同Token
        $this->delete($data['token']);
        
        return $this->query->insert($data) !== false;
    }

    /**
     * 获取Token信息
     * @param string $token Token字符串
     * @return array|null
     */
    public function get(string $token): ?array
    {
        return $this->query->where('token', $token)->find();
    }

    /**
     * 更新Token信息
     * @param string $token Token字符串
     * @param array $data 要更新的数据
     * @return bool
     */
    public function update(string $token, array $data): bool
    {
        return $this->query->where('token', $token)->update($data) !== false;
    }

    /**
     * 删除Token
     * @param string $token Token字符串
     * @return bool
     */
    public function delete(string $token): bool
    {
        return $this->query->where('token', $token)->delete() !== false;
    }

    /**
     * 根据条件删除Token
     * @param array $condition 条件
     * @return bool
     */
    public function deleteByCondition(array $condition): bool
    {
        $query = $this->query;
        
        foreach ($condition as $key => $value) {
            // 处理JSON字段查询，如 'payload->user_id'
            if (strpos($key, '->') !== false) {
                list($field, $jsonKey) = explode('->', $key, 2);
                $query->whereRaw("JSON_EXTRACT(`{$field}`, '$.{$jsonKey}') = ?", [$value]);
            } else {
                $query->where($key, $value);
            }
        }
        
        return $query->delete() !== false;
    }

    /**
     * 清理过期的Token
     * @param int $currentTime 当前时间戳
     * @return int 清理的数量
     */
    public function cleanExpired(int $currentTime): int
    {
        return $this->query->where('expire_at', '<', $currentTime)->delete();
    }
}
