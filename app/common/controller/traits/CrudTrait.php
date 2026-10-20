<?php
namespace app\common\controller\traits;

/**
 * CRUD 组合 Trait
 *
 * 一键引入 CRUD 全部能力：查询构建 + 标准 RESTful 操作。
 *
 * ⚠️ 两个 trait 的分工：
 *   - `RequestQueryBuild`：查询参数构建（public 辅助方法，控制器可直接调用）；
 *   - `CrudAction`：**protected 实现**（`_index/_getList/_save/...`），不参与路由。
 *
 * 控制器必须自己声明要开放的动作，人工与 AI 才都能访问：
 *
 * ```php
 * class Article extends BaseController
 * {
 *     use CrudTrait;
 *
 *     protected function getModel(): Model { return new ArticleModel(); }
 *     protected function beforeSave(array &$data, bool $isNew): void { ... }
 *
 *     public function index()   { return $this->_index(); }
 *     public function getList() { return $this->_getList(); }
 *     public function save()    { return $this->_save(); }
 *     public function update()  { return $this->_update(); }
 *     public function delete()  { return $this->_delete(); }
 * }
 * ```
 *
 * 没写 public 方法的动作 = 不开放（fail-closed）。
 */
trait CrudTrait
{
    use RequestQueryBuild;
    use CrudAction;
}
