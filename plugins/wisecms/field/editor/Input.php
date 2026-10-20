<?php
namespace plugin\wisecms\field\editor;

use plugin\wisecms\field\base\FieldBase;
use plugin\wisecms\service\content\HtmlSanitizer;

class Input extends FieldBase
{
    /**
     * 输入处理（入库前）
     */
    public function inputFilter($value, array $fieldInfo = [])
    {
        // UIUX-P0-31：setting 已由 FieldRegistry 归一化为数组，禁止直接 json_decode（PHP 8 抛 TypeError）
        $setting = self::normalizeSetting($fieldInfo['setting'] ?? $this->fieldInfo['setting'] ?? []);

        // 是否保存远程图片
        $enableSaveImage = $setting['enablesaveimage'] ?? 0;

        if ($enableSaveImage) {
            // UIUX-P1-06：本地化实现已收敛到 AttachmentService（与「正文远程图片本地化」共用，
            // 含 SSRF 防护与单引号 src 的兼容）
            $value = app('wisecms.attach')->localizeRemoteImages((string) $value);
        }

        // XSS 过滤（保留基本 HTML）
        // P0-4：净化逻辑已收敛到 HtmlSanitizer，主表正文 / 单页正文共用同一白名单，
        // 避免「自定义字段净化、主字段不净化」的层间不一致。
        return HtmlSanitizer::clean((string) $value);
    }
}
