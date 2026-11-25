<?php
declare(strict_types=1);

namespace wise\plugin\tests;

use PHPUnit\Framework\TestCase;
use wise\plugin\PluginManager;

/**
 * 插件标识判定回归（Web 与 CLI 必须一致）。
 *
 * 背景（2026-11-08）：历史上后台 Web 安装走 `checkPluginIdent()`（旧规则，
 * 且把 `member` 误列入保留词），CLI `plugin:install` 走 `validatePluginName()`
 * （正则规则），导致「命令行能装、后台报插件标识不符合规范」的分裂。
 *
 * 本轮统一为 `checkPluginIdent()` 单一实现，本测试锁死以下不变量：
 *   1. `member`（官方会员插件）必须合法 —— 它只是插件目录名，不与应用名冲突；
 *   2. `checkPluginIdent()` 与 `validatePluginName()` 对同一标识给出相同判定；
 *   3. 系统应用名（admin/api/...）仍被保留词拦截；
 *   4. 路径穿越、过短标识被拦截；连字符/下划线被允许。
 */
class PluginIdentTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function identCases(): array
    {
        return [
            'member（官方插件，必须放行）' => ['member', true],
            'demo'                       => ['demo', true],
            'wisecms'                    => ['wisecms', true],
            '带连字符'                     => ['my-plugin', true],
            '带下划线'                     => ['my_plugin', true],
            '数字开头合法'                   => ['2fa', true],
            '保留词 admin'                 => ['admin', false],
            '保留词 api'                   => ['api', false],
            '保留词 common'                => ['common', false],
            '保留词 plugin'                => ['plugin', false],
            '大写 ADMIN 也拦截'             => ['ADMIN', false],
            '过短（单字符）'                 => ['a', false],
            '路径穿越'                      => ['../etc/passwd', false],
            '含点号'                       => ['wise.auth', false],
            '含空格'                       => ['my plugin', false],
        ];
    }

    /**
     * @dataProvider identCases
     */
    public function testCheckPluginIdent(string $ident, bool $expected): void
    {
        self::assertSame($expected, PluginManager::checkPluginIdent($ident), "checkPluginIdent('{$ident}')");
    }

    /**
     * @dataProvider identCases
     */
    public function testValidatePluginNameAgreesWithCheckPluginIdent(string $ident, bool $expected): void
    {
        $threw = false;

        try {
            PluginManager::validatePluginName($ident);
        } catch (\Throwable) {
            $threw = true;
        }

        self::assertSame(!$expected, $threw, "validatePluginName('{$ident}') 抛异常与否应与 checkPluginIdent 相反");
    }
}
