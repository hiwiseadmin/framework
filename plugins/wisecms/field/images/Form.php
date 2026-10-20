<?php
namespace plugin\wisecms\field\images;

use plugin\wisecms\field\base\FieldBase;

class Form extends FieldBase
{
    public function getFormHtml(array $f, $v = ''): string
    {
        $required = $f['is_required'] ? 'required' : '';
        $html = '<input type="file" name="' . $f['field'] . '[]" class="form-control" multiple ' . $required . '>';

        // 显示已上传的图片缩略图
        if ($v) {
            $thumbSize = (int) ($f['setting']['thumb_size'] ?? 80);
            $images = is_array($v) ? $v : explode(',', $v);
            $html .= '<div class="mt-2 d-flex flex-wrap gap-2">';
            foreach ($images as $img) {
                $safeImg = htmlspecialchars(trim($img), ENT_QUOTES, 'UTF-8');
                if ($safeImg !== '') {
                    $html .= '<div class="position-relative"><img src="' . $safeImg . '" style="width:' . $thumbSize . 'px;height:' . $thumbSize . 'px;object-fit:cover" class="border rounded"><input type="hidden" name="' . $f['field'] . '_existing[]" value="' . $safeImg . '"></div>';
                }
            }
            $html .= '</div>';
        }

        return $html;
    }

    public function getValidateRules(array $f): array
    {
        return $f['is_required'] ? ['require'] : [];
    }
}
