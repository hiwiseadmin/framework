<?php
namespace plugin\wisecms\controller;

use plugin\BaseController;
use plugin\wisecms\exception\ErrorCode;
use plugin\wisecms\exception\WisecmsException;
use plugin\wisecms\model\Template as TemplateModel;
use plugin\wisecms\service\content\TemplateService;
use plugin\wisecms\service\model\MassAssignmentGuard;

/**
 * 内容模板管理（UIUX-P2-03）
 *
 * 「能力→入口」缺口补齐：`TemplateService` 早已实现，却被交互方案 §1.10 / §9 决策.4
 * 点名为「死能力」（服务有、后台无触点）。真实消费方是生成引擎的**模板模式**：
 * `GenerationEngine::template()` → `TemplateService::applyTemplate()`，
 * 用 `presets` 作内容预设、`seo_defaults` 作 SEO 兜底。
 *
 * ⚠️ 四处已核实的事实：
 *   1. **`wisecms_template` 无 `site_id` 列** → 模板**全站共享**，故本页**不挂站点选择器**
 *      （与「广告管理」同一口径）；页面须显式说明，否则切站点后列表不变会被误判为 bug。
 *   2. **`schema` / `icon` 两列全插件无消费方**（grep 无读取点）→ 表单**刻意不暴露**：
 *      配了不生效即"假配置"。真正生效的是 `presets` / `seo_defaults`，
 *      `type` 真实参与 `getList($type)` 过滤，`category` 在列表可见（属数据而非配置）。
 *   3. **无软删列** → 删除为**真删除、无回收站**（确认框已写明）；
 *      `is_system=1` 的默认模板被删后可用「安装默认模板」装回。
 *   4. **`alias` 有唯一索引** → 必须服务端先查重，否则抛 DB 异常（500 + 不可读报错）。
 */
class Template extends BaseController
{
    use PageFallbackTrait;

    /**
     * 表单可写字段白名单（与视图保持一致）
     *
     * 不放 `schema` / `icon`（无消费方），不放 `is_system`（服务端托管，
     * 已在 `MassAssignmentGuard::TABLE_DENY` 登记）。
     */
    const EDITABLE_FIELDS = ['name', 'alias', 'type', 'category', 'sort', 'status', 'presets', 'seo_defaults'];

    /**
     * 模板列表（列表页 + AJAX 数据源）
     */
    public function index()
    {
        $service = new TemplateService();

        if ($this->request->isAjax()) {
            return $this->success('', $service->getPage([
                'page'    => $this->request->param('page', 1),
                'limit'   => $this->request->param('limit', 15),
                'type'    => $this->request->param('type', ''),
                'status'  => $this->request->param('status', ''),
                'keyword' => $this->request->param('keyword', ''),
            ]));
        }

        $this->assign('typeOptions', $this->typeOptions());
        $this->assign('totalCount', TemplateModel::count());

        return $this->fetch('admin/template/index');
    }

    /**
     * 新增模板（GET 视图 / POST 提交）
     */
    public function create()
    {
        if (!$this->request->isPost()) {
            $this->assign('typeOptions', $this->typeOptions());

            return $this->fetch('admin/template/create');
        }

        try {
            $service = new TemplateService();
            $data = $this->collect($this->request->post(), true);
            $service->assertAliasUnique((string) $data['alias']);
            $service->create($data);

            return $this->success('模板创建成功');
        } catch (WisecmsException $e) {
            return $this->error(WisecmsException::userMessage($e));
        } catch (\Throwable $e) {
            cms_log('error', '创建内容模板失败: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);

            return $this->error('创建失败，请稍后重试');
        }
    }

    /**
     * 编辑模板（GET 视图 / POST 提交）
     *
     * POST 同时承担**状态开关**：列表页 toggle 只提交 `{id, status}`，
     * `collect()` 按「传了哪些键就更新哪些键」处理（部分更新）。
     */
    public function edit()
    {
        $id = (int) $this->request->param('id');
        $service = new TemplateService();

        if ($this->request->isPost()) {
            try {
                $data = $this->collect($this->request->post(), false);
                if (array_key_exists('alias', $data)) {
                    $service->assertAliasUnique((string) $data['alias'], $id);
                }
                $service->update($id, $data);

                return $this->success('更新成功');
            } catch (WisecmsException $e) {
                return $this->error(WisecmsException::userMessage($e));
            } catch (\Throwable $e) {
                cms_log('error', '更新内容模板失败: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);

                return $this->error('更新失败，请稍后重试');
            }
        }

        $detail = $service->getDetail($id);
        if (!$detail) {
            return $this->pageFallback('模板不存在', plugin_url('wisecms/template/index'));
        }

        // JSON 列回显为「格式化文本」（供 textarea 编辑，保存时再由 collect() 解析回来）
        foreach (['presets', 'seo_defaults'] as $field) {
            $detail[$field . '_text'] = !empty($detail[$field])
                ? json_encode($detail[$field], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
                : '';
        }

        $this->assign('template', $detail);
        $this->assign('typeOptions', $this->typeOptions());

        return $this->fetch('admin/template/edit');
    }

    /**
     * 删除模板（真删除，无回收站）
     */
    public function delete()
    {
        try {
            (new TemplateService())->delete((int) $this->request->post('id'));

            return $this->success('模板已删除');
        } catch (WisecmsException $e) {
            return $this->error(WisecmsException::userMessage($e));
        } catch (\Throwable $e) {
            cms_log('error', '删除内容模板失败: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);

            return $this->error('删除失败，请稍后重试');
        }
    }

    /**
     * 安装默认模板（幂等：已存在的 `alias` 跳过）
     *
     * `installDefaultTemplates()` 早已实现但**从未被任何入口调用**（模板表因此恒为空），
     * 这里补上唯一入口，并如实回报「新增了几个」。
     */
    public function installDefaults()
    {
        try {
            $before = (int) TemplateModel::count();
            (new TemplateService())->installDefaultTemplates();
            $added = (int) TemplateModel::count() - $before;

            return $this->success(
                $added > 0 ? "已安装 {$added} 个默认模板" : '默认模板均已存在，无需安装',
                ['added' => $added]
            );
        } catch (\Throwable $e) {
            cms_log('error', '安装默认内容模板失败: ' . $e->getMessage(), ['file' => $e->getFile(), 'line' => $e->getLine()]);

            return $this->error('安装默认模板失败，请稍后重试');
        }
    }

    /**
     * 类型下拉数据
     *
     * 本项目模板里只用**列表形态**的 `{foreach $list as $item}`（见 category/create.html），
     * 故把服务层的 `[value => label]` 映射转成 `[{value, label}]` 再下发。
     *
     * @return array<int, array{value: string, label: string}>
     */
    protected function typeOptions(): array
    {
        $options = [];
        foreach (TemplateService::TYPE_LABELS as $value => $label) {
            $options[] = ['value' => $value, 'label' => $label];
        }

        return $options;
    }

    /**
     * 请求体 → 服务层数据（黑名单过滤 + 白名单裁剪 + 类型/状态归一 + JSON 解析）
     *
     * @param array $post     原始请求体
     * @param bool  $isCreate 新增时要求 name / alias 必填并补齐默认值
     * @throws WisecmsException 校验失败（文案可直接回显）
     */
    protected function collect(array $post, bool $isCreate): array
    {
        // ① 剔除框架/DB 托管列（id / create_time / update_time / is_system …）
        $data = MassAssignmentGuard::filter($post, 'wisecms_template');
        // ② 只保留表单字段（防止绕过前端堆其它列）
        $data = array_intersect_key($data, array_flip(self::EDITABLE_FIELDS));

        // ③ 文本列归一
        if (array_key_exists('name', $data)) {
            $data['name'] = trim((string) $data['name']);
            if ($data['name'] === '') {
                throw new WisecmsException('模板名称不能为空', ErrorCode::PARAM_INVALID);
            }
        } elseif ($isCreate) {
            throw new WisecmsException('模板名称不能为空', ErrorCode::PARAM_INVALID);
        }

        if (array_key_exists('alias', $data)) {
            $data['alias'] = trim((string) $data['alias']);
            if ($data['alias'] === '') {
                throw new WisecmsException('模板标识不能为空', ErrorCode::PARAM_INVALID);
            }
        } elseif ($isCreate) {
            throw new WisecmsException('模板标识不能为空', ErrorCode::PARAM_INVALID);
        }

        if (array_key_exists('type', $data)) {
            $data['type'] = array_key_exists((string) $data['type'], TemplateService::TYPE_LABELS)
                ? (string) $data['type']
                : 'article';
        }

        if (array_key_exists('status', $data)) {
            $data['status'] = ((int) $data['status']) === 1 ? 1 : 0;
        }

        if (array_key_exists('sort', $data)) {
            $data['sort'] = (int) $data['sort'];
        }

        // ④ JSON 列：空串 → 空对象；非法 JSON / 非对象 → 可读报错
        foreach (['presets' => '预设内容', 'seo_defaults' => 'SEO 默认值'] as $field => $label) {
            if (array_key_exists($field, $data)) {
                $data[$field] = $this->decodeJson((string) $data[$field], $label);
            }
        }

        if ($isCreate) {
            $data['type']   = $data['type'] ?? 'article';
            $data['status'] = $data['status'] ?? 1;
        }

        return $data;
    }

    /**
     * JSON 文本 → 关联数组
     *
     * @throws WisecmsException 非法 JSON 或不是对象/数组
     */
    protected function decodeJson(string $raw, string $label): array
    {
        $raw = trim($raw);
        if ($raw === '' || $raw === '{}' || $raw === '[]') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new WisecmsException('「' . $label . '」不是合法 JSON：' . json_last_error_msg(), ErrorCode::PARAM_INVALID);
        }
        if (!is_array($decoded)) {
            throw new WisecmsException('「' . $label . '」必须是 JSON 对象（形如 {"键":"值"}）', ErrorCode::PARAM_INVALID);
        }

        return $decoded;
    }
}
