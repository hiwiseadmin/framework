<?php
namespace app\admin\model;

use app\common\model\BaseModel;

/**
 * 仪表盘布局模型
 *
 * layout_json 结构：
 *   ['settings' => ['columns'=>12,'cellHeight'=>70,'gap'=>12],
 *    'panels'   => [ ['uid'=>..., 'widgetKey'=>..., 'x'=>..,'y'=>..,'w'=>..,'h'=>..,
 *                     'accent'=>..., 'props'=>[], 'refresh'=>0, 'hidden'=>false], ... ]]
 *
 * 保存按 admin_id 单行覆盖（无并发冲突），故不使用乐观锁。
 */
class DashboardLayout extends BaseModel
{
    protected $name = 'dashboard_layouts';

    protected $json = ['settings_json', 'layout_json'];

    protected $jsonAssoc = true;

    /** 默认布局名 */
    public const DEFAULT_NAME = 'default';

    /** 系统模板归属标识 */
    public const OWNER_SYSTEM = 0;
}
