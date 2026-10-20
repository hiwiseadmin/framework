<?php
namespace app\common\library;

use think\facade\Request;
use think\Model;
use think\db\Query;

/**
 * 请求查询参数构建器
 * 用于构建查询参数，支持WHERE、ORDER、LIMIT等条件
 */
/*
 * 请求查询参数构建器
 * 用于构建查询参数，支持WHERE、ORDER、LIMIT等条件
 * 
 * 示例请求参数：?name=张三&status=1
 * $builder = new RequestQueryBuilder();
 * list[$where, $order, $page, $limit, $offset, $fields] = $builder->buildQueryParams($request);
 * 结果：where = [['name', '=', '张三'], ['status', '=', 1]]
 * 
 * ###### 比较操作符查询 ######
 * 大于: ?age__gt=18
 * 结果：where = [['age', '>', 18]]
 * 小于: ?age__lt=60
 * 结果：where = [['age', '<', 60]]
 * 大于等于: ?score__gte=60 或 ?score__ge=60
 * 结果：where = [['score', '>=', 60]]
 * 小于等于: ?score__lte=100 或 ?score__le=100
 * 结果：where = [['score', '<=', 100]]
 * 不等于: ?status__ne=0 或 ?status__neq=0
 * 结果：where = [['status', '!=', 0]] 或 [['status', '<>', 0]]
 * 
 * ###### 模糊查询 ######
 * 包含: ?name__like=张三
 * 结果：where = [['name', 'like', '%张三%']]
 * 不包含: ?name__notlike=张三
 * 结果：where = [['name', 'not like', '%张三%']]
 * 已包含%符号: ?email__like=%@gmail.com
 * 结果：where = [['email', 'like', '%@gmail.com']]
 * 
 * ###### 范围查询 ######
 * IN查询: ?status__in=1,2,3
 * 结果：where = [['status', 'in', [1, 2, 3]]]
 * 数组格式: ?status__in[]=1&status__in[]=2
 * 结果：where = [['status', 'in', [1, 2]]]
 * NOT IN查询: ?id__notin=4,5,6
 * 结果：where = [['id', 'not in', [4, 5, 6]]]
 * BETWEEN查询: ?age__between=18,60
 * 结果：where = [['age', 'between', [18, 60]]]
 * NOT BETWEEN查询: ?age__notbetween=10,20
 * 结果：where = [['age', 'not between', [10, 20]]]
 * 日期范围: ?create_time__between=2023-01-01,2023-12-31
 * 结果：where = [['create_time', 'between', ['2023-01-01', '2023-12-31']]]
 * 
 * ###### 空值查询 ######
 * 为NULL: ?delete_time__null=1
 * 结果：where = [['delete_time', 'null']]
 * 不为NULL: ?email__notnull=1
 * 结果：where = [['email', 'not null']]
 * 
 * ###### 排序参数 ######
 * 单字段排序: ?_order=create_time desc
 * 结果：order = ['create_time' => 'desc']
 * 多字段排序: ?_order=create_time desc,update_time asc
 * 结果：order = ['create_time' => 'desc', 'update_time' => 'asc']
 * 字段名带排序: ?_order=create_time__desc,update_time__asc
 * 结果：order = ['create_time' => 'desc', 'update_time' => 'asc']
 * 混合写法: ?_order=create_time__desc asc
 * 结果：order = ['create_time' => 'desc'] (优先使用__后的排序)
 * 
 * ###### 分页参数 ######
 * 分页: ?_page=2&_limit=20
 * 结果：page = 2, limit = 20
 * 默认值: 不传page和limit
 * 结果：page = 1, limit = 20 (来自config)
 * 限制最大值: ?_limit=2000
 * 结果：limit = 1000 (最大值限制)
 * 
 * ###### 存在参数 ######
 * 存在: ?_exists=email
 * 结果：where = [['email', 'exists']]
 * 不存在: ?_notexists=email
 * 结果：where = [['email', 'not exists']]
 * 
 * ###### 字段选择参数 ######
 * 选择字段: ?_fields=id,name,email
 * 结果：fields = ['id', 'name', 'email']
 * 别名参数: ?_fields=id,name 或 ?_select=id,name
 * 结果：fields = ['id', 'name']
 * 
 * ###### 特殊值处理 ######
 * 1.数字字符串: ?age__gt=18
 * 结果：where = [['age', '>', 18]] (自动转为int)
 * 2.浮点数: ?price__gt=99.99
 * 结果：where = [['price', '>', 99.99]] (自动转为float)
 * 3.布尔值: ?is_active__eq=true
 * 结果：where = [['is_active', '=', true]] (自动转为bool)
 * 4.JSON字符串: ?meta__like={"type":"user"}
 * 结果：where = [['meta', 'like', '%{"type":"user"}%']]
 * 5.空值忽略: ?name=&status=1
 * 结果：where = [['status', '=', 1]] (name参数被忽略)
 * 
 * ###### 数组值处理 ######
 * 1.逗号分隔: ?tags__like=php,thinkphp
 * 结果：where = [['tags', 'like', '%php,thinkphp%']]
 * 2.竖线分隔: ?categories__in=1|2|3
 * 结果：where = [['categories', 'in', ['1', '2', '3']]]
 * 3.空格分隔: ?ids__in=1 2 3
 * 结果：where = [['ids', 'in', ['1', '2', '3']]]
 * 
 * ####### OR条件 #########
 * OR条件: ?name__or=张三&age__or=18
 * 复杂条件: ?_and[0][name][eq]=张三&_and[1][age][gt]=18
 * 
 * ####### HAVING条件 #########
 * HAVING: ?_having__total__gt=100
 * 
 * ####### 复杂条件 #########
 * 关联预加载: ?_with=profile,posts.comments
 * 关联统计: ?_with_count=comments,likes
 * 分组: ?_group=user_id,status
 * 
 */

class RequestQueryBuilder
{
    /**
     * 查询参数对象
     */
    protected $queryParams = [];

    /**
     * 原始参数
     */
    protected $originalParams = [];
    
    /**
     * 默认排序
     */
    protected $defaultOrder = ['id' => 'desc'];
    
    /**
     * 操作符映射关系：参数缩写 => MySQL操作符
     */
    protected $operatorMap = [
        // 比较操作符
        'eq'      => '=',        // 等于
        'ne'      => '!=',       // 不等于
        'neq'     => '<>',       // 不等于(另一种写法)
        'gt'      => '>',        // 大于
        'lt'      => '<',        // 小于
        'gte'     => '>=',       // 大于等于
        'ge'      => '>=',       // 大于等于(简写)
        'lte'     => '<=',       // 小于等于
        'le'      => '<=',       // 小于等于(简写)
        
        // 模糊匹配
        'like'    => 'like',     // 模糊匹配
        'notlike' => 'not like', // 不模糊匹配
        
        // 范围查询
        'in'      => 'in',       // 在范围内
        'notin'   => 'not in',   // 不在范围内
        'between' => 'between',  // 在区间内
        'notbetween' => 'not between', // 不在区间内
        
        // 空值判断
        'null'    => 'null',     // 为null
        'notnull' => 'not null', // 不为null
        
        // 存在性判断
        'exists'  => 'exists',   // 存在
        'notexists' => 'not exists', // 不存在
    ];
    
    /**
     * 允许的原始MySQL操作符（直接支持，无需转换）
     */
    protected $allowedRawOperators = [
        '=', '>', '<', '>=', '<=', '<>', '!=', 
        'like', 'not like', 'in', 'not in', 'null', 'not null', 
        'between','not between',
        //  'exists', 'not exists'
    ];
    
    /**
     * 非where参数列表
     */
    protected $nonWhereParams = ['search', 'sort', 'order', 'offset', 'limit'];
    
    /**
     * 保留参数列表
     */
    protected $reservedParams= [
        'page', 'limit', 'pagesize', 'offset',
        'sort', 'order', 'field', 'fields', 'select',
        'with', 'with_count', 'group', 'having', '_'
    ];

    /**
     * 逻辑分组前缀（用于复杂条件）
     */
    protected $logicGroups = ['_and', '_or'];

    /**
     * 保留参数前缀，设为空字符串则使用原来的无前缀形式
     */
    protected $reservedPrefix = '';

    /**
     * 单例实例
     */
    protected static $instance = null;
    
    /**
     * 构造函数
     * @param Request|null $request 请求对象
     */
    public function __construct($request = null)
    {
        $this->originalParams = $request ? $request->param() : app()->request->param();
        $this->nonWhereParams = $this->buildNonWhereParams();
    }

    /**
     * 获取单例实例
     * @param Request|null $request 请求对象
     * @return self
     */
    public static function getInstance($request = null): self
    {
        if (self::$instance === null) {
            self::$instance = new self($request);
        }
        return self::$instance;
    }


    /**
     * 将构建的参数应用到模型查询
     * @param Model|string $model 模型实例或类名
     * @param array $options 选项（withoutPage, withoutField等）
     * @return Model|Query
     */
    public function applyToModel($model, $options = []): Model|Query
    {
        // 获取模型表字段
        $tableFields = $model->getTableFields();
        
        // 确保查询参数已构建
        if (empty($this->queryParams)) {
            $this->buildQueryParams();
        }
        $qp = $this->queryParams;

        // WHERE条件（支持复杂分组）
        foreach ($qp['where'] as $condition) {
            if (is_array($condition) && isset($condition[0]) && in_array(strtoupper($condition[0]), ['AND', 'OR'])) {
                $logic = strtoupper($condition[0]); // 'AND' 或 'OR'
                $subConditions = $condition[1];
                if ($logic === 'AND') {
                    // AND 分组：默认就是 AND，可以直接使用闭包
                    $model = $model->where(function ($query) use ($subConditions, $tableFields) {
                        foreach ($subConditions as $subCond) {
                            if(in_array($subCond[0], $tableFields)){
                                $query->where([$subCond]);
                            } else {
                                // 修复 P1-2: 记录非表字段过滤警告
                                $this->logFilteredField($subCond[0], 'AND group');
                            }
                        }
                    });
                } else { // 'OR'
                    $model = $model->whereOr(function ($query) use ($subConditions, $tableFields) {
                        foreach ($subConditions as $subCond) {
                            if(in_array($subCond[0], $tableFields)){
                                $query->where([$subCond]);
                            } else {
                                $this->logFilteredField($subCond[0], 'OR group');
                            }
                        }
                    });
                }
            } else {
                if(in_array($condition[0], $tableFields)){
                    $model = $model->where([$condition]);
                } else {
                    // 修复 P1-2: 记录非表字段过滤警告
                    $this->logFilteredField($condition[0], 'where');
                }
            }
        }

        // 关联预加载
        if (!empty($qp['with'])) {
            $model = $model->with($qp['with']);
        }

        // 关联统计
        if (!empty($qp['withCount'])) {
            $model = $model->withCount($qp['withCount']);
        }

        // 分组
        if (!empty($qp['group'])) {
            $model = $model->group($qp['group']);
        }

        // HAVING条件 — 修复 P1: 不使用 implode 数组（between 等操作符含数组值会崩溃）
        foreach ($qp['having'] as $having) {
            $model = $this->applyHavingToModel($model, $having);
        }

        // 排序
        if (!empty($qp['order'])) {
            foreach ($qp['order'] as $field => $direction) {
                if(in_array($field, $tableFields)){
                    $model = $model->order($field, $direction);
                }
            }
        }

        // 字段选择
        if (!empty($qp['fields']) && empty($options['withoutField'])) {
            $model = $model->field($qp['fields']);
        }

        // 分页
        if (!empty($qp['page']) && empty($options['withoutPage'])) {
            $model = $model->page($qp['page'], $qp['limit']);
        }

        return $model;
    }

    /**
     * 将单个 HAVING 条件应用到模型
     * 支持简单格式 [field, op]（null/not null）和三元素格式 [field, op, value]
     * @param Model|Query $model
     * @param array $having [field, operator, value] 或 [field, operator]
     * @return Model|Query
     */
    protected function applyHavingToModel($model, array $having): Model|Query
    {
        $field    = $having[0];
        $operator = $having[1];
        $value    = $having[2] ?? null;

        // null / not null：双参数格式 "field is null"
        if (in_array(strtolower($operator), ['null', 'not null'])) {
            return $model->having("{$field} {$operator}");
        }

        // between / not between：数组值
        if (in_array(strtolower($operator), ['between', 'not between']) && is_array($value)) {
            return $model->having("{$field} {$operator} ? AND ?", $value);
        }

        // in / not in：数组值
        if (in_array(strtolower($operator), ['in', 'not in']) && is_array($value)) {
            $placeholders = implode(',', array_fill(0, count($value), '?'));
            return $model->having("{$field} {$operator} ({$placeholders})", $value);
        }

        // 标准三元素格式
        return $model->having("{$field} {$operator} ?", [$value]);
    }

    /**
     * 获取构建后的参数数组（供手动使用）
     * @return array
     */
    public function getQueryParams(): array
    {
        if (empty($this->queryParams)) {
            $this->buildQueryParams();
        }
        return $this->queryParams;
    }

    /**
     * 构建查询参数对象
     * @param array|null $params 自定义参数数组
     * @return array ['where', 'order', 'page', 'limit', 'offset', 'fields', 'with', 'withCount', 'group', 'having']
     * @throws \Exception
     */
    public function buildQueryParams($params = null): array
    {
        $params = $params ?? $this->originalParams;

        $this->queryParams = [
            'where'       => $this->buildWhere($params),
            'order'       => $this->buildOrder($params),
            'page'        => $this->getPage($params),
            'limit'       => $this->getLimit($params),
            'fields'      => $this->getFields($params),
            'offset'      => $this->getOffset($params),
            'with'        => $this->getWith($params),
            'withCount'   => $this->getWithCount($params),
            'group'       => $this->getGroup($params),
            'having'      => $this->buildHaving($params),
        ];

        return $this->queryParams;
    }
    
    /**
     * 构建where条件数组
     * @param array|null $params 自定义参数数组
     * @return array
     */
    public function buildWhere($params = null): array
    {
        $params = $params ?? $this->originalParams;
        $where = [];
        $filterParams = array_diff_key($params, array_flip($this->nonWhereParams));
        
        foreach ($filterParams as $field => $value) {
            if ($value === '' || $value === null || in_array($field, $this->logicGroups)) {
                continue;
            }
            
            // 处理带操作符的字段，如: age__gt, name__like
            if (strpos($field, '__') !== false) {
                list($fieldName, $operator) = explode('__', $field, 2);
                
                // 转换操作符缩写
                $operator = $this->normalizeOperator($operator);
                
                if ($this->isValidOperator($operator)) {
                    $whereItem = $this->buildWhereItem($fieldName, $operator, $value);
                    if ($whereItem) {
                        $where[] = $whereItem;
                    }
                }
            } else {
                // 默认等于查询
                $where[] = [$field, '=', $value];
            }
        }
        
        // 处理复杂逻辑分组（_and, _or）
        foreach ($this->logicGroups as $groupKey) {
            if (isset($params[$groupKey]) && is_array($params[$groupKey])) {
                $groupWhere = $this->buildLogicGroup($params[$groupKey], $groupKey);
                if (!empty($groupWhere)) {
                    $where[] = $groupWhere;
                }
            }
        }

        return $where;
    }
    
    /**
     * 构建逻辑分组（_and 或 _or）
     * @param array $conditions 条件数组，每个元素是一个字段的条件定义
     * @param string $logic '_and' 或 '_or'（默认 '_and'）
     * @return array
     */
    protected function buildLogicGroup($conditions, $logic = '_and'): array
    {
        $logic = strtoupper(trim($logic, '_'));
        $group = [];

        foreach ($conditions as $condition) {
            if (!is_array($condition)) continue;
            foreach ($condition as $field => $value) {
                if ($value === '' || $value === null) continue;
                if (strpos($field, '__') !== false) {
                    list($fieldName, $operator) = explode('__', $field, 2);
                    $operator = $this->normalizeOperator($operator);
                    if ($this->isValidOperator($operator)) {
                        $item = $this->buildWhereItem($fieldName, $operator, $value);
                        if ($item) $group[] = $item;
                    }
                } else {
                    $group[] = [$field, '=', $value];
                }
            }
        }

        if (empty($group)) return [];
        return [$logic, $group];
    }

    /**
     * 构建排序条件
     * @param array|null $params 自定义参数数组
     * @return array
     */
    public function buildOrder($params = null): array
    {
        $params = $params ?? $this->originalParams;
        $order = [];
        $_order = $this->getPreParam($params, 'order', null, in_array('order', $this->nonWhereParams));
        $_sort = $this->getPreParam($params, 'sort', null, in_array('sort', $this->nonWhereParams));
        if(!empty($_order) && !empty($_sort)){
            return [$_sort => $_order];
        }else if (!empty($_order) || !empty($_sort)) {
            $orderParam = !empty($_order) ? $_order : ($_sort ?? null);
            $orderItems = is_array($orderParam) ? $orderParam : explode(',', $orderParam);
            
            foreach ($orderItems as $item) {
                $item = trim($item);
                if (empty($item)) continue;
                
                // 解析排序字段和方向
                $parts = preg_split('/\s+/', $item);
                $field = $parts[0];
                $direction = isset($parts[1]) ? strtolower($parts[1]) : 'asc';
                
                // 支持字段名后加__desc或__asc的写法
                if (strpos($field, '__') !== false) {
                    list($fieldName, $dir) = explode('__', $field, 2);
                    if (in_array(strtolower($dir), ['asc', 'desc'])) {
                        $field = $fieldName;
                        $direction = strtolower($dir);
                    }
                }
                
                if (in_array($direction, ['asc', 'desc'])) {
                    $order[$field] = $direction;
                } else {
                    $order[$field] = 'asc';
                }
            }
        }
        
        // 默认排序
        if (empty($order) && property_exists($this, 'defaultOrder')) {
            $order = $this->defaultOrder;
        }
        
        return $order;
    }
    
    /**
     * 获取分页参数
     * @param array|null $params 自定义参数数组
     * @return int
     */
    public function getPage($params = null): int
    {
        $params = $params ?? $this->originalParams;

        $pageParam = config('paginate.page_param', 'page');
        if (!empty($page = $this->getPreParam($params, $pageParam, null, in_array($pageParam, $this->nonWhereParams)))) {
            return max(1, intval($page ?? 1));
        }

        // 如果提供了offset，反向计算page
        $offsetParam = config('paginate.offset_param', 'offset');
        $limitParam = config('paginate.limit_param', 'limit');

        if (($offset = $this->getPreParam($params, $offsetParam, null, in_array($offsetParam, $this->nonWhereParams))) !== null && 
            !empty($limit = $this->getPreParam($params, $limitParam, 0, in_array($limitParam, $this->nonWhereParams)))) {
            return max(1, floor(intval($offset) / intval($limit)) + 1);
        }
        return 0;
    }
    
    /**
     * 获取每页数量
     * @param array|null $params 自定义参数数组
     * @return int
     */
    public function getLimit($params = null): int
    {
        $params = $params ?? $this->originalParams;
        $limitParam = config('paginate.limit_param', 'limit');
        if (!empty($limit = $this->getPreParam($params, $limitParam, null, in_array($limitParam, $this->nonWhereParams))) || 
            !empty($pagesize = $this->getPreParam($params, 'pagesize', null, in_array('pagesize', $this->nonWhereParams)))) {
            $limit = intval($limit ?? $pagesize);  
            return min(max(1, $limit), config('paginate.max_list_rows', 1000));
        }
        
        return config('paginate.list_rows', 20);
    }

    /**
     * 获取偏移量
     * @param array|null $params 自定义参数数组
     * @return int
     */
    public function getOffset($params = null): int
    {
        $params = $params ?? $this->originalParams;
        $offsetParam = config('paginate.offset_param', 'offset');
        if (!empty($offset = $this->getPreParam($params, $offsetParam, null, in_array($offsetParam, $this->nonWhereParams)))) {
            return max(0, intval($offset));
        }

        // 如果提供了page和limit，反向计算offset
        $pageParam = config('paginate.page_param', 'page');
        $limitParam = config('paginate.limit_param', 'limit');
        if (!empty($page = $this->getPreParam($params, $pageParam, null, in_array($pageParam, $this->nonWhereParams))) && 
            !empty($limit = $this->getPreParam($params, $limitParam, null, in_array($limitParam, $this->nonWhereParams)))) {
            return ($page - 1) * $limit;
        }
        return 0;
    }

    /**
     * 获取查询字段
     * @param array|null $params 自定义参数数组
     * @return array
     */
    public function getFields($params = null): array
    {
        $params = $params ?? $this->originalParams;
        foreach (['field', 'fields', 'select'] as $key) {
            if (!empty($field = $this->getPreParam($params, $key, null, in_array($key, $this->nonWhereParams)))) {
                return is_array($field) ? $field : array_filter(explode(',', $field));
            }
        }
        return [];
    }
   
    /**
     * 获取关联预加载参数
     * 支持 ?with=profile,posts.comments 或 ?with[]=profile&with[]=posts.comments
     * @param array|null $params 自定义参数数组
     * @return array
     */
    public function getWith($params = null): array
    {
        $params = $params ?? $this->originalParams;
        if (!empty($with = $this->getPreParam($params, 'with', null, in_array('with', $this->nonWhereParams)))) {
            return is_array($with) ? $with : array_filter(explode(',', $with));
        }
        return [];
    }

    /**
     * 获取关联统计参数
     * 支持 ?with_count=comments,likes
     * @param array|null $params 自定义参数数组
     * @return array
     */
    public function getWithCount($params = null): array 
    {
        $params = $params ?? $this->originalParams;
        if (!empty($withCount = $this->getPreParam($params, 'with_count', null, in_array('with_count', $this->nonWhereParams)))) {
            return is_array($withCount) ? $withCount : array_filter(explode(',', $withCount));
        }
        return [];
    }

    /**
     * 获取分组字段
     * @param array|null $params 自定义参数数组
     * @return array
     */
    public function getGroup($params = null): array
    {
        $params = $params ?? $this->originalParams;
        if (!empty($group = $this->getPreParam($params, 'group', null, in_array('group', $this->nonWhereParams)))) {
            return is_array($group) ? $group : array_filter(explode(',', $group));
        }
        return [];
    }

    /**
     * 构建HAVING条件（语法与WHERE类似）
     * 例如 ?having__total__gt=100
     * @param array|null $params 自定义参数数组
     * @return array
     */
    public function buildHaving($params = null): array
    {
        $params = $params ?? $this->originalParams;
        $having = [];

        if (!empty($_having = $this->getPreParam($params, 'having', null, in_array('having', $this->nonWhereParams))) && is_array($_having)) {
            // 复杂写法：having[字段][操作符]=值
            foreach ($_having as $field => $condition) {
                if (is_array($condition)) {
                    foreach ($condition as $operator => $value) {
                        $operator = $this->normalizeOperator($operator);
                        if ($this->isValidOperator($operator)) {
                            $item = $this->buildWhereItem($field, $operator, $value);
                            if ($item) $having[] = $item;
                        }
                    }
                } else {
                    $having[] = [$field, '=', $condition];
                }
            }
        } else {
            // 简单写法：having__字段__操作符
            $filterParams = array_filter($params, function ($key) {
                return strpos($key, 'having__') === 0;
            }, ARRAY_FILTER_USE_KEY);

            foreach ($filterParams as $key => $value) {
                if ($value === '' || $value === null) continue;
                $parts = explode('__', $key);
                array_shift($parts); // 移除 'having'
                $field = $parts[0];
                $operator = isset($parts[1]) ? $parts[1] : 'eq';
                $operator = $this->normalizeOperator($operator);
                if ($this->isValidOperator($operator)) {
                    $item = $this->buildWhereItem($field, $operator, $value);
                    if ($item) $having[] = $item;
                }
            }
        }

        return $having;
    }

    /**
     * 获取参数值，支持带前缀和不带前缀的访问
     * @param array|null $params 自定义参数数组
     * @param string $key 参数键
     * @param mixed|null $default 默认值
     * @param bool $isRaw 是否同时检查原始返回（不处理前缀）
     * @return mixed 参数值
     */
    protected function getPreParam($params, $key, $default = null, $isRaw = false): mixed
    {
        $prefixed = $this->reservedPrefix . $key;
        if (isset($params[$prefixed])) {
            return $params[$prefixed];
        }
        if ($isRaw && isset($params[$key])) {
            return $params[$key];
        }
        return $default;
    }
    /**
     * 构建非WHERE参数列表
     * @return array
     */
    protected function buildNonWhereParams(): array
    {
        if ($this->reservedPrefix === '') {
            return $this->reservedParams;
        }
        // 带前缀的版本
        return array_map(function($param) {
            return $param == '_' ? $param : $this->reservedPrefix . $param;
        }, $this->reservedParams);
    }

    /**
     * 规范化操作符（将缩写转换为标准MySQL操作符）
     * @param string $operator 操作符
     * @return string 规范化操作符
     */
    protected function normalizeOperator($operator): string
    {
        $operatorLower = strtolower($operator);
        if (in_array($operatorLower, $this->allowedRawOperators)) {
            return $operatorLower;
        }
        return $this->operatorMap[$operatorLower] ?? $operator;
    }
    
    /**
     * 检查是否有效的操作符
     * @param string $operator 操作符
     * @return bool 是否有效操作符
     */
    protected function isValidOperator($operator): bool
    {
        return in_array(strtolower($operator), $this->allowedRawOperators);
    }
    
    /**
     * 构建单个where条件
     * @param string $field 字段名
     * @param string $operator 操作符
     * @param mixed $value 值
     * @return array|bool 条件数组或false（无效操作符）
     */
    protected function buildWhereItem($field, $operator, $value): array|bool
    {
        $operator = strtolower($operator);
        
        switch ($operator) {
            case 'in':
            case 'not in':
                if (!is_array($value)) {
                    $value = $this->parseValue($value, 'array');
                }
                return [$field, $operator, $value];
                
            case 'between':
            case 'not between':
                if (!is_array($value)) {
                    $value = $this->parseValue($value, 'array');
                }
                // 修复 P2-5: between 要求恰好 2 个值，不足时记录警告并跳过
                if (count($value) == 2) {
                    return [$field, $operator, $value];
                }
                if (count($value) == 1) {
                    // 单值退化："between 18" → ">= 18"，同时记录日志
                    if (config('app.debug', false)) {
                        app('log')->warning(
                            "[RequestQueryBuilder] between 操作符仅收到 1 个值，已退化为 '>=' 查询: {$field} >= {$value[0]}"
                        );
                    }
                    return [$field, '>=', $value[0]];
                }
                break;
                
            case 'like':
            case 'not like':
                // 如果值已经包含%，直接使用；否则在两边加%
                if (strpos($value, '%') === false) {
                    $value = '%' . $value . '%';
                }
                return [$field, $operator, $value];
                
            case 'null':
            case 'not null':
                // NULL查询不需要值
                return [$field, $operator];
                
            case 'exists':
            case 'not exists':
                // EXISTS查询需要子查询
                return [$field, $operator, $value];
                
            default:
                // 对于比较操作符，处理值的类型
                $value = $this->parseValue($value);
                return [$field, $operator, $value];
        }
        
        return false;
    }
    
    /**
     * 解析值的类型
     * @param mixed $value 值
     * @param string $type 类型（auto或array）
     * @return mixed 解析后的值
     */
    protected function parseValue($value, $type = 'auto'): mixed
    {
        if ($type === 'array') {
            if (is_string($value)) {
                // 支持逗号分隔和竖线分隔（移除空格分隔避免误拆 "New York" 等）
                if (strpos($value, ',') !== false) {
                    return explode(',', $value);
                } elseif (strpos($value, '|') !== false) {
                    return explode('|', $value);
                } else {
                    return [$value];
                }
            }
            return (array)$value;
        }
        
        // 自动判断类型
        if ($type === 'auto') {
            // 如果是数字字符串，转换为数字
            if (is_numeric($value)) {
                return strpos($value, '.') !== false ? (float)$value : (int)$value;
            }
            
            // 如果是布尔字符串
            if ($value === 'true' || $value === 'false') {
                return $value === 'true';
            }
            
            // 如果是JSON字符串
            if ($this->isJson($value)) {
                return json_decode($value, true);
            }
        }
        
        return $value;
    }
    
    /**
     * 检查是否是JSON字符串
     * @param string $string 字符串
     * @return bool 是否是JSON字符串
     */
    protected function isJson($string): bool
    {
        if (!is_string($string)) {
            return false;
        }
        
        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * 记录被过滤的非表字段查询条件
     * @param string $field 字段名
     * @param string $context 上下文（where / AND group / OR group）
     * @return void
     */
    protected function logFilteredField(string $field, string $context = 'where'): void
    {
        // 仅在 DEBUG 模式下记录日志
        if (config('app.debug', false)) {
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
            $caller = $trace[2]['function'] ?? 'unknown';
            app('log')->warning(
                "[RequestQueryBuilder] 查询字段 '{$field}' 不在模型表字段中，已跳过 ({$context})",
                ['caller' => $caller]
            );
        }
    }    
}