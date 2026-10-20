<?php
namespace plugin\wisecms\field\image;
use plugin\wisecms\field\base\FieldBase;
class Form extends FieldBase {
    public function getFormHtml(array $f, $v=''): string {
        $html = "<div class=\"input-group\">";
        $html .= "<input type=\"text\" name=\"{$f['field']}\" value=\"{$v}\" class=\"form-control\" placeholder=\"图片URL或上传\">";
        $html .= "<button type=\"button\" class=\"btn btn-outline-secondary\" onclick=\"openUpload('{$f['field']}')\">上传</button>";
        $html .= "</div>";
        $previewHeight = (int) ($f['setting']['preview_height'] ?? 150);
        if ($v) $html .= "<img src=\"{$v}\" class=\"mt-2 img-thumbnail\" style=\"max-height:{$previewHeight}px\">";
        return $html;
    }
    public function getValidateRules(array $f): array { return []; }
}
