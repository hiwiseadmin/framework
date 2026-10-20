<?php
namespace plugin\wisecms\field\datetime;
use plugin\wisecms\field\base\FieldBase;
class Form extends FieldBase {
    public function getFormHtml(array $f, $v=''): string {
        $r = $f['is_required'] ? 'required' : '';
        if (is_numeric($v) && $v > 0) $v = date('Y-m-d\TH:i', $v);
        $sv = htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        return "<input type=\"datetime-local\" name=\"{$f['field']}\" value=\"{$sv}\" class=\"form-control\" {$r}>";
    }
    public function getValidateRules(array $f): array {
        return $f['is_required'] ? ['require'] : [];
    }
}
