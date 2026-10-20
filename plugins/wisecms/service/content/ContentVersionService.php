<?php
namespace plugin\wisecms\service\content;

use plugin\wisecms\exception\ErrorCode;
use plugin\wisecms\exception\WisecmsException;
use plugin\wisecms\model\Content;
use plugin\wisecms\model\ContentVersion;
use plugin\wisecms\model\Model as ModelModel;
use plugin\wisecms\model\ModelField;
use plugin\wisecms\model\Tag;
use plugin\wisecms\model\TagRelation;
use plugin\wisecms\service\site\CacheService;
use think\facade\Db;

/**
 * 内容版本快照服务（A-1）
 *
 * 版本语义（保存后快照）：
 *   - 创建内容 → v1 = 创建后状态；
 *   - 第 N 次保存 → vN = 保存后状态；
 *   - 最新版本即当前线上内容；回滚 vM 会追加 v(N+1) = 状态M，
 *     而回滚前状态 vN 仍在表中，因此天然支持「再次回退」。
 *
 * 设计要点：
 *   - 快照自包含（主表剔除冻结字段 + 副表 + 标签），采集点在
 *     ContentService::createWithFields()/updateWithFields() 事务末尾（单点采集）；
 *   - 冻结字段（FROZEN）既不进快照也不随回滚还原，用于保护
 *     status（不绕审核）、site_id/model_id（不错位）、view_count/seo_*（运营数据）；
 *   - 用 data_hash 对「无实质改动的保存」去重，避免版本噪声。
 *
 * 详见 docs/wisecms-content-version-design.md
 */
class ContentVersionService
{
    /** 快照格式版本 */
    public const SCHEMA = 1;

    /** 默认保留条数（须 >= 2，否则回滚后无法再回退） */
    public const DEFAULT_KEEP = 20;

    /** 默认快照备注 */
    public const REMARK_DEFAULT = '系统快照';

    /**
     * 冻结字段：既不进快照，也不随回滚还原
     *
     * @var string[]
     */
    public const FROZEN = [
        // create_time / update_time / delete_time 由框架维护，随快照回滚会破坏「先审后发」
        // 与软删语义；publish_time 是发布动作的产物，同样不随内容版本回滚。
        'id', 'create_time', 'update_time', 'delete_time',
        'site_id', 'model_id',
        'status', 'view_count', 'seo_score', 'seo_level',
        'publish_time',
    ];

    // ══════════════════════ 采集（写入链路） ══════════════════════

    /**
     * 采集完整快照（主表剔除冻结字段 + 副表 + 标签）
     *
     * 与最新版本内容完全一致时跳过（返回 null），避免无实质改动的保存产生版本噪声。
     *
     * @param Content $content  已保存的内容模型（含最新内存属性）
     * @param string  $remark   版本备注
     */
    public function capture(Content $content, string $remark = self::REMARK_DEFAULT): ?ContentVersion
    {
        $contentId = (int) $content->id;
        if ($contentId <= 0) {
            return null;
        }

        $payload = $this->buildSnapshot($content);
        $json    = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || $json === '') {
            return null;
        }
        $hash = md5($json);

        $latest = ContentVersion::where('content_id', $contentId)->order('version', 'desc')->find();
        if ($latest && (string) $latest->data_hash === $hash) {
            return null; // 无实质改动，不产生版本
        }

        $row = [
            'content_id'  => $contentId,
            'data'        => $json,
            'remark'      => $remark,
            'create_user' => $this->currentUser(),
            // create_time 由模型 autoWriteTimestamp 维护
        ];

        // 兼容尚未执行 P0 迁移的情况：新列不存在时降级为不写（功能可用，仅失去去重/标题快照）
        $fields = $this->versionTableFields();
        if (in_array('title', $fields, true)) {
            $row['title'] = mb_substr((string) $content->title, 0, 255);
        }
        if (in_array('data_hash', $fields, true)) {
            $row['data_hash'] = $hash;
        }

        // P1-C：版本号由 max(version)+1 生成（读后写），两人同时保存会算出同一个号。
        // 唯一键 (content_id, version) 是最终防线：命中冲突时重新取 max 并重试。
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $row['version'] = ((int) ContentVersion::where('content_id', $contentId)->max('version')) + 1;

            try {
                return ContentVersion::create($row);
            } catch (\Throwable $e) {
                if (!cms_is_duplicate_key($e) || $attempt >= 2) {
                    throw $e;
                }
            }
        }

        return null;
    }

    /**
     * 构造快照数据（纯逻辑，便于测试）
     *
     * @return array{_schema:int, content:array, custom:array, tags:string[]}
     */
    public function buildSnapshot(Content $content): array
    {
        $main = $content->toArray();
        foreach (self::FROZEN as $field) {
            unset($main[$field]);
        }

        return [
            '_schema' => self::SCHEMA,
            'content' => $main,
            'custom'  => $this->fetchCustomData($content),
            'tags'    => $this->fetchTagNames((int) $content->id),
        ];
    }

    // ══════════════════════ 读取 ══════════════════════

    /**
     * 解码快照为 {content, custom, tags}；损坏返回 null
     *
     * @param ContentVersion|array|string|null $raw
     */
    public function normalize($raw): ?array
    {
        if ($raw instanceof ContentVersion) {
            $json = $raw->data;
        } elseif (is_array($raw)) {
            $json = $raw['data'] ?? null;
        } else {
            $json = $raw;
        }

        if (!is_string($json) || $json === '') {
            return null;
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded) || !isset($decoded['content']) || !is_array($decoded['content'])) {
            return null;
        }

        $custom = $decoded['custom'] ?? [];
        $tags   = $decoded['tags'] ?? [];

        return [
            'content' => $decoded['content'],
            'custom'  => is_array($custom) ? $custom : [],
            'tags'    => is_array($tags) ? array_values($tags) : [],
        ];
    }

    /**
     * 按内容归属查找版本（强制 content_id 匹配，防 IDOR）
     */
    public function find(int $contentId, int $versionId): ?ContentVersion
    {
        if ($contentId <= 0 || $versionId <= 0) {
            return null;
        }

        return ContentVersion::where('content_id', $contentId)->where('id', $versionId)->find();
    }

    /**
     * 版本列表（不含 data，避免大字段进响应）
     */
    public function list(int $contentId, int $page = 1, int $limit = 20): array
    {
        $page  = max(1, $page);
        $limit = min(100, max(1, $limit));

        $total = (int) ContentVersion::where('content_id', $contentId)->count();
        $max   = (int) ContentVersion::where('content_id', $contentId)->max('version');

        $rows = ContentVersion::where('content_id', $contentId)
            ->order('version', 'desc')
            ->page($page, $limit)
            ->select();

        $list = [];
        foreach ($rows as $row) {
            $snapshot = $this->normalize($row);
            $content  = $snapshot['content'] ?? [];

            $list[] = [
                'id'          => (int) $row->id,
                'version'     => (int) $row->version,
                'title'       => (string) ($row->title ?: ($content['title'] ?? '')),
                'word_count'  => (int) ($content['word_count'] ?? 0),
                'size'        => strlen((string) $row->data),
                'create_user' => (string) $row->create_user,
                // 统一 DATETIME 后需返回字符串：视图用 substr($create_time, 0, 19) 截取，
                // 强转 int 会把 '2026-10-05 12:00:00' 变成 1762... 之类的年份数字
                'create_time' => (string) $row->create_time,
                'remark'      => (string) $row->remark,
                'is_latest'   => (int) $row->version === $max,
                'broken'      => $snapshot === null,
            ];
        }

        return [
            'total'           => (int) $total,
            'page'            => $page,
            'limit'           => $limit,
            'keep'            => $this->keepLimit(),
            'current_version' => $max,
            'data'            => $list,
        ];
    }

    /**
     * 版本对比
     *
     * base = 基准（未指定 base_version_id 时为「当前线上内容」），target = 选中版本；
     * 输出即「应用该版本后会删除(del)/新增(ins)的内容」。
     */
    public function diff(int $contentId, int $versionId, ?int $baseVersionId = null): array
    {
        $version = $this->find($contentId, $versionId);
        if (!$version) {
            throw new WisecmsException('版本不存在', ErrorCode::NOT_FOUND);
        }

        $target = $this->normalize($version);
        if ($target === null) {
            throw new WisecmsException('该版本快照已损坏，无法对比', ErrorCode::CONFLICT);
        }

        $content = Content::find($contentId);
        if (!$content) {
            throw new WisecmsException('内容不存在', ErrorCode::NOT_FOUND);
        }

        if ($baseVersionId !== null && $baseVersionId > 0) {
            $baseVersion = $this->find($contentId, $baseVersionId);
            if (!$baseVersion) {
                throw new WisecmsException('对比基准版本不存在', ErrorCode::NOT_FOUND);
            }

            $base = $this->normalize($baseVersion);
            if ($base === null) {
                throw new WisecmsException('对比基准版本快照已损坏', ErrorCode::CONFLICT);
            }

            $baseMeta = [
                'type'        => 'version',
                'version_id'  => (int) $baseVersion->id,
                'version'     => (int) $baseVersion->version,
                'create_user' => (string) $baseVersion->create_user,
                'create_time' => (string) $baseVersion->create_time,
            ];
        } else {
            $base     = $this->buildSnapshot($content);
            $baseMeta = [
                'type'        => 'current',
                'version_id'  => null,
                'version'     => null,
                'create_user' => '',
                // 「当前线上内容」的伪版本时间 = 内容的最后更新时间
                'create_time' => (string) $content->update_time,
            ];
        }

        // 主表字段优先（同名时覆盖自定义字段）
        $diffService = new TextDiffService();
        $fields = $diffService->diffFields(
            array_merge($base['custom'], $base['content']),
            array_merge($target['custom'], $target['content'])
        );

        if ($base['tags'] !== $target['tags']) {
            $fields[] = [
                'field' => 'tags',
                'label' => '标签',
                'old'   => implode('、', $base['tags']),
                'new'   => implode('、', $target['tags']),
            ];
        }

        $body = $diffService->diff(
            $diffService->normalizeToLines((string) ($base['content']['content'] ?? '')),
            $diffService->normalizeToLines((string) ($target['content']['content'] ?? ''))
        );

        return [
            'base'       => $baseMeta,
            'target'     => [
                'type'        => 'version',
                'version_id'  => (int) $version->id,
                'version'     => (int) $version->version,
                'create_user' => (string) $version->create_user,
                'create_time' => (int) $version->create_time,
                'remark'      => (string) $version->remark,
            ],
            'fields'     => $fields,
            'stats'      => $diffService->stats($body['ops']),
            'hunks'      => $body['ops'],
            'hunks_html' => $diffService->toHtml($body['ops']),
            'degraded'   => $body['degraded'],
        ];
    }

    /**
     * 删除单个版本快照（人工运维入口）
     */
    public function delete(int $contentId, int $versionId): bool
    {
        $version = $this->find($contentId, $versionId);
        if (!$version) {
            throw new WisecmsException('版本不存在', ErrorCode::NOT_FOUND);
        }

        return (bool) $version->delete();
    }

    // ══════════════════════ 回滚 ══════════════════════

    /**
     * 构造回滚载荷（纯函数：白名单 + 冻结字段过滤）
     *
     * - main：主表实际字段 ∩ 快照 ∩ 非冻结（历史字段被删列后不会写库报错）
     * - custom：当前模型已定义字段 ∩ 快照
     *
     * @return array{main:array, custom:array, tags:string[]}
     */
    public function buildRestorePayload(array $snapshot, Content $current): array
    {
        $mainFields = $this->mainTableFields();
        $frozen     = array_flip(self::FROZEN);

        $main = [];
        foreach ((array) ($snapshot['content'] ?? []) as $field => $value) {
            if (!is_string($field) || isset($frozen[$field]) || !isset($mainFields[$field])) {
                continue;
            }
            $main[$field] = $value;
        }

        $customAllowed = $this->customFieldNames((int) $current->model_id);
        $custom        = [];
        foreach ((array) ($snapshot['custom'] ?? []) as $field => $value) {
            if (is_string($field) && isset($customAllowed[$field])) {
                $custom[$field] = $value;
            }
        }

        $tags = [];
        foreach ((array) ($snapshot['tags'] ?? []) as $tag) {
            $tag = trim((string) $tag);
            if ($tag !== '') {
                $tags[] = $tag;
            }
        }

        return ['main' => $main, 'custom' => $custom, 'tags' => $tags];
    }

    /**
     * 回滚到指定版本
     *
     * 事务内：行锁 + 乐观锁校验 → 复用 updateWithFields()（主/副表/标签原子，
     * 并自动追加一条新版本，故回滚可再次回退）。
     *
     * @param array{base_update_time?:string} $opts 乐观锁基线（内容当前 update_time）
     */
    public function rollback(int $contentId, int $versionId, array $opts = []): Content
    {
        $contentService = new ContentService();

        Db::startTrans();
        try {
            $content = Content::where('id', $contentId)->lock(true)->find();
            if (!$content) {
                throw new WisecmsException('内容不存在', ErrorCode::NOT_FOUND);
            }

            $version = ContentVersion::where('content_id', $contentId)
                ->where('id', $versionId)
                ->lock(true)
                ->find();
            if (!$version) {
                throw new WisecmsException('版本不存在', ErrorCode::NOT_FOUND);
            }

            // 乐观锁：diff 弹窗打开后被他人修改 → 拒绝，避免覆盖
            // 基线为提交时的 update_time（DATETIME），归一为时间戳后比较
            $baseTs = cms_timestamp($opts['base_update_time'] ?? null);
            if ($baseTs > 0 && $baseTs !== cms_timestamp($content->update_time)) {
                throw new WisecmsException('内容已被其他人修改，请刷新后重试', ErrorCode::CONFLICT);
            }

            $snapshot = $this->normalize($version);
            if ($snapshot === null) {
                throw new WisecmsException('该版本快照已损坏，无法回滚', ErrorCode::CONFLICT);
            }

            $oldCatid = (int) $content->catid;
            $payload  = $this->buildRestorePayload($snapshot, $content);

            $content = $contentService->updateWithFields(
                $contentId,
                $payload['main'],
                $payload['custom'],
                $payload['tags'],
                '回滚至 v' . (int) $version->version
            );

            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }

        // 事务外：update() 只清新 catid 的列表缓存，这里补清旧 catid
        $newCatid = (int) $content->catid;
        if ($oldCatid > 0 && $oldCatid !== $newCatid) {
            (new CacheService())->delete(sprintf(CacheService::KEY_CONTENT_LIST, $oldCatid));
        }

        cms_log('warning', '内容版本回滚', [
            'content_id' => $contentId,
            'version_id' => $versionId,
            'version'    => (int) $version->version,
        ]);

        return $content;
    }

    // ══════════════════════ 保留策略 ══════════════════════

    /**
     * 计算待删版本号（纯函数）
     *
     * @param int[]|string[] $versions 已有版本号集合
     * @return int[] 升序的待删除版本号
     */
    public static function planPrune(array $versions, int $keep): array
    {
        $versions = array_values(array_unique(array_map('intval', $versions)));
        sort($versions);

        if ($keep <= 0 || count($versions) <= $keep) {
            return [];
        }

        return array_slice($versions, 0, count($versions) - $keep);
    }

    /**
     * 裁剪指定内容的版本，仅保留 version 最大的 N 条
     *
     * @return int 删除条数
     */
    public function prune(int $contentId, ?int $keep = null): int
    {
        $keep = $keep === null ? $this->keepLimit() : $keep;
        if ($contentId <= 0 || $keep <= 0) {
            return 0;
        }

        $doomed = self::planPrune(ContentVersion::where('content_id', $contentId)->column('version'), $keep);
        if (empty($doomed)) {
            return 0;
        }

        return (int) ContentVersion::where('content_id', $contentId)->whereIn('version', $doomed)->delete();
    }

    /**
     * 全量裁剪（供清理命令使用）
     *
     * @return array{contents:int, deleted:int}
     */
    public function pruneAll(?int $keep = null, bool $dryRun = false): array
    {
        $keep   = $keep === null ? $this->keepLimit() : $keep;
        $result = ['contents' => 0, 'deleted' => 0];
        if ($keep <= 0) {
            return $result;
        }

        $contentIds = ContentVersion::group('content_id')->column('content_id');
        foreach ($contentIds as $contentId) {
            $doomed = self::planPrune(
                ContentVersion::where('content_id', (int) $contentId)->column('version'),
                $keep
            );
            if (empty($doomed)) {
                continue;
            }

            $result['contents']++;
            $result['deleted'] += count($doomed);

            if (!$dryRun) {
                ContentVersion::where('content_id', (int) $contentId)->whereIn('version', $doomed)->delete();
            }
        }

        return $result;
    }

    /**
     * 保留条数配置（脱离框架环境时回落默认值）
     */
    public function keepLimit(): int
    {
        if (!function_exists('config')) {
            return self::DEFAULT_KEEP;
        }

        try {
            return (int) config('wisecms.version_keep', self::DEFAULT_KEEP);
        } catch (\Throwable $e) {
            return self::DEFAULT_KEEP;
        }
    }

    // ══════════════════════ 内部工具 ══════════════════════

    /**
     * 读取副表自定义字段（不含与主表同 id 的主键）
     */
    protected function fetchCustomData(Content $content): array
    {
        $table = $this->customTableName((int) $content->model_id);
        if ($table === '') {
            return [];
        }

        try {
            $row = Db::name($table)->where('id', (int) $content->id)->find();
        } catch (\Throwable $e) {
            return [];
        }

        if (!is_array($row)) {
            return [];
        }

        unset($row['id']);

        return $row;
    }

    /**
     * 副表名（{模型表名}_data）；模型不存在时返回空串
     */
    protected function customTableName(int $modelId): string
    {
        if ($modelId <= 0) {
            return '';
        }

        try {
            $model = ModelModel::find($modelId);
        } catch (\Throwable $e) {
            return '';
        }

        return $model && !empty($model->table_name) ? (string) $model->table_name . '_data' : '';
    }

    /**
     * 读取内容标签名
     */
    protected function fetchTagNames(int $contentId): array
    {
        if ($contentId <= 0) {
            return [];
        }

        try {
            $tagIds = TagRelation::where('content_id', $contentId)->column('tag_id');
            if (empty($tagIds)) {
                return [];
            }
            return array_values(Tag::whereIn('id', $tagIds)->column('name'));
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 主表实际字段（flip 便于 isset 判定）
     */
    protected function mainTableFields(): array
    {
        static $fields = null;
        if ($fields === null) {
            try {
                $fields = array_flip(Db::name('wisecms_content')->getTableFields());
            } catch (\Throwable $e) {
                $fields = [];
            }
        }

        return $fields;
    }

    /**
     * 当前模型的副表字段名（flip）；按 model_id 记忆化
     */
    protected function customFieldNames(int $modelId): array
    {
        static $cache = [];
        if ($modelId <= 0) {
            return [];
        }

        if (!isset($cache[$modelId])) {
            try {
                $names = ModelField::where('model_id', $modelId)->column('field');
            } catch (\Throwable $e) {
                $names = [];
            }
            $cache[$modelId] = array_flip(array_map('strval', (array) $names));
        }

        return $cache[$modelId];
    }

    /**
     * 版本表字段（用于兼容尚未执行 P0 迁移的环境）
     */
    protected function versionTableFields(): array
    {
        static $fields = null;
        if ($fields === null) {
            try {
                $fields = Db::name('wisecms_content_version')->getTableFields();
            } catch (\Throwable $e) {
                $fields = [];
            }
        }

        return (array) $fields;
    }

    /**
     * 当前后台操作人
     */
    protected function currentUser(): string
    {
        try {
            $admin = request()->admin ?? null;
            if (is_array($admin) && !empty($admin['username'])) {
                return (string) $admin['username'];
            }
        } catch (\Throwable $e) {
            // CLI/未登录场景
        }

        return '';
    }
}
