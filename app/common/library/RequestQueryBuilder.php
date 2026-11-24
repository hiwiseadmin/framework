<?php
namespace app\common\library;

use think\facade\Request;
use think\Model;
use think\db\Query;

/**
 * 请求查询参数构建器
 * 用于构建查询参数，支持WHERE、ORDER、LIMIT等条件
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
        'eq'      => '=',
        'ne'      => '!=',
        'neq'     => '<>',
        'gt'      => '>',
        'lt'      => '<',
        'gte'     => '>=',
        'ge'      => '>=',
        'lte'     => '<=',
        'le'      => '<=',
        
        'like'    => 'like',
        'notlike' => 'not like',
        
        'in'      => 'in',
        'notin'   => 'not in',
        'between' => 'between',
        'notbetween' => 'not between',
        
        'null'    => 'null',
        'notnull' => 'not null',
        
        'exists'  => 'exists',
        'notexists' => 'not exists',
    ];
    
    /**
     * 允许的原始MySQL操作符（直接支持，无需转换）
     */
    protected $allowedRawOperators = [
        '=', '>', '<', '>=', '<=', '<>', '!=', 
        'like', 'not like', 'in', 'not in', 'null', 'not null', 
        'between','not between',
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
        $tableFields = $model->getTableFields();
        
        if (empty($this->queryParams)) {
            $this->buildQueryParams();
        }
        $qp = $this->queryParams;

        foreach ($qp['where'] as $condition) {
            if (is_array($condition) && isset($condition[0]) && in_array(strtoupper($condition[0]), ['AND', 'OR'])) {
                $logic = strtoupper($condition[0]);
                $subConditions = $condition[1];
                if ($logic === 'AND') {
                    $model = $model->where(function ($query) use ($subConditions, $tableFields) {
                        foreach ($subConditions as $subCond) {
                            if(in_array($subCond[0], $tableFields)){
                                $query->where([$subCond]);
                            } else {
                                $this->logFilteredField($subCond[0], 'AND group');
                            }
                        }
                    });
                } else {
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
                    $this->logFilteredField($condition[0], 'where');
                }
            }
        }

        if (!empty($qp['with'])) {
            $model = $model->with($qp['with']);
        }

        if (!empty($qp['withCount'])) {
            $model = $model->withCount($qp['withCount']);
        }

        if (!empty($qp['group'])) {
            $model = $model->group($qp['group']);
        }

        foreach ($qp['having'] as $having) {
            $model = $this->applyHavingToModel($model, $having);
        }

        if (!empty($qp['order'])) {
            foreach ($qp['order'] as $field => $direction) {
                if(in_array($field, $tableFields)){
                    $model = $model->order($field, $direction);
                }
            }
        }

        if (!empty($qp['fields']) && empty($options['withoutField'])) {
            $model = $model->field($qp['fields']);
        }

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

        if (in_array(strtolower($operator), ['null', 'not null'])) {
            return $model->having("{$field} {$operator}");
        }

        if (in_array(strtolower($operator), ['between', 'not between']) && is_array($value)) {
            return $model->having("{$field} {$operator} ? AND ?", $value);
        }

        if (in_array(strtolower($operator), ['in', 'not in']) && is_array($value)) {
            $placeholders = implode(',', array_fill(0, count($value), '?'));
            return $model->having("{$field} {$operator} ({$placeholders})", $value);
        }

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
            
            if (strpos($field, '__') !== false) {
                list($fieldName, $operator) = explode('__', $field, 2);
                
                $operator = $this->normalizeOperator($operator);
                
                if ($this->isValidOperator($operator)) {
                    $whereItem = $this->buildWhereItem($fieldName, $operator, $value);
                    if ($whereItem) {
                        $where[] = $whereItem;
                    }
                }
            } else {
                $where[] = [$field, '=', $value];
            }
        }
        
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
                
                $parts = preg_split('/\s+/', $item);
                $field = $parts[0];
                $direction = isset($parts[1]) ? strtolower($parts[1]) : 'asc';
                
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
            $filterParams = array_filter($params, function ($key) {
                return strpos($key, 'having__') === 0;
            }, ARRAY_FILTER_USE_KEY);

            foreach ($filterParams as $key => $value) {
                if ($value === '' || $value === null) continue;
                $parts = explode('__', $key);
                array_shift($parts);
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
                if (count($value) == 2) {
                    return [$field, $operator, $value];
                }
                if (count($value) == 1) {
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
                if (strpos($value, '%') === false) {
                    $value = '%' . $value . '%';
                }
                return [$field, $operator, $value];
                
            case 'null':
            case 'not null':
                return [$field, $operator];
                
            case 'exists':
            case 'not exists':
                return [$field, $operator, $value];
                
            default:
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
        
        if ($type === 'auto') {
            if (is_numeric($value)) {
                return strpos($value, '.') !== false ? (float)$value : (int)$value;
            }
            
            if ($value === 'true' || $value === 'false') {
                return $value === 'true';
            }
            
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