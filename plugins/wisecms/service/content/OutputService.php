<?php
namespace plugin\wisecms\service\content;
use plugin\wisecms\service\model\FieldFactory;
use plugin\wisecms\service\model\FieldRegistry;
use plugin\wisecms\service\url\UrlService;

/**
 * 内容输出服务
 *
 * 负责内容在前台的输出处理：
 *   1. 对字段应用 outputFilter（editor/markdown/datetime/images 等自定义字段类型）；
 *   2. 处理公共字段（URL、时间格式化、缩略图、摘要兜底）。
 */
class OutputService
{
    /**
     * 字段注册中心
     */
    protected FieldRegistry $fields;

    /**
     * URL 服务（请求内复用，保留栏目路径缓存）
     */
    protected ?UrlService $urls = null;

    public function __construct(?FieldRegistry $fields = null)
    {
        $this->fields = $fields ?? new FieldRegistry();
    }

    /**
     * 处理内容数据输出
     */
    public function processContentOutput(array $content): array
    {
        // 兼容两种来源：主表 model_id 与动态模型表的 modelid
        $modelId = $content['model_id'] ?? $content['modelid'] ?? 0;
        if (empty($content) || $modelId <= 0) {
            return $content;
        }

        // 字段输出处理（优先 Output 类，回退 Form）
        $fields = $this->fields->getFields($modelId);
        foreach ($fields as $field) {
            $fieldName = $field['field'];
            if (!isset($content[$fieldName])) {
                continue;
            }

            try {
                $fieldObj = FieldFactory::createOutput($field['form_type'], $field, $content[$fieldName], $content);
                $content[$fieldName] = $fieldObj->outputFilter($content[$fieldName], $field);
            } catch (\Exception $e) {
                // 输出处理失败，保持原值
            }
        }

        // 公共字段处理
        return $this->processCommonFields($content);
    }

    /**
     * 批量处理内容列表输出（按 model_id 分组，每模型一次批量查副表，避免 N+1）
     *
     * @param array $list 内容数组列表（每项须含 id 与 model_id）
     */
    public function processContentListOutput(array $list): array
    {
        if (empty($list)) {
            return $list;
        }

        // 按 model_id 分组索引
        $grouped = [];
        foreach ($list as $index => $item) {
            $modelId = (int) ($item['model_id'] ?? 0);
            if ($modelId > 0) {
                $grouped[$modelId][] = $index;
            }
        }

        // 每模型一次 IN 查询，合并自定义字段到对应项
        $contentService = new ContentService();
        foreach ($grouped as $modelId => $indexes) {
            $ids = [];
            foreach ($indexes as $index) {
                $id = (int) ($list[$index]['id'] ?? 0);
                if ($id > 0) {
                    $ids[$id] = $id;
                }
            }
            if (empty($ids)) {
                continue;
            }

            $customMap = $contentService->getCustomDataBatch(array_values($ids), $modelId);
            foreach ($indexes as $index) {
                $id = (int) ($list[$index]['id'] ?? 0);
                if ($id > 0 && isset($customMap[$id])) {
                    $list[$index] = array_merge($list[$index], $customMap[$id]);
                }
            }
        }

        // 逐项输出处理（字段 outputFilter + 公共字段）
        foreach ($list as $index => $item) {
            $list[$index] = $this->processContentOutput($item);
        }

        return $list;
    }

    /**
     * 处理公共字段输出
     */
    protected function processCommonFields(array $content): array
    {
        // URL
        if (empty($content['url']) && isset($content['id'])) {
            $content['url'] = $this->generateContentUrl($content);
        }

        // 时间格式化（create_time / update_time 为 DATETIME；为空时不输出派生键）
        $createTs = cms_timestamp($content['create_time'] ?? null);
        if ($createTs > 0) {
            $content['create_time_text'] = date('Y-m-d H:i:s', $createTs);
            $content['create_time_date'] = date('Y-m-d', $createTs);
        }

        $updateTs = cms_timestamp($content['update_time'] ?? null);
        if ($updateTs > 0) {
            $content['update_time_text'] = date('Y-m-d H:i:s', $updateTs);
            $content['update_time_date'] = date('Y-m-d', $updateTs);
        }

        // 缩略图绝对路径
        if (isset($content['thumb']) && !empty($content['thumb'])) {
            $content['thumb_url'] = $this->getImageUrl($content['thumb']);
        }

        // 摘要兜底
        if (isset($content['description']) && empty($content['description'])) {
            $content['description'] = $this->generateDescription($content);
        }

        return $content;
    }

    /**
     * 生成内容前台 URL（统一委托 UrlService —— 全站唯一 URL 事实源）
     *
     * 形态由 config('wisecms.url_rewrite') 决定：
     *   关闭（默认）→ /cms/content/detail?slug=xxx，与旧站完全一致；
     *   开启         → 按 URL 规则表生成伪静态，如 /news/tech/hello.html。
     */
    public function buildContentUrl(array $content): string
    {
        return $this->urls()->content($content);
    }

    /**
     * URL 服务实例（请求内复用）
     */
    protected function urls(): UrlService
    {
        return $this->urls ??= new UrlService();
    }

    /**
     * 生成内容 URL（内部使用，委托 buildContentUrl）
     */
    protected function generateContentUrl(array $content): string
    {
        return $this->buildContentUrl($content);
    }

    /**
     * 获取图片完整 URL
     */
    protected function getImageUrl(string $imagePath): string
    {
        if (empty($imagePath)) {
            return '';
        }
        if (strpos($imagePath, 'http://') === 0 || strpos($imagePath, 'https://') === 0) {
            return $imagePath;
        }
        return rtrim(request()->domain(), '/') . '/' . ltrim($imagePath, '/');
    }

    /**
     * 生成摘要
     */
    protected function generateDescription(array $content): string
    {
        if (isset($content['content'])) {
            $contentText = strip_tags($content['content']);
            $description = mb_substr($contentText, 0, 200, 'UTF-8');
            if (mb_strlen($contentText, 'UTF-8') > 200) {
                $description .= '...';
            }
            return $description;
        }
        if (isset($content['title'])) {
            return $content['title'];
        }
        return '';
    }
}
