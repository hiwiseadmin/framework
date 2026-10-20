<?php
namespace plugin\wisecms\field\select;
use plugin\wisecms\field\base\FieldBase;
class Form extends FieldBase {
    public function getFormHtml(array $f, $v=''): string {
        $r = $f['is_required'] ? 'required' : '';
        $opts = self::normalizeOptions($f['setting']['options'] ?? []);
        $html = "<select name=\"{$f['field']}\" class=\"form-select\" {$r}><option value=\"\">请选择</option>";
        foreach ($opts as $k => $val) {
            $sel = ($v == $k) ? 'selected' : '';
            $safeVal = htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
            $html .= "<option value=\"{$k}\" {$sel}>{$safeVal}</option>";
        }
        $html .= "</select>";
        return $html;
    }
    public function getValidateRules(array $f): array {
        return $f['is_required'] ? ['require'] : [];
    }
}
