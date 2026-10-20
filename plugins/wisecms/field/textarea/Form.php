<?php
namespace plugin\wisecms\field\textarea;
use plugin\wisecms\field\base\FieldBase;
class Form extends FieldBase {
    public function getFormHtml(array $f, $v=''): string {
        $r = $f['is_required'] ? 'required' : '';
        $p = $f['tips'] ?? "请输入{$f['name']}";
        $rows = ($f['setting']['rows'] ?? 5);
        $sv = htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        return "<textarea name=\"{$f['field']}\" class=\"form-control\" {$r} rows=\"{$rows}\" placeholder=\"{$p}\">{$sv}</textarea>";
    }
    public function getValidateRules(array $f): array {
        $r = [];
        if ($f['is_required']) $r[] = 'require';
        return $r;
    }
}
