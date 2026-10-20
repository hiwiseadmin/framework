<?php
namespace plugin\wisecms\controller\api;

use plugin\ApiController;
use plugin\wisecms\service\seo\SitemapService;

/**
 * CMS 工具 API 控制器
 */
class Tool extends ApiController
{
    /**
     * 站点配置
     */
    public function config()
    {
        return $this->success('ok', [
            'site_name' => config('wisecms.site_name', ''),
            'site_url'  => config('wisecms.site_url', ''),
        ]);
    }

    /**
     * Sitemap XML 输出
     */
    public function sitemap()
    {
        $svc = new SitemapService();
        header('Content-Type: application/xml; charset=utf-8');
        echo $svc->generateSitemapIndex();
        exit;
    }
}
