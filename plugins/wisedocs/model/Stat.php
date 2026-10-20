<?php
namespace plugin\wisedocs\model;

use think\Model;

class Stat extends Model
{
    protected $name = 'wisedocs_stats';
    
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
    
    // protected $type = [
    //     'id' => 'integer',
    //     'document_id' => 'integer',
    //     'view_count' => 'integer',
    //     'helpful_count' => 'integer',
    //     'unhelpful_count' => 'integer',
    //     'share_count' => 'integer',
    //     'total_view_count' => 'integer',
    //     'total_helpful_count' => 'integer',
    //     'total_unhelpful_count' => 'integer',
    // ];
    
    // 关联文档
    public function document()
    {
        return $this->belongsTo(Document::class, 'document_id');
    }
    
    // 记录浏览次数
    public function recordView($documentId)
    {
        $today = date('Y-m-d');
        
        $stat = $this->where('document_id', $documentId)
            ->where('date', $today)
            ->find();
        
        if ($stat) {
            // 更新现有记录
            $stat->view_count++;
            $stat->total_view_count++;
            $stat->save();
        } else {
            // 获取累计数据
            $totalStats = $this->where('document_id', $documentId)
                ->order('date DESC')
                ->find();
            
            $totalView = $totalStats ? $totalStats->total_view_count : 0;
            
            // 创建新记录
            $this->create([
                'document_id' => $documentId,
                'date' => $today,
                'view_count' => 1,
                'helpful_count' => 0,
                'unhelpful_count' => 0,
                'share_count' => 0,
                'total_view_count' => $totalView + 1,
                'total_helpful_count' => $totalStats ? $totalStats->total_helpful_count : 0,
                'total_unhelpful_count' => $totalStats ? $totalStats->total_unhelpful_count : 0,
            ]);
        }
        
        // 更新文档表的总计（保持同步）
        Document::where('id', $documentId)->inc('view_count')->update();
    }
    
    // 记录反馈
    public function recordFeedback($documentId, $type)
    {
        $today = date('Y-m-d');
        
        $stat = $this->where('document_id', $documentId)
            ->where('date', $today)
            ->find();
        
        if ($stat) {
            if ($type === 'helpful') {
                $stat->helpful_count++;
                $stat->total_helpful_count++;
            } else {
                $stat->unhelpful_count++;
                $stat->total_unhelpful_count++;
            }
            $stat->save();
        } else {
            // 获取累计数据
            $totalStats = $this->where('document_id', $documentId)
                ->order('date DESC')
                ->find();
            
            $totalView = $totalStats ? $totalStats->total_view_count : 0;
            $totalHelpful = $totalStats ? $totalStats->total_helpful_count : 0;
            $totalUnhelpful = $totalStats ? $totalStats->total_unhelpful_count : 0;
            
            // 创建新记录
            $data = [
                'document_id' => $documentId,
                'date' => $today,
                'view_count' => 0,
                'helpful_count' => 0,
                'unhelpful_count' => 0,
                'share_count' => 0,
                'total_view_count' => $totalView,
                'total_helpful_count' => $totalHelpful,
                'total_unhelpful_count' => $totalUnhelpful,
            ];
            
            if ($type === 'helpful') {
                $data['helpful_count'] = 1;
                $data['total_helpful_count'] = $totalHelpful + 1;
            } else {
                $data['unhelpful_count'] = 1;
                $data['total_unhelpful_count'] = $totalUnhelpful + 1;
            }
            
            $this->create($data);
        }
    }
    
    // 获取文档统计趋势
    public function getTrendData($documentId, $days = 30)
    {
        $endDate = date('Y-m-d');
        $startDate = date('Y-m-d', strtotime("-$days days"));
        
        $stats = $this->where('document_id', $documentId)
            ->whereBetween('date', [$startDate, $endDate])
            ->order('date ASC')
            ->select();
        
        $result = [
            'dates' => [],
            'views' => [],
            'helpful' => [],
            'unhelpful' => [],
        ];
        
        // 填充日期
        $currentDate = $startDate;
        while ($currentDate <= $endDate) {
            $result['dates'][] = $currentDate;
            $result['views'][] = 0;
            $result['helpful'][] = 0;
            $result['unhelpful'][] = 0;
            $currentDate = date('Y-m-d', strtotime($currentDate . ' +1 day'));
        }
        
        // 填充数据
        foreach ($stats as $stat) {
            $index = array_search($stat->date, $result['dates']);
            if ($index !== false) {
                $result['views'][$index] = $stat->view_count;
                $result['helpful'][$index] = $stat->helpful_count;
                $result['unhelpful'][$index] = $stat->unhelpful_count;
            }
        }
        
        return $result;
    }
    
    // 获取热门文档
    public function getHotDocuments($limit = 10, $days = 7)
    {
        $startDate = date('Y-m-d', strtotime("-$days days"));
        
        return $this->alias('s')
            ->join('wisedocs_documents d', 's.document_id = d.id')
            ->where('s.date', '>=', $startDate)
            ->where('d.status', 1)
            ->field('s.document_id, d.title, SUM(s.view_count) as total_views')
            ->group('s.document_id')
            ->order('total_views DESC')
            ->limit($limit)
            ->select();
    }
}