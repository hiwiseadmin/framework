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


    public function testExactPathStaysStable(): void
    {
        $this->assertSame('plugin/mycms/article', $this->normalizer->normalize('/plugin/mycms/article'));
    }

    public function testStripsDefaultActionSegment(): void
    {
        $this->assertSame('plugin/mycms/article', $this->normalizer->normalize('/plugin/mycms/article/index'));
    }

    public function testStripsDefaultActionThenDefaultController(): void
    {
        $this->assertSame('plugin/mycms', $this->normalizer->normalize('/plugin/mycms/index/index'));
    }

    public function testStripsDefaultControllerSegment(): void
    {
        $this->assertSame('plugin/mycms', $this->normalizer->normalize('/plugin/mycms/index'));
    }


    public function testKeepsNonDefaultAction(): void
    {
        $this->assertSame('plugin/mycms/article/detail', $this->normalizer->normalize('/plugin/mycms/article/detail'));
    }

    public function testDoesNotStripParameterValueNamedIndex(): void
    {
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
        $this->assertSame('admin/menu/index', $this->normalizer->normalize('/admin/menu/index'));
        $this->assertSame('admin/index/index', $this->normalizer->normalize('/admin/index/index'));
    }


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
        $this->assertSame('plugin/wisecms/urlRule', $this->normalizer->normalize('/plugin/wisecms/urlRule/index'));
        $this->assertNotSame(
            $this->normalizer->normalize('/plugin/wisecms/urlRule'),
            $this->normalizer->normalize('/plugin/wisecms/urlrule')
        );
    }


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
        $this->assertSame('', $this->normalizer->normalize(''));
        $this->assertFalse($this->normalizer->isSame('', ''));
        $this->assertFalse($this->normalizer->isSame('', '/plugin/mycms/article'));
        $this->assertFalse($this->normalizer->isSame('/plugin/mycms/article', ''));
    }


    public function testRespectsConfiguredDefaults(): void
    {
        $custom = new MenuPathNormalizer('list', 'Home');

        $this->assertSame('plugin/x/y', $custom->normalize('/plugin/x/y/list'));
        $this->assertSame('plugin/x', $custom->normalize('/plugin/x/home/list'));
        $this->assertSame('plugin/x', $custom->normalize('/plugin/x/home'));

        $this->assertSame('plugin/x/y/index', $custom->normalize('/plugin/x/y/index'));
        $this->assertFalse($custom->isSame('/plugin/x/y', '/plugin/x/y/index'));
    }


    public function testVariantsStripsRedundantDefaultSegments(): void
    {
        $this->assertSame(['plugin/x/y/index', 'plugin/x/y'], $this->normalizer->variants('/plugin/x/y/index'));
        $this->assertSame(
            ['plugin/x/index/index', 'plugin/x/index', 'plugin/x'],
            $this->normalizer->variants('/plugin/x/index/index')
        );
    }

    /**
     * 反向（补）：短式写法必须能覆盖到存量的长式写法。
     *
     * 这是「声明用短式、库里/权限表里是长式」场景的**关键方向** ——
     * 只削不补时，`findOrCreateMenu()` 认不出既有长式行，会多建一条菜单 + 一份权限＼�。
     */
    public function testVariantsAppendsDefaultSegments(): void
    {
        $this->assertSame(['plugin/x/y', 'plugin/x/y/index'], $this->normalizer->variants('/plugin/x/y'));
        $this->assertSame(
            ['plugin/x', 'plugin/x/index', 'plugin/x/index/index'],
            $this->normalizer->variants('/plugin/x')
        );
        $this->assertSame(['plugin/x/y/index', 'plugin/x/y'], $this->normalizer->variants('/plugin/x/y/index'));
    }

    public function testVariantsHonoursConfiguredDefaultsWhenAppending(): void
    {
        $custom = new MenuPathNormalizer('list', 'Home');

        $this->assertSame(
            ['plugin/x', 'plugin/x/home', 'plugin/x/home/list'],
            $custom->variants('/plugin/x')
        );
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
            $this->assertSame(trim($path, '/'), $forms[0], $path);
            $this->assertSame($this->normalizer->normalize($path), $this->normalizer->normalize(end($forms)), $path);
        }
    }


    public function testExpandCandidatesCoversSlashAndStyleDimensions(): void
    {
        $this->assertSame(
            ['plugin/x/y/index', '/plugin/x/y/index', 'plugin/x/y', '/plugin/x/y'],
            $this->normalizer->expandCandidates(['plugin/x/y/index'])
        );
    }

    public function testExpandCandidatesDeduplicates(): void
    {
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
        $routes = $this->normalizer->expandCandidates(['plugin/wisecms/content/index']);

        $this->assertContains('plugin/wisecms/content', $routes);
        $this->assertContains('/plugin/wisecms/content', $routes);
    }

    public function testExpandCandidatesCoversPluginRootPage(): void
    {
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
        $routes = $this->normalizer->expandCandidates(['plugin/wisecms/article/index']);

        $this->assertNotContains('plugin/wisecms/content', $routes);
        $this->assertNotContains('plugin/wisecms/article/detail', $routes);
        $this->assertNotContains('admin/wisecms/article', $routes);
    }


    public function testFormOfClassifiesStyles(): void
    {
        $this->assertSame(MenuPathNormalizer::FORM_LONG, $this->normalizer->formOf('/plugin/x/y/index'));
        $this->assertSame(MenuPathNormalizer::FORM_LONG, $this->normalizer->formOf('/plugin/x/index/index'));
        $this->assertSame(MenuPathNormalizer::FORM_SHORT, $this->normalizer->formOf('/plugin/x/y'));
        $this->assertSame(MenuPathNormalizer::FORM_SHORT, $this->normalizer->formOf('/plugin/x'));

        $this->assertSame(MenuPathNormalizer::FORM_NEUTRAL, $this->normalizer->formOf('/plugin/x/y/detail'));
        $this->assertSame(MenuPathNormalizer::FORM_NEUTRAL, $this->normalizer->formOf('/plugin/x/y/index/1/2'));
        $this->assertSame(MenuPathNormalizer::FORM_NEUTRAL, $this->normalizer->formOf(''));
        $this->assertSame(MenuPathNormalizer::FORM_NEUTRAL, $this->normalizer->formOf('/admin/menu/index'));
    }

    public function testPreferredFormTakesMajorityAndIgnoresNeutral(): void
    {
        $this->assertSame(MenuPathNormalizer::FORM_SHORT, $this->normalizer->preferredForm([
            '/plugin/x/a', '/plugin/x/b', '/plugin/x/c/detail', '/plugin/x/e/index',
        ]));

        $this->assertSame(MenuPathNormalizer::FORM_LONG, $this->normalizer->preferredForm([
            '/plugin/x/a/index', '/plugin/x/b/index', '/plugin/x/c/detail',
        ]));

        $this->assertSame(MenuPathNormalizer::FORM_LONG, $this->normalizer->preferredForm([]));
        $this->assertSame(MenuPathNormalizer::FORM_LONG, $this->normalizer->preferredForm(['/plugin/x/a', '/plugin/x/b/index']));
    }


    public function testIdentityFoldsControllerSpelling(): void
    {
        $this->assertSame('plugin/x/group_battle', $this->normalizer->identity('/plugin/x/groupBattle/index'));
        $this->assertSame('plugin/x/group_battle', $this->normalizer->identity('/plugin/x/group_battle/index'));
        $this->assertSame('plugin/x/group_battle', $this->normalizer->identity('/plugin/x/GroupBattle/index'));

        $this->assertTrue($this->normalizer->isSame('/plugin/x/groupBattle/index', '/plugin/x/group_battle'));

        $this->assertSame('plugin/x/article/someAction', $this->normalizer->identity('/plugin/x/article/someAction'));
        $this->assertSame(
            'plugin/x/url_rule/detail',
            $this->normalizer->identity('/plugin/x/urlRule/detail')
        );

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

        $this->assertSame(['plugin/x/article'], $this->normalizer->spellingVariants('/plugin/x/article'));
        $this->assertSame(['admin/menu/index'], $this->normalizer->spellingVariants('/admin/menu/index'));
        $this->assertSame([], $this->normalizer->spellingVariants(''));
    }

    public function testExpandCandidatesClosesSpellingBypass(): void
    {
        $routes = $this->normalizer->expandCandidates(['plugin/x/groupBattle/index']);

        $this->assertContains('plugin/x/group_battle', $routes);
        $this->assertContains('/plugin/x/group_battle', $routes);
    }
}
