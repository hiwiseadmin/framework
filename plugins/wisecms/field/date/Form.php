<?php
namespace plugin\wisecms\field\date;
use plugin\wisecms\field\base\FieldBase;
class Form extends FieldBase {
    public function getFormHtml(array $f, $v=''): string {
        $r = $f['is_required'] ? 'required' : '';
        return "<input type=\"date\" name=\"{$f['field']}\" value=\"{$v}\" class=\"form-control\" {$r}>";
    }
    public function getValidateRules(array $f): array {
        return $f['is_required'] ? ['require','date'] : ['date'];
    }
}
