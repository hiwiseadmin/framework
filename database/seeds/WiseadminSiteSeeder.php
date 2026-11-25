<?php
declare(strict_types=1);

use think\migration\Seeder;
use think\facade\Db;

/**
 * WiseAdmin 官网初始化（Phase A）
 *
 * 特性：**幂等**，可重复执行；已存在的数据只做字段校正，不重复插入。
 *
 * 用法：
 *   php think seed:run -s WiseadminSiteSeeder
 *
 * 说明：
 *   - 只建结构（站点 / 栏目 / 单页 / 配置），不写正文文案；正文由 Phase C 录入。
 *   - 单页 content 留空，Phase C 必须填充，否则前台出现空白页。
 */
class WiseadminSiteSeeder extends Seeder
{
    /** 站点 ID（单站点部署固定为 1） */
    private const SITE_ID = 1;

    /** 文章模型 ID（由 wisecms 默认 seeder 创建） */
    private const MODEL_ARTICLE = 1;

    /** 单页模型 ID（栏目 type=1 统一绑定，仅用于满足栏目表单约束） */
    private const MODEL_PAGE = 2;

    public function run(): void
    {
        $now = date('Y-m-d H:i:s');

        $this->ensureSite($now);
        $this->ensureCategories($now);
        $this->ensurePages($now);
        $this->cleanupDuplicateContentField();
        $this->configure();

        echo PHP_EOL . 'WiseAdmin 官网结构初始化完成。' . PHP_EOL;
    }

    /**
     * 站点记录
     *
     * 该行缺失时 SiteService 会回退到硬编码的 site_id=1，功能可用但
     * 站点名/SEO 拿不到站点级值（只能回退插件全局配置），故必须补齐。
     */
    private function ensureSite(string $now): void
    {
        $exists = Db::name('wisecms_site')->where('id', self::SITE_ID)->find();

        $setting = json_encode([
            'site_name' => 'WiseAdmin',
            'theme'     => 'default',
        ], JSON_UNESCAPED_UNICODE);

        $seoSetting = json_encode([
            'title_format' => '{title} - {sitename}',
            'keywords'     => 'ThinkPHP8后台框架,PHP后台框架,开源后台管理,RBAC权限管理,插件化后台,Composer组件',
            'description'  => 'WiseAdmin 是基于 ThinkPHP 8 的开源后台框架：20+ 可独立安装的 wise-* 能力包、插件即应用、完整 RBAC2 权限、AI Agent 与 MCP 支持，Apache-2.0 免费商用。',
        ], JSON_UNESCAPED_UNICODE);

        $data = [
            'name'        => 'WiseAdmin',
            'domain'      => $exists['domain'] ?? '',
            'logo'        => $exists['logo'] ?? '',
            'setting'     => $setting,
            'seo_setting' => $seoSetting,
            'sort'        => 0,
            'status'      => 1,
            'update_time' => $now,
        ];

        if ($exists) {
            Db::name('wisecms_site')->where('id', self::SITE_ID)->update($data);
            echo '[site] 已更新站点记录 id=' . self::SITE_ID . PHP_EOL;
        } else {
            $data['id']          = self::SITE_ID;
            $data['create_time'] = $now;
            Db::name('wisecms_site')->insert($data);
            echo '[site] 已创建站点记录 id=' . self::SITE_ID . PHP_EOL;
        }
    }

    /**
     * 8 个栏目
     *
     * 默认 seeder 留下的 3 个栏目就地改造（news→blog / products→changelog / about 保留），
     * 避免删除后 alias 唯一索引残留与 URL 别名悬空。
     */
    private function ensureCategories(string $now): void
    {
        $this->remapCategory('news', [
            'name'      => '博客',
            'alias'     => 'blog',
            'type'      => 0,
            'model_id'  => self::MODEL_ARTICLE,
            'is_nav'    => 1,
            'sort'      => 60,
            'list_rows' => 10,
            'description' => 'WiseAdmin 官方技术博客：ThinkPHP 8 实践、插件开发、架构设计与真实踩坑记录。',
            'seo_title'   => '技术博客 - WiseAdmin',
            'seo_keywords' => 'ThinkPHP8教程,PHP后台开发,插件开发',
            'seo_description' => 'WiseAdmin 官方技术博客：ThinkPHP 8 实践、插件开发、架构设计与真实踩坑记录。',
        ], $now);

        $this->remapCategory('products', [
            'name'      => '版本更新',
            'alias'     => 'changelog',
            'type'      => 0,
            'model_id'  => self::MODEL_ARTICLE,
            'is_nav'    => 0,
            'sort'      => 100,
            'list_rows' => 10,
            'description' => 'WiseAdmin 各版本的功能变更、修复与升级说明。',
            'seo_title'   => '版本更新 - WiseAdmin',
            'seo_keywords' => 'WiseAdmin版本,更新日志',
            'seo_description' => 'WiseAdmin 各版本的功能变更、修复与升级说明。',
        ], $now);

        $this->remapCategory('about', [
            'name'      => '关于',
            'alias'     => 'about',
            'type'      => 1,
            'model_id'  => self::MODEL_PAGE,
            'is_nav'    => 1,
            'sort'      => 90,
            'list_rows' => 15,
            'seo_title' => '关于 WiseAdmin - 设计理念与开源治理',
            'seo_keywords' => 'WiseAdmin介绍,开源后台框架',
            'seo_description' => 'WiseAdmin 是以「开放 + 插件化」为核心设计的 ThinkPHP 8 后台框架，框架开源免费，围绕它维护 20+ 个可复用 Composer 组件与插件生态。',
        ], $now);

        $rows = [
            [
                'name' => '核心特性', 'alias' => 'features', 'type' => 1,
                'is_nav' => 1, 'sort' => 30,
                'seo_title' => '核心特性 - WiseAdmin | ThinkPHP 8 开源后台框架',
                'seo_keywords' => 'ThinkPHP8特性,后台框架能力,RBAC2,插件化',
                'seo_description' => 'WiseAdmin 六大核心特性：多应用与插件化、RBAC2 权限、插件脚手架、现代前端组件、工作流与 Webhook 等高级能力、AI Agent 与 MCP 支持。',
            ],
            [
                'name' => '能力包清单', 'alias' => 'packages', 'type' => 1,
                'is_nav' => 1, 'sort' => 40,
                'seo_title' => '能力包清单 - 20 个可独立安装的 wise-* Composer 包 | WiseAdmin',
                'seo_keywords' => 'wise-* 组件,Composer包,PHP组件库',
                'seo_description' => 'WiseAdmin 的 20 个在役 Composer 能力包完整清单：权限、API、附件、配置、定时任务、表单、JWT、市场、MCP、通知、OAuth、操作日志、RAG、基础设施、URL、Webhook、工作流、AI Agent 等。',
            ],
            [
                'name' => '快速开始', 'alias' => 'quickstart', 'type' => 1,
                'is_nav' => 0, 'sort' => 70,
                'seo_title' => '快速开始 - 5 分钟跑起 WiseAdmin',
                'seo_keywords' => 'WiseAdmin安装,ThinkPHP8快速开始',
                'seo_description' => '从克隆仓库到后台可用的完整步骤：composer install、初始化数据库、php think install，然后用 wise:crud 生成第一个业务模块。',
            ],
            [
                'name' => '下载', 'alias' => 'download', 'type' => 1,
                'is_nav' => 1, 'sort' => 80,
                'seo_title' => '下载 WiseAdmin v1.0.1 - Apache-2.0 开源免费',
                'seo_keywords' => 'WiseAdmin下载,开源后台框架下载',
                'seo_description' => '下载 WiseAdmin v1.0.1。GitHub 主仓库与 Gitee 镜像，Apache-2.0 许可，支持商业项目免费使用。环境要求 PHP 8.1+、MySQL 5.7+。',
            ],
            [
                'name' => '联系', 'alias' => 'contact', 'type' => 1,
                'is_nav' => 0, 'sort' => 95,
                'seo_title' => '联系我们 - WiseAdmin',
                'seo_keywords' => 'WiseAdmin联系方式',
                'seo_description' => 'WiseAdmin 的联系方式：项目问题请提交 GitHub Issues，安全漏洞请邮件上报，商务与合作请发邮件。',
            ],
        ];

        foreach ($rows as $row) {
            $this->upsertCategory($row + [
                'model_id'  => self::MODEL_PAGE,
                'list_rows' => 15,
            ], $now);
        }
    }

    /**
     * 把默认 seeder 创建的栏目（按旧 alias 定位）改造成目标栏目
     */
    private function remapCategory(string $legacyAlias, array $target, string $now): void
    {
        $legacy = Db::name('wisecms_category')
            ->where('site_id', self::SITE_ID)
            ->where('alias', $legacyAlias)
            ->find();

        if (!$legacy) {
            $this->upsertCategory($target, $now);
            return;
        }

        $conflict = Db::name('wisecms_category')
            ->where('site_id', self::SITE_ID)
            ->where('alias', $target['alias'])
            ->where('id', '<>', $legacy['id'])
            ->find();

        if ($conflict) {
            echo sprintf('[category] 跳过 %s → %s（目标 alias 已被 id=%d 占用）', $legacyAlias, $target['alias'], $conflict['id']) . PHP_EOL;
            return;
        }

        Db::name('wisecms_category')->where('id', $legacy['id'])->update($target + [
            'site_id'     => self::SITE_ID,
            'status'      => 1,
            'update_time' => $now,
        ]);

        echo sprintf('[category] 改造 %s → %s (id=%d)', $legacyAlias, $target['alias'], $legacy['id']) . PHP_EOL;
    }

    /**
     * 按 alias 幂等写入栏目
     */
    private function upsertCategory(array $row, string $now): void
    {
        $exists = Db::name('wisecms_category')
            ->where('site_id', self::SITE_ID)
            ->where('alias', $row['alias'])
            ->find();

        $data = array_merge([
            'site_id'      => self::SITE_ID,
            'parent_id'    => 0,
            'type'         => 0,
            'model_id'     => 0,
            'list_rows'    => 15,
            'is_nav'       => 1,
            'sort'         => 0,
            'status'       => 1,
            'description'  => '',
            'seo_keywords' => '',
            'seo_description' => '',
        ], $row, ['update_time' => $now]);

        if ($exists) {
            Db::name('wisecms_category')->where('id', $exists['id'])->update($data);
            echo sprintf('[category] 更新 %s (id=%d)', $row['alias'], $exists['id']) . PHP_EOL;
            return;
        }

        $data['create_time'] = $now;
        $id = Db::name('wisecms_category')->insertGetId($data);
        echo sprintf('[category] 创建 %s (id=%d)', $row['alias'], $id) . PHP_EOL;
    }

    /**
     * 6 个单页（catid 关联同名栏目）
     */
    private function ensurePages(string $now): void
    {
        $pages = [
            ['title' => '核心特性', 'alias' => 'features'],
            ['title' => '能力包清单', 'alias' => 'packages'],
            ['title' => '快速开始', 'alias' => 'quickstart'],
            ['title' => '下载', 'alias' => 'download'],
            ['title' => '关于 WiseAdmin', 'alias' => 'about'],
            ['title' => '联系我们', 'alias' => 'contact'],
        ];

        foreach ($pages as $page) {
            $catid = (int) Db::name('wisecms_category')
                ->where('site_id', self::SITE_ID)
                ->where('alias', $page['alias'])
                ->value('id');

            $exists = Db::name('wisecms_page')
                ->where('site_id', self::SITE_ID)
                ->where('alias', $page['alias'])
                ->find();

            if ($exists) {
                Db::name('wisecms_page')->where('id', $exists['id'])->update([
                    'title'       => $page['title'],
                    'catid'       => $catid,
                    'update_time' => $now,
                ]);
                echo sprintf('[page] 更新 %s (id=%d, catid=%d)', $page['alias'], $exists['id'], $catid) . PHP_EOL;
                continue;
            }

            $id = Db::name('wisecms_page')->insertGetId([
                'site_id'     => self::SITE_ID,
                'catid'       => $catid,
                'title'       => $page['title'],
                'alias'       => $page['alias'],
                'content'     => '',
                'status'      => 1,
                'view_count'  => 0,
                'create_time' => $now,
                'update_time' => $now,
            ]);
            echo sprintf('[page] 创建 %s (id=%d, catid=%d)', $page['alias'], $id, $catid) . PHP_EOL;
        }
    }

    /**
     * 清理 article 模型里与主表同名的 `content` 自定义字段
     *
     * 主表 `wisecms_content.content` 才是前台读取的正文；模型若再注册一个同名
     * 自定义字段，后台编辑器会出现**两个正文框**，且副表冗余一份正文。
     * 官网内容只用系统字段，故删除该自定义字段记录。
     */
    private function cleanupDuplicateContentField(): void
    {
        $deleted = Db::name('wisecms_model_field')
            ->where('model_id', self::MODEL_ARTICLE)
            ->where('field', 'content')
            ->where('is_system', 0)
            ->delete();

        if ($deleted > 0) {
            try {
                (new \plugin\wisecms\service\FieldRegistry())->clear(self::MODEL_ARTICLE);
            } catch (\Throwable $e) {
            }
            echo '[field] 已删除 article 模型的重复 content 自定义字段（避免后台双正文框）' . PHP_EOL;
        } else {
            echo '[field] article 模型无重复 content 字段，跳过' . PHP_EOL;
        }
    }

    /**
     * CMS 运行配置（写入 plugin_config，值由模型 json 类型自动编码）
     *
     * 只写确定安全的项；`url_rewrite` 依赖 Nginx 重写，留到 Phase D 开启。
     */
    private function configure(): void
    {
        $config = [
            'site_name'            => 'WiseAdmin',
            'content_check'        => '0',
            'auto_description'     => true,
            'auto_thumb'           => true,
            'save_remote_image'    => false,
            'list_rows'            => 10,
            'max_title_length'     => 200,
            'image_max_size'       => 2048,
            'comment_check'        => '0',
            'editor_mode'          => 'html',
            'version_keep'         => 20,
            'diff_max_lines'       => 2000,
            'preview_enable'       => true,
            'preview_ttl'          => 1800,
            'preview_bind_user'    => true,
            'preview_once'         => false,
            'recommend_enable'     => true,
            'feed_enable'          => true,
            'tag_enable'           => true,
            'friendlink_enable'    => false,
            'theme_editor_enable'  => false,
        ];

        foreach ($config as $key => $value) {
            $this->setPluginConfig($key, $value);
        }

        echo '[config] 已写入 ' . count($config) . ' 项 wisecms 配置' . PHP_EOL;
    }

    /**
     * 写单项插件配置（优先走框架 PluginConfig 服务以正确失效缓存）
     */
    private function setPluginConfig(string $key, mixed $value): void
    {
        try {
            $cfg = app(\wise\plugin\config\PluginConfig::class);
            $cfg->set('wisecms.' . $key, $value);
            return;
        } catch (\Throwable $e) {
        }

        $model = \wise\plugin\model\PluginConfigModel::where('plugin_name', 'wisecms')
            ->where('key', $key)
            ->find();

        if ($model) {
            $model->value = $value;
            $model->save();
            return;
        }

        \wise\plugin\model\PluginConfigModel::create([
            'plugin_name' => 'wisecms',
            'key'         => $key,
            'value'       => $value,
        ]);
    }
}
