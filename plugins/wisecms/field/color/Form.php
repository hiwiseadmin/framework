<?php
namespace plugin\wisecms\field\color;
use plugin\wisecms\field\base\FieldBase;
class Form extends FieldBase {
    public function getFormHtml(array $f, $v=''): string {
        $v = $v ?: '#000000';
        return "<input type=\"color\" name=\"{$f['field']}\" value=\"{$v}\" class=\"form-control form-control-color\" style=\"width:80px\">";
    }
    public function getValidateRules(array $f): array { return []; }
}
