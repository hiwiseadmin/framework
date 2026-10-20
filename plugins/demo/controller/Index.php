<?php
declare(strict_types=1);

namespace plugin\demo\controller;

use plugin\BaseController;
use think\facade\Lang;

/**
 * demo 默认控制器（插件后台控制器，继承 plugin\BaseController
 * 以获得插件标识解析 / 视图路径 / 模板变量等插件上下文能力）
 *
 * 多语言示例：插件自有文案写在 plugins/demo/lang/{locale}.php，
 * 无需任何注册 —— LangPackService 会按目录约定自动登记进 lang.extend_list。
 */
class Index extends BaseController
{
    /**
     * 插件首页
     */
    public function index()
    {
        return $this->assign([
            'plugin_name' => 'demo',
            'title'       => lang('demo.title'),
            'intro'       => lang('demo.intro'),
            'label_version'    => lang('demo.version'),
            'label_author'     => lang('demo.author'),
            'label_created_at' => lang('demo.created_at'),
            'locale'           => Lang::getLangSet(),
        ])->fetch();
    }
}
