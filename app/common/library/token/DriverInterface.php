<?php
namespace app\common\library\token;

/**
 * Token驱动接口
 * 所有Token存储驱动必须实现此接口
 */
interface DriverInterface
{
    /**
     * 构造方法
     * @param array $config 配置参数
     */
    public function __construct(array $config);

    /**
     * 保存Token
     * @param array $data Token数据
     * @return bool
     */
    public function save(array $data): bool;

    /**
     * 获取Token信息
     * @param string $token Token字符串
     * @return array|null
     */
    public function get(string $token): ?array;

    /**
     * 更新Token信息
     * @param string $token Token字符串
     * @param array $data 要更新的数据
     * @return bool
     */
    public function update(string $token, array $data): bool;

    /**
     * 删除Token
     * @param string $token Token字符串
     * @return bool
     */
    public function delete(string $token): bool;

    /**
     * 根据条件删除Token
     * @param array $condition 条件
     * @return bool
     */
    public function deleteByCondition(array $condition): bool;

    /**
     * 清理过期的Token
     * @param int $currentTime 当前时间戳
     * @return int 清理的数量
     */
    public function cleanExpired(int $currentTime): int;
}
