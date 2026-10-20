<?php
namespace app\admin\controller;

use app\admin\validate\Attachment as AttachmentValidate;
use wise\attachment\model\AttachmentModel;
use wise\attachment\model\AttachmentCategory;
use wise\attachment\model\AttachmentLog;
use wise\attachment\model\AttachmentVersion;
use wise\attachment\traits\LoggableAttachment;
use wise\attachment\factory\AttachmentStore;
use think\facade\Config;
use wise\support\context\ContextualLogger;

class Attachment extends BaseController
{
    use LoggableAttachment;

    /**
     * 附件管理首页
     */
    public function index()
    {
        return $this->fetch();
    }

    /**
     * 回收站页面
     */
    public function trash()
    {
        return $this->fetch('attachment/trash');
    }

    /**
     * 上传文件
     * POST /admin/attachment/upload
     * 支持分片上传（chunk_index/chunk_total）和常规上传
     */
    public function upload()
    {
        $file = $this->request->file('file');
        if (!$file) {
            return $this->error('请选择文件');
        }

        // 文件类型校验
        $allowedExt = Config::get('wise-attachment.upload.allowed_ext', '');
        if ($allowedExt) {
            $allowedExts = array_map('trim', explode(',', $allowedExt));
            $ext = strtolower($file->getOriginalExtension());
            if (!in_array($ext, $allowedExts, true)) {
                return $this->error("不允许的文件类型 [{$ext}]，允许的类型: {$allowedExt}");
            }
        }

        // 文件大小校验
        $maxSize  = (float) Config::get('wise-attachment.upload.max_size', 50);
        $maxBytes = (int) ($maxSize * 1024 * 1024);
        if ($file->getSize() > $maxBytes) {
            return $this->error("文件大小超过最大限制 ({$maxSize}MB)");
        }

        // ========== 分片上传 ==========
        $chunkIndex = $this->request->post('chunk_index/d', -1);
        $chunkTotal = $this->request->post('chunk_total/d', -1);
        $fileId     = $this->request->post('file_id', '');

        if ($chunkIndex >= 0 && $chunkTotal > 0) {
            return $this->handleChunkUpload($file, $chunkIndex, $chunkTotal, $fileId);
        }
        // ========== 常规上传 ==========

        return $this->handleNormalUpload($file);
    }

    /**
     * 常规上传流程
     */
    private function handleNormalUpload($file)
    {
        $pathFormat = Config::get('wise-attachment.upload.path_format', 'Y/m/d');
        $subDir = date($pathFormat);
        $hash = md5_file($file->getPathname());
        $ext  = strtolower($file->getOriginalExtension() ?: 'bin');
        $objectName = $subDir . '/' . ($hash !== false ? $hash : uniqid('', true)) . '.' . $ext;

        $contentHash = hash_file('sha256', $file->getPathname()) ?: '';
        $enableDedup = Config::get('wise-attachment.upload.enable_dedup', true);

        // 去重检测
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
                    'category_id'            => $this->request->post('category_id/d', 0),
                ]);

                $this->logAttachmentAction($attachment, 'upload', [
                    'detail' => ['dedup' => true, 'original_id' => $existing->id]
                ]);

                return $this->success('上传成功（去重复用）', [
                    'id'          => $attachment->id,
                    'url'         => $attachment->url,
                    'size'        => $attachment->size,
                    'mime'        => $attachment->mime_type,
                    'width'       => $attachment->image_width,
                    'height'      => $attachment->image_height,
                    'is_image'    => $attachment->is_image,
                    'is_duplicate'=> true,
                ]);
            }
        }

        // 上传到存储驱动
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
                'category_id'  => $this->request->post('category_id/d', 0),
            ]);

            $this->logAttachmentAction($attachment, 'upload');

            return $this->success('上传成功', [
                'id'       => $attachment->id,
                'url'      => $attachment->url,
                'size'     => $attachment->size,
                'mime'     => $attachment->mime_type,
                'width'    => $attachment->image_width,
                'height'   => $attachment->image_height,
                'is_image' => $attachment->is_image,
            ]);

        } catch (\Throwable $e) {
            return $this->error($e->getMessage());
        }
    }

    /**
     * 分片上传处理
     */
    private function handleChunkUpload($file, int $chunkIndex, int $chunkTotal, string $fileId)
    {
        $tempDir = runtime_path() . 'chunks' . DIRECTORY_SEPARATOR;
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        // 分片参数上限保护，防止 chunk_total 超大造成循环/资源耗尽
        $maxChunks = (int) Config::get('wise-attachment.upload.max_chunks', 1000);
        if ($chunkIndex < 0 || $chunkTotal <= 0 || $chunkIndex >= $chunkTotal || $chunkTotal > $maxChunks) {
            return $this->error('分片参数非法');
        }

        // 服务端生成或校验 fileId：仅允许字母数字格式，杜绝目录穿越
        if ($fileId === '') {
            $fileId = md5($file->getOriginalName() . $file->getSize() . $file->getOriginalExtension() . time() . uniqid('', true));
        }
        if (!preg_match('/^[a-zA-Z0-9]{16,64}$/', $fileId)) {
            return $this->error('非法的分片标识');
        }
        $chunkDir = $tempDir . $fileId . DIRECTORY_SEPARATOR;
        if (!is_dir($chunkDir)) {
            mkdir($chunkDir, 0755, true);
        }

        // 扩展名白名单，防止构造路径写入恶意文件（纵深防御，入口处已有 allowed_ext 校验）
        $ext = strtolower($file->getOriginalExtension() ?: 'bin');
        if (!preg_match('/^[a-zA-Z0-9]{1,10}$/', $ext)) {
            return $this->error('非法的文件扩展名');
        }
        $chunkFile = $chunkDir . 'chunk_' . $chunkIndex;
        rename($file->getPathname(), $chunkFile);

        // 如果这不是最后一个分片，返回分片进度
        if ($chunkIndex < $chunkTotal - 1) {
            return $this->success('分片接收成功', [
                'chunk_index' => $chunkIndex,
                'chunk_total' => $chunkTotal,
                'file_id'     => $fileId,
                'received'    => true,
            ]);
        }

        // 合并所有分片
        $originalName = $file->getOriginalName();
        $mergedFile   = $chunkDir . 'merged.' . $ext;
        $outHandle    = fopen($mergedFile, 'wb');

        for ($i = 0; $i < $chunkTotal; $i++) {
            $partFile = $chunkDir . 'chunk_' . $i;
            if (!file_exists($partFile)) {
                @fclose($outHandle);
                return $this->error("分片 {$i} 缺失，请重新上传");
            }
            $partContent = file_get_contents($partFile);
            fwrite($outHandle, $partContent);
        }
        fclose($outHandle);

        // 清理分片文件
        for ($i = 0; $i < $chunkTotal; $i++) {
            @unlink($chunkDir . 'chunk_' . $i);
        }

        // 模拟文件对象用于后续上传
        $tmpFile = $chunkDir . 'tmp_' . uniqid();
        copy($mergedFile, $tmpFile);
        @unlink($mergedFile);

        // 生成存储对象名
        $pathFormat = Config::get('wise-attachment.upload.path_format', 'Y/m/d');
        $subDir     = date($pathFormat);
        $hash       = md5_file($tmpFile);
        $objectName = $subDir . '/' . ($hash !== false ? $hash : uniqid('', true)) . '.' . $ext;

        $contentHash = hash_file('sha256', $tmpFile) ?: '';

        try {
            $store  = app(AttachmentStore::class);
            $driver = $store->driver();
            $result = $driver->upload($tmpFile, $objectName);

            $attachment = AttachmentModel::create([
                'storage'      => $result['storage'] ?? $driver->getDriverName(),
                'object_name'  => $result['path'] ?? $objectName,
                'url'          => $result['url'],
                'size'         => filesize($tmpFile),
                'mime_type'    => $result['mime_type'],
                'image_width'  => $result['width'] ?? 0,
                'image_height' => $result['height'] ?? 0,
                'is_image'     => (!empty($result['width']) && $result['width'] > 0) ? 1 : 0,
                'content_hash' => $contentHash,
                'category_id'  => $this->request->post('category_id/d', 0),
            ]);

            @unlink($tmpFile);
            @rmdir($chunkDir);

            $this->logAttachmentAction($attachment, 'upload', [
                'detail' => ['chunks' => $chunkTotal, 'file_id' => $fileId]
            ]);

            return $this->success('上传成功', [
                'id'       => $attachment->id,
                'url'      => $attachment->url,
                'size'     => $attachment->size,
                'mime'     => $attachment->mime_type,
                'width'    => $attachment->image_width,
                'height'   => $attachment->image_height,
                'is_image' => $attachment->is_image,
            ]);

        } catch (\Throwable $e) {
            @unlink($tmpFile);
            return $this->error($e->getMessage());
        }
    }

    /**
     * 获取上传配置
     * GET /admin/attachment/uploadConfig
     */
    public function uploadConfig()
    {
        $maxSize  = (float) Config::get('wise-attachment.upload.max_size', 50);
        $allowedExt = Config::get('wise-attachment.upload.allowed_ext', 'jpg,jpeg,png,gif,pdf,doc,docx,zip,mp4');
        $imageExt = Config::get('wise-attachment.upload.image_ext', 'jpg,jpeg,png,gif,bmp,webp');

        return $this->success('获取成功', [
            'max_size'  => $maxSize * 1024 * 1024,
            'allow_ext' => $allowedExt,
            'image_ext' => $imageExt,
            'chunk_size' => 5 * 1024 * 1024, // 分片大小 5MB
        ]);
    }

    /**
     * 附件列表
     * GET /admin/attachment/getList
     */
    public function getList()
    {
        $params = $this->request->param();
        try {
            validate(AttachmentValidate::class)
                ->scene('list')->check($params);
        } catch (\think\exception\ValidateException $e) {
            return $this->error($e->getError());
        }

        $page       = $params['page'] ?? 1;
        $limit      = $params['limit'] ?? 12;
        $categoryId = $params['category_id'] ?? 0;
        $keyword    = $params['keyword'] ?? '';

        $query = (new AttachmentModel())->search([
            'category_id' => $categoryId,
            'keyword'     => $keyword,
        ]);

        $paginator = $query->paginate([
            'list_rows' => $limit,
            'page'      => $page,
        ]);

        return $this->success('获取成功', $this->formatPaginator($paginator));
    }

    /**
     * 回收站列表（被软删除的附件）
     * GET /admin/attachment/getTrashList
     */
    public function getTrashList()
    {
        $params = $this->request->param();
        $page   = $params['page'] ?? 1;
        $limit  = $params['limit'] ?? 12;
        $keyword = $params['keyword'] ?? '';

        $query = AttachmentModel::onlyTrashed();
        if (!empty($keyword)) {
            $query->where('object_name', 'like', "%{$keyword}%");
        }

        $paginator = $query->order('delete_time', 'desc')->paginate([
            'list_rows' => $limit,
            'page'      => $page,
        ]);

        return $this->success('获取成功', $this->formatPaginator($paginator));
    }

    /**
     * 统一格式化分页数据
     */
    private function formatPaginator($paginator): array
    {
        $items = [];
        foreach ($paginator->items() as $item) {
            $items[] = [
                'id'           => $item->id,
                'name'         => basename($item->object_name),
                'url'          => $item->url,
                'size'         => $item->size,
                'ext'          => $item->getExtension(),
                'is_image'     => $item->is_image,
                'upload_time'  => $item->create_time,
                'mime_type'    => $item->mime_type,
                'object_name'  => $item->object_name,
                'storage'      => $item->storage,
                'delete_time'  => $item->delete_time ?? null,
                'human_size'   => $item->getHumanSize(),
                'icon_class'   => $item->getIconClass(),
            ];
        }

        return [
            'list'         => $items,
            'total'        => $paginator->total(),
            'per_page'     => $paginator->listRows(),
            'current_page' => $paginator->currentPage(),
            'total_page'   => $paginator->lastPage(),
        ];
    }

    /**
     * 删除附件（软删除）
     * POST /admin/attachment/delete
     */
    public function delete()
    {
        $params = $this->request->post();
        try {
            validate(AttachmentValidate::class)
                ->scene('delete')->check($params);
        } catch (\think\exception\ValidateException $e) {
            return $this->error($e->getError());
        }

        $ids = $params['ids'];
        if (is_string($ids)) {
            $ids = array_filter(array_map('intval', explode(',', $ids)));
        }

        if (empty($ids)) {
            return $this->error('请选择要操作的附件');
        }

        $attachments = AttachmentModel::whereIn('id', $ids)->select();
        $deleted = AttachmentModel::destroy($ids);

        foreach ($attachments as $attachment) {
            $this->logAttachmentAction($attachment, 'delete', [
                'detail' => ['object_name' => $attachment->object_name]
            ]);
        }

        return $this->success('已移入回收站', ['deleted' => $deleted]);
    }

    /**
     * 从回收站恢复附件
     * POST /admin/attachment/restore
     */
    public function restore()
    {
        $ids = $this->request->post('ids', '');
        if (is_string($ids)) {
            $ids = array_filter(array_map('intval', explode(',', $ids)));
        }

        if (empty($ids)) {
            return $this->error('请选择要恢复的附件');
        }

        $attachments = AttachmentModel::onlyTrashed()->whereIn('id', $ids)->select();
        $count = 0;
        foreach ($attachments as $attachment) {
            // 手动清除 delete_time 来恢复
            $attachment->delete_time = null;
            if ($attachment->save()) {
                $count++;
                $this->logAttachmentAction($attachment, 'restore');
            }
        }

        return $this->success('已恢复 ' . $count . ' 个附件', ['restored' => $count]);
    }

    /**
     * 永久删除附件（物理删除 + 存储文件删除）
     * POST /admin/attachment/forceDelete
     */
    public function forceDelete()
    {
        $ids = $this->request->post('ids', '');
        if (is_string($ids)) {
            $ids = array_filter(array_map('intval', explode(',', $ids)));
        }

        if (empty($ids)) {
            return $this->error('请选择要永久删除的附件');
        }

        $attachments = AttachmentModel::onlyTrashed()->whereIn('id', $ids)->select();
        if ($attachments->isEmpty()) {
            return $this->error('未找到可永久删除的附件');
        }

        $store  = app(AttachmentStore::class);
        $errors = [];
        $deleted = 0;

        foreach ($attachments as $attachment) {
            // 记录日志
            $this->logAttachmentAction($attachment, 'force_delete', [
                'detail' => ['object_name' => $attachment->object_name]
            ]);

            // 如果是去重复用记录或原文件仍被引用，不要删除存储文件
            $isDuplicate = $attachment->isDuplicate();
            $originalId  = (int)$attachment->original_attachment_id;

            if (!$isDuplicate || $originalId <= 0) {
                // 检查是否还有其他记录引用此文件
                $refCount = AttachmentModel::where('original_attachment_id', $attachment->id)
                    ->where('id', '<>', $attachment->id)
                    ->count();

                if ($refCount === 0) {
                    try {
                        $driver = $store->driver($attachment->storage);
                        $driver->delete($attachment->object_name);
                    } catch (\Throwable $e) {
                        $errors[] = "删除存储文件失败 [{$attachment->id}]: " . $e->getMessage();
                        continue;
                    }
                }
            }

            // 物理删除数据库记录
            $attachment->force()->delete();
            $deleted++;
        }

        $msg = "已永久删除 {$deleted} 个附件";
        if (!empty($errors)) {
            $msg .= '，但部分存储文件删除失败';
        }
        return $this->success($msg, ['deleted' => $deleted, 'errors' => $errors]);
    }

    /**
     * 向下兼容的清空回收站
     * POST /admin/attachment/emptyTrash
     */
    public function emptyTrash()
    {
        $attachments = AttachmentModel::onlyTrashed()->select();
        $ids = $attachments->column('id');
        if (empty($ids)) {
            return $this->success('回收站已空');
        }

        $this->request->withPost(['ids' => implode(',', $ids)]);
        return $this->forceDelete();
    }

    /**
     * 移动附件分类
     * POST /admin/attachment/moveCategory
     */
    public function moveCategory()
    {
        $params = $this->request->post();
        try {
            validate(AttachmentValidate::class)
                ->scene('move')->check($params);
        } catch (\think\exception\ValidateException $e) {
            return $this->error($e->getError());
        }

        $ids = $params['ids'];
        $categoryId = (int) $params['category_id'];

        if (is_string($ids)) {
            $ids = array_filter(array_map('intval', explode(',', $ids)));
        }

        if (empty($ids)) {
            return $this->error('请选择要操作的附件');
        }

        $count = AttachmentModel::whereIn('id', $ids)
            ->update(['category_id' => $categoryId]);

        return $this->success('移动成功', ['count' => $count]);
    }

    /**
     * 获取分类树
     * GET /admin/attachment/categories
     */
    public function categories()
    {
        $categories = AttachmentCategory::getTree();
        return $this->success('获取成功', $categories);
    }

    /**
     * 获取附件详情
     * GET /admin/attachment/info
     */
    public function info()
    {
        $id = $this->request->param('id/d', 0);
        if (!$id) {
            return $this->error('参数错误');
        }

        $attachment = AttachmentModel::find($id);
        if (!$attachment) {
            return $this->error('附件不存在');
        }

        // 记录查看日志
        $this->logAttachmentAction($attachment, 'view');

        return $this->fetch('attachment/detail', [
            'attachment' => $attachment,
        ]);
    }

    /**
     * 获取附件详情 JSON（用于 AJAX）
     * GET /admin/attachment/getInfo
     */
    public function getInfo()
    {
        $id = $this->request->param('id/d', 0);
        if (!$id) {
            return $this->error('参数错误');
        }

        $attachment = AttachmentModel::find($id);
        if (!$attachment) {
            return $this->error('附件不存在');
        }

        $this->logAttachmentAction($attachment, 'view');

        return $this->success('获取成功', [
            'id'           => $attachment->id,
            'name'         => basename($attachment->object_name),
            'url'          => $attachment->url,
            'size'         => $attachment->size,
            'human_size'   => $attachment->getHumanSize(),
            'ext'          => $attachment->getExtension(),
            'is_image'     => $attachment->is_image,
            'upload_time'  => $attachment->create_time,
            'mime_type'    => $attachment->mime_type,
            'object_name'  => $attachment->object_name,
            'storage'      => $attachment->storage,
            'image_width'  => $attachment->image_width,
            'image_height' => $attachment->image_height,
            'is_duplicate' => $attachment->isDuplicate(),
            'original_id'  => $attachment->original_attachment_id,
            'icon_class'   => $attachment->getIconClass(),
            'version_count'=> AttachmentVersion::where('attachment_id', $id)->count(),
            'log_count'    => AttachmentLog::where('attachment_id', $id)->count(),
        ]);
    }

    /**
     * 下载附件
     * GET /admin/attachment/download?id=X
     */
    public function download()
    {
        $id = $this->request->param('id/d', 0);
        if (!$id) {
            return $this->error('参数错误');
        }

        $attachment = AttachmentModel::find($id);
        if (!$attachment) {
            return $this->error('附件不存在');
        }

        try {
            $store  = app(AttachmentStore::class);
            $driver = $store->driver($attachment->storage);

            // 本地存储：直接读取文件流
            if ($attachment->storage === 'local') {
                $config = Config::get('wise-attachment.drivers.local', []);
                $rootPath = $config['root_path'] ?? (root_path() . 'public' . DIRECTORY_SEPARATOR . 'uploads');
                $filePath = rtrim($rootPath, '/\\') . DIRECTORY_SEPARATOR . ltrim($attachment->object_name, '/\\');

                if (!file_exists($filePath)) {
                    return $this->error('文件不存在');
                }

                // 读取文件大小用于 Content-Length，避免分块传输
                $filesize = filesize($filePath);
                $filename = basename($attachment->object_name);

                // FPM + Swoole 兼容：使用 response->sendfile() 替代 header()+readfile()+exit
                $this->logAttachmentAction($attachment, 'download');

                return response('', 200, [
                    'Content-Type'        => $attachment->mime_type ?: 'application/octet-stream',
                    'Content-Disposition' => 'attachment; filename="' . rawurlencode($filename) . '"',
                    'Content-Length'      => (string) $filesize,
                    'Cache-Control'       => 'no-cache, must-revalidate',
                    'Pragma'              => 'no-cache',
                    'Expires'             => '0',
                ])->sendfile($filePath);
            }

            // 云存储：使用驱动获取临时 URL 然后重定向
            $tempUrl = $driver->temporaryUrl($attachment->object_name, 3600, [
                'download' => basename($attachment->object_name),
            ]);
            $this->logAttachmentAction($attachment, 'download');
            return redirect($tempUrl);

        } catch (\Throwable $e) {
            return $this->error('下载失败: ' . $e->getMessage());
        }
    }

    /**
     * 预览附件（内联展示）
     * GET /admin/attachment/preview?id=X
     */
    public function preview()
    {
        $id = $this->request->param('id/d', 0);
        if (!$id) {
            return $this->error('参数错误');
        }

        $attachment = AttachmentModel::find($id);
        if (!$attachment) {
            return $this->error('附件不存在');
        }

        $this->logAttachmentAction($attachment, 'view');

        return $this->fetch('attachment/preview', [
            'attachment' => $attachment,
        ]);
    }

    // ===================== 批量下载 =====================

    /**
     * 批量下载附件（ZIP 打包）
     * POST /admin/attachment/batchDownload
     * @param string ids 附件ID列表（逗号分隔）
     */
    public function batchDownload()
    {
        $ids = $this->request->post('ids', '');
        if (is_string($ids)) {
            $ids = array_filter(array_map('intval', explode(',', $ids)));
        }

        if (empty($ids)) {
            return $this->error('请选择要下载的附件');
        }

        if (count($ids) > 50) {
            return $this->error('单次最多下载 50 个文件');
        }

        $attachments = AttachmentModel::whereIn('id', $ids)->select();
        if ($attachments->isEmpty()) {
            return $this->error('未找到指定的附件');
        }
        if ($attachments->count() === 1) {
            // 单个文件直接下载
            return redirect('/admin/attachment/download?id=' . $attachments[0]->id);
        }

        // 生成 ZIP 文件
        $zipFileName = 'attachments_' . date('YmdHis') . '.zip';
        $zipPath = runtime_path() . 'downloads' . DIRECTORY_SEPARATOR;
        if (!is_dir($zipPath)) {
            mkdir($zipPath, 0755, true);
        }
        $zipFile = $zipPath . $zipFileName;

        $zip = new \ZipArchive();
        if ($zip->open($zipFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return $this->error('无法创建 ZIP 文件');
        }

        $errors = [];
        $added  = 0;
        foreach ($attachments as $attachment) {
            try {
                if ($attachment->storage === 'local') {
                    $config = Config::get('wise-attachment.drivers.local', []);
                    $rootPath = $config['root_path'] ?? (root_path() . 'public' . DIRECTORY_SEPARATOR . 'uploads');
                    $filePath = rtrim($rootPath, '/\\') . DIRECTORY_SEPARATOR . ltrim($attachment->object_name, '/\\');

                    if (file_exists($filePath)) {
                        $entryName = basename($attachment->object_name);
                        // 处理重名：在文件名前加序号
                        $baseName = $entryName;
                        $counter = 1;
                        while ($zip->locateName($entryName) !== false) {
                            $info = pathinfo($baseName);
                            $entryName = $info['filename'] . '_' . $counter . '.' . ($info['extension'] ?? '');
                            $counter++;
                        }
                        $zip->addFile($filePath, $entryName);
                        $added++;
                        $this->logAttachmentAction($attachment, 'download', [
                            'detail' => ['batch' => true]
                        ]);
                    } else {
                        $errors[] = basename($attachment->object_name) . ' — 文件不存在';
                    }
                } else {
                    // 云存储：下载到临时目录后加入 ZIP
                    $store  = app(AttachmentStore::class);
                    $driver = $store->driver($attachment->storage);
                    $tempUrl = $driver->temporaryUrl($attachment->object_name, 300);
                    $tmpContent = @file_get_contents($tempUrl);
                    if ($tmpContent !== false) {
                        $entryName = basename($attachment->object_name);
                        $baseName = $entryName;
                        $counter = 1;
                        while ($zip->locateName($entryName) !== false) {
                            $info = pathinfo($baseName);
                            $entryName = $info['filename'] . '_' . $counter . '.' . ($info['extension'] ?? '');
                            $counter++;
                        }
                        $zip->addFromString($entryName, $tmpContent);
                        $added++;
                    } else {
                        $errors[] = basename($attachment->object_name) . ' — 下载失败';
                    }
                }
            } catch (\Throwable $e) {
                $errors[] = basename($attachment->object_name) . ' — ' . $e->getMessage();
            }
        }

        $zip->close();

        if ($added === 0) {
            @unlink($zipFile);
            return $this->error('没有文件可下载');
        }

        // 输出 ZIP 文件 — FPM + Swoole 兼容：使用 sendfile() 替代 header()+readfile()+exit
        return response('', 200, [
            'Content-Type'        => 'application/zip',
            'Content-Disposition' => 'attachment; filename="' . $zipFileName . '"',
            'Content-Length'      => (string) filesize($zipFile),
            'Cache-Control'       => 'no-cache',
        ])->sendfile($zipFile);
    }

    // ===================== 版本管理 =====================

    /**
     * 获取版本历史
     * GET /admin/attachment/versions?id=X
     */
    public function versions()
    {
        $id = $this->request->param('id/d', 0);
        if (!$id) {
            return $this->error('参数错误');
        }

        $attachment = AttachmentModel::find($id);
        if (!$attachment) {
            return $this->error('附件不存在');
        }

        $versions = AttachmentVersion::where('attachment_id', $id)
            ->order('version_number', 'desc')
            ->select()
            ->toArray();

        $currentVersion = $attachment->getCurrentVersion();

        // 格式化版本列表
        $list = array_map(function ($v) use ($currentVersion) {
            return [
                'id'             => $v['id'],
                'version_number' => $v['version_number'],
                'object_name'    => $v['object_name'],
                'url'            => $v['url'],
                'size'           => $v['size'],
                'mime_type'      => $v['mime_type'],
                'operator_id'    => $v['operator_id'],
                'note'           => $v['note'],
                'create_time'    => $v['create_time'],
                'is_current'     => $v['version_number'] == $currentVersion,
                'human_size'     => $this->formatBytes($v['size']),
            ];
        }, $versions);

        return $this->success('获取成功', [
            'current_version' => $currentVersion,
            'versions'        => $list,
        ]);
    }

    /**
     * 回滚到指定版本
     * POST /admin/attachment/rollback
     * @param id            int  附件ID
     * @param version_number int  目标版本号
     */
    public function rollback()
    {
        $id            = $this->request->post('id/d', 0);
        $versionNumber = $this->request->post('version_number/d', 0);

        if (!$id || !$versionNumber) {
            return $this->error('参数错误');
        }

        $attachment = AttachmentModel::find($id);
        if (!$attachment) {
            return $this->error('附件不存在');
        }

        $version = AttachmentVersion::where('attachment_id', $id)
            ->where('version_number', $versionNumber)
            ->find();

        if (!$version) {
            return $this->error('指定版本不存在');
        }

        if ($attachment->getCurrentVersion() === $versionNumber) {
            return $this->error('已经是当前版本');
        }

        $success = $attachment->rollbackToVersion($versionNumber);
        if ($success) {
            $this->logAttachmentAction($attachment, 'update', [
                'detail' => ['action' => 'rollback', 'from_version' => $attachment->getCurrentVersion(), 'to_version' => $versionNumber]
            ]);
            return $this->success('已回滚到版本 v' . $versionNumber);
        }

        return $this->error('回滚失败');
    }

    // ===================== 操作日志 =====================

    /**
     * 获取附件操作日志
     * GET /admin/attachment/logs?id=X
     */
    public function logs()
    {
        $id = $this->request->param('id/d', 0);
        $page  = $this->request->param('page/d', 1);
        $limit = $this->request->param('limit/d', 20);

        $query = AttachmentLog::order('id', 'desc');
        if ($id > 0) {
            $query->where('attachment_id', $id);
        }

        $paginator = $query->paginate([
            'list_rows' => $limit,
            'page'      => $page,
        ]);

        $items = [];
        foreach ($paginator->items() as $log) {
            $items[] = [
                'id'             => $log->id,
                'attachment_id'  => $log->attachment_id,
                'action'         => $log->action,
                'action_label'   => $this->getActionLabel($log->action),
                'operator_id'    => $log->operator_id,
                'operator_type'  => $log->operator_type,
                'operator_name'  => $log->operator_name,
                'ip'             => $log->ip,
                'detail'         => $log->detail ? json_decode($log->detail, true) : null,
                'create_time'    => $log->create_time,
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
     * 获取操作类型的中文标签
     */
    private function getActionLabel(string $action): string
    {
        $labels = [
            'upload'       => '上传',
            'delete'       => '删除',
            'update'       => '更新',
            'download'     => '下载',
            'view'         => '查看',
            'copy'         => '复制',
            'move'         => '移动',
            'restore'      => '恢复',
            'force_delete' => '永久删除',
            'rollback'     => '版本回滚',
        ];
        return $labels[$action] ?? $action;
    }

    /**
     * 格式化字节数
     */
    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow   = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow   = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}
