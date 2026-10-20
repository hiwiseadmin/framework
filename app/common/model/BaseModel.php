<?php
namespace app\common\model;

use think\Model;

abstract class BaseModel extends Model
{
    // 使用自定义的验证辅助 Trait
    use traits\DataValidation;

    // 使用自定义扩展方法 Trait
    use traits\ExtensionMethod;

    // 乐观锁扩展（默认关闭，子模型需要时调用 enableOptimisticLock()）
    use traits\OptimisticLock;

    // 自动时间戳
    protected $autoWriteTimestamp = true;
    
    // 创建时间字段 datetime 格式
    protected $createTime = 'create_time';

    // 更新时间字段 datetime 格式
    protected $updateTime = 'update_time';

    // 软删除字段 datetime 格式
    protected $deleteTime = 'delete_time';
    
    // 默认排序
    protected $defaultOrder = [];

    // 只读字段
    protected $readonly = ['create_time'];

    /**
     * 可写字段白名单（null 表示不启用白名单，仅按表字段过滤）
     *
     * 启用后控制器 CRUD 写操作只会写入白名单内的字段，
     * 用于从根源上杜绝批量赋值（Mass Assignment）漏洞。
     *
     * @var array|null
     */
    protected $allowWriteField = null;

    /**
     * 获取可写字段白名单
     * @return array|null null 表示未启用白名单
     */
    public function getAllowWriteFields(): ?array
    {
        return $this->allowWriteField;
    }
    
    // 设置返回数据集的对象名
	protected $resultSetType = collection\Collection::class;

    // 创建时间字段时间戳格式
    public function getCreateTimeIntAttr($value, $data)
    {
        return isset($data[$this->createTime]) ? strtotime($data[$this->createTime]) : 0;
    }

    // 更新时间字段时间戳格式
    public function getUpdateTimeIntAttr($value, $data)
    {
        return isset($data[$this->updateTime]) ? strtotime($data[$this->updateTime]) : 0;
    }

    // 软删除时间字段时间戳格式
    public function getDeleteTimeIntAttr($value, $data)
    {
        return isset($data[$this->deleteTime]) ? strtotime($data[$this->deleteTime]) : 0;
    }
}