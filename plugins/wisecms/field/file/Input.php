<?php
namespace plugin\wisecms\field\file;

use plugin\wisecms\exception\ErrorCode;
use plugin\wisecms\exception\WisecmsException;
use plugin\wisecms\field\base\FieldBase;

class Input extends FieldBase
{
    /**
     * 输入处理：单文件上传
     * 保存文件 → 返回相对路径
     */
    public function inputFilter($value, array $fieldInfo = [])
    {
        $fieldName = $fieldInfo['field'] ?? $this->fieldInfo['field'] ?? '';

        // 保留已有文件路径
        $existing = $this->value ?? '';

        $file = request()->file($fieldName);
        if (!$file) {
            return $existing;
        }

        // 扩展名白名单
        $allowed = $fieldInfo['setting']['allowed_ext'] ?? $this->fieldInfo['setting']['allowed_ext'] ?? '';
        if ($allowed) {
            $exts = array_map('trim', explode(',', $allowed));
            if (!in_array(strtolower($file->extension()), $exts)) {
                throw new WisecmsException('不支持的文件类型，仅允许: ' . $allowed, ErrorCode::VALIDATE_FAILED);
            }
        }

        try {
            $info = $file->move(public_path('uploads/files'), md5(uniqid((string)mt_rand(), true)) . '.' . $file->extension());
            return 'uploads/files/' . $info->getFilename();
        } catch (\Exception $e) {
            return $existing;
        }
    }
}
