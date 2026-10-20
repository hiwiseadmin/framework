<?php
declare(strict_types=1);

namespace wise\crud\tests;

use wise\crud\CommentOptionParser;

/**
 * 宽松注释解析 —— 必须覆盖本项目存量的四种写法（全部不含 `=`）。
 */
class CommentOptionParserTest extends CrudTestCase
{
    public function testHandlesLegacyFormatWithHalfWidthComma(): void
    {
        $this->assertSame(
            ['0' => '禁用', '1' => '启用'],
            CommentOptionParser::parse('状态 0:禁用,1:启用')
        );
    }

    public function testHandlesLegacyFormatWithSemicolon(): void
    {
        $this->assertSame(
            ['0' => '否', '1' => '是'],
            CommentOptionParser::parse('是否默认 0:否;1:是')
        );
    }

    public function testHandlesLegacyFormatWithSpaceSeparator(): void
    {
        $this->assertSame(
            ['0' => '免费', '1' => '收费'],
            CommentOptionParser::parse('是否收费 0:免费 1:收费')
        );
    }

    public function testHandlesLegacyFormatWithFullWidthCommaAndThreeValues(): void
    {
        $this->assertSame(
            ['0' => '禁用', '1' => '启用', '2' => '未安装'],
            CommentOptionParser::parse('状态 0:禁用，1:启用，2:未安装')
        );
    }

    public function testHandlesRecommendedFormat(): void
    {
        $this->assertSame(
            ['0' => '禁用', '1' => '启用'],
            CommentOptionParser::parse('标签:0=禁用,1=启用')
        );
    }

    public function testHandlesNoLabelPrefix(): void
    {
        $this->assertSame(
            ['0' => '禁用', '1' => '启用'],
            CommentOptionParser::parse('0:禁用,1:启用')
        );
    }

    public function testHandlesStringKeysForEnumColumns(): void
    {
        // Column::enum('type', ['dir','menu','button']) 的典型注释
        $this->assertSame(
            ['dir' => '目录', 'menu' => '菜单', 'button' => '按钮'],
            CommentOptionParser::parse('菜单类型 dir:目录,menu:菜单,button:按钮')
        );
    }

    public function testHandlesValueFirstFormat(): void
    {
        $this->assertSame(
            ['1' => '启用', '0' => '禁用'],
            CommentOptionParser::parse('启用=1,禁用=0')
        );
    }

    public function testReturnsEmptyForPlainTextComment(): void
    {
        $this->assertSame([], CommentOptionParser::parse('创建时间'));
        $this->assertSame([], CommentOptionParser::parse('菜单图标'));
    }

    public function testReturnsEmptyWhenLessThanTwoPairs(): void
    {
        // 单个键值对不构成枚举 —— 必须返回空，由调用方显式回退
        $this->assertSame([], CommentOptionParser::parse('状态 0:禁用'));
        $this->assertSame([], CommentOptionParser::parse(''));
    }
}
