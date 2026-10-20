<?php
namespace app\common\library\token\driver;

use app\common\library\token\DriverInterface;
use think\Exception;
use think\facade\Redis;

/**
 * Redis驱动
 * 使用Redis存储Token
 */
class Redis implements DriverInterface
{
    /**
     * Redis连接实例
     * @var \Redis
     */
    protected $redis;

    /**
     * Key前缀
     * @var string
     */
    protected $prefix;

    /**
     * 构造方法
     * @param array $config 配置参数
     * @throws Exception
     */
    public function __construct(array $config)
    {
        $this->prefix = $config['prefix'] ?? 'token:';
        
        // 获取Redis实例
        $this->redis = Redis::connection()->handler();
        
        if (!$this->redis instanceof \Redis) {
            throw new Exception("Redis连接失败");
        }
    }

    /**
     * 生成Redis键名
     * @param string $token Token字符串
     * @return string
     */
    protected function getKey(string $token): string
    {
        return $this->prefix . $token;
    }

    /**
     * 生成用户Token集合键名
     * @param string $userId 用户ID
     * @return string
     */
    protected function getUserTokenKey(string $userId): string
    {
        return $this->prefix . 'user:' . $userId;
    }

    /**
     * 保存Token
     * @param array $data Token数据
     * @return bool
     */
    public function save(array $data): bool
    {
        $key = $this->getKey($data['token']);
        $expire = $data['expire_at'] - time();

        // 存储Token数据
        $result = $this->redis->hMSet($key, $data);
        
        // 设置过期时间
        if ($expire > 0) {
            $this->redis->expire($key, $expire);
        }

        // 将Token添加到用户Token集合（如果包含用户ID）
        $payload = json_decode($data['payload'], true);
        if (!empty($payload['user_id'])) {
            $userKey = $this->getUserTokenKey($payload['user_id']);
            $this->redis->sAdd($userKey, $data['token']);
            // 设置用户Token集合过期时间（比Token过期时间多一天）
            $this->redis->expire($userKey, $expire + 86400);
        }

        return $result;
    }

    /**
     * 获取Token信息
     * @param string $token Token字符串
     * @return array|null
     */
    public function get(string $token): ?array
    {
        $key = $this->getKey($token);
        $data = $this->redis->hGetAll($key);
        
        return $data ? $data : null;
    }

    /**
     * 更新Token信息
     * @param string $token Token字符串
     * @param array $data 要更新的数据
     * @return bool
     */
    public function update(string $token, array $data): bool
    {
        $key = $this->getKey($token);
        
        // 如果包含过期时间，更新Redis过期时间
        if (isset($data['expire_at'])) {
            $expire = $data['expire_at'] - time();
            if ($expire > 0) {
                $this->redis->expire($key, $expire);
            }
        }
        
        return $this->redis->hMSet($key, $data) !== false;
    }

    /**
     * 删除Token
     * @param string $token Token字符串
     * @return bool
     */
    public function delete(string $token): bool
    {
        $key = $this->getKey($token);
        
        // 获取用户ID以便从用户集合中移除
        $payload = $this->redis->hGet($key, 'payload');
        $payloadData = json_decode($payload, true);
        
        // 从用户Token集合中移除
        if (!empty($payloadData['user_id'])) {
            $userKey = $this->getUserTokenKey($payloadData['user_id']);
            $this->redis->sRem($userKey, $token);
        }
        
        // 删除Token
        return $this->redis->del($key) > 0;
    }

    /**
     * 根据条件删除Token
     * @param array $condition 条件
     * @return bool
     */
    public function deleteByCondition(array $condition): bool
    {
        // 目前主要支持按用户ID删除
        if (isset($condition['payload->user_id'])) {
            $userId = $condition['payload->user_id'];
            $userKey = $this->getUserTokenKey($userId);
            
            // 获取用户所有Token
            $tokens = $this->redis->sMembers($userKey);
            if ($tokens) {
                // 批量删除Token
                $keys = array_map(function($token) {
                    return $this->getKey($token);
                }, $tokens);
                
                $this->redis->del($keys);
                $this->redis->del($userKey);
            }
            
            return true;
        }
        
        return false;
    }

    /**
     * 清理过期的Token
     * Redis会自动清理过期键，这里返回0即可
     * @param int $currentTime 当前时间戳
     * @return int 清理的数量
     */
    public function cleanExpired(int $currentTime): int
    {
        // Redis会自动处理过期键，无需手动清理
        return 0;
    }
}
