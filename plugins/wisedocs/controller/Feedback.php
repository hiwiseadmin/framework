<?php

declare(strict_types=1);

namespace plugin\wisedocs\controller;

use plugin\wisedocs\model\Document;
use plugin\wisedocs\model\Feedback as FeedbackModel;

/**
 * 反馈管理（P4-1）
 *
 * 修复内容：
 *   - 原文件命名空间为 `plugin\wisedocs\controller\admin`（与实际路径不符），
 *     PluginDispatch 按 `plugin\wisedocs\controller\Feedback` 解析 → 控制器不可达；
 *   - 原实现直接访问未定义的 `$this->auth`（处理器 ID 恒为 0）；
 *   - 改为标准 `getList / process / delete / batch` 接口，返回 `{total, rows}`。
 */
class Feedback extends Base
{
    /** 类型文案 */
    protected const TYPE_TEXT = [
        FeedbackModel::TYPE_HELPFUL   => '有帮助',
        FeedbackModel::TYPE_UNHELPFUL => '没帮助',
        FeedbackModel::TYPE_SUGGESTION => '建议',
    ];

    /** 处理状态文案 */
    protected const STATUS_TEXT = [
        FeedbackModel::STATUS_PENDING   => '待处理',
        FeedbackModel::STATUS_PROCESSED => '已处理',
        FeedbackModel::STATUS_IGNORED   => '已忽略',
    ];

    /**
     * 列表页
     */
    public function index()
    {
        $documents = Document::field('id,title')->order('id DESC')->limit(200)->select();

        return $this->assign(['documents' => $documents])->fetch();
    }

    /**
     * Bootstrap Table 分页数据
     */
    public function getList()
    {
        [$page, $size] = $this->pageParams();

        $documentId = (int) $this->request->param('document_id/d', 0);
        $type       = $this->request->param('type', '');
        $processed  = $this->request->param('processed', '');
        $startDate  = (string) $this->request->param('start_date', '');
        $endDate    = (string) $this->request->param('end_date', '');

        $query = FeedbackModel::with(['document']);

        if ($documentId > 0) {
            $query->where('document_id', $documentId);
        }
        if ($type !== '' && $type !== null) {
            $query->where('type', (int) $type);
        }
        if ($processed !== '' && $processed !== null) {
            $value = (int) $processed;

            if ($value === FeedbackModel::STATUS_PENDING) {
                // 兼容历史数据：早期写入未显式赋值，processed 为 NULL 同样属于「待处理」
                $query->whereRaw('(processed = 0 OR processed IS NULL)');
            } else {
                $query->where('processed', $value);
            }
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
            $query->where('create_time', '>=', $startDate . ' 00:00:00');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
            $query->where('create_time', '<=', $endDate . ' 23:59:59');
        }

        $total = $query->count();
        $list  = $query->order('id DESC')->page($page, $size)->select();

        $rows = [];
        foreach ($list as $item) {
            $row                    = $item->toArray();
            $row['document_title']  = $item->document ? $item->document->title : '-';
            $row['type_text']       = self::TYPE_TEXT[(int) $item->type] ?? '-';
            $row['processed_text']  = self::STATUS_TEXT[(int) $item->processed] ?? '-';
            $row['comment_short']   = mb_substr((string) $item->comment, 0, 80);
            $rows[]                 = $row;
        }

        return $this->listResponse($total, $rows);
    }

    /**
     * 反馈详情
     */
    public function detail(int $id = 0)
    {
        $feedback = FeedbackModel::with(['document'])->find($id);
        if (!$feedback) {
            return $this->error('反馈不存在');
        }

        $row                   = $feedback->toArray();
        $row['document_title'] = $feedback->document ? $feedback->document->title : '-';
        $row['type_text']      = self::TYPE_TEXT[(int) $feedback->type] ?? '-';
        $row['processed_text'] = self::STATUS_TEXT[(int) $feedback->processed] ?? '-';

        return $this->success('', $row);
    }

    /**
     * 处理反馈（已处理 / 已忽略 + 备注）
     */
    public function process()
    {
        if (!$this->request->isPost()) {
            return $this->error('非法请求');
        }

        $id     = (int) $this->request->post('id', 0);
        $status = (int) $this->request->post('status', FeedbackModel::STATUS_PROCESSED);
        $remark = (string) $this->request->post('remark', '');

        if (!in_array($status, [FeedbackModel::STATUS_PROCESSED, FeedbackModel::STATUS_IGNORED], true)) {
            return $this->error('处理状态不合法');
        }

        $feedback = FeedbackModel::find($id);
        if (!$feedback) {
            return $this->error('反馈不存在');
        }

        try {
            $feedback->save([
                'processed'    => $status,
                'processed_at' => date('Y-m-d H:i:s'),
                'processor_id' => (int) (auth_id('admin') ?? 0),
                'remark'       => mb_substr($remark, 0, 500),
            ]);

            return $this->success('处理成功');
        } catch (\Throwable $e) {
            \think\facade\Log::error('[wisedocs] feedback process failed: ' . $e->getMessage());

            return $this->error('处理失败，请稍后重试');
        }
    }

    /**
     * 删除反馈
     */
    public function delete()
    {
        if (!$this->request->isPost()) {
            return $this->error('非法请求');
        }

        $ids = array_map('intval', (array) $this->request->post('ids/a', []));
        if (empty($ids)) {
            return $this->error('请选择要删除的数据');
        }

        try {
            FeedbackModel::destroy($ids);

            return $this->success('删除成功');
        } catch (\Throwable $e) {
            \think\facade\Log::error('[wisedocs] feedback delete failed: ' . $e->getMessage());

            return $this->error('删除失败，请稍后重试');
        }
    }

    /**
     * 批量操作：process（标记已处理）/ ignore（忽略）/ delete
     */
    public function batch()
    {
        if (!$this->request->isPost()) {
            return $this->error('非法请求');
        }

        $ids    = array_map('intval', (array) $this->request->post('ids/a', []));
        $action = (string) $this->request->post('action', '');

        if (empty($ids)) {
            return $this->error('请选择要操作的数据');
        }

        try {
            switch ($action) {
                case 'process':
                    FeedbackModel::whereIn('id', $ids)->update([
                        'processed'    => FeedbackModel::STATUS_PROCESSED,
                        'processed_at' => date('Y-m-d H:i:s'),
                        'processor_id' => (int) (auth_id('admin') ?? 0),
                    ]);
                    return $this->success('批量处理成功');

                case 'ignore':
                    FeedbackModel::whereIn('id', $ids)->update([
                        'processed'    => FeedbackModel::STATUS_IGNORED,
                        'processed_at' => date('Y-m-d H:i:s'),
                        'processor_id' => (int) (auth_id('admin') ?? 0),
                    ]);
                    return $this->success('批量忽略成功');

                case 'delete':
                    FeedbackModel::destroy($ids);
                    return $this->success('批量删除成功');

                default:
                    return $this->error('未知操作');
            }
        } catch (\Throwable $e) {
            \think\facade\Log::error('[wisedocs] feedback batch failed: ' . $e->getMessage());

            return $this->error('操作失败，请稍后重试');
        }
    }
}
