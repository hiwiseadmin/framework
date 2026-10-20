<?php
namespace plugin\wisecms\controller\api;

use plugin\wisecms\exception\WisecmsException;
use plugin\ApiController;
use plugin\wisecms\service\engine\GenerationEngine;
/**
 * CMS AI 生成 API 控制器
 *
 * 限流：AI 生成消耗大，3次/分钟（令牌桶：容量3, 速率0.05/s）
 */
class Generation extends ApiController
{
    protected $middleware = [
        \wise\api\middleware\RateLimitMiddleware::class . ':3,0.05',
    ];

    /**
     * 自然语言生成
     */
    public function natural()
    {
        $params = $this->validateRequest([
            'task_name'    => 'max:100',
            'topic'        => 'require|max:500',
            'style'        => 'max:50',
            'requirements' => 'max:2000',
            'catid'        => 'integer',
        ], [
            'topic.require' => '生成主题不能为空',
        ]);

        // 不再用原始 POST 覆盖已校验参数（曾导致 auto_publish / catid 等被任意覆盖，
        // 匿名请求即可直接写入「已发布」内容）。仅显式补取无校验规则的可选字段。
        $params['auto_publish'] = (int) $this->request->post('auto_publish', 0) === 1 ? 1 : 0;
        $params['style']        = (string) ($params['style'] ?? '专业');

        try {
            $result = (new GenerationEngine())->generateNatural($params);
            return $this->success('ok', $result);
        } catch (\Exception $e) {
            // P1-A5：底层异常细节仅落日志，不回显（防泄露 SQL / 表结构 / 绝对路径）
            cms_log('error', 'CMS 后台操作失败: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
            return $this->error(WisecmsException::userMessage($e), [], 4000);
        }
    }

    /**
     * 批量生成
     */
    public function batch()
    {
        $params = $this->validateRequest([
            'keywords'    => 'require',
            'catid'       => 'integer',
            'daily_limit' => 'integer|between:1,50',
        ], [
            'keywords.require' => '关键词列表不能为空',
        ]);

        // 同上：不使用原始 POST 覆盖已校验参数
        $params['auto_publish'] = (int) $this->request->post('auto_publish', 0) === 1 ? 1 : 0;
        $params['keywords'] = array_filter(array_map('trim', explode("\n", $params['keywords'] ?? '')));

        if (empty($params['keywords'])) {
            return $this->error('请输入至少一个关键词', [], 4001);
        }

        try {
            $result = (new GenerationEngine())->generateBatch($params);
            return $this->success('批量生成任务已创建', $result);
        } catch (\Exception $e) {
            // P1-A5：底层异常细节仅落日志，不回显（防泄露 SQL / 表结构 / 绝对路径）
            cms_log('error', 'CMS 后台操作失败: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
            return $this->error(WisecmsException::userMessage($e), [], 4000);
        }
    }

    /**
     * 任务状态查询
     */
    public function task()
    {
        $params = $this->validateRequest([
            'id' => 'require|integer|egt:1',
        ]);

        $result = (new GenerationEngine())->getTaskStatus((int)$params['id']);
        return $this->success('ok', $result);
    }
}
