<?php
namespace plugin;

use app\common\model\BaseModel as CommonBaseModel;

/**
 * 插件 ORM 模型基类
 *
 * 所有插件的数据库模型应继承此类。
 * 子类需在 $name 中手动添加插件前缀（如 wisecms_），
 * 确保与数据库迁移文件中的表名一致。
 *
 * 例如：plugin\wisecms\model\Content 设置 $name = 'wisecms_content'
 */
abstract class BaseModel extends CommonBaseModel
{
}
