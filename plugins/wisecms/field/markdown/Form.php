<?php
namespace plugin\wisecms\field\markdown;
use plugin\wisecms\field\base\FieldBase;
class Form extends FieldBase {
    public function getFormHtml(array $f, $v=''): string {
        $r = $f['is_required'] ? 'required' : '';
        $h = $f['setting']['height'] ?? 400;
        $sv = htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        return "<textarea name=\"{$f['field']}\" class=\"form-control markdown-editor\" {$r} style=\"height:{$h}px\">{$sv}</textarea>";
    }
    public function getValidateRules(array $f): array {
        return $f['is_required'] ? ['require'] : [];
    }
}
