<?php
namespace app\common\facade;

use think\Facade;

/**
 * 当前登录管理员Facade类
 * 提供静态方式快速获取当前登录管理员的相关信息
 * 
 * @method static array buildQueryParams($params) 构建查询参数
 * @method static array buildWhere($params) 构建where条件数组
 * @method static array buildOrder($params) 构建排序条件
 * @method static int getPage($params) 获取分页参数
 * @method static int getLimit($params) 获取每页数量
 * @method static int getOffset($params) 获取偏移量
 * @method static array getFields($params) 获取查询字段
 * @method static array getWith($params) 获取关联查询字段
 * @method static array getWithCount($params) 获取关联查询字段
 * @method static array getGroup($params) 获取分组字段
 * @method static array buildHaving($params) 构建having条件
 */

class RequestQueryBuilder extends Facade
{
    /**
     * 获取当前Facade对应类名（或者已经绑定的容器对象标识）
     *
     * @return string
     */
    protected static function getFacadeClass()
    {
        return \app\common\library\RequestQueryBuilder::class;
    }

}
