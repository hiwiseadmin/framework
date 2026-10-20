<?php
namespace plugin\shortlink\service;

use plugin\shortlink\model\Link;

class CodeGenerator
{
    public function generate(int $length = 6, ?string $customCode = null): string
    {
        if ($customCode !== null) {
            $code = trim($customCode);
            if (empty($code)) {
                throw new \RuntimeException('自定义短码不能为空');
            }
            if (!preg_match('/^[a-zA-Z0-9_-]+$/', $code)) {
                throw new \RuntimeException('自定义短码只能包含字母、数字、下划线或中划线');
            }
            if ($this->codeExists($code)) {
                throw new \RuntimeException('该短码已被使用');
            }
            return $code;
        }

        $maxAttempts = 10;
        for ($i = 0; $i < $maxAttempts; $i++) {
            $code = shortlink_random_code($length);
            if (!$this->codeExists($code)) {
                return $code;
            }
        }

        $last = Link::order('id', 'desc')->find();
        $seed = $last ? shortlink_base62_decode($last->code) + 1 : 1;
        for ($i = 0; $i < 100; $i++) {
            $code = shortlink_base62_encode($seed + $i, $length);
            if (!$this->codeExists($code)) {
                return $code;
            }
        }
        throw new \RuntimeException('无法生成唯一短码');
    }

    public function codeExists(string $code): bool
    {
        // 使用 find() 替代 count()，找到第一条记录即停止扫描，效率更高
        return Link::where('code', $code)->find() !== null;
    }
}
