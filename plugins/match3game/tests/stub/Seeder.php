<?php
/**
 * 测试替身：think\migration\Seeder
 *
 * 项目未引入 PHPUnit，verify-level-schema.php 需要在无框架环境下实例化 Seed
 * 子类来取 buildLevels() 的纯数据。这里给出最小替身，仅用于离线自检。
 */

declare(strict_types=1);

namespace think\migration;

class Seeder
{
    /** 最近一次 insert 的行 */
    public array $rows = [];

    public function table(string $name)
    {
        return $this;
    }

    public function insert($rows)
    {
        $this->rows = $rows;
        return $this;
    }

    public function save()
    {
        return $this;
    }
}
