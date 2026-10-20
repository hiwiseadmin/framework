<?php
namespace app\common\model\traits;

/**
 * Trait: 乐观锁扩展
 *
 * 提供并发控制下的数据安全更新机制。
 * 默认关闭（`$optimisticLock = false`），需调用 enableOptimisticLock() 显式启用，
 * 并使用版本字段（默认 `version`，对应表需存在该字段）做 CAS 更新，冲突时自动重试。
 *
 * 本 Trait 提供的对外方法：
 * @method $this enableOptimisticLock()                              启用乐观锁（实例级），返回 $this 支持链式调用
 * @method $this disableOptimisticLock()                             禁用乐观锁，返回 $this 支持链式调用
 * @method $this setLockVersionField(string $field)                  设置乐观锁版本字段名（默认 version），返回 $this
 * @method $this setLockRetry(int $times, int $delay = 100000)       设置重试次数与重试间隔（$delay 单位微秒，默认 100ms），返回 $this
 * @method bool  safeUpdate(array $data, $id, string $pk = 'id')     乐观锁安全更新：未启用时等价于普通 update；启用时带版本校验与重试，冲突/记录不存在抛 RuntimeException
 * @method int   getCurrentVersion($id, string $pk = 'id')           读取指定记录的当前版本号（记录不存在返回 0）
 * @method bool  initVersion($id, string $pk = 'id')                 将指定记录版本号初始化为 1，返回是否更新成功
 *
 * 依赖的模型查询方法（内部调用，可一并使用）：
 * @method \think\db\Query|$this where(mixed $field, mixed $opOrValue = null, mixed $value = null)
 * @method \think\db\Query field(mixed ...$fields)
 * @method array|\think\Model|null find(mixed $data = null)
 * @method int|false  update(array $data = [])
 * @method mixed      value(string $field)
 *
 * 使用示例：
 * ```php
 * // 表需含 version 字段，模型默认 $lockVersion = 'version'
 * $user = User::find(1);
 * $user->enableOptimisticLock()
 *      ->setLockRetry(5, 200000); // 重试 5 次，每次间隔 200ms
 *
 * try {
 *     $user->safeUpdate(['name' => '新名字'], $user->id);
 * } catch (\RuntimeException $e) {
 *     // 版本冲突：数据已被其他进程修改
 *     echo $e->getMessage();
 * }
 *
 * // 读取 / 初始化版本号
 * $version = $user->getCurrentVersion(1);
 * $user->initVersion(1);
 * ```
 */
trait OptimisticLock
{
    /**
     * 乐观锁版本字段
     * @var string
     */
    protected $lockVersion = 'version';

    /**
     * 是否启用乐观锁
     * @var bool
     */
    protected $optimisticLock = false;

    /**
     * 重试次数
     * @var int
     */
    protected $lockRetryTimes = 3;

    /**
     * 重试间隔（微秒）
     * @var int
     */
    protected $lockRetryDelay = 100000;

    /**
     * 启用乐观锁
     *
     * @return $this
     */
    public function enableOptimisticLock(): self
    {
        $this->optimisticLock = true;
        return $this;
    }

    /**
     * 禁用乐观锁
     *
     * @return $this
     */
    public function disableOptimisticLock(): self
    {
        $this->optimisticLock = false;
        return $this;
    }

    /**
     * 设置乐观锁字段
     *
     * @param string $field
     * @return $this
     */
    public function setLockVersionField(string $field): self
    {
        $this->lockVersion = $field;
        return $this;
    }

    /**
     * 设置重试参数
     *
     * @param int $times
     * @param int $delay 微秒
     * @return $this
     */
    public function setLockRetry(int $times, int $delay = 100000): self
    {
        $this->lockRetryTimes = $times;
        $this->lockRetryDelay = $delay;
        return $this;
    }

    /**
     * 乐观锁安全更新（带重试机制）
     *
     * @param array $data 更新数据
     * @param mixed $id 主键值
     * @param string $pk 主键字段
     * @return bool
     * @throws \RuntimeException
     */
    public function safeUpdate(array $data, $id, string $pk = 'id'): bool
    {
        if (!$this->optimisticLock) {
            return $this->where($pk, $id)->update($data) !== false;
        }

        $lastException = null;

        for ($i = 0; $i < $this->lockRetryTimes; $i++) {
            try {
                return $this->doOptimisticUpdate($data, $id, $pk);
            } catch (\RuntimeException $e) {
                $lastException = $e;
                if ($i < $this->lockRetryTimes - 1) {
                    usleep($this->lockRetryDelay * ($i + 1));
                }
            }
        }

        throw new \RuntimeException(
            "乐观锁更新失败，已达到最大重试次数({$this->lockRetryTimes})：" . $lastException->getMessage()
        );
    }

    /**
     * 执行乐观锁更新
     *
     * @param array $data
     * @param mixed $id
     * @param string $pk
     * @return bool
     * @throws \RuntimeException
     */
    protected function doOptimisticUpdate(array $data, $id, string $pk): bool
    {
        $record = $this->where($pk, $id)->field([$pk, $this->lockVersion])->find();

        if (!$record) {
            throw new \RuntimeException('记录不存在');
        }

        $currentVersion = (int) $record[$this->lockVersion];
        $data[$this->lockVersion] = $currentVersion + 1;

        $result = $this->where($pk, $id)
            ->where($this->lockVersion, $currentVersion)
            ->update($data);

        // 受影响行数为 0：可能是版本冲突，也可能是记录不存在（区分二者，避免误报冲突）
        if ($result === 0) {
            $exists = (bool) $this->where($pk, $id)->find();
            throw new \RuntimeException(
                $exists ? '数据已被其他进程修改，版本冲突' : '记录不存在'
            );
        }

        return true;
    }

    /**
     * 获取当前版本号
     *
     * @param mixed $id
     * @param string $pk
     * @return int
     */
    public function getCurrentVersion($id, string $pk = 'id'): int
    {
        $version = $this->where($pk, $id)->value($this->lockVersion);
        return $version ? (int) $version : 0;
    }

    /**
     * 初始化版本号（新记录）
     *
     * @param mixed $id
     * @param string $pk
     * @return bool
     */
    public function initVersion($id, string $pk = 'id'): bool
    {
        return $this->where($pk, $id)->update([$this->lockVersion => 1]) !== false;
    }
}
