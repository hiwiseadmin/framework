<?php
declare(strict_types=1);

namespace wise\plugin\tests;

use PHPUnit\Framework\TestCase;
use wise\plugin\manifest\MenuPathNormalizer;

/**
 * 菜单 path 归一化测试。
 *
 * 锁住三类约定：
 *   1. 「只在正确的位置收敛」—— 动作位 / 控制器位才削，参数值不削；
 *   2. 「不越界」—— 宿主路径、非默认动作、camelCase 控制器名一律原样；
 *   3. 「只做比较键」—— 空 path 不与他者等价（否则分组节点会被误判成同一节点）。
 */
class MenuPathNormalizerTest extends TestCase
{
    private MenuPathNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new MenuPathNormalizer();
    }

    // ──────────────────────────────────────────────
    //  等价收敛
    // ──────────────────────────────────────────────

    public function testExactPathStaysStable(): void
    {
        $this->assertSame('plugin/mycms/article', $this->normalizer->normalize('/plugin/mycms/article'));
    }

    public function testStripsDefaultActionSegment(): void
    {
        // 生成器当前产出的长式
        $this->assertSame('plugin/mycms/article', $this->normalizer->normalize('/plugin/mycms/article/index'));
    }

    public function testStripsDefaultActionThenDefaultController(): void
    {
        // 插件首页的另一种写法：动作位与控制器位都是默认值
        $this->assertSame('plugin/mycms', $this->normalizer->normalize('/plugin/mycms/index/index'));
    }

    public function testStripsDefaultControllerSegment(): void
    {
        // 控制器位即默认控制器：`PluginTrait::getPluginUrl()` 生成的形态之一
        $this->assertSame('plugin/mycms', $this->normalizer->normalize('/plugin/mycms/index'));
    }

    // ──────────────────────────────────────────────
    //  不收敛的边界（防误削）
    // ──────────────────────────────────────────────

    public function testKeepsNonDefaultAction(): void
    {
        $this->assertSame('plugin/mycms/article/detail', $this->normalizer->normalize('/plugin/mycms/article/detail'));
    }

    public function testDoesNotStripParameterValueNamedIndex(): void
    {
        // 尾段是「参数值」而不是动作位 —— 尾正则写法会在这里削错
        $this->assertSame(
            'plugin/mycms/article/detail/type/index',
            $this->normalizer->normalize('/plugin/mycms/article/detail/type/index')
        );
    }

    public function testDoesNotStripWhenActionPositionIsNotLast(): void
    {
        $this->assertSame(
            'plugin/mycms/article/index/1/2',
            $this->normalizer->normalize('/plugin/mycms/article/index/1/2')
        );
    }

    public function testLeavesHostPathUntouched(): void
    {
        // 宿主 /admin/** 不收敛：那边 route 有历史通配语义，削段会造成真实回归
        $this->assertSame('admin/menu/index', $this->normalizer->normalize('/admin/menu/index'));
        $this->assertSame('admin/index/index', $this->normalizer->normalize('/admin/index/index'));
    }

    // ──────────────────────────────────────────────
    //  文本归一
    // ──────────────────────────────────────────────

    public function testNormalizesSlashesWhitespaceAndQuery(): void
    {
        $this->assertSame('plugin/mycms/article', $this->normalizer->normalize('//plugin/mycms//article/index/?a=1#top'));
        $this->assertSame('plugin/mycms/article', $this->normalizer->normalize('  /plugin/mycms/article/index/  '));
    }

    public function testActionAndControllerSegmentAreCaseInsensitive(): void
    {
        $this->assertSame('plugin/mycms/article', $this->normalizer->normalize('/plugin/mycms/article/INDEX'));
        $this->assertSame('plugin/mycms', $this->normalizer->normalize('/plugin/mycms/Index'));
    }

    public function testCamelCaseControllerKeepsItsCase(): void
    {
        // `Str::studly('urlRule')` = UrlRule ≠ `Str::studly('urlrule')` = Urlrule：
        // 整体小写会制造错误的等价，故只折叠被比较的那一段
        $this->assertSame('plugin/wisecms/urlRule', $this->normalizer->normalize('/plugin/wisecms/urlRule/index'));
        $this->assertNotSame(
            $this->normalizer->normalize('/plugin/wisecms/urlRule'),
            $this->normalizer->normalize('/plugin/wisecms/urlrule')
        );
    }

    // ──────────────────────────────────────────────
    //  isSame：空 path 永不参与
    // ──────────────────────────────────────────────

    public function testIsSameIgnoresEquivalentStyles(): void
    {
        $this->assertTrue($this->normalizer->isSame('/plugin/mycms/article', '/plugin/mycms/article/index'));
        $this->assertTrue($this->normalizer->isSame('/plugin/mycms/index/index', '/plugin/mycms'));
        $this->assertTrue($this->normalizer->isSame('/plugin/mycms/article/index', 'plugin/mycms/article'));
    }

    public function testIsSameRejectsDifferentResources(): void
    {
        $this->assertFalse($this->normalizer->isSame('/plugin/mycms/article', '/plugin/mycms/article/detail'));
        $this->assertFalse($this->normalizer->isSame('/plugin/mycms/article', '/plugin/mycms/article_extra/index'));
        $this->assertFalse($this->normalizer->isSame('/plugin/mycms/article', '/plugin/other/article'));
        $this->assertFalse($this->normalizer->isSame('/plugin/mycms/article', '/admin/mycms/article'));
    }

    public function testEmptyPathNeverEqualsAnything(): void
    {
        // `dir` 分组节点 path 为空：若判成等价，去重会误杀整个分组
        $this->assertSame('', $this->normalizer->normalize(''));
        $this->assertFalse($this->normalizer->isSame('', ''));
        $this->assertFalse($this->normalizer->isSame('', '/plugin/mycms/article'));
        $this->assertFalse($this->normalizer->isSame('/plugin/mycms/article', ''));
    }

    // ──────────────────────────────────────────────
    //  配置驱动（默认动作 / 默认控制器不是硬编码）
    // ──────────────────────────────────────────────

    public function testRespectsConfiguredDefaults(): void
    {
        $custom = new MenuPathNormalizer('list', 'Home');

        $this->assertSame('plugin/x/y', $custom->normalize('/plugin/x/y/list'));
        $this->assertSame('plugin/x', $custom->normalize('/plugin/x/home/list'));
        $this->assertSame('plugin/x', $custom->normalize('/plugin/x/home'));

        // 配置改变后，原默认值不再被视作「可省略」
        $this->assertSame('plugin/x/y/index', $custom->normalize('/plugin/x/y/index'));
        $this->assertFalse($custom->isSame('/plugin/x/y', '/plugin/x/y/index'));
    }

    // ──────────────────────────────────────────────
    //  variants：等价写法全集（双向：削 + 补）
    // ──────────────────────────────────────────────

    public function testVariantsStripsRedundantDefaultSegments(): void
    {
        $this->assertSame(['plugin/x/y/index', 'plugin/x/y'], $this->normalizer->variants('/plugin/x/y/index'));
        // 插件首页：动作位与控制器位依次省略 → 三种写法
        $this->assertSame(
            ['plugin/x/index/index', 'plugin/x/index', 'plugin/x'],
            $this->normalizer->variants('/plugin/x/index/index')
        );
    }

    /**
     * 反向（补）：短式写法必须能覆盖到存量的长式写法。
     *
     * 这是「声明用短式、库里/权限表里是长式」场景的**关键方向** ——
     * 只削不补时，`findOrCreateMenu()` 认不出既有长式行，会多建一条菜单 + 一份权限（§8.11）。
     */
    public function testVariantsAppendsDefaultSegments(): void
    {
        $this->assertSame(['plugin/x/y', 'plugin/x/y/index'], $this->normalizer->variants('/plugin/x/y'));
        $this->assertSame(
            ['plugin/x', 'plugin/x/index', 'plugin/x/index/index'],
            $this->normalizer->variants('/plugin/x')
        );
        // 4 段写法已显式带动作段，不再向后补（否则会造出 /index/index 之类的形态）
        $this->assertSame(['plugin/x/y/index', 'plugin/x/y'], $this->normalizer->variants('/plugin/x/y/index'));
    }

    public function testVariantsHonoursConfiguredDefaultsWhenAppending(): void
    {
        $custom = new MenuPathNormalizer('list', 'Home');

        $this->assertSame(
            ['plugin/x', 'plugin/x/home', 'plugin/x/home/list'],
            $custom->variants('/plugin/x')
        );
        // 补的是**配置里的**默认段（list），而不是硬编码的 index
        $this->assertSame(['plugin/x/y', 'plugin/x/y/list'], $custom->variants('/plugin/x/y'));
    }

    public function testVariantsDoesNotInventForms(): void
    {
        $this->assertSame(['plugin/x/y/detail'], $this->normalizer->variants('/plugin/x/y/detail'));
        $this->assertSame(
            ['plugin/x/y/detail/type/index'],
            $this->normalizer->variants('/plugin/x/y/detail/type/index')
        );
        $this->assertSame(['admin/menu/index'], $this->normalizer->variants('/admin/menu/index'));
        $this->assertSame([], $this->normalizer->variants(''));
        $this->assertSame([], $this->normalizer->variants('/'));
    }

    public function testVariantsAllFoldToSameIdentity(): void
    {
        foreach ([
            '/plugin/x/y/index',
            '/plugin/x/index/index',
            '/plugin/x/index',
            '/plugin/x/y',
            '/plugin/x',
        ] as $path) {
            foreach ($this->normalizer->variants($path) as $form) {
                $this->assertSame(
                    $this->normalizer->identity($path),
                    $this->normalizer->identity($form),
                    sprintf('%s → %s 应等价', $path, $form)
                );
            }
        }
    }

    public function testVariantsKeepTheOriginalFormFirst(): void
    {
        foreach ([
            '/plugin/x/y/index',
            '/plugin/x/index/index',
            '/plugin/x/index',
            '/plugin/x/y',
            '/plugin/x/y/detail',
            '/admin/menu/index',
        ] as $path) {
            $forms = $this->normalizer->variants($path);

            $this->assertNotEmpty($forms);
            // 原样写法在最前（调用方按序取用：先试既有写法，再试等价写法）
            $this->assertSame(trim($path, '/'), $forms[0], $path);
            $this->assertSame($this->normalizer->normalize($path), $this->normalizer->normalize(end($forms)), $path);
        }
    }

    // ──────────────────────────────────────────────
    //  expandCandidates：鉴权候选集合
    // ──────────────────────────────────────────────

    public function testExpandCandidatesCoversSlashAndStyleDimensions(): void
    {
        $this->assertSame(
            ['plugin/x/y/index', '/plugin/x/y/index', 'plugin/x/y', '/plugin/x/y'],
            $this->normalizer->expandCandidates(['plugin/x/y/index'])
        );
    }

    public function testExpandCandidatesDeduplicates(): void
    {
        // 两个基础候选收敛到同一写法集时不得重复投递
        $this->assertSame(
            ['plugin/x/y/index', '/plugin/x/y/index', 'plugin/x/y', '/plugin/x/y'],
            $this->normalizer->expandCandidates(['plugin/x/y/index', '/plugin/x/y'])
        );
    }

    /**
     * 回归：短式声明的权限，不得因为「URL 多写一个 /index」而匹配不到。
     *
     * 接入前的真实缺口：候选集合只含请求命中的那一种写法时，声明 `/plugin/x/y`
     * 的权限在访问 `/plugin/x/y/index` 时命中 0 条 → 插件侧「未匹配即放行」
     * → 权限形同虚设（末尾加 `/index` 即可绕过）。
     */
    public function testExpandCandidatesClosesIndexSuffixBypass(): void
    {
        // 请求 `/plugin/wisecms/content/index` 时，base 候选即该 URL 本身
        $routes = $this->normalizer->expandCandidates(['plugin/wisecms/content/index']);

        $this->assertContains('plugin/wisecms/content', $routes);
        $this->assertContains('/plugin/wisecms/content', $routes);
    }

    public function testExpandCandidatesCoversPluginRootPage(): void
    {
        // 插件首页的三种写法都必须能命中同一条短式声明 `/plugin/wisecms`
        $routes = $this->normalizer->expandCandidates(['plugin/wisecms/index/index']);

        $this->assertContains('plugin/wisecms', $routes);
        $this->assertContains('plugin/wisecms/index', $routes);
    }

    /**
     * 回归（G7 的反方向）：**长式声明**的权限，不得因为「请求省略了 /index」而匹配不到。
     *
     * `variants()` 只削不补时，访问 `/plugin/wisecms/content` 的候选集合里没有
     * `/plugin/wisecms/content/index`，而存量长式权限（如 wisecms 的 10 条）恰好存的是它 →
     * 命中 0 条 → 插件侧「未匹配即放行」→ 与 G7 同型的绕过路径。
     */
    public function testExpandCandidatesClosesShortRequestBypass(): void
    {
        $routes = $this->normalizer->expandCandidates(['plugin/wisecms/content']);

        $this->assertContains('plugin/wisecms/content/index', $routes);
        $this->assertContains('/plugin/wisecms/content/index', $routes);
    }

    public function testExpandCandidatesKeepsUnrelatedRoutesOut(): void
    {
        // 反向：展开不得把别的控制器 / 别的动作的 route 拉进候选
        $routes = $this->normalizer->expandCandidates(['plugin/wisecms/article/index']);

        $this->assertNotContains('plugin/wisecms/content', $routes);
        $this->assertNotContains('plugin/wisecms/article/detail', $routes);
        $this->assertNotContains('admin/wisecms/article', $routes);
    }

    // ──────────────────────────────────────────────
    //  产出风格识别（供生成器「风格继承」）
    // ──────────────────────────────────────────────

    public function testFormOfClassifiesStyles(): void
    {
        $this->assertSame(MenuPathNormalizer::FORM_LONG, $this->normalizer->formOf('/plugin/x/y/index'));
        $this->assertSame(MenuPathNormalizer::FORM_LONG, $this->normalizer->formOf('/plugin/x/index/index'));
        $this->assertSame(MenuPathNormalizer::FORM_SHORT, $this->normalizer->formOf('/plugin/x/y'));
        $this->assertSame(MenuPathNormalizer::FORM_SHORT, $this->normalizer->formOf('/plugin/x'));

        // 动作级 / 带参数 / 空 path / 非插件路径：不表达风格偏好
        $this->assertSame(MenuPathNormalizer::FORM_NEUTRAL, $this->normalizer->formOf('/plugin/x/y/detail'));
        $this->assertSame(MenuPathNormalizer::FORM_NEUTRAL, $this->normalizer->formOf('/plugin/x/y/index/1/2'));
        $this->assertSame(MenuPathNormalizer::FORM_NEUTRAL, $this->normalizer->formOf(''));
        $this->assertSame(MenuPathNormalizer::FORM_NEUTRAL, $this->normalizer->formOf('/admin/menu/index'));
    }

    public function testPreferredFormTakesMajorityAndIgnoresNeutral(): void
    {
        // 中性条目（动作级页面）不参与投票
        $this->assertSame(MenuPathNormalizer::FORM_SHORT, $this->normalizer->preferredForm([
            '/plugin/x/a', '/plugin/x/b', '/plugin/x/c/detail', '/plugin/x/e/index',
        ]));

        $this->assertSame(MenuPathNormalizer::FORM_LONG, $this->normalizer->preferredForm([
            '/plugin/x/a/index', '/plugin/x/b/index', '/plugin/x/c/detail',
        ]));

        // 无数据 / 平票 → 长式（生成器既有默认，零惊喜）
        $this->assertSame(MenuPathNormalizer::FORM_LONG, $this->normalizer->preferredForm([]));
        $this->assertSame(MenuPathNormalizer::FORM_LONG, $this->normalizer->preferredForm(['/plugin/x/a', '/plugin/x/b/index']));
    }

    // ──────────────────────────────────────────────
    //  身份：控制器段拼写折叠
    // ──────────────────────────────────────────────

    public function testIdentityFoldsControllerSpelling(): void
    {
        $this->assertSame('plugin/x/group_battle', $this->normalizer->identity('/plugin/x/groupBattle/index'));
        $this->assertSame('plugin/x/group_battle', $this->normalizer->identity('/plugin/x/group_battle/index'));
        $this->assertSame('plugin/x/group_battle', $this->normalizer->identity('/plugin/x/GroupBattle/index'));

        $this->assertTrue($this->normalizer->isSame('/plugin/x/groupBattle/index', '/plugin/x/group_battle'));

        // 动作段不折叠（动作按方法名原样调用，大小写敏感）
        $this->assertSame('plugin/x/article/someAction', $this->normalizer->identity('/plugin/x/article/someAction'));
        $this->assertSame(
            'plugin/x/url_rule/detail',
            $this->normalizer->identity('/plugin/x/urlRule/detail')
        );

        // 拼写折叠不得把「不同的类」判成同一个
        $this->assertNotSame(
            $this->normalizer->identity('/plugin/x/urlRule'),
            $this->normalizer->identity('/plugin/x/urlrule'),
            'Str::studly(urlrule)=Urlrule ≠ Str::studly(urlRule)=UrlRule'
        );
        $this->assertFalse($this->normalizer->isSame('/plugin/x/urlRule', '/plugin/x/urlrule'));
    }

    public function testSpellingVariantsCoverSnakeAndCamel(): void
    {
        $this->assertSame(
            ['plugin/x/groupBattle/index', 'plugin/x/group_battle/index'],
            $this->normalizer->spellingVariants('/plugin/x/groupBattle/index')
        );
        $this->assertSame(
            ['plugin/x/group_battle/index'],
            $this->normalizer->spellingVariants('/plugin/x/group_battle/index'),
            '已是 snake → 不产生多余变体'
        );

        // 单段控制器 / 非插件路径：原样返回
        $this->assertSame(['plugin/x/article'], $this->normalizer->spellingVariants('/plugin/x/article'));
        $this->assertSame(['admin/menu/index'], $this->normalizer->spellingVariants('/admin/menu/index'));
        $this->assertSame([], $this->normalizer->spellingVariants(''));
    }

    public function testExpandCandidatesClosesSpellingBypass(): void
    {
        // 声明 `/plugin/x/group_battle`（snake），请求 `/plugin/x/groupBattle`（camel）
        // 时也必须命中 —— 否则同样是「未匹配即放行」的绕过路径
        $routes = $this->normalizer->expandCandidates(['plugin/x/groupBattle/index']);

        $this->assertContains('plugin/x/group_battle', $routes);
        $this->assertContains('/plugin/x/group_battle', $routes);
    }
}
