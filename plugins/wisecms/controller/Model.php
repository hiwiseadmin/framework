<?php
namespace plugin\wisecms\controller;

use plugin\wisecms\exception\WisecmsException;
use plugin\wisecms\model\Model as ModelModel;
use plugin\wisecms\validate\Model as ModelValidate;
use plugin\wisecms\service\model\ModelService;
use plugin\wisecms\service\model\FieldFactory;
use plugin\BaseController;

class Model extends BaseController
{
    use PageFallbackTrait;

    protected $modelService;
    
    protected function initPlugin(): void
    {
        $this->modelService = new ModelService();
    }
    
    /**
     * 模型列表
     */
    public function index()
    {
        // P1-B6：参数未做类型转换/上限收敛，超大 limit 会退化为慢查询
        $page  = max(1, (int) $this->request->param('page', 1));
        $limit = min(100, max(1, (int) $this->request->param('limit', 15)));

        $list = ModelModel::paginate([
            'page' => $page,
            'list_rows' => $limit,
        ]);
        
        if ($this->request->isAjax()) {
            return $this->success('', [
                'code' => 0,
                'data' => $list->items(),
                'count' => $list->total(),
                'msg' => ''
            ]);
        }
        
        // 视图按数组键取数（$list.data / $list.total），故统一转数组
        $this->assign('list', $list->toArray());
        return $this->fetch('admin/model/index');
    }
    
    /**
     * 添加模型
     */
    public function create()
    {
        if ($this->request->isPost()) {
            $data = $this->request->post();
            
            // 验证数据
            $validate = new ModelValidate();
            if (!$validate->scene('create')->check($data)) {
                return $this->error($validate->getError());
            }
            
            try {
                $result = $this->modelService->createModel($data);
                return $this->success('模型创建成功', $result);
            } catch (\Exception $e) {
                // P1-A5：底层异常细节仅落日志，不回显（防泄露 SQL / 表结构 / 绝对路径）
                cms_log('error', 'CMS 后台操作失败: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
                return $this->error(WisecmsException::userMessage($e));
            }
        }
        
        // 获取字段类型列表
        $fieldTypes = FieldFactory::getAllFieldTypes();
        
        $this->assign('fieldTypes', $fieldTypes);
        return $this->fetch('admin/model/create');
    }
    
    /**
     * 编辑模型
     */
    // ⚠️ `$id` 必须**可选**：声明成必填时，无 `?id` 的页面请求会抛
    //    `ArgumentCountError` → 500（UIUX-V-03 实测），而不是走到下面的存在性校验
    public function edit($id = 0)
    {
        $model = ModelModel::find($id);
        if (!$model) {
            return $this->pageFallback('模型不存在', plugin_url('wisecms/model/index'));
        }
        
        if ($this->request->isPost()) {
            $data = $this->request->post();
            
            // 验证数据
            $validate = new ModelValidate();
            if (!$validate->scene('edit')->check($data)) {
                return $this->error($validate->getError());
            }
            
            try {
                $result = $this->modelService->updateModel($id, $data);
                return $this->success('模型更新成功', $result);
            } catch (\Exception $e) {
                // P1-A5：底层异常细节仅落日志，不回显（防泄露 SQL / 表结构 / 绝对路径）
                cms_log('error', 'CMS 后台操作失败: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
                return $this->error(WisecmsException::userMessage($e));
            }
        }
        
        $this->assign('model', $model);
        return $this->fetch('admin/model/edit');
    }
    
    /**
     * 删除模型
     */
    public function delete($id)
    {
        if (!$this->request->isPost()) {
            return $this->error('请求方式错误');
        }
        
        try {
            $this->modelService->deleteModel($id);
            return $this->success('模型删除成功');
        } catch (\Exception $e) {
            // P1-A5：底层异常细节仅落日志，不回显（防泄露 SQL / 表结构 / 绝对路径）
            cms_log('error', 'CMS 后台操作失败: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
            return $this->error(WisecmsException::userMessage($e));
        }
    }
    
    /**
     * 启用/禁用模型
     */
    public function toggleStatus($id)
    {
        if (!$this->request->isPost()) {
            return $this->error('请求方式错误');
        }
        
        $model = ModelModel::find($id);
        if (!$model) {
            return $this->pageFallback('模型不存在', plugin_url('wisecms/model/index'));
        }
        
        // 传入 status 时按**目标值**设置（幂等）：列表的 toggle formatter 发的就是目标值
        // （`data-params="id=..&status=1"`），只做取反的话重复/并发点击会把状态翻回去，
        // 表现为"开关随机失灵"。与 Category::toggleStatus 同一处理，见任务清单 §1.1⑬。
        $target = $this->request->post('status', null);
        $status = ($target === null || $target === '')
            ? ($model->status == 1 ? 0 : 1)
            : ((int) $target === 1 ? 1 : 0);
        $model->status = $status;
        $model->save();
        
        return $this->success('状态更新成功');
    }
    
    /**
     * 导出模型
     */
    public function export($id)
    {
        $model = ModelModel::with('fields')->find($id);
        if (!$model) {
            return $this->pageFallback('模型不存在', plugin_url('wisecms/model/index'));
        }
        
        $data = [
            'model' => $model->toArray(),
            'fields' => $model->fields->toArray()
        ];
        
        $filename = 'model_' . $model->name . '_' . date('YmdHis') . '.json';
        
        return download(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), $filename);
    }
    
    /**
     * 导入模型
     */
    public function import()
    {
        if (!$this->request->isPost()) {
            return $this->error('请求方式错误');
        }
        
        $file = $this->request->file('file');
        if (!$file) {
            return $this->error('请选择文件');
        }
        
        try {
            $content = file_get_contents($file->getRealPath());
            $data = json_decode($content, true);
            
            if (!$data || !isset($data['model'])) {
                return $this->error('文件格式错误');
            }
            
            $result = $this->modelService->importModel($data);
            return $this->success('模型导入成功', $result);
        } catch (\Exception $e) {
            // P1-A5：底层异常细节仅落日志，不回显（防泄露 SQL / 表结构 / 绝对路径）
            cms_log('error', 'CMS 后台操作失败: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
            return $this->error(WisecmsException::userMessage($e));
        }
    }
}