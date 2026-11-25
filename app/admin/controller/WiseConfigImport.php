<?php
declare(strict_types=1);

namespace app\admin\controller;

use app\admin\controller\traits\WiseConfigTrait;
use wise\config\model\ConfigModel;

/**
 * 配置导入/导出控制器
 *
 * 路由: admin/wise_config/import/*
 * 实际使用 admin/wise_config/export 等旧路由（通过路由映射保持兼容）
 */
class WiseConfigImport extends BaseController
{
    use WiseConfigTrait;

    protected function _initialize()
    {
        $this->initWiseConfig();
    }

    /**
     * 导出指定命名空间的配置
     */
    public function export()
    {
        $namespace = (string) $this->request->param('namespace', 'default');

        if (!preg_match('/^[A-Za-z0-9_.\-]{1,100}$/', $namespace)) {
            return $this->error('命名空间不合法');
        }

        $configs = ConfigModel::where('namespace', $namespace)
            ->where('visibility', 1)
            ->order('group', 'asc')
            ->order('sort', 'asc')
            ->select()
            ->visible(['key', 'value', 'type', 'group', 'title', 'description', 'sort'])
            ->toArray();

        $result = [
            'namespace'   => $namespace,
            'exported_at' => date('Y-m-d H:i:s'),
            'configs'     => $configs,
        ];

        $filename = 'wiseconfig-' . $namespace . '.json';
        $filepath = app()->getRuntimePath() . 'wiseconfig-' . $namespace . '-' . bin2hex(random_bytes(6)) . '.json';

        file_put_contents($filepath, json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return download($filepath, $filename);
    }

    /**
     * 复制配置到另一个命名空间
     */
    public function copyToNamespace()
    {
        $id       = $this->request->post('id/d', 0);
        $targetNs = $this->request->post('target_namespace', '');

        $source = ConfigModel::find($id);
        if (!$source) {
            return $this->error('源配置不存在');
        }
        if (empty($targetNs)) {
            return $this->error('目标命名空间不能为空');
        }
        if (!preg_match('/^[A-Za-z0-9_.\-]{1,100}$/', (string) $targetNs)) {
            return $this->error('目标命名空间不合法');
        }

        $exists = ConfigModel::where('namespace', $targetNs)
            ->where('key', $source->key)
            ->find();
        if ($exists) {
            return $this->error("配置 '{$source->key}' 在命名空间 '{$targetNs}' 中已存在");
        }

        $extra = [
            'type'        => $source->type,
            'group'       => $source->group,
            'title'       => $source->title,
            'description' => $source->description,
            'inherit'     => $source->inherit,
            'sort'        => $source->sort,
            'status'      => $source->status,
            'visibility'  => $source->visibility ?? 1,
        ];

        $path = $targetNs . ':' . $source->group . ':' . $source->key;
        if ($this->configManager->namespace($targetNs)->set($path, $source->value, $extra)) {
            return $this->success("已复制到 '{$targetNs}'");
        }
        return $this->error('复制失败');
    }

    /**
     * 导入配置（JSON格式）
     */
    public function importConfig()
    {
        $namespace = (string) $this->request->post('namespace', 'default');

        if (!preg_match('/^[A-Za-z0-9_.\-]{1,100}$/', $namespace)) {
            return $this->error('命名空间不合法');
        }

        $jsonText  = $this->request->post('json_text', '');

        if (empty($jsonText)) {
            $file = $this->request->file('json_file');
            if ($file) {
                $jsonText = file_get_contents($file->getPathname());
            }
        }

        if (empty($jsonText)) {
            return $this->error('请提供导入数据');
        }

        $data = json_decode($jsonText, true);
        if (!$data) {
            return $this->error('JSON解析失败');
        }

        $configs = $data['configs'] ?? $data;
        if (!is_array($configs)) {
            return $this->error('数据格式错误');
        }

        $successCount = 0;
        $skipCount    = 0;
        $errors       = [];

        $allowedTypes = ['text', 'number', 'html', 'image', 'json'];

        foreach ($configs as $cfg) {
            if (!is_array($cfg)) {
                continue;
            }

            $key = (string) ($cfg['key'] ?? '');
            if ($key === '' || !preg_match('/^[a-zA-Z0-9_\-]+$/', $key)) {
                $errors[] = ($key !== '' ? $key : '?') . ': 键名不合法';
                continue;
            }

            $group = (string) ($cfg['group'] ?? 'system');
            if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $group)) {
                $errors[] = $key . ': 分组名不合法';
                continue;
            }

            $type = (string) ($cfg['type'] ?? 'text');
            if (!in_array($type, $allowedTypes, true)) {
                $type = 'text';
            }

            $exists = ConfigModel::where('namespace', $namespace)
                ->where('key', $key)
                ->find();
            if ($exists) {
                $skipCount++;
                continue;
            }

            try {
                $path  = $namespace . ':' . $group . ':' . $key;
                $extra = [
                    'type'        => $type,
                    'group'       => $group,
                    'title'       => (string) ($cfg['title'] ?? ''),
                    'description' => (string) ($cfg['description'] ?? ''),
                    'inherit'     => 1,
                    'sort'        => (int) ($cfg['sort'] ?? 0),
                    'status'      => 1,
                    'visibility'  => 1,
                ];
                $this->configManager->namespace($namespace)->set($path, $cfg['value'] ?? '', $extra);
                $successCount++;
            } catch (\Exception $e) {
                $errors[] = $key . ': ' . $e->getMessage();
            }
        }

        return $this->success("导入完成：成功 {$successCount}，跳过 {$skipCount}" .
            (empty($errors) ? '' : '，错误 ' . count($errors)));
    }

    /**
     * 上传配置图片
     */
    public function uploadImage()
    {
        $file = $this->request->file('file');
        if (!$file) {
            return $this->error('请选择文件');
        }

        $extensions = (string) config('wise-config.image_extensions', 'jpg,jpeg,png,gif,webp,bmp');
        $maxSize    = (int) config('wise-config.image_max_size', 2048);

        $allowed = [];
        foreach (explode(',', $extensions) as $item) {
            $item = strtolower(trim($item));
            if ($item !== '') {
                $allowed[] = $item;
            }
        }
        $ext = strtolower((string) pathinfo($file->getOriginalName(), PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) {
            return $this->error('不允许的图片类型：' . $ext);
        }

        if ($maxSize > 0 && $file->getSize() > $maxSize * 1024) {
            return $this->error('图片超过大小限制：' . $maxSize . 'KB');
        }

        if (@getimagesize($file->getPathname()) === false) {
            return $this->error('不是有效的图片文件');
        }

        $uploadPath = config('wise-config.upload_path', 'uploads/config/');
        $disk       = config('wise-config.upload_disk', 'public');

        try {
            $savename = \think\facade\Filesystem::disk($disk)->putFile($uploadPath, $file);
            $fullUrl  = \think\facade\Filesystem::disk($disk)->url($savename);
            return json([
                'code' => 0,
                'msg'  => '上传成功',
                'data' => [
                    'path' => $savename,
                    'url'  => $fullUrl,
                ],
            ]);
        } catch (\Exception $e) {
            return $this->error('上传失败: ' . $e->getMessage());
        }
    }
}
