<?php
namespace plugin\wisecms\service\content;

use plugin\wisecms\exception\ErrorCode;
use plugin\wisecms\exception\WisecmsException;
use plugin\wisecms\model\Template;

class TemplateService
{
    /**
     * 模板类型词表（取值见建表迁移注释：article/page/product/custom）
     *
     * `type` 是**真实消费项**：`getList($type)` 按类型过滤；后台管理页与消费方共用本表，
     * 避免两处各写一份中文名。
     */
    public const TYPE_LABELS = [
        'article' => '文章',
        'page'    => '页面',
        'product' => '产品',
        'custom'  => '自定义',
    ];
    /**
     * 获取可用模板列表
     */
    public function getList(string $type = ''): array
    {
        $query = Template::where('status', 1)->order('sort', 'asc');
        if ($type) $query->where('type', $type);
        return $query->select()->toArray();
    }

    /**
     * 获取模板详情
     */
    public function getDetail(int $id): ?array
    {
        $tmpl = Template::find($id);
        if (!$tmpl) return null;
        $data = $tmpl->toArray();
        $data['schema'] = json_decode($data['schema'] ?? '{}', true);
        $data['presets'] = json_decode($data['presets'] ?? '{}', true);
        $data['seo_defaults'] = json_decode($data['seo_defaults'] ?? '{}', true);
        return $data;
    }

    /**
     * 应用模板生成内容数据
     */
    public function applyTemplate(int $templateId, array $overrides = []): array
    {
        $tmpl = $this->getDetail($templateId);
        if (!$tmpl) throw new WisecmsException('模板不存在', ErrorCode::NOT_FOUND);

        $data = $tmpl['presets'] ?? [];
        $data = array_merge($data, $overrides);

        // 合并SEO默认值
        foreach ($tmpl['seo_defaults'] as $key => $val) {
            if (empty($data[$key])) $data[$key] = $val;
        }

        return $data;
    }

    /**
     * 创建模板
     */
    public function create(array $data): Template
    {
        // create_time / update_time 由模型 autoWriteTimestamp 维护
        return Template::create($this->encodeJsonFields($data));
    }

    /**
     * 更新模板（UIUX-P2-03：管理页需要，原服务层缺此方法）
     *
     * @throws WisecmsException 模板不存在
     */
    public function update(int $id, array $data): Template
    {
        $tmpl = Template::find($id);
        if (!$tmpl) {
            throw new WisecmsException('模板不存在', ErrorCode::NOT_FOUND);
        }

        // 只保存传入的键（状态开关只传 {status} 时即部分更新）
        $tmpl->save($this->encodeJsonFields($data));

        return $tmpl;
    }

    /**
     * 删除模板（UIUX-P2-03）
     *
     * ⚠️ `wisecms_template` **无软删列** → 真删除、无回收站。
     * `is_system=1` 的默认模板被删后可由 {@see installDefaultTemplates()} 重新装回。
     *
     * @throws WisecmsException 模板不存在
     */
    public function delete(int $id): void
    {
        $tmpl = Template::find($id);
        if (!$tmpl) {
            throw new WisecmsException('模板不存在', ErrorCode::NOT_FOUND);
        }

        $tmpl->delete();
    }

    /**
     * 后台管理列表（UIUX-P2-03）
     *
     * 与 {@see getList()} 的区别：`getList()` 面向消费方（只出 `status=1`、无分页），
     * 本方法面向管理页 —— 含**全部状态**、支持类型/关键词筛选与分页。
     *
     * @param array $params page / limit / type / status / keyword
     * @return array 标准 paginate 契约（total / per_page / current_page / last_page / data）
     */
    public function getPage(array $params = []): array
    {
        $page  = max(1, (int) ($params['page'] ?? 1));
        // limit 上限收敛（P1-19b 口径）
        $limit = min(100, max(1, (int) ($params['limit'] ?? 15)));

        $query = Template::order('sort', 'asc')->order('id', 'asc');

        $type = trim((string) ($params['type'] ?? ''));
        if ($type !== '') {
            $query->where('type', $type);
        }

        // status 允许 0：不能用 empty() 判断（'0' 会被判空）
        if (isset($params['status']) && $params['status'] !== '' && $params['status'] !== null) {
            $query->where('status', (int) $params['status']);
        }

        $keyword = trim((string) ($params['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($q) use ($keyword) {
                $q->whereLike('name', '%' . $keyword . '%')
                    ->whereOr('alias', 'like', '%' . $keyword . '%');
            });
        }

        return $query->paginate(['page' => $page, 'list_rows' => $limit])->toArray();
    }

    /**
     * 模板标识唯一性校验（UIUX-P2-03）
     *
     * `wisecms_template.alias` 有唯一索引；不校验会直接抛 DB 异常（500 + 不可读报错）。
     *
     * @throws WisecmsException 标识已被占用
     */
    public function assertAliasUnique(string $alias, int $excludeId = 0): void
    {
        if ($alias === '') {
            throw new WisecmsException('模板标识不能为空', ErrorCode::PARAM_INVALID);
        }

        $query = Template::where('alias', $alias);
        if ($excludeId > 0) {
            $query->where('id', '<>', $excludeId);
        }

        if ($query->find()) {
            throw new WisecmsException('模板标识「' . $alias . '」已被占用', ErrorCode::DUPLICATE);
        }
    }

    /**
     * JSON 字段序列化：数组 → JSON 串（已是字符串的原样保留）
     *
     * `schema` / `presets` / `seo_defaults` 三列以 JSON 文本落库，
     * 而模型未声明 `$json` 类型，故统一在服务层转换。
     */
    protected function encodeJsonFields(array $data): array
    {
        foreach (['schema', 'presets', 'seo_defaults'] as $field) {
            if (isset($data[$field]) && is_array($data[$field])) {
                $data[$field] = json_encode($data[$field], JSON_UNESCAPED_UNICODE);
            }
        }

        return $data;
    }

    /**
     * 安装默认模板
     */
    public function installDefaultTemplates(): void
    {
        $defaults = [
            ['name' => '新闻稿', 'alias' => 'news', 'type' => 'article', 'category' => '文章', 'is_system' => 1, 'sort' => 1,
             'seo_defaults' => json_encode(['focus_keyword' => '', 'meta_description_length' => 150])],
            ['name' => '教程', 'alias' => 'tutorial', 'type' => 'article', 'category' => '文章', 'is_system' => 1, 'sort' => 2],
            ['name' => 'FAQ', 'alias' => 'faq', 'type' => 'page', 'category' => '页面', 'is_system' => 1, 'sort' => 3],
            ['name' => '关于我们', 'alias' => 'about', 'type' => 'page', 'category' => '页面', 'is_system' => 1, 'sort' => 4],
        ];
        foreach ($defaults as $d) {
            $exists = Template::where('alias', $d['alias'])->find();
            if (!$exists) {
                Template::create($d);
            }
        }
    }
}
