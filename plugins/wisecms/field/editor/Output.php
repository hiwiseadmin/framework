<?php
namespace plugin\wisecms\field\editor;

use plugin\wisecms\field\base\FieldBase;
use plugin\wisecms\model\InternalLink;

class Output extends FieldBase
{
    /**
     * 输出处理（出库后）
     */
    public function outputFilter($value, array $fieldInfo = [])
    {
        // UIUX-P0-31：setting 已由 FieldRegistry 归一化为数组，禁止直接 json_decode（PHP 8 抛 TypeError）
        $setting = self::normalizeSetting($fieldInfo['setting'] ?? $this->fieldInfo['setting'] ?? []);
        
        // 是否启用关联链接
        $enableKeyLink = $setting['enablekeylink'] ?? 0;
        
        if ($enableKeyLink) {
            $value = $this->processKeyLinks($value, $setting);
        }
        
        // 处理图片路径（相对转绝对）
        $value = $this->processImagePaths($value);
        
        return $value;
    }
    
    /**
     * 处理关联链接
     */
    private function processKeyLinks(string $content, array $setting): string
    {
        $replaceNum = $setting['replacenum'] ?? 1;
        $linkMode = $setting['link_mode'] ?? 1;
        
        $keywords = $this->getKeywords();
        
        if (empty($keywords)) {
            return $content;
        }
        
        // 保护alt和title属性中的内容
        $content = preg_replace_callback('/(alt|title)\s*=\s*["\'](.*?)["\']/i', function($matches) {
            return $matches[1] . '="' . base64_encode($matches[2]) . '"';
        }, $content);
        
        if ($linkMode == 1) {
            foreach ($keywords as $keyword) {
                $safeKw = htmlspecialchars($keyword, ENT_QUOTES, 'UTF-8');
                $searchUrl = rtrim(config('wisecms.site_url', request()->domain()), '/')
                    . '/search?q=' . urlencode($keyword);
                $link = '<a href="' . htmlspecialchars($searchUrl, ENT_QUOTES, 'UTF-8') . '" class="keylink">' . $safeKw . '</a>';
                
                if ($replaceNum > 0) {
                    $pattern = '/(?!(<a.*?>))' . preg_quote($keyword, '/') . '(?!.*<\/a>)/s';
                    $content = preg_replace($pattern, $link, $content, $replaceNum);
                } else {
                    $content = str_replace($keyword, $link, $content);
                }
            }
        } else {
            $linkData = $this->getLinkData();
            foreach ($linkData as $linkItem) {
                $keyword = $linkItem['keyword'];
                $url     = htmlspecialchars($linkItem['url'] ?? '', ENT_QUOTES, 'UTF-8');
                $safeKw  = htmlspecialchars($keyword, ENT_QUOTES, 'UTF-8');
                $link    = '<a href="' . $url . '" target="_blank" class="keylink">' . $safeKw . '</a>';
                
                if ($replaceNum > 0) {
                    $pattern = '/(?!(<a.*?>))' . preg_quote($keyword, '/') . '(?!.*<\/a>)/s';
                    $content = preg_replace($pattern, $link, $content, $replaceNum);
                } else {
                    $content = str_replace($keyword, $link, $content);
                }
            }
        }
        
        // 恢复alt和title属性
        $content = preg_replace_callback('/(alt|title)\s*=\s*["\'](.*?)["\']/i', function($matches) {
            return $matches[1] . '="' . base64_decode($matches[2]) . '"';
        }, $content);
        
        return $content;
    }
    
    /**
     * 处理图片路径（相对转绝对）
     */
    private function processImagePaths(string $content): string
    {
        $baseUrl = request()->domain();
        
        $content = preg_replace_callback('/<img[^>]+src="([^"]+)"[^>]*>/i', function($matches) use ($baseUrl) {
            $src = $matches[1];
            if (!preg_match('/^(http|https):\/\//i', $src)) {
                $newSrc = rtrim($baseUrl, '/') . '/' . ltrim($src, '/');
                return str_replace($src, $newSrc, $matches[0]);
            }
            return $matches[0];
        }, $content);
        
        return $content;
    }
    
    /**
     * 从内容数据中获取关键词列表
     */
    private function getKeywords(): array
    {
        if (isset($this->data['keywords'])) {
            $keywords = $this->data['keywords'];
            if (is_string($keywords)) {
                return strpos($keywords, ',') !== false
                    ? array_map('trim', explode(',', $keywords))
                    : array_map('trim', explode(' ', $keywords));
            }
        }
        return [];
    }
    
    /**
     * 获取链接数据
     */
    private function getLinkData(): array
    {
        $links = InternalLink::where('status', 1)->select()->toArray();
        return array_map(static function (array $item): array {
            return [
                'keyword' => $item['keyword'] ?? '',
                'url'     => $item['target_url'] ?? '',
            ];
        }, $links);
    }
}
