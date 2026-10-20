<?php
namespace plugin\wisecms\model;

use plugin\BaseModel;

/**
 * 广告模型（**计划功能**，保留不动）
 *
 * 详见建表迁移 `20251102000019_create_wisecms_ad_table.php` 的决策记录：
 * 广告系统仅有后台 CRUD、暂无前台消费方，属计划功能而非死代码。
 *
 * 注：`wisecms_ad` 无 `site_id` 列 —— 按站点投放的诉求出现时再统一补列与隔离，
 * 当前保持全站共享。
 */
class Ad extends BaseModel
{
    protected $name = 'wisecms_ad';
    // 自动时间戳：create_time / update_time 均为 DATETIME（见建表迁移的时间列约定）
    protected $autoWriteTimestamp = true;
}
