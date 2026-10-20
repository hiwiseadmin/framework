<?php
namespace plugin\wisecms\field\images;

use plugin\wisecms\field\base\FieldBase;

class Input extends FieldBase
{
    /**
     * 输入处理：多图上传
     * ① 新上传文件保存并获取 URL
     * ② 与 _existing 旧值合并
     * ③ 输出逗号分隔字符串
     */
    public function inputFilter($value, array $fieldInfo = [])
    {
        $fieldName = $fieldInfo['field'] ?? $this->fieldInfo['field'] ?? '';

        // 获取已有图片
        $existing = [];
        if (isset($this->data[$fieldName . '_existing'])) {
            $existing = (array)$this->data[$fieldName . '_existing'];
        }

        // 处理新上传
        $newUrls = $this->handleUploads($fieldName);

        return implode(',', array_filter(array_merge($existing, $newUrls)));
    }

    /**
     * 处理上传文件
     */
    private function handleUploads(string $fieldName): array
    {
        $files = request()->file($fieldName);
        if (empty($files)) {
            return [];
        }

        $urls = [];
        foreach ($files as $file) {
            try {
                $info = $file->move(public_path('uploads/images'), md5(uniqid((string)mt_rand(), true)) . '.' . $file->extension());
                $urls[] = 'uploads/images/' . $info->getFilename();
            } catch (\Exception $e) {
                continue;
            }
        }
        return $urls;
    }
}
