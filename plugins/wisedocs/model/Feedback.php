<?php
namespace plugin\wisedocs\model;

use think\Model;

class Feedback extends Model
{
    protected $name = 'wisedocs_feedback';
    
    /**
     * 自动时间戳（M5：显式声明）
     *
     * 原实现依赖 `config/database.php` 的全局 `auto_timestamp` 开关；一旦部署时关闭，
     * `create_time` / `update_time` 便不再写入。此处显式声明，行为不再随全局配置漂移。
     */
    protected $autoWriteTimestamp = 'datetime';
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';
    protected $deleteTime = 'delete_time';
    
    protected $type = [
        'id' => 'integer',
        'document_id' => 'integer',
        'user_id' => 'integer',
        'type' => 'integer',
    ];
    
    // 反馈类型常量
    const TYPE_HELPFUL = 1;    // 有帮助
    const TYPE_UNHELPFUL = 0;  // 无帮助
    const TYPE_SUGGESTION = 2; // 建议反馈
    
    // 状态常量
    const STATUS_PENDING = 0;  // 待处理
    const STATUS_PROCESSED = 1; // 已处理
    const STATUS_IGNORED = 2;   // 已忽略
    
    // 关联文档
    public function document()
    {
        return $this->belongsTo(Document::class, 'document_id');
    }
    
    /**
     * 反馈归属会员（可选）
     *
     * D5：反馈以匿名为主、会员可选。原实现指向不存在的 app\common\model\User，
     * 访问该关联即报错，故改指向 wise-member 的会员模型（user_id 存会员 ID）。
     */
    public function member()
    {
        return $this->belongsTo(\wise\member\model\Member::class, 'user_id');
    }
    
    // 获取反馈统计
    public function getFeedbackStats($documentId = null, $startDate = null, $endDate = null)
    {
        $query = $this;
        
        if ($documentId) {
            $query = $query->where('document_id', $documentId);
        }
        
        if ($startDate) {
            $query = $query->where('create_time', '>=', $startDate);
        }
        
        if ($endDate) {
            $query = $query->where('create_time', '<=', $endDate);
        }
        
        $stats = $query->field('type, count(*) as count')
            ->group('type')
            ->select()
            ->toArray();
        
        $result = [
            'helpful' => 0,
            'unhelpful' => 0,
            'suggestion' => 0,
            'total' => 0,
        ];
        
        foreach ($stats as $stat) {
            switch ($stat['type']) {
                case self::TYPE_HELPFUL:
                    $result['helpful'] = $stat['count'];
                    break;
                case self::TYPE_UNHELPFUL:
                    $result['unhelpful'] = $stat['count'];
                    break;
                case self::TYPE_SUGGESTION:
                    $result['suggestion'] = $stat['count'];
                    break;
            }
            $result['total'] += $stat['count'];
        }
        
        return $result;
    }
    
    // 获取未处理的反馈
    public function getPendingFeedback($limit = 10)
    {
        return $this->with(['document'])
            ->where('processed', 0)
            ->order('create_time DESC')
            ->limit($limit)
            ->select();
    }
}