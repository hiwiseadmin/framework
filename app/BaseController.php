<?php
namespace app;

use think\App;
use think\exception\ValidateException;
use think\Validate;
use think\facade\View;
use app\common\library\RequestQueryBuilder;

/**
 * 控制器基础类
 */
abstract class BaseController
{
    /**
     * Request实例
     * @var \think\Request
     */
    protected $request;

    /**
     * 应用实例
     * @var \think\App
     */
    protected $app;

    /**
     * 模型实例
     * @var Model
     */
    protected $model;

    /**
     * 控制器中间件
     * @var array
     */
    protected $middleware = [];

    /**
     * 查询条件
     * @var array
     */
    protected $where = [];

    /**
     * 排序字段
     * @var array
     */
    protected $order = [];

    /**
     * 分页参数
     * @var int
     */
    protected $page = 0;

    /**
     * 分页大小
     * @var int
     */
    protected $pagesize = 0;
    protected $limit = 0;

    /**
     * 偏移量
     * @var int
     */
    protected $offset = 0;

    /**
     * 查询字段
     * @var array
     */
    protected $fields = [];

    /**
     * 关联查询字段
     * @var array
     */
    protected $with = [];

    /**
     * 关联查询计数字段数量
     * @var array
     */
    protected $withCount = [];

    /**
     * 分组字段
     * @var array
     */
    protected $group = [];

    /**
     * 分组条件
     * @var array
     */
    protected $having = [];

    /**
     * 查询参数构造器
     * @var RequestQueryBuilder
     */
    protected $queryBuilder;

    /**
     * 构造方法
     * @access public
     * @param  App  $app  应用对象
     */
    public function __construct(App $app)
    {
        $this->app     = $app;
        $this->request = $this->app->request;
        $this->queryBuilder = new RequestQueryBuilder($this->request);

        $this->initialize();
    }

    // 初始化
    protected function initialize()
    {

    }

    /**
     * 模板变量赋值
     * @param string|array $name 变量名
     * @param mixed $value 变量值
     * @return $this
     */
    public function assign($name, $value = null)
    {
        if (is_array($name)) {
            View::assign($name);
        } elseif (func_num_args() < 2) {
            return View::assign($name);
        } else {
            View::assign($name, $value);
        }

        return $this;
    }
    
    /**
     * 渲染模板输出
     * @param string $template 模板文件
     * @param array $vars 模板变量
     * @param array $config 模板参数
     * @return string
     */
    public function fetch($template = '', $vars = [], $config = [])
    {
        if (!empty($vars)) {
            View::assign($vars);
        }
        
        return View::fetch($template, $config);
    }
}
