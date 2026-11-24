<?php
declare(strict_types=1);

namespace wise\plugin\tests;

use PHPUnit\Framework\TestCase;
use wise\plugin\extract\NamespaceRewriter;

/**
 * 命名空间改写器测试（token 级、零依赖）。
 *
 * 守住的是 `plugin:extract` 最容易出错的三件事：
 *  1. 只改**头部**（namespace / 顶部 use），类内 trait use、闭包 use、函数体 FQN 一律不动；
 *  2. 最长前缀优先（`app\common\model` 必须赢过 `app\common`）；
 *  3. 命不中的 `app\...` 名字要进 `unmapped`，不能静默保留成「看起来对、实际错」。
 */
class NamespaceRewriterTest extends TestCase
{
    public function testRewritesNamespaceAndUsePrefixes(): void
    {
        $code = <<<'PHP'
<?php
namespace app\admin\controller;

use app\common\model\Order;
use app\common\model\Order as OrderModel;
use think\Request;

class OrderController
{
    public function index()
    {
        return Order::find(1);
    }
}
PHP;

        $result = (new NamespaceRewriter([
            'app\admin\controller' => 'plugin\demo\controller',
            'app\common\model'     => 'plugin\demo\model',
        ]))->rewrite($code);

        $this->assertStringContainsString('namespace plugin\demo\controller;', $result['code']);
        $this->assertStringContainsString('use plugin\demo\model\Order;', $result['code']);
        $this->assertStringContainsString('use plugin\demo\model\Order as OrderModel;', $result['code'], '别名必须原样保留');
        $this->assertStringContainsString('use think\Request;', $result['code'], '包外 use 不动');
        $this->assertSame(3, $result['replaced']);
        $this->assertSame([], $result['unmapped']);
    }

    public function testLongestPrefixWins(): void
    {
        $code = "<?php\nnamespace app\\common\\model;\n";

        $result = (new NamespaceRewriter([
            'app\common'       => 'plugin\demo\base',
            'app\common\model' => 'plugin\demo\model',
        ]))->rewrite($code);

        $this->assertStringContainsString('namespace plugin\demo\model;', $result['code']);
        $this->assertStringNotContainsString('plugin\demo\base', $result['code']);
    }

    public function testBodyReferencesAreReportedButNotRewritten(): void
    {
        $code = <<<'PHP'
<?php
namespace plugin\demo\controller;

class Index
{
    public function index()
    {
        $row = \app\common\model\Order::find(1);
        $name = '\app\common\model\Order'; // 字符串里的不能被改

        return $row;
    }
}
PHP;

        $result = (new NamespaceRewriter(['app\common\model' => 'plugin\demo\model']))->rewrite($code);

        $this->assertStringContainsString('\\app\\common\\model\\Order::find(1)', $result['code'], '函数体 FQN 故意不改');
        $this->assertContains('app\common\model\Order', $result['body_refs']);
        $this->assertSame(0, $result['replaced']);
    }

    public function testTraitUseAndClosureUseAreNotRewritten(): void
    {
        $code = <<<'PHP'
<?php
namespace plugin\demo\controller;

use plugin\demo\model\Order;

class Index
{
    use \app\common\traits\JsonResponse;

    public function index()
    {
        $fn = function () use ($order) {
            return $order;
        };

        return $fn();
    }
}
PHP;

        $result = (new NamespaceRewriter([
            'app\common\traits' => 'plugin\demo\traits',
            'plugin\demo\model' => 'plugin\demo\entity',
        ]))->rewrite($code);

        $this->assertStringContainsString('use \\app\\common\\traits\\JsonResponse;', $result['code'], '类内 trait use 不在改写范围');
        $this->assertStringContainsString('use plugin\demo\entity\Order;', $result['code'], '顶部 use 照常改写');
        $this->assertSame(1, $result['replaced'], '类内 trait use / 闭包 use 不计入改写数');
    }

    public function testGroupedUseKeepsBraces(): void
    {
        $code = "<?php\nnamespace app\\admin\\controller;\n\nuse app\\common\\model\\{Order, Goods};\n";

        $result = (new NamespaceRewriter([
            'app\admin\controller' => 'plugin\demo\controller',
            'app\common\model'     => 'plugin\demo\model',
        ]))->rewrite($code);

        $this->assertStringContainsString('use plugin\demo\model\\{Order, Goods};', $result['code']);
    }

    public function testUnmappedNamesAreReported(): void
    {
        $code = "<?php\nnamespace app\\admin\\controller;\n\nuse app\\common\\model\\Order;\nuse app\\legacy\\Helper;\n";

        $result = (new NamespaceRewriter(['app\admin\controller' => 'plugin\demo\controller']))->rewrite($code);

        $this->assertContains('app\common\model\Order', $result['unmapped']);
        $this->assertContains('app\legacy\Helper', $result['unmapped']);
        $this->assertStringContainsString('use app\common\model\Order;', $result['code'], '未映射的名字保持原样，只做提示');
    }

    public function testIgnoresNonWatchedNamespaces(): void
    {
        $code = "<?php\nnamespace vendor\\pkg;\n\nuse vendor\\pkg\\Thing;\n";

        $result = (new NamespaceRewriter([], ['app\\']))->rewrite($code);

        $this->assertSame([], $result['unmapped']);
        $this->assertSame(0, $result['replaced']);
    }
}
