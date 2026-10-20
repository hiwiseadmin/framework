<?php
namespace plugin\wisecms\field\number;
use plugin\wisecms\field\base\FieldBase;
class Form extends FieldBase {
    public function getFormHtml(array $f, $v=''): string {
        $r = $f['is_required'] ? 'required' : '';
        $min = isset($f['setting']['min']) ? "min=\"{$f['setting']['min']}\"" : '';
        $max = isset($f['setting']['max']) ? "max=\"{$f['setting']['max']}\"" : '';
        $sv = htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        return "<input type=\"number\" name=\"{$f['field']}\" value=\"{$sv}\" class=\"form-control\" {$r} {$min} {$max}>";
    }
    public function getValidateRules(array $f): array {
        $r = [];
        if ($f['is_required']) $r[] = 'require';
        if (isset($f['setting']['min'])) $r[] = "min:{$f['setting']['min']}";
        if (isset($f['setting']['max'])) $r[] = "max:{$f['setting']['max']}";
        return $r;
    }
}
