<?php
namespace plugin\wisecms\field\toggle;
use plugin\wisecms\field\base\FieldBase;
class Form extends FieldBase {
    public function getFormHtml(array $f, $v=''): string {
        $checked = $v ? 'checked' : '';
        return "<div class=\"form-check form-switch\"><input type=\"checkbox\" name=\"{$f['field']}\" value=\"1\" class=\"form-check-input\" {$checked}></div>";
    }
    public function getValidateRules(array $f): array { return []; }
}
