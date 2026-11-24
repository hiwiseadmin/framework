<?php
declare(strict_types=1);

namespace app\api\controller\admin;

use think\Request;
use think\response\Json;
use wise\api\response\ErrorCode;
use wise\attachment\model\AttachmentCategory;
use wise\attachment\model\AttachmentModel;

/**
 * 附件（后台 App API，只读）
 *
 * GET /api/admin/attachments              列表（page/limit/category_id/keyword）
 * GET /api/admin/attachments/{id}         详情（含直链 url / 人类可读大小 / 扩展名）
 * GET /api/admin/attachments/categories   分类树
 *
 * 权限：
 *   - 列表 / 详情 → admin.attachment.index
 *   - 分类树     → admin.attachment_category.index
 *
 * ⚠️ 本期**不含上传 / 删除**：
 *   后台权限表里没有 admin.attachment.upload / delete 节点（只有 index/trash/restore/empty_trash），
 *   按本方案的不变式「无节点不开放」先在 App 侧收口。上传还涉及分片与多存储驱动，
 *   建议单独立项，同时补 upload/delete 权限节点，避免「复用页面节点」造成越权面扩大。
 */
class Attachment extends Base
{
    protected const MAX_LIMIT = 100;

    /**
     * 附件列表
     */
    public function index(Request $request): Json
    {
        if ($this->admin($request) === null) {
            return $this->error('未认证', null, ErrorCode::UNAUTHORIZED);
        }

        $page       = max(1, (int) $request->param('page', 1));
        $limit      = (int) $request->param('limit', 20);
        $limit      = $limit < 1 ? 20 : min(self::MAX_LIMIT, $limit);
        $categoryId = (int) $request->param('category_id', 0);
        $keyword    = trim((string) $request->param('keyword', ''));

        $query = (new AttachmentModel())->search([
            'category_id' => $categoryId,
            'keyword'     => $keyword,
        ]);

        $paginator = $query->paginate([
            'list_rows' => $limit,
            'page'      => $page,
        ]);

        $list = [];
        foreach ($paginator->items() as $item) {
            $list[] = $this->toArray($item);
        }

        return $this->success('ok', [
            'total' => $paginator->total(),
            'list'  => $list,
            'page'  => $page,
            'limit' => $limit,
        ]);
    }

    /**
     * 附件详情
     */
    public function detail(Request $request, int $id = 0): Json
    {
        if ($this->admin($request) === null) {
            return $this->error('未认证', null, ErrorCode::UNAUTHORIZED);
        }

        $id = $id > 0 ? $id : (int) $request->param('id', 0);
        if ($id <= 0) {
            return $this->error('缺少附件 ID');
        }

        $attachment = AttachmentModel::find($id);
        if ($attachment === null) {
            return $this->error('附件不存在', null, ErrorCode::NOT_FOUND);
        }

        return $this->success('ok', $this->toArray($attachment));
    }

    /**
     * 附件分类树
     */
    public function categories(Request $request): Json
    {
        if ($this->admin($request) === null) {
            return $this->error('未认证', null, ErrorCode::UNAUTHORIZED);
        }

        return $this->success('ok', ['categories' => AttachmentCategory::getTree()]);
    }

    /**
     * 附件 → 输出数组
     *
     * 字段与 Web 侧 `Attachment@getInfo` 对齐；额外带上 category_id / storage，
     * 便于 App 侧按分类筛选与展示来源。
     */
    protected function toArray(AttachmentModel $attachment): array
    {
        return [
            'id'          => (int) $attachment->id,
            'name'        => basename((string) $attachment->object_name),
            'object_name' => (string) $attachment->object_name,
            'url'         => (string) $attachment->url,
            'size'        => (int) $attachment->size,
            'human_size'  => $attachment->getHumanSize(),
            'ext'         => $attachment->getExtension(),
            'mime_type'   => (string) $attachment->mime_type,
            'is_image'    => (int) $attachment->is_image,
            'storage'     => (string) $attachment->storage,
            'category_id' => (int) $attachment->category_id,
            'upload_time' => (string) $attachment->create_time,
        ];
    }
}
