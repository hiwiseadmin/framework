<?php
namespace app\common\library\token;

use think\Exception;
use think\facade\Config;

/**
 * Token管理类
 * 支持MySQL和Redis两种存储方式，可通过配置切换
 */
class Token
{
    /**
     * 当前驱动实例
     * @var DriverInterface
     */
    protected $driver;

    /**
     * Token配置
     * @var array
     */
    protected $config;

    /**
     * 构造方法
     * @param array $config 配置参数，为空则使用默认配置
     * @throws Exception
     */
    public function __construct(array $config = [])
    {
        // 合并配置
        $this->config = array_merge(
            Config::get('token', []),
            $config
        );

        // 检查必要配置
        $this->checkConfig();

        // 初始化驱动
        $this->initDriver();
    }

    /**
     * 检查配置是否完整
     * @throws Exception
     */
    protected function checkConfig()
    {
        $required = ['driver', 'prefix', 'expire'];
        
        foreach ($required as $key) {
            if (!isset($this->config[$key])) {
                throw new Exception("Token配置缺失: {$key}");
            }
        }
    }

    /**
     * 初始化驱动
     * @throws Exception
     */
    protected function initDriver()
    {
        $driver = $this->config['driver'];
        $driverClass = '';

        switch (strtolower($driver)) {
            case 'mysql':
                $driverClass = 'app\\common\\library\\token\\driver\\Mysql';
                break;
            case 'redis':
                $driverClass = 'app\\common\\library\\token\\driver\\Redis';
                break;
            default:
                throw new Exception("不支持的Token驱动: {$driver}");
        }

        if (!class_exists($driverClass) || !is_subclass_of($driverClass, DriverInterface::class)) {
            throw new Exception("Token驱动类不存在或未实现DriverInterface接口: {$driverClass}");
        }

        $this->driver = new $driverClass($this->config);
    }

    /**
     * 生成Token
     * @param array $payload 负载数据
     * @param int|null $expire 过期时间(秒)，为null则使用配置值
     * @return string
     */
    public function generate(array $payload = [], int $expire = null): string
    {
        // 生成唯一Token字符串
        $token = $this->createTokenString();
        
        // 处理过期时间
        $expireTime = $expire ?? $this->config['expire'];
        $expireAt = time() + $expireTime;

        // 准备存储数据
        $data = [
            'token'     => $token,
            'payload'   => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'expire_at' => $expireAt,
            'create_time' => time(),
            'update_time' => time()
        ];

        // 保存Token
        $this->driver->save($data);

        return $token;
    }

    /**
     * 创建Token字符串
     * @return string
     */
    protected function createTokenString(): string
    {
        return md5(uniqid(mt_rand(), true) . microtime(true) . openssl_random_pseudo_bytes(16));
    }

    /**
     * 验证Token是否有效
     * @param string $token Token字符串
     * @param bool $autoRenew 是否自动续期
     * @return bool
     */
    public function validate(string $token, bool $autoRenew = false): bool
    {
        $info = $this->driver->get($token);
        
        if (!$info) {
            return false;
        }

        // 检查是否过期
        if ($info['expire_at'] < time()) {
            $this->delete($token);
            return false;
        }

        // 自动续期
        if ($autoRenew) {
            $this->renew($token);
        }

        return true;
    }

    /**
     * 获取Token负载数据
     * @param string $token Token字符串
     * @param bool $autoRenew 是否自动续期
     * @return array|null
     */
    public function getPayload(string $token, bool $autoRenew = false): ?array
    {
        if (!$this->validate($token, $autoRenew)) {
            return null;
        }

        $info = $this->driver->get($token);
        return json_decode($info['payload'], true) ?: [];
    }

    /**
     * 更新Token负载数据
     * @param string $token Token字符串
     * @param array $payload 新的负载数据
     * @param bool $merge 是否合并旧数据
     * @return bool
     */
    public function updatePayload(string $token, array $payload, bool $merge = true): bool
    {
        if (!$this->validate($token)) {
            return false;
        }

        $info = $this->driver->get($token);
        $oldPayload = json_decode($info['payload'], true) ?: [];
        
        // 合并数据
        if ($merge) {
            $payload = array_merge($oldPayload, $payload);
        }

        return $this->driver->update($token, [
            'payload'   => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'update_time' => time()
        ]);
    }

    /**
     * 续期Token
     * @param string $token Token字符串
     * @param int|null $expire 续期时间(秒)，为null则使用配置值
     * @return bool
     */
    public function renew(string $token, int $expire = null): bool
    {
        if (!$this->validate($token)) {
            return false;
        }

        $expireTime = $expire ?? $this->config['expire'];
        $expireAt = time() + $expireTime;

        return $this->driver->update($token, [
            'expire_at' => $expireAt,
            'update_time' => time()
        ]);
    }

    /**
     * 删除Token
     * @param string $token Token字符串
     * @return bool
     */
    public function delete(string $token): bool
    {
        return $this->driver->delete($token);
    }

    /**
     * 批量删除用户的所有Token
     * @param string $userId 用户标识
     * @return bool
     */
    public function deleteByUserId(string $userId): bool
    {
        return $this->driver->deleteByCondition([
            'payload->user_id' => $userId // JSON字段查询，适用于MySQL 5.7+和Redis
        ]);
    }

    /**
     * 清理过期的Token
     * @return int 清理的数量
     */
    public function cleanExpired(): int
    {
        return $this->driver->cleanExpired(time());
    }

    /**
     * 获取Token信息
     * @param string $token Token字符串
     * @return array|null
     */
    public function getInfo(string $token): ?array
    {
        return $this->driver->get($token);
    }

    /**
     * 获取当前驱动实例
     * @return DriverInterface
     */
    public function getDriver(): DriverInterface
    {
        return $this->driver;
    }

    /**
     * 静态创建方法
     * @param array $config 配置参数
     * @return static
     * @throws Exception
     */
    public static function create(array $config = []): self
    {
        return new self($config);
    }
}
