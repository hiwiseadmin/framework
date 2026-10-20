<?php

declare(strict_types=1);

namespace plugin\wisedocs\controller;

use plugin\wisedocs\model\Tag as TagModel;
use plugin\wisedocs\validate\Tag as TagValidate;
use think\facade\Db;

/**
 * 标签管理（P2-4）
 *
 * P1 已将标签关系落到关联表 `wisedocs_document_tags`，本控制器全面改用关联表，
 * 不再使用 `documents.tags` 的 LIKE 匹配（修复子串误匹配问题）。
 */
class Tag extends Base
{
    /** 文档-标签关联表（Db 会自动补表前缀） */
    protected const PIVOT = 'wisedocs_document_tags';

    public function index()
    {
        return $this->fetch();
    }

    /**
     * Bootstrap Table 分页数据
     */
    public function getList()
    {
        [$page, $size] = $this->pageParams();

        $keyword = trim((string) $this->request->param('keyword', ''));

        $query = TagModel::order('count DESC, id DESC');
        if ($keyword !== '') {
            $query->whereLike('name|slug', "%{$keyword}%");
        }

        $total = $query->count();
        $list  = $query->page($page, $size)->select();

        $rows = [];
        foreach ($list as $item) {
            $row                = $item->toArray();
            $row['status_text'] = (int) $item->status === 1 ? '启用' : '禁用';
            $rows[]             = $row;
        }

        return $this->listResponse($total, $rows);
    }

    /**
     * 标签选项（供文档表单多选）
     */
    public function options()
    {
        $keyword = trim((string) $this->request->param('keyword', ''));

        $query = TagModel::where('status', 1)->order('count DESC, id DESC')->limit(200);
        if ($keyword !== '') {
            $query->whereLike('name', "%{$keyword}%");
        }

        return $this->success('', $query->field('id,name,count')->select()->toArray());
    }

    /**
     * 新增 / 编辑
     */
    public function save()
    {
        if (!$this->request->isPost()) {
            return $this->error('非法请求');
        }

        $data = $this->request->post();
        $id   = (int) ($data['id'] ?? 0);

        // 标识留空时自动生成（表单占位提示「留空自动生成」）。
        // 必须在控制器内回填并落库：验证器里的同名生成只改局部副本，不会写回 $data。
        if (empty($data['slug']) && !empty($data['name'])) {
            $data['slug'] = $this->makeSlug((string) $data['name'], $id);
        }

        $validate = new TagValidate();
        $scene    = $id > 0 ? 'update' : 'create';
        if (!$validate->scene($scene)->check($data)) {
            return $this->error($validate->getError());
        }

        try {
            if ($id > 0) {
                $model = TagModel::find($id);
                if (!$model) {
                    return $this->error('标签不存在');
                }
                $model->save([
                    'name'   => (string) ($data['name'] ?? ''),
                    'slug'   => (string) ($data['slug'] ?? ''),
                    'status' => (int) ($data['status'] ?? 1),
                ]);
            } else {
                $model = TagModel::create([
                    'name'   => (string) ($data['name'] ?? ''),
                    'slug'   => (string) ($data['slug'] ?? ''),
                    'count'  => 0,
                    'status' => (int) ($data['status'] ?? 1),
                ]);
                $id    = (int) $model->id;
            }

            return $this->success('保存成功', ['id' => $id]);
        } catch (\Throwable $e) {
            \think\facade\Log::error('[wisedocs] tag save failed: ' . $e->getMessage());

            return $this->error('保存失败，请稍后重试');
        }
    }

    /**
     * 删除（仍被文档使用时拒绝）
     */
    public function delete()
    {
        if (!$this->request->isPost()) {
            return $this->error('非法请求');
        }

        $ids = array_map('intval', (array) $this->request->post('ids/a', []));
        if (empty($ids)) {
            return $this->error('请选择要删除的数据');
        }

        try {
            $used = Db::name(self::PIVOT)->whereIn('tag_id', $ids)->count();
            if ($used > 0) {
                return $this->error("该标签已被 {$used} 篇文档使用，无法删除");
            }

            TagModel::destroy($ids);

            return $this->success('删除成功');
        } catch (\Throwable $e) {
            \think\facade\Log::error('[wisedocs] tag delete failed: ' . $e->getMessage());

            return $this->error('删除失败，请稍后重试');
        }
    }

    /**
     * 合并标签（关联表实现）
     */
    public function merge()
    {
        if (!$this->request->isPost()) {
            return $this->error('非法请求');
        }

        $sourceIds = array_map('intval', (array) $this->request->post('source_ids/a', []));
        $targetId  = (int) $this->request->post('target_id', 0);

        $sourceIds = array_values(array_diff($sourceIds, [$targetId]));

        if (empty($sourceIds) || $targetId <= 0) {
            return $this->error('请选择要合并的标签和目标标签');
        }

        $target = TagModel::find($targetId);
        if (!$target) {
            return $this->error('目标标签不存在');
        }

        try {
            Db::transaction(function () use ($sourceIds, $targetId) {
                $now = date('Y-m-d H:i:s');

                $linked = array_map(
                    'intval',
                    Db::name(self::PIVOT)->where('tag_id', $targetId)->column('document_id')
                );

                foreach ($sourceIds as $sourceId) {
                    $documentIds = array_map(
                        'intval',
                        Db::name(self::PIVOT)->where('tag_id', $sourceId)->column('document_id')
                    );

                    foreach ($documentIds as $documentId) {
                        if (in_array($documentId, $linked, true)) {
                            continue;
                        }
                        Db::name(self::PIVOT)->insert([
                            'document_id' => $documentId,
                            'tag_id'      => $targetId,
                            'create_time' => $now,
                        ]);
                        $linked[] = $documentId;
                    }

                    Db::name(self::PIVOT)->where('tag_id', $sourceId)->delete();
                    TagModel::destroy($sourceId);
                }

                Db::name('wisedocs_tags')->where('id', $targetId)->update([
                    'count' => (int) Db::name(self::PIVOT)->where('tag_id', $targetId)->count(),
                ]);
            });

            return $this->success('合并成功');
        } catch (\Throwable $e) {
            \think\facade\Log::error('[wisedocs] tag merge failed: ' . $e->getMessage());

            return $this->error('合并失败，请稍后重试');
        }
    }

    /**
     * 行内启用 / 禁用（供 EnhancedTable 的 toggle formatter 调用）
     */
    public function status()
    {
        if (!$this->request->isPost()) {
            return $this->error('非法请求');
        }

        $id     = (int) $this->request->post('id', 0);
        $status = (int) $this->request->post('status', 1);

        if ($id <= 0 || !in_array($status, [0, 1], true)) {
            return $this->error('参数错误');
        }

        $model = TagModel::find($id);
        if (!$model) {
            return $this->error('标签不存在');
        }

        try {
            $model->save(['status' => $status]);

            return $this->success($status === 1 ? '已启用' : '已禁用');
        } catch (\Throwable $e) {
            \think\facade\Log::error('[wisedocs] tag status failed: ' . $e->getMessage());

            return $this->error('操作失败，请稍后重试');
        }
    }

    /**
     * 生成唯一 slug（纯中文等无法生成时给出兜底）
     *
     * 仅保留 ASCII 字母/数字，其余（中文、空格、符号）折叠为 '-'；
     * 不能用 /[^\w]+/u：UTF-8 下 \w 会匹配中文，生成含中文的标识会被 alphaDash 拒绝。
     */
    protected function makeSlug(string $name, int $excludeId = 0): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($name));
        $slug = trim((string) $slug, '-');

        if ($slug === '') {
            $slug = 'tag-' . substr(md5($name . microtime(true)), 0, 8);
        }

        $base = $slug;
        $i    = 1;
        while (TagModel::where('slug', $slug)->where('id', '<>', $excludeId)->count() > 0) {
            $slug = $base . '-' . $i;
            $i++;
        }

        return $slug;
    }
}
