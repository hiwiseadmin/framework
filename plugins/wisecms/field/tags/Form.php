<?php
namespace plugin\wisecms\field\tags;
use plugin\wisecms\field\base\FieldBase;
class Form extends FieldBase {
    public function getFormHtml(array $f, $v=''): string {
        $sv = htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        return "<input type=\"text\" name=\"{$f['field']}\" value=\"{$sv}\" class=\"form-control\" placeholder=\"逗号分隔多个标签\">";
    }
    public function getValidateRules(array $f): array { return []; }
}
