<?php
namespace app\common\controller\traits;

use think\Model;
use think\db\Query;

/**
 * 基础查询操作Trait
 * 
 * @property \app\common\library\RequestQueryBuilder $queryBuilder 查询构建器
 * @property array $where 查询条件
 * @property array $order 排序条件
 * @property int $page 当前页码
 * @property int $limit 每页数量
 * @property int $pagesize 每页数量 $limit 别名
 * @property int $offset 偏移量
 * @property array $fields 查询字段
 * @property array $with 关联查询字段
 * @property array $withCount 关联查询字段数量
 * @property array $group 分组字段
 * @property array $having 分组条件
 */
trait RequestQueryBuild
{
    /**
     * 构建查询参数
     * @param array $build 需要构建的参数key数组
     * @return array
     */
    public function buildQueryParams($build = []): array
    {
        $data = [
            'where'     => $this->buildWhere(),
            'order'     => $this->buildOrder(),
            'page'      => $this->getPage(),
            'limit'     => $this->getLimit(),
            'fields'    => $this->getFields(),
            'offset'    => $this->getOffset(),
            'with'      => $this->getWith(),
            'withCount' => $this->getWithCount(),
            'group'     => $this->getGroup(),
            'having'    => $this->buildHaving(),
        ];
        if(empty($build)){
            return $data;
        }
        return array_values(array_intersect_key($data, array_flip($build)));
    }

    /**
     * 应用查询参数到模型
     * @param Model $model 模型对象
     */
    public function applyQueryToModel($model)
    {
        return $this->queryBuilder->applyToModel($model);
    }

    /**
     * 构建查询条件
     * @return array
     */
    public function buildWhere(): array
    {
        if(empty($this->where)){
            $this->where = $this->queryBuilder->buildWhere();
        }
        return $this->where;
    }

    /**
     * 生成排序条件
     * @return array
     */
    public function buildOrder(): array
    {
        if(empty($this->order)){
            $this->order = $this->queryBuilder->buildOrder();
        }
        return $this->order;
    }

    /**
     * 获取当前页码
     * @return int 当前页码
     */
    public function getPage(): int
    {
        if(empty($this->page)){
            $this->page = $this->queryBuilder->getPage();
        }
        return $this->page;
    }
    
    /**
     * 获取每页数量
     * @return int 每页数量
     */
    public function getLimit(): int
    {
        if(empty($this->limit)){
            $this->limit = $this->offset = $this->pagesize = $this->queryBuilder->getLimit();
        }
        return $this->limit;
    }

    /**
     * 获取偏移量
     * @return int 偏移量
     */
    public function getOffset(): int
    {
        if(empty($this->offset)){
            $this->offset = $this->queryBuilder->getOffset();
        }
        return $this->offset;
    }

    /**
     * 获取查询字段
     * @return array
     */
    public function getFields(): array
    {
        if(empty($this->fields)){
            $this->fields = $this->queryBuilder->getFields();
        }
        return $this->fields;
    }

    /**
     * 获取关联查询字段
     * @return array
     */
    public function getWith(): array
    {
        if(empty($this->with)){
            $this->with = $this->queryBuilder->getWith();
        }
        return $this->with;
    }
    
    /**
     * 获取关联查询字段数量
     * @return array
     */
    public function getWithCount(): array
    {
        if(empty($this->withCount)){
            $this->withCount = $this->queryBuilder->getWithCount();
        }
        return $this->withCount;
    }

    /**
     * 获取分组字段
     * @return array
     */
    public function getGroup(): array
    {
        if(empty($this->group)){
            $this->group = $this->queryBuilder->getGroup();
        }
        return $this->group;
    }
    
    /**
     * 构建分组条件
     * @return array
     */
    public function buildHaving(): array
    {
        if(empty($this->having)){
            $this->having = $this->queryBuilder->buildHaving();
        }
        return $this->having;
    }
}
