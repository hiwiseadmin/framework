<?php
declare(strict_types=1);

namespace wise\plugin\tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use wise\plugin\audit\AuditIssue;
use wise\plugin\audit\AuditRuleSet;

/**
 * 本地预检规则集测试。
 *
 * 规则 id 是对外契约：作者看到的驳回文案、服务端与本地的一致性比对（方桼�
 * 都按 rule id 对齐。因此这里**锁死 id 与级别**，改动必须先改测试。
 *
 * 另一条不可退让的边界：代码层正则一律 warn，外部规则库也不能把它变成 error。
 */
class AuditRuleSetTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wiserules_' . uniqid();
        mkdir($this->tmp, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->tmp);
    }

    public function testBundledVersionIsFrozen(): void
    {
        $this->assertSame('bundled-2026.11.1', AuditRuleSet::version());
        $this->assertSame(AuditRuleSet::version(), AuditRuleSet::load(null)['version']);
    }

    public function testRuleNamespacesAreSeparated(): void
    {
        $metadata = AuditRuleSet::metadata();
        $this->assertNotEmpty($metadata);

        foreach ($metadata as $id => $meta) {
            $this->assertMatchesRegularExpression(
                '~^(structure|manifest|code\.regex)\.[a-z0-9_]+$~',
                $id,
                '本地规则只允许三类命名空间：structure.* / manifest.* / code.regex.*'
            );
            $this->assertContains($meta['level'], [AuditIssue::ERROR, AuditIssue::WARN], $id . ' 的级别只能是 error / warn');
            $this->assertNotSame('', $meta['title'], $id . ' 必须有中文标题');
            $this->assertNotSame('', $meta['why'], $id . ' 必须写明原因');
        }
    }

    public function testCodeRulesOnlyWarnAndBlockingRulesStayBlocking(): void
    {
        foreach (AuditRuleSet::metadata() as $id => $meta) {
            if (str_starts_with($id, 'code.regex.')) {
                $this->assertSame(AuditIssue::WARN, $meta['level'], $id . ' 只能 warn（正则无法判定上下文）');
            }
        }

        foreach ([
            AuditRuleSet::STRUCTURE_MANIFEST_MISSING,
            AuditRuleSet::STRUCTURE_MANIFEST_INVALID,
            AuditRuleSet::MANIFEST_REQUIRED_FIELD,
            AuditRuleSet::MANIFEST_NAME_FORMAT,
        ] as $id) {
            $this->assertSame(AuditIssue::ERROR, AuditRuleSet::rule($id)['level'], $id . ' 必须阻断');
        }
    }

    public function testStableRuleIdsExist(): void
    {
        foreach ([
            AuditRuleSet::STRUCTURE_MANIFEST_MISSING,
            AuditRuleSet::STRUCTURE_MANIFEST_INVALID,
            AuditRuleSet::MANIFEST_REQUIRED_FIELD,
            AuditRuleSet::MANIFEST_NAME_FORMAT,
            AuditRuleSet::CODE_DANGEROUS_FUNCTION,
            AuditRuleSet::CODE_EVAL_CHAIN,
        ] as $id) {
            $this->assertNotNull(AuditRuleSet::rule($id), '缺少稳定规则 id：' . $id);
        }

        $this->assertNull(AuditRuleSet::rule('structure.not_exists'));
    }

    public function testRegexRulesAreNamespaceQualified(): void
    {
        $rules = AuditRuleSet::regexRules();
        $this->assertCount(5, $rules);

        foreach ($rules as $rule) {
            $this->assertStringStartsWith('code.regex.', $rule['id']);
        }
    }


    public function testBundledRulesetFileIsValidJson(): void
    {
        $file = AuditRuleSet::bundledFile();

        $this->assertFileExists($file, '规则库必须随包分发（rules/local/bundled.json）');
        $this->assertTrue(
            str_ends_with(str_replace('\\', '/', $file), 'rules/local/bundled.json'),
            '路径需保持可预测，便于构建期覆盖：' . $file
        );

        $data = json_decode((string) file_get_contents($file), true);
        $this->assertIsArray($data);

        $this->assertSame(AuditRuleSet::version(), $data['version'] ?? null);
        $this->assertSame(39, count($data['rules'] ?? []), '规则条数变化必须同时更新本用例（规则 id 是对外契约）');
        $this->assertSame(5, count($data['regex_rules'] ?? []));
    }

    public function testBundledRegexRulesAllHaveMetadata(): void
    {
        $metadata = AuditRuleSet::metadata();

        foreach (AuditRuleSet::regexRules() as $rule) {
            $this->assertArrayHasKey($rule['id'], $metadata, '正则规则必须有元数据，否则 --show-rules 与文档会漏项');
            $this->assertNotSame('', $rule['message'], $rule['id'] . ' 缺少 message');
        }
    }

    public function testBundledCacheIsStableAcrossFlush(): void
    {
        $before = AuditRuleSet::metadata();

        AuditRuleSet::flushBundledCache();

        $this->assertSame($before, AuditRuleSet::metadata(), '清缓存重读必须得到同一份规则');
    }

    public function testValidateAcceptsMinimalPayload(): void
    {
        $normalized = AuditRuleSet::validate($this->validPayload());

        $this->assertSame('bundled-test', $normalized['version']);
        $this->assertSame(['structure.test_rule', 'code.regex.test_rule'], array_keys($normalized['rules']));
        $this->assertCount(1, $normalized['regex']);
    }

    public function testValidateRejectsMissingVersion(): void
    {
        $payload = $this->validPayload();
        unset($payload['version']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('version');

        AuditRuleSet::validate($payload);
    }

    public function testValidateRejectsEmptyRules(): void
    {
        $payload = $this->validPayload();
        $payload['rules'] = [];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('非空数组');

        AuditRuleSet::validate($payload);
    }

    public function testValidateRejectsUnknownRuleNamespace(): void
    {
        $payload = $this->validPayload();
        $payload['rules'][0]['id'] = 'code.ast.secret';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('id 非法');

        AuditRuleSet::validate($payload);
    }

    public function testValidateRejectsDuplicateRuleId(): void
    {
        $payload = $this->validPayload();
        $payload['rules'][] = $payload['rules'][0];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('重复');

        AuditRuleSet::validate($payload);
    }

    public function testValidateRejectsCodeRuleThatBlocks(): void
    {
        $payload = $this->validPayload();
        $payload['rules'][] = [
            'id'    => 'code.regex.bad_level',
            'level' => AuditIssue::ERROR,
            'title' => '错误级别',
            'why'   => '代码规则必须 warn',
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('必须是 warn');

        AuditRuleSet::validate($payload);
    }

    public function testValidateRejectsRegexWithoutMetadata(): void
    {
        $payload = $this->validPayload();
        $payload['regex_rules'][] = [
            'id'      => 'code.regex.unregistered',
            'pattern' => '~\bfoo~',
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('未在 rules 中登记');

        AuditRuleSet::validate($payload);
    }

    public function testValidateRejectsBrokenRegex(): void
    {
        $payload = $this->validPayload();
        $payload['regex_rules'][0]['pattern'] = '~[~';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('正则表达式非法');

        AuditRuleSet::validate($payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(): array
    {
        return [
            'version'     => 'bundled-test',
            'rules'       => [
                ['id' => 'structure.test_rule', 'level' => AuditIssue::ERROR, 'title' => '测试规则', 'why' => '测试用途'],
                ['id' => 'code.regex.test_rule', 'level' => AuditIssue::WARN, 'title' => '测试正则', 'why' => '测试用途'],
            ],
            'regex_rules' => [
                ['id' => 'code.regex.test_rule', 'pattern' => '~\btest_rule~i', 'message' => '命中', 'hint' => '提示'],
            ],
        ];
    }

    public function testExternalRulesetCanAppendRegexRulesButNotStructureRules(): void
    {
        $file = $this->tmp . '/rules.json';
        file_put_contents($file, json_encode([
            'version'     => '2026.12.1',
            'regex_rules' => [
                [
                    'id'      => 'code.regex.custom_backdoor',
                    'pattern' => '~\bassert\s*\(\s*\$_POST~i',
                    'message' => '自定义规则命中',
                    'hint'    => '仅提示',
                ],
            ],
        ], JSON_UNESCAPED_SLASHES));

        $loaded = AuditRuleSet::load($file);

        $this->assertSame('2026.12.1', $loaded['version']);
        $ids = array_column($loaded['regex'], 'id');
        $this->assertContains('code.regex.custom_backdoor', $ids, '外部规则可追加');
        $this->assertContains(AuditRuleSet::CODE_DANGEROUS_FUNCTION, $ids, '内置规则不会被外部规则替换掉');
    }

    public function testExternalRulesetRejectsNonCodeNamespace(): void
    {
        $file = $this->tmp . '/bad.json';
        file_put_contents($file, json_encode([
            'version'     => '2026.12.1',
            'regex_rules' => [
                ['id' => 'structure.custom', 'pattern' => '~\bfoo~'],
            ],
        ]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('必须以 code.regex. 开头');

        AuditRuleSet::load($file);
    }

    public function testExternalRulesetRejectsInvalidRegex(): void
    {
        $file = $this->tmp . '/bad-regex.json';
        file_put_contents($file, json_encode([
            'version'     => '2026.12.1',
            'regex_rules' => [
                ['id' => 'code.regex.broken', 'pattern' => '~[~'],
            ],
        ]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('正则表达式非法');

        AuditRuleSet::load($file);
    }

    public function testSha256MismatchRefusesToLoad(): void
    {
        $file = $this->tmp . '/rules.json';
        file_put_contents($file, json_encode(['version' => '2026.12.1', 'regex_rules' => []]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('sha256 不匹配');

        AuditRuleSet::load($file, str_repeat('0', 64));
    }

    public function testSha256MatchLoads(): void
    {
        $file = $this->tmp . '/rules.json';
        $json = json_encode(['version' => '2026.12.1', 'regex_rules' => []], JSON_UNESCAPED_SLASHES);
        file_put_contents($file, $json);

        $loaded = AuditRuleSet::load($file, hash('sha256', $json));

        $this->assertSame('2026.12.1', $loaded['version']);
    }

    public function testMissingRulesetFileIsReported(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('不存在或不可读');

        AuditRuleSet::load($this->tmp . '/nope.json');
    }
}
