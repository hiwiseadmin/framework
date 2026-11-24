<?php
namespace app\common\model;

use think\Model;

abstract class BaseModel extends Model
{
    use traits\DataValidation;

    use traits\ExtensionMethod;

    use traits\OptimisticLock;

    protected $autoWriteTimestamp = true;
    
    protected $createTime = 'create_time';

    protected $updateTime = 'update_time';

    protected $deleteTime = 'delete_time';
    
    protected $defaultOrder = [];

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
    
	protected $resultSetType = collection\Collection::class;

    public function getCreateTimeIntAttr($value, $data)
    {
        return isset($data[$this->createTime]) ? strtotime($data[$this->createTime]) : 0;
    }

    public function getUpdateTimeIntAttr($value, $data)
    {
        return isset($data[$this->updateTime]) ? strtotime($data[$this->updateTime]) : 0;
    }

    public function getDeleteTimeIntAttr($value, $data)
    {
        return isset($data[$this->deleteTime]) ? strtotime($data[$this->deleteTime]) : 0;
    }
}