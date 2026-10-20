<?php
namespace plugin\wisecms\service\content;
use plugin\wisecms\exception\ErrorCode;
use plugin\wisecms\exception\WisecmsException;
use plugin\wisecms\service\integration\WebhookService;
use plugin\wisecms\service\model\FieldRegistry;
use plugin\wisecms\service\site\CacheService;
use plugin\wisecms\service\site\SiteService;
use plugin\wisecms\service\url\UrlService;

use plugin\wisecms\model\Content;
use plugin\wisecms\model\ContentVersion;
use plugin\wisecms\model\Tag;
use plugin\wisecms\model\TagRelation;
use plugin\wisecms\pipeline\ContentPipeline;
use think\facade\Db;

class ContentService
{
    /**
     * 内容状态常量
     */
    const STATUS_DRAFT     = 0;
    const STATUS_PENDING   = 1;
    const STATUS_PUBLISHED = 2;
    const STATUS_ARCHIVED  = 3;

    /**
     * 「内容审核模式」配置取值（manifest.json / config/app.php 的 content_check）
     */
    const REVIEW_NONE     = 0; // 无需审核
    const REVIEW_BEFORE   = 1; // 先审后发
    const REVIEW_AFTER    = 2; // 先发后审

    /**
     * 按全局审核模式推导「新建内容」的初始状态（P0-5）
     *
     * 所有自动创建入口（后台表单、AI 生成、采集入库）都必须经过本方法，
     * 否则 `auto_publish` 之类的客户端参数可绕过「先审后发」。
     *
     * 语义：
     *   - $intendPublish = false            → 草稿
     *   - $intendPublish = true & 无需审核  → 已发布
     *   - $intendPublish = true & 需审核    → 待审核（先审后发 / 先发后审在创建期一致）
     *
     * @param bool $intendPublish 提交者是否表达了「直接发布」的意图
     */
    public static function derivePublishStatus(bool $intendPublish): int
    {
        if (!$intendPublish) {
            return self::STATUS_DRAFT;
        }

        $mode = (int) config('wisecms.content_check', self::REVIEW_BEFORE);

        return $mode === self::REVIEW_NONE ? self::STATUS_PUBLISHED : self::STATUS_PENDING;
    }

    /**
     * 净化写入数据中的正文主字段（P0-4）
     *
     * 仅在传入 `content` 时生效（局部更新不会误清空正文）。
     * 非字符串（如 null）原样保留，交由模型层处理。
     */
    /**
     * 时间字段入参归一化
     *
     * `publish_time` 语义 = 「发布时间（计划或实际）」，NULL = 未设置：
     * 后台 `datetime-local` 控件提交 `2026-10-05T12:00`（含 T，MySQL 不接受），需转换。
     *
     * create_time / update_time / delete_time 属框架托管字段，一律不接受入参
     * （已由 MassAssignmentGuard 剔除，且模型 autoWriteTimestamp 自动维护）。
     */
    protected static function normalizeTimeFields(array $data): array
    {
        if (array_key_exists('publish_time', $data)) {
            $data['publish_time'] = cms_datetime($data['publish_time']);
        }

        return $data;
    }

    /**
     * slug 归一化：空串 → NULL（P1-C）
     *
     * `(catid, slug)` 上有唯一索引，而 MySQL 把多个空串视为**重复值** ——
     * 同一栏目下两篇「未填 slug」的内容会撞唯一键（1062）；代码里的查重又只在
     * `!empty($slug)` 时生效，等于绕不过去。把空串写为 NULL 后，唯一索引自动放行
     * （NULL 不参与唯一性判定），既保留「同栏目 slug 不重复」的约束，也不影响空值语义。
     */
    protected static function normalizeSlug(array $data): array
    {
        if (array_key_exists('slug', $data) && $data['slug'] !== null) {
            $slug = trim((string) $data['slug']);
            $data['slug'] = $slug === '' ? null : $slug;
        }

        return $data;
    }

    /**
     * 字数 / 阅读时长派生字段（create 与 update 共用，避免两处口径漂移）
     */
    protected static function applyContentMetrics(array $data): array
    {
        if (!empty($data['content'])) {
            $data['word_count'] = mb_strlen(strip_tags((string) $data['content']));
            $data['read_time']  = (int) ceil($data['word_count'] / 500);
        }

        return $data;
    }

    /**
     * 正文净化（+ 可选的远程图片本地化）
     *
     * UIUX-P1-06：编辑器（wiseeditor）贴入的外链图片可在本步骤落库为本地附件 —— 由插件配置
     * `save_remote_image` 控制（**默认关闭**：本地化是带网络 I/O 的重操作，不能默认打开）。
     * 实现复用 `AttachmentService::localizeRemoteImages()`（含 SSRF 防护、失败保持原图）。
     */
    protected static function sanitizeContentField(array $data): array
    {
        if (array_key_exists('content', $data) && is_string($data['content'])) {
            $html = $data['content'];

            if ((int) config('wisecms.save_remote_image', 0) === 1
                && $html !== ''
                && str_contains($html, '<img')) {
                $html = (new AttachmentService())->localizeRemoteImages($html);
            }

            $data['content'] = HtmlSanitizer::clean($html);
        }

        return $data;
    }

    /**
     * 获取内容列表
     */
    public function getList(array $params = []): array
    {
        $query = Content::with(['category']);
        $query->where('delete_time', null);

        // 栏目筛选
        if (!empty($params['catid'])) {
            $query->where('catid', $params['catid']);
        }
        // 模型筛选
        if (!empty($params['model_id'])) {
            $query->where('model_id', $params['model_id']);
        }
        // 站点筛选（多站点隔离）
        if (!empty($params['site_id'])) {
            $query->where('site_id', (int) $params['site_id']);
        }
        // 状态筛选
        if (isset($params['status'])) {
            $query->where('status', $params['status']);
        }
        // 关键词搜索
        if (!empty($params['keyword'])) {
            $escapedKeyword = addcslashes($params['keyword'], '%_\\');
            $query->where('title|keywords', 'like', "%{$escapedKeyword}%");
        }
        // 排序（白名单校验，防止注入任意列名）
        $order = (string) ($params['order'] ?? 'id desc');
        $allowedOrders = ['id', 'create_time', 'update_time', 'publish_time', 'view_count', 'listorder'];
        if (preg_match('/^(\w+)\s*(asc|desc)?$/i', $order, $m)) {
            if (in_array(strtolower($m[1]), $allowedOrders)) {
                $query->order(strtolower($m[1]), strtolower($m[2] ?? 'desc'));
            }
        }

        // P1-B6：分页参数收敛（原实现 limit 无上限，超大值会退化为慢查询/资源耗尽）
        $page  = max(1, (int) ($params['page'] ?? 1));
        $limit = min(100, max(1, (int) ($params['limit'] ?? 15)));

        return $query->paginate([
            'page' => $page,
            'list_rows' => $limit,
        ])->toArray();
    }

    /**
     * 获取内容详情
     */
    public function getDetail(int $id): ?Content
    {
        // 详情页/编辑页仅需栏目与标签；versions（版本快照）仅在后台版本历史场景使用，避免无谓加载
        return Content::with(['category', 'tags'])->find($id);
    }

    /**
     * 获取内容详情并做输出处理（前台展示用）
     *
     * 返回数组：主表字段 + 自定义字段（副表合并）+ 输出处理（字段 outputFilter + 公共字段）。
     */
    public function getDetailOutput(int $id): ?array
    {
        $content = Content::with(['category', 'tags'])->find($id);
        if (!$content) {
            return null;
        }

        $data = $content->toArray();

        // 显式补充关联（避免依赖 toArray 是否包含关联的差异）
        if ($content->category) {
            $data['category'] = $content->category->toArray();
        }
        if ($content->tags) {
            $data['tags'] = $content->tags->toArray();
        }

        // 合并自定义字段（副表）
        $modelId = (int) ($data['model_id'] ?? 0);
        if ($modelId > 0) {
            $data = array_merge($data, $this->getCustomData($id, $modelId));
        }

        // 输出处理（字段 outputFilter + 公共字段）
        return (new OutputService())->processContentOutput($data);
    }

    /**
     * 获取内容的自定义字段数据（副表）
     *
     * 自定义字段存储于 per-model 副表 wisecms_content_{name}_data（id 与主表同 id）。
     */
    public function getCustomData(int $contentId, int $modelId): array
    {
        if ($modelId <= 0) {
            return [];
        }
        $model = \plugin\wisecms\model\Model::find($modelId);
        if (!$model || empty($model->table_name)) {
            return [];
        }
        $row = Db::name($model->table_name . '_data')->where('id', $contentId)->find();
        return $row ?: [];
    }

    /**
     * 批量获取内容的自定义字段数据（副表，按 id IN 一次查询，避免 N+1）
     *
     * @return array<int, array> 以内容 id 为键的自定义字段数组
     */
    public function getCustomDataBatch(array $contentIds, int $modelId): array
    {
        $result = [];
        $contentIds = array_values(array_unique(array_map('intval', $contentIds)));
        if (empty($contentIds) || $modelId <= 0) {
            return $result;
        }
        $model = \plugin\wisecms\model\Model::find($modelId);
        if (!$model || empty($model->table_name)) {
            return $result;
        }
        $rows = Db::name($model->table_name . '_data')->whereIn('id', $contentIds)->select()->toArray();
        foreach ($rows as $row) {
            $result[(int) $row['id']] = $row;
        }
        return $result;
    }

    /**
     * 保存内容的自定义字段数据到副表（upsert）
     */
    public function saveCustomData(int $contentId, int $modelId, array $customData): void
    {
        if ($modelId <= 0 || empty($customData)) {
            return;
        }
        $model = \plugin\wisecms\model\Model::find($modelId);
        if (!$model || empty($model->table_name)) {
            return;
        }

        // P2-2：只落「本模型已注册的自定义字段」，未知键一律丢弃。
        //
        // 为什么必须在这里收口：副表的列 = 注册字段的列（由
        // ModelService::syncModelSchema 保证），而本方法是自定义字段落库的
        // **唯一入口**（createWithFields / updateWithFields / autosave 都经过它）。
        // 若把未注册的键交给 ThinkPHP，会抛 "fields not exists: [xxx]"；
        // 由于调用方多数处在事务内，一个多余键就会让整条内容写入回滚。
        // 采集（SpiderService）/ AI 生成（GenerationEngine）/ 第三方接入
        // 都不一定了解目标模型的字段集合，因此过滤必须由服务端兜底，
        // 而不能只依赖上游 InputService 已做了白名单。
        $customData = $this->filterRegisteredFields($modelId, $customData);
        if ($customData === []) {
            return;
        }

        $table = $model->table_name . '_data';
        $exists = Db::name($table)->where('id', $contentId)->find();

        try {
            if ($exists) {
                Db::name($table)->where('id', $contentId)->update($customData);
            } else {
                $customData['id'] = $contentId;
                Db::name($table)->insert($customData);
            }
        } catch (\Throwable $e) {
            $fieldName = $this->extractDuplicateKeyField($e);
            if ($fieldName !== null) {
                throw new WisecmsException($fieldName !== '' ? "字段「{$fieldName}」的值已存在，请勿重复提交" : '字段值已存在，请勿重复提交', ErrorCode::DUPLICATE);
            }
            throw $e;
        }
    }

    /**
     * 过滤出自定义字段表中真实存在的键（P2-2）
     *
     * 白名单来源为 FieldRegistry（字段体系唯一 schema 入口，与
     * Form/Input/Output/Update 四阶段同源），只保留 is_system=0 且 status=1 的字段。
     * 同时显式排除主键 `id` —— 它由本方法在插入分支注入，不接受外部覆盖。
     *
     * @param int   $modelId    模型 ID
     * @param array $customData 待落库的键值对（可能含未知键）
     * @return array 仅含已注册字段的子集
     */
    protected function filterRegisteredFields(int $modelId, array $customData): array
    {
        $allowed = [];
        foreach ((new FieldRegistry())->getCustomFields($modelId) as $field) {
            $name = (string) ($field['field'] ?? '');
            if ($name !== '') {
                $allowed[$name] = true;
            }
        }

        $filtered = [];
        foreach ($customData as $key => $value) {
            $key = (string) $key;
            if ($key !== 'id' && isset($allowed[$key])) {
                $filtered[$key] = $value;
            }
        }

        return $filtered;
    }

    /**
     * 识别唯一索引冲突（MySQL 1062 / SQLSTATE 23000），并返回冲突字段名；非冲突返回 null
     */
    protected function extractDuplicateKeyField(\Throwable $e): ?string
    {
        $prev = $e;
        while ($prev !== null) {
            $msg = method_exists($prev, 'getMessage') ? $prev->getMessage() : '';
            $isDuplicate = stripos($msg, 'Duplicate entry') !== false
                || stripos($msg, '1062') !== false
                || ($prev instanceof \PDOException && (int) ($prev->errorInfo[1] ?? 0) === 1062);

            if ($isDuplicate) {
                if (preg_match("/for key '([^']+)'/i", $msg, $m)) {
                    return preg_replace('/^uniq_/i', '', $m[1]);
                }
                return '';
            }
            $prev = $prev->getPrevious();
        }
        return null;
    }

    /**
     * 创建内容（仅主表）
     *
     * @internal 版本快照在 createWithFields() 的事务末尾采集；
     *           直接调用本方法不会产生版本记录。
     */
    public function create(array $data): Content
    {
        // create_time / update_time / delete_time 交由模型 autoWriteTimestamp 自动维护；
        // 此处只做入参归一化（`publish_time` 来自 datetime-local，含 T 需转换）。
        $data = self::normalizeTimeFields($data);
        $data = self::normalizeSlug($data);

        // P0-4：正文 HTML 服务端净化（唯一入口，覆盖后台表单 / AI 生成 / 采集入库）
        $data = self::sanitizeContentField($data);

        // P1-C：字数 / 阅读时长（旧实现只在 update() 计算 → 新建内容恒为 0）
        $data = self::applyContentMetrics($data);

        // 多站点：未指定 site_id 时回退到当前站点
        if (empty($data['site_id'])) {
            $data['site_id'] = (new SiteService())->resolveCurrentSiteId();
        }

        // slug 唯一性检查（P1-C：必须含回收站内容 —— `(catid, slug)` 是数据库唯一键，
        // 软删行同样占用该别名；旧实现只查未删行，会在写入时撞 1062 抛 500）
        if (!empty($data['slug']) && !empty($data['catid'])) {
            $exists = Content::withTrashed()
                ->where('catid', $data['catid'])
                ->where('slug', $data['slug'])
                ->find();
            if ($exists) {
                throw new WisecmsException('URL别名已存在（回收站内容同样占用），请更换', ErrorCode::DUPLICATE);
            }
        }
        
        // Pipeline 处理
        $data = $this->runPipeline($data, 'create');

        $content = Content::create($data);
        $this->clearListCache($data['catid'] ?? 0);

        // 同步 URL 别名索引（伪静态开启时写入当前/历史 URL，供解析与 301 使用）
        (new UrlService())->syncContent($content);

        return $content;
    }

    /**
     * 更新内容（仅主表）
     *
     * @internal 版本快照在 updateWithFields() 的事务末尾采集；
     *           直接调用本方法不会产生版本记录。
     */
    public function update(int $id, array $data): Content
    {
        $content = Content::find($id);
        if (!$content) {
            throw new WisecmsException('内容不存在', ErrorCode::NOT_FOUND);
        }

        // slug 唯一性检查（排除自身；P1-C：含回收站内容，理由同 create）
        if (!empty($data['slug']) && !empty($data['catid'])) {
            $exists = Content::withTrashed()
                ->where('catid', $data['catid'])
                ->where('slug', $data['slug'])
                ->where('id', '<>', $id)
                ->find();
            if ($exists) {
                throw new WisecmsException('URL别名已存在（回收站内容同样占用），请更换', ErrorCode::DUPLICATE);
            }
        }

        // update_time 交由模型 autoWriteTimestamp 维护；此处归一化 publish_time / slug
        $data = self::normalizeTimeFields($data);
        $data = self::normalizeSlug($data);

        // P0-4：正文 HTML 服务端净化（在字数统计之前，保证统计反映净化后的内容）
        $data = self::sanitizeContentField($data);
        $data = self::applyContentMetrics($data);

        // P1-B1：编辑路径同样收口到状态机 —— 后端仅靠控制器的状态改写保护不够，
        // 任何直接调用本方法的写入方（API / 未来新增入口）都不得绕过「先审后发」
        if (array_key_exists('status', $data) && (int) $data['status'] !== (int) $content->status) {
            $this->assertStatusTransition((int) $content->status, (int) $data['status']);
        }

        $content->save($data);
        $this->clearDetailCache($id);
        $this->clearListCache($content->catid);

        // 同步 URL 别名索引：slug/栏目变更时旧 URL 转为历史（301 到新 URL）
        (new UrlService())->syncContent($content);

        return $content;
    }

    /**
     * 创建内容并保存自定义字段（事务，保证主/副表/标签/版本快照原子）
     *
     * 内容写入的唯一入口（含采集/生成等自动创建场景）。
     */
    public function createWithFields(array $data, array $customData = [], array $tags = [], string $versionRemark = ContentVersionService::REMARK_DEFAULT): Content
    {
        Db::startTrans();
        try {
            $content = $this->create($data);
            $modelId = (int) ($data['model_id'] ?? 0);
            if (!empty($customData) && $modelId > 0) {
                $this->saveCustomData($content->id, $modelId, $customData);
            }
            if (!empty($tags)) {
                // 标签归属 = 内容所属站点（P2 标签站点隔离）
                $this->saveTags($content->id, $tags, $modelId, (int) $content->site_id);
            }

            // 版本快照：主表 + 副表 + 标签写入完成后采集，保证快照自包含
            $this->versionService()->capture($content, $versionRemark);

            Db::commit();
            return $content;
        } catch (\Exception $e) {
            Db::rollback();
            throw $e;
        }
    }

    /**
     * 更新内容并保存自定义字段（事务，保证主/副表/标签/版本快照原子）
     */
    public function updateWithFields(
        int $id,
        array $data,
        array $customData = [],
        array $tags = [],
        string $versionRemark = ContentVersionService::REMARK_DEFAULT
    ): Content {
        Db::startTrans();
        try {
            $content = $this->update($id, $data);
            $modelId = (int) ($content->model_id ?? $data['model_id'] ?? 0);
            if (!empty($customData) && $modelId > 0) {
                $this->saveCustomData($content->id, $modelId, $customData);
            }
            if (!empty($tags)) {
                // 标签归属 = 内容所属站点（P2 标签站点隔离；站点不可随编辑变更）
                $this->saveTags($content->id, $tags, $modelId, (int) $content->site_id);
            }

            // 版本快照 + 保留策略（与内容写入同事务，失败一并回滚）
            $versions = $this->versionService();
            $versions->capture($content, $versionRemark);
            $versions->prune((int) $content->id);

            Db::commit();
            return $content;
        } catch (\Exception $e) {
            Db::rollback();
            throw $e;
        }
    }

    /**
     * 删除内容（软删除到回收站）
     */
    public function delete(int $id): bool
    {
        $content = Content::find($id);
        if (!$content) {
            throw new WisecmsException('内容不存在', ErrorCode::NOT_FOUND);
        }

        // P1-C 级联约定：软删只把内容移出可见范围，**关联数据全部保留**（供恢复），
        // 仅 URL 别名需移除（回收站内容不可访问）；标签计数需重算 ——
        // 回收站内容不再计入标签云（标签云与标签页可见性保持一致）。
        $tagIds = array_map('intval', TagRelation::where('content_id', $id)->column('tag_id'));

        $content->delete();
        $this->clearDetailCache($id);
        $this->clearListCache((int) $content->catid);

        (new UrlService())->removeAlias(\plugin\wisecms\model\UrlAlias::TYPE_CONTENT, $id);
        $this->recountTags($tagIds);

        return true;
    }

    /**
     * 彻底删除（含全部关联数据，P1-C）
     *
     * 插件全表零外键，级联清理统一收口到 {@see ContentRelationService::purge()}：
     * 标签关联 / 评论（+点赞）/ 元数据 / 质量评分 / SEO 审计 / 推送日志 / 排期 /
     * 版本 / 自定义字段副表。旧实现只删标签关联与版本，其余表长期残留孤儿行。
     */
    public function forceDelete(int $id): bool
    {
        $content = Content::onlyTrashed()->find($id);
        if (!$content) {
            throw new WisecmsException('内容不存在', ErrorCode::NOT_FOUND);
        }

        Db::startTrans();
        try {
            // 关联清理必须在主表行被物理删除之前（副表表名依赖 model_id）
            $tagIds = (new ContentRelationService())->purge([$id]);
            $content->force()->delete();
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }

        $this->clearDetailCache($id);
        $this->clearListCache((int) $content->catid);
        $this->recountTags($tagIds);

        (new UrlService())->removeAlias(\plugin\wisecms\model\UrlAlias::TYPE_CONTENT, $id);

        return true;
    }

    /**
     * 恢复回收站内容
     */
    public function restore(int $id): Content
    {
        $content = Content::onlyTrashed()->find($id);
        if (!$content) {
            throw new WisecmsException('内容不存在', ErrorCode::NOT_FOUND);
        }
        $content->restore();

        // 恢复后重建别名索引
        (new UrlService())->syncContent($content);

        // 重新纳入标签云（软删时已将其排除）
        $this->recountTags(
            array_map('intval', TagRelation::where('content_id', $id)->column('tag_id'))
        );
        $this->clearListCache((int) $content->catid);

        return $content;
    }

    /**
     * 状态流转校验（**状态机唯一规则点**，P1-B1）
     *
     * 所有会改变 `content.status` 的入口（后台 toggleStatus、编辑表单、工作流流转、
     * 定时发布、回收站恢复）都必须经过本方法，保证规则只有一处实现。
     *
     * 规则：`content_check = 先审后发(1)` 时，**只有「待审核」或「已发布」的内容**
     * 才能置为已发布 —— 否则任意低权账号 post 一个 `status=2` 即可绕过审核直接上线。
     *
     * @param int  $from        当前状态
     * @param int  $to          目标状态
     * @param bool $viaWorkflow 是否由 wise-workflow 引擎授权流转而来。
     *                          工作流本身已校验流转合法性（如 draft→publish 是定义内的合法路径），
     *                          故此时跳过「须先审核」校验，避免与流程定义冲突。
     *
     * @throws WisecmsException
     */
    protected function assertStatusTransition(int $from, int $to, bool $viaWorkflow = false): void
    {
        $allowed = [self::STATUS_DRAFT, self::STATUS_PENDING, self::STATUS_PUBLISHED, self::STATUS_ARCHIVED];
        if (!in_array($to, $allowed, true)) {
            throw new WisecmsException('无效的状态值', ErrorCode::VALIDATE_FAILED);
        }

        if ($viaWorkflow || $to !== self::STATUS_PUBLISHED) {
            return;
        }

        if ((int) config('wisecms.content_check', self::REVIEW_BEFORE) !== self::REVIEW_BEFORE) {
            return;
        }

        if ($from === self::STATUS_PUBLISHED || $from === self::STATUS_PENDING) {
            return;
        }

        throw new WisecmsException(
            '「先审后发」模式下内容须先提交审核，审核通过后方可发布',
            ErrorCode::VALIDATE_FAILED
        );
    }

    /**
     * 更新内容状态（状态机唯一收口，P1-B1）
     *
     * 相对旧实现的差异：
     *   1. 经过 {@see assertStatusTransition()} —— 「先审后发」不可被绕过；
     *   2. 变更前采集版本快照，状态流转可追溯（采集失败不阻断流转）；
     *   3. 发布事件与缓存失效保持原行为。
     *
     * @param bool $viaWorkflow 由工作流引擎驱动时置 true（见 assertStatusTransition）
     */
    public function updateStatus(int $id, int $status, bool $viaWorkflow = false): Content
    {
        $content = Content::find($id);
        if (!$content) {
            throw new WisecmsException('内容不存在', ErrorCode::NOT_FOUND);
        }

        $from = (int) $content->status;
        $this->assertStatusTransition($from, $status, $viaWorkflow);

        if ($from !== $status) {
            // 状态变更前留快照：内容字段可回滚，且变更有据可查
            try {
                $this->versionService()->capture($content, "状态变更 {$from} → {$status}");
            } catch (\Throwable $e) {
                cms_log('warning', '状态变更快照失败: ' . $e->getMessage(), ['content_id' => $id]);
            }
        }

        $content->status = $status;
        // 首次发布补记发布时间
        if ($status == self::STATUS_PUBLISHED && empty($content->publish_time)) {
            $content->publish_time = cms_now();
        }
        $content->save();

        // 发布事件 Webhook（接线 WebhookService）
        if ($status == self::STATUS_PUBLISHED) {
            (new WebhookService())->trigger('content.published', [
                'content_id' => $id,
                'title'      => $content->title,
                'catid'      => $content->catid,
            ]);
        }

        // 列表 / 标签云 / 站点地图 / feed 均受状态变更影响，统一走 onContentSaved 一并失效
        // （此前非发布状态只清详情缓存，列表与 sitemap 会陈旧到定时重建；
        //   取消发布 / 归档同样会让条目从 feed 中消失，故不做分支）
        // feed 缓存键含 site_id，需显式传入
        (new CacheService())->onContentSaved($id, (int) $content->catid, (int) $content->site_id);

        // P1-C：标签云只统计「已发布」内容，状态变更必须重算计数
        // （旧实现不重算 → 取消发布 / 归档的内容仍计入标签云，标签页与标签云数字不一致）
        if ($from !== $status) {
            $this->recountTags(
                array_map('intval', TagRelation::where('content_id', $id)->column('tag_id'))
            );
        }

        return $content;
    }

    /**
     * 批量删除（软删除到回收站，P1-B4）
     *
     * 旧实现 `Content::whereIn('id', $ids)->delete()` 在本框架下是**空操作**：
     * `SoftDelete::db()` 会给查询挂上 `soft_delete` 选项（值为「未删除」条件 `['null','']`），
     * `BaseQuery::delete()` 会把该条件当作 SET 数据下发，而 `Builder::parseData()` 对
     * `['null','']` 这种形态不做任何赋值 → SET 为空 → `Mysql::update()` 返回空 SQL →
     * **不执行任何 SQL**。表现为「批量删除无反应、返回 0」。
     *
     * 现改为与单条删除（{@see delete()}）完全一致的**模型实例路径**，并补齐事务与
     * 缓存 / 别名 / 标签计数的一致性维护。
     *
     * @param int[] $ids
     * @return int 实际处理的条数
     */
    public function batchDelete(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return 0;
        }

        /** @var \think\Collection $contents */
        $contents = Content::whereIn('id', $ids)->select();
        if ($contents->isEmpty()) {
            return 0;
        }

        $urlService = new UrlService();

        // 一次性取回全部受影响标签，避免逐条查询（N+1）
        $tagIds = array_map('intval', TagRelation::whereIn('content_id', $ids)->column('tag_id'));

        Db::startTrans();
        try {
            foreach ($contents as $content) {
                // 实例 delete()：触发 SoftDelete，写入 delete_time
                $content->delete();

                // 回收站内容不可访问，移除别名索引（与 delete() 一致）
                $urlService->removeAlias(\plugin\wisecms\model\UrlAlias::TYPE_CONTENT, (int) $content->id);
            }

            // P1-C：软删**保留标签关联**（与单条 delete() 对齐）。旧实现此处直接删关联，
            // 导致「批量删除 → 回收站恢复」后内容标签永久丢失，且与单条删除行为不一致；
            // 标签关联的物理清理只发生在彻底删除（forceDelete → ContentRelationService::purge）。

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }

        // 事务提交后再做缓存与计数（避免把缓存/统计操作卷进长事务）
        foreach ($contents as $content) {
            $this->clearDetailCache((int) $content->id);
            $this->clearListCache((int) $content->catid);
        }
        $this->recountTags($tagIds);

        return $contents->count();
    }

    /**
     * 批量移动栏目（P1-B4）
     *
     * 旧实现只跑一条 UPDATE：不清缓存、不同步 URL 别名（栏目变更会改变内容路径，
     * 旧链接应转 301），导致列表页陈旧 + 伪静态链接 404。现逐条走模型并同步别名。
     *
     * @param int[] $ids
     */
    public function batchMoveCategory(array $ids, int $catid): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === [] || $catid <= 0) {
            return 0;
        }

        /** @var \think\Collection $contents */
        $contents = Content::whereIn('id', $ids)->select();
        if ($contents->isEmpty()) {
            return 0;
        }

        $urlService  = new UrlService();
        $oldCatids   = [];

        Db::startTrans();
        try {
            foreach ($contents as $content) {
                $oldCatids[] = (int) $content->catid;

                $content->catid = $catid;
                // update_time 由模型 autoWriteTimestamp 维护
                $content->save();

                // 栏目参与 URL 规则：旧路径转历史（301 到新路径）
                $urlService->syncContent($content);
            }
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }

        foreach (array_unique(array_merge($oldCatids, [$catid])) as $affectedCatid) {
            $this->clearListCache((int) $affectedCatid);
        }

        return $contents->count();
    }

    /**
     * 重算标签引用计数（P1-B4 / P1-B6 / P1-C）
     *
     * 口径（P1-C 统一）：`tag.count` = 该标签下**已发布且未进回收站**的内容数 ——
     * 与标签页实际可见列表一致（旧口径统计全部关联，草稿/待审/归档/回收站内容也计入，
     * 导致标签云数字远大于点进去能看到的内容数）。
     *
     * 实现：一次 JOIN + GROUP BY 取回全部计数再逐条写回，消除 N+1。
     *
     * 公开供跨站复制（SiteService::copyContentToSite）与数据维护命令复用。
     *
     * @param int[] $tagIds
     */
    public function recountTags(array $tagIds): void
    {
        $tagIds = array_values(array_unique(array_filter(array_map('intval', $tagIds))));
        if ($tagIds === []) {
            return;
        }

        // P2 标签站点隔离：`count` 是**本站口径**（本站已发布 + 未回收），
        // 故 JOIN 内容表时额外要求「关联站点 = 内容站点」（关联侧 site_id 为冗余列，
        // 越界行（历史数据）不得计入本站计数）。
        $rows = TagRelation::alias('tr')
            ->join('wisecms_content c', 'c.id = tr.content_id')
            ->whereIn('tr.tag_id', $tagIds)
            ->whereColumn('tr.site_id', 'c.site_id')
            ->where('c.status', self::STATUS_PUBLISHED)
            ->whereNull('c.delete_time')
            ->field('tr.tag_id, COUNT(*) AS c')
            ->group('tr.tag_id')
            ->select()
            ->toArray();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['tag_id']] = (int) $row['c'];
        }

        foreach ($tagIds as $tagId) {
            Tag::where('id', $tagId)->update(['count' => $counts[$tagId] ?? 0]);
        }

        (new CacheService())->deletePattern(CacheService::TAG_CLOUD_PATTERN);
    }

    /**
     * 版本快照服务
     */
    protected function versionService(): ContentVersionService
    {
        return new ContentVersionService();
    }

    /**
     * 版本历史列表（后台用，含分页与保留条数）
     */
    public function listVersions(int $contentId, int $page = 1, int $limit = 20): array
    {
        return $this->versionService()->list($contentId, $page, $limit);
    }

    /**
     * 获取单个版本快照（已解码为 {content, custom, tags}；不存在或损坏返回 null）
     */
    public function getVersion(int $contentId, int $versionId): ?array
    {
        $version = $this->versionService()->find($contentId, $versionId);
        if (!$version) {
            return null;
        }

        return $this->versionService()->normalize($version);
    }

    /**
     * 版本对比（base 缺省为「当前线上内容」）
     */
    public function diffVersion(int $contentId, int $versionId, ?int $baseVersionId = null): array
    {
        return $this->versionService()->diff($contentId, $versionId, $baseVersionId);
    }

    /**
     * 删除单个版本快照
     */
    public function deleteVersion(int $contentId, int $versionId): bool
    {
        return $this->versionService()->delete($contentId, $versionId);
    }

    /**
     * 回滚到指定版本（事务 + 行锁 + 乐观锁；回滚动作本身会产生新版本）
     *
     * @param array{base_update_time?:string} $opts 乐观锁基线（内容当前 update_time）
     */
    public function rollback(int $contentId, int $versionId, array $opts = []): Content
    {
        return $this->versionService()->rollback($contentId, $versionId, $opts);
    }

    /**
     * 保存标签关联
     *
     * P1-B4/B6：旧关联删除前先记下受影响的标签，最后**一次性**重算计数 ——
     * 旧实现逐个标签 `Tag::updateCount()`（N 次全表 COUNT），且不重算「被移除关联」
     * 的那些标签（计数只增不减）。
     */
    public function saveTags(int $contentId, array $tagNames, int $modelId, int $siteId): void
    {
        // 旧关联涉及的原标签，删除后同样需要重算
        $affectedTagIds = array_map('intval', TagRelation::where('content_id', $contentId)->column('tag_id'));

        // 删除旧关联
        TagRelation::where('content_id', $contentId)->delete();

        // P2 收敛：站点内 find-or-create 统一走服务层（原内联实现缺撞键重试 / 别名回填 / URL 别名同步）
        $tagService = new TagService();

        foreach ($tagNames as $tagName) {
            $tagName = trim($tagName);
            if (empty($tagName)) continue;

            // 查找或创建标签（站点内唯一）
            $tag = $tagService->findOrCreate($siteId, $tagName);

            // 创建关联（site_id 冗余：恒与内容同站，便于按站点统计与删站级联清理）
            TagRelation::create([
                'tag_id'     => $tag->id,
                'content_id' => $contentId,
                'site_id'    => $siteId,
                'model_id'   => $modelId,
            ]);

            $affectedTagIds[] = (int) $tag->id;
        }

        // 一次 GROUP BY 重算全部受影响标签的计数（含缓存失效）
        $this->recountTags($affectedTagIds);
    }

    /**
     * 清除缓存
     */
    protected function clearDetailCache(int $id): void
    {
        (new CacheService())->delete(sprintf(CacheService::KEY_CONTENT_DETAIL, $id));
    }

    protected function clearListCache(int $catid): void
    {
        (new CacheService())->delete(sprintf(CacheService::KEY_CONTENT_LIST, $catid));
    }

    /**
     * 执行内容处理链
     */
    protected function runPipeline(array $data, string $scene = 'create'): array
    {
        try {
            if (\think\facade\App::getInstance()->has('wisecms.pipeline')) {
                $pipeline = \think\facade\App::getInstance()->make('wisecms.pipeline');
                $data = $pipeline->process($data, $scene);
            }
        } catch (\Exception $e) {
            cms_log('warning', "CMS Pipeline failed [scene={$scene}]: " . $e->getMessage());
            // Pipeline 失败不阻断主流程
        }
        return $data;
    }
}
