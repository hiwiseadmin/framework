<?php
namespace plugin\wisecms\field\radio;

use plugin\wisecms\field\base\FieldBase;

class Form extends FieldBase
{
    public function getFormHtml(array $f, $v = ''): string
    {
        $opts = self::normalizeOptions($f['setting']['options'] ?? []);
        $html = '';
        foreach ($opts as $k => $val) {
            $checked = ((string)$v === (string)$k) ? 'checked' : '';
            $safeVal = htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
            $html .= '<div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="' . $f['field'] . '" value="' . $k . '" ' . $checked . '><label class="form-check-label">' . $safeVal . '</label></div>';
        }
        return $html;
    }

    public function getValidateRules(array $f): array
    {
        return $f['is_required'] ? ['require'] : [];
    }
}
