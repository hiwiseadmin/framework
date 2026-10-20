<?php
namespace plugin\wisecms\controller;

use plugin\wisecms\exception\WisecmsException;
use plugin\BaseController;
use plugin\wisecms\model\ModelField as FieldModel;
use plugin\wisecms\model\Model as ModelModel;
use plugin\wisecms\validate\ModelField as FieldValidate;
use plugin\wisecms\service\model\ModelFieldService;
use plugin\wisecms\service\model\FieldFactory;

class ModelField extends BaseController
{
    use PageFallbackTrait;

    protected $fieldService;

    protected function initPlugin(): void
    {
        $this->fieldService = new ModelFieldService();
    }
    
    /**
     * 字段列表
     */
    public function index()
    {
        $modelId = $this->request->param('model_id', 0);
        
        $query = FieldModel::where('model_id', $modelId);
        
        $list = $query->order('sort_order', 'asc')->select();
        
        if ($this->request->isAjax()) {
            return $this->success('', [
                'code' => 0,
                'data' => $list,
                'msg' => ''
            ]);
        }
        
        $model = ModelModel::find($modelId);
        
        $this->assign([
            'list' => $list,
            'model' => $model
        ]);
        return $this->fetch('admin/field/index');
    }
    
    /**
     * 添加字段
     */
    public function create()
    {
        if ($this->request->isPost()) {
            $data = $this->request->post();
            
            // 验证数据
            $validate = new FieldValidate();
            if (!$validate->scene('create')->check($data)) {
                return $this->error($validate->getError());
            }
            
            try {
                $this->fieldService->addField($data['model_id'], $data);
                return $this->success('字段添加成功');
            } catch (\Exception $e) {
                // P1-A5：底层异常细节仅落日志，不回显（防泄露 SQL / 表结构 / 绝对路径）
                cms_log('error', 'CMS 后台操作失败: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
                return $this->error(WisecmsException::userMessage($e));
            }
        }
        
        $modelId = $this->request->param('model_id', 0);
        $model = ModelModel::find($modelId);
        
        if (!$model) {
            // UIUX-V-03：页面请求 302 回模型列表（原来是裸 JSON）；AJAX 仍是 JSON
            return $this->pageFallback('模型不存在', plugin_url('wisecms/model/index'));
        }
        
        // 获取字段类型列表
        $fieldTypes = FieldFactory::getFieldTypeList();
        
        $this->assign([
            'model' => $model,
            'fieldTypes' => $fieldTypes
        ]);
        return $this->fetch('admin/field/create');
    }
    
    /**
     * 编辑字段
     */
    // ⚠️ `$id` 必须**可选**：声明成必填时，无 `?id` 的页面请求会抛
    //    `ArgumentCountError` → 500（UIUX-V-03 实测），而不是走到下面的存在性校验
    public function edit($id = 0)
    {
        $field = FieldModel::find($id);
        if (!$field) {
            return $this->pageFallback('字段不存在', plugin_url('wisecms/model/index'));
        }
        
        if ($this->request->isPost()) {
            $data = $this->request->post();
            
            // 验证数据
            $validate = new FieldValidate();
            if (!$validate->scene('edit')->check($data)) {
                return $this->error($validate->getError());
            }
            
            try {
                $this->fieldService->updateField($id, $data);
                return $this->success('字段更新成功');
            } catch (\Exception $e) {
                // P1-A5：底层异常细节仅落日志，不回显（防泄露 SQL / 表结构 / 绝对路径）
                cms_log('error', 'CMS 后台操作失败: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
                return $this->error(WisecmsException::userMessage($e));
            }
        }
        
        $model = ModelModel::find($field->model_id);
        
        // 获取字段类型配置
        $fieldType = $field->form_type;
        // ⚠️ UIUX-P0-31 附带修复（原为致命错误）：`ModelField` 声明了
        // `protected $json = ['setting']`，think-orm 在**查询层**就把该列解成数组
        // （`ResultOperation::jsonResult()`），所以这里的值是 array；若再丢给 json_decode
        // （它只接受 string）会在 PHP 8 抛 TypeError → **整个字段编辑页 500**（实测确认）。
        // 统一走形状归一化，兼容 array / stdClass / 原始 JSON 字符串三种历史形态。
        $setting = self::normalizeSetting($field->setting);
        $editForm = FieldFactory::getEditFormHtml($fieldType, $setting);
        
        $this->assign([
            'field' => $field,
            'model' => $model,
            'editForm' => $editForm,
            'fieldType' => $fieldType
        ]);
        return $this->fetch('admin/field/edit');
    }
    
    /**
     * 删除字段
     */
    public function delete($id)
    {
        if (!$this->request->isPost()) {
            return $this->error('请求方式错误');
        }
        
        try {
            $this->fieldService->deleteField($id);
            return $this->success('字段删除成功');
        } catch (\Exception $e) {
            // P1-A5：底层异常细节仅落日志，不回显（防泄露 SQL / 表结构 / 绝对路径）
            cms_log('error', 'CMS 后台操作失败: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
            return $this->error(WisecmsException::userMessage($e));
        }
    }
    
    /**
     * 获取字段添加表单
     */
    public function getAddForm()
    {
        $fieldType = $this->request->param('field_type', '');
        
        if (empty($fieldType)) {
            return '';
        }
        
        $html = FieldFactory::getAddFormHtml($fieldType);
        
        return $html;
    }
    
    /**
     * 字段排序
     */
    public function sort()
    {
        if (!$this->request->isPost()) {
            return $this->error('请求方式错误');
        }
        
        $sortData = $this->request->post('sort', []);
        
        try {
            foreach ($sortData as $fieldId => $sortOrder) {
                FieldModel::where('id', $fieldId)->update(['sort_order' => $sortOrder]);
            }
            
            // 更新缓存
            if (!empty($sortData)) {
                $field = FieldModel::find(key($sortData));
                if ($field) {
                    $this->fieldService->updateFieldCache($field->model_id);
                }
            }
            
            return $this->success('排序更新成功');
        } catch (\Exception $e) {
            // P1-A5：底层异常细节仅落日志，不回显（防泄露 SQL / 表结构 / 绝对路径）
            cms_log('error', 'CMS 后台操作失败: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);
            return $this->error(WisecmsException::userMessage($e));
        }
    }
    
    /**
     * 归一化 `setting` 的形状（UIUX-P0-31）
     *
     * 三种可能的形态都要吃下：
     *   · array  —— 常态。模型 `$json = ['setting']` 让查询层已 json_decode；
     *   · object —— jsonAssoc=false 的历史查询路径解出 stdClass；
     *   · string —— 手工写入 / 更早版本遗留的原始 JSON 字符串。
     *
     * @param mixed $raw
     * @return array
     */
    protected static function normalizeSetting($raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (is_object($raw)) {
            return (array) $raw;
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /**
     * 导出字段
     */
    public function export($modelId)
    {
        $fields = FieldModel::where('model_id', $modelId)->select();
        $model = ModelModel::find($modelId);
        
        $data = [
            'model' => $model ? $model->toArray() : [],
            'fields' => $fields->toArray()
        ];
        
        $filename = 'fields_' . ($model ? $model->name : 'model') . '_' . date('YmdHis') . '.json';
        
        return download(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), $filename);
    }
}