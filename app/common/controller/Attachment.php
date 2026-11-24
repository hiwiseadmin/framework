<?php
namespace app\common\controller;

use wise\api\ApiController;
use wise\attachment\model\AttachmentModel;
use wise\attachment\factory\AttachmentStore;
use think\facade\Config;

/**
 * 附件 API 控制器
 *
 * 提供 RESTful 附件操作接口，适用于外部客户端/移动端调用。
 * 路由前缀: /api/attachment/
 *
 * 继承 wise\api\ApiController，获得：
 * - 统一 JSON 响应（success/error/paginate）
 * - 请求参数校验（validateRequest）
 * - 字段过滤（allowFields / ?fields=）
 */
class Attachment extends ApiController
{
    /**
     * 上传文件
     * POST /api/attachment/upload
     *
     * @param file file 上传的文件
     * @param int  category_id 分类ID（可选）
     * @return JSON
     */
    public function upload()
    {
        $editorCap = (string) $this->getRequest()->header('x-wise-editor-capability', '');
        if ($editorCap !== '') {
            $guardClass = '\\plugin\\wiseeditor\\src\\EditorCapabilityGuard';

            if (class_exists($guardClass) && !$guardClass::allows($editorCap)) {
                return $this->error('没有上传权限', [], \wise\api\response\ErrorCode::FORBIDDEN);
            }
        }

        $file = $this->getRequest()->file('file');
        if (!$file) {
            return $this->error('请选择文件');
        }

        $allowedExt = Config::get('wise-attachment.upload.allowed_ext', '');
        if ($allowedExt) {
            $allowedExts = array_map('trim', explode(',', $allowedExt));
            $ext = strtolower($file->getOriginalExtension());
            if (!in_array($ext, $allowedExts, true)) {
                return $this->error("不允许的文件类型 [{$ext}]");
            }
        }

        $maxSize  = (float) Config::get('wise-attachment.upload.max_size', 50);
        $maxBytes = (int) ($maxSize * 1024 * 1024);
        if ($file->getSize() > $maxBytes) {
            return $this->error("文件大小超过最大限制 ({$maxSize}MB)");
        }

        $pathFormat = Config::get('wise-attachment.upload.path_format', 'Y/m/d');
        $subDir     = date($pathFormat);
        $hash       = md5_file($file->getPathname());
        $ext        = strtolower($file->getOriginalExtension() ?: 'bin');
        $objectName = $subDir . '/' . ($hash !== false ? $hash : uniqid('', true)) . '.' . $ext;

        $contentHash = hash_file('sha256', $file->getPathname()) ?: '';
        $enableDedup = Config::get('wise-attachment.upload.enable_dedup', true);

        if ($enableDedup && !empty($contentHash)) {
            $existing = AttachmentModel::findByHash($contentHash);
            if ($existing) {
                $attachment = AttachmentModel::create([
                    'storage'                => $existing->storage,
                    'object_name'            => $existing->object_name,
                    'url'                    => $existing->url,
                    'size'                   => $existing->size,
                    'mime_type'              => $existing->mime_type,
                    'image_width'            => $existing->image_width,
                    'image_height'           => $existing->image_height,
                    'is_image'               => $existing->is_image,
                    'content_hash'           => $contentHash,
                    'is_duplicate'           => 1,
                    'original_attachment_id' => $existing->id,
                    'category_id'            => $this->getRequest()->post('category_id/d', 0),
                ]);

                return $this->success('上传成功', [
                    'id'   => $attachment->id,
                    'url'  => $attachment->url,
                    'size' => $attachment->size,
                    'mime' => $attachment->mime_type,
                ]);
            }
        }

        try {
            $store  = app(AttachmentStore::class);
            $driver = $store->driver();
            $result = $driver->upload($file->getPathname(), $objectName);

            $attachment = AttachmentModel::create([
                'storage'      => $result['storage'] ?? $driver->getDriverName(),
                'object_name'  => $result['path'] ?? $objectName,
                'url'          => $result['url'],
                'size'         => $result['size'],
                'mime_type'    => $result['mime_type'],
                'image_width'  => $result['width'] ?? 0,
                'image_height' => $result['height'] ?? 0,
                'is_image'     => (!empty($result['width']) && $result['width'] > 0) ? 1 : 0,
                'content_hash' => $contentHash,
                'category_id'  => $this->getRequest()->post('category_id/d', 0),
            ]);

            return $this->success('上传成功', [
                'id'    => $attachment->id,
                'url'   => $attachment->url,
                'size'  => $attachment->size,
                'mime'  => $attachment->mime_type,
                'width' => $attachment->image_width,
                'height'=> $attachment->image_height,
            ]);

        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 附件列表
     * GET /api/attachment/list?page=1&limit=20&keyword=&category_id=0
     */
    public function getList()
    {
        $page       = $this->getRequest()->param('page/d', 1);
        $limit      = min($this->getRequest()->param('limit/d', 20), 100);
        $categoryId = $this->getRequest()->param('category_id/d', 0);
        $keyword    = $this->getRequest()->param('keyword', '');

        $query = AttachmentModel::search([
            'category_id' => $categoryId,
            'keyword'     => $keyword,
        ]);

        $paginator = $query->paginate(['list_rows' => $limit, 'page' => $page]);

        $items = [];
        foreach ($paginator->items() as $item) {
            $items[] = [
                'id'         => $item->id,
                'name'       => basename($item->object_name),
                'url'        => $item->url,
                'size'       => $item->size,
                'human_size' => $item->getHumanSize(),
                'ext'        => $item->getExtension(),
                'is_image'   => $item->is_image,
                'mime_type'  => $item->mime_type,
                'created_at' => $item->create_time,
            ];
        }

        return $this->success('获取成功', [
            'list'         => $items,
            'total'        => $paginator->total(),
            'per_page'     => $paginator->listRows(),
            'current_page' => $paginator->currentPage(),
            'total_page'   => $paginator->lastPage(),
        ]);
    }

    /**
     * 附件详情
     * GET /api/attachment/info?id=1
     */
    public function info()
    {
        $id = $this->getRequest()->param('id/d', 0);
        if (!$id) {
            return $this->error('参数错误');
        }

        $attachment = AttachmentModel::find($id);
        if (!$attachment) {
            return $this->error('附件不存在');
        }

        return $this->success('获取成功', [
            'id'           => $attachment->id,
            'name'         => basename($attachment->object_name),
            'url'          => $attachment->url,
            'size'         => $attachment->size,
            'human_size'   => $attachment->getHumanSize(),
            'ext'          => $attachment->getExtension(),
            'is_image'     => $attachment->is_image,
            'mime_type'    => $attachment->mime_type,
            'image_width'  => $attachment->image_width,
            'image_height' => $attachment->image_height,
            'storage'      => $attachment->storage,
            'created_at'   => $attachment->create_time,
        ]);
    }

    /**
     * 下载附件
     * GET /api/attachment/download?id=1
     */
    public function download()
    {
        $id = $this->getRequest()->param('id/d', 0);
        if (!$id) {
            return $this->error('参数错误');
        }

        $attachment = AttachmentModel::find($id);
        if (!$attachment) {
            return $this->error('附件不存在');
        }

        try {
            if ($attachment->storage === 'local') {
                $config = Config::get('wise-attachment.drivers.local', []);
                $rootPath = $config['root_path'] ?? (root_path() . 'public' . DIRECTORY_SEPARATOR . 'uploads');
                $filePath = rtrim($rootPath, '/\\') . DIRECTORY_SEPARATOR . ltrim($attachment->object_name, '/\\');

                if (!file_exists($filePath)) {
                    return $this->error('文件不存在');
                }

                return response('', 200, [
                    'Content-Type'        => $attachment->mime_type ?: 'application/octet-stream',
                    'Content-Disposition' => 'attachment; filename="' . rawurlencode(basename($attachment->object_name)) . '"',
                    'Content-Length'      => (string) filesize($filePath),
                    'Cache-Control'       => 'no-cache',
                ])->sendfile($filePath);
            }

            $store   = app(AttachmentStore::class);
            $driver  = $store->driver($attachment->storage);
            $tempUrl = $driver->temporaryUrl($attachment->object_name, 3600);
            return redirect($tempUrl);

        } catch (\Throwable $e) {
            return $this->error('下载失败: ' . $e->getMessage());
        }
    }

    /**
     * 删除附件
     * DELETE /api/attachment/delete?id=1
     */
    public function delete()
    {
        $id = $this->getRequest()->param('id/d', 0);
        if (!$id) {
            return $this->error('参数错误');
        }

        $attachment = AttachmentModel::find($id);
        if (!$attachment) {
            return $this->error('附件不存在');
        }

        $attachment->delete();

        return $this->success('删除成功');
    }
}
