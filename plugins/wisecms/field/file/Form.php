<?php
namespace plugin\wisecms\field\file;

use plugin\wisecms\field\base\FieldBase;

class Form extends FieldBase
{
    public function getFormHtml(array $f, $v = ''): string
    {
        $required  = $f['is_required'] ? 'required' : '';
        $ext       = $f['setting']['allowed_ext'] ?? '';
        $accept    = $ext ? 'accept=".' . str_replace(',', ',.', $ext) . '"' : '';
        $html      = '<input type="file" name="' . $f['field'] . '" class="form-control" ' . $required . ' ' . $accept . '>';

        // 显示已有文件链接
        if ($v) {
            $safeUrl  = htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
            $html .= '<div class="mt-2"><a href="' . $safeUrl . '" target="_blank" class="btn btn-sm btn-outline-secondary">查看已上传文件</a></div>';
        }

        return $html;
    }

    public function getValidateRules(array $f): array
    {
        return $f['is_required'] ? ['require'] : [];
    }
}
