<?php
declare(strict_types=1);

namespace wise\command\package;

use app\common\service\PackageInspector;
use app\common\service\PackageLifecycle;
use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;

/**
 * wise:package:uninstall — 执行包声明的**卸载副作用**
 *
 * ## 它不做什么
 *
 * - 不删文件（`composer remove` 负责）
 * - 不删表、不清菜单/权限/配置（`wise:package:cleanup --apply` 负责）
 *
 * 只做一件事：调用包 ServiceProvider 里 {@see \wise\support\contracts\PackageInstallable::uninstall()}
 * 声明的「包外世界」清理（注销 webhook、释放第三方凭据……）。
 *
 * ## 为什么必须是独立命令
 *
 * `uninstall()` 需要**包文件仍在**才能实例化服务类。而 `composer remove` 的
 * `post-package-uninstall` 阶段文件已删，只能走 `pre-package-uninstall`；
 * 该阶段在 Composer 进程里没有 ThinkPHP App，因此由 {@see \wise\composer\PackageHook}
 * 以子进程方式调用本命令。
 *
 * 手动卸载流程（等价）：
 *
 *   php think wise:package:uninstall wiseadmin/wise-member   # 1. 包外副作用
 *   composer remove wiseadmin/wise-member                    # 2. 删文件（自动触发 cleanup 清痕迹）
 *
 * ## 幂等
 *
 * 由包的 `uninstall()` 自身保证（重复调用不得报错），本命令可安全重跑。
 */
class PackageUninstall extends Command
{
    protected function configure(): void
    {
        $this->setName('wise:package:uninstall')
            ->setDescription('执行标准 Composer 包声明的卸载副作用（不删文件、不动数据库）')
            ->addArgument('package', Argument::REQUIRED, 'composer 包名，如 wiseadmin/wise-member')
            ->addOption('silent', null, Option::VALUE_NONE, '静默：无输出、异常也不返回失败（供 composer 钩子调用）')
            ->addOption('json', null, Option::VALUE_NONE, '以 JSON 输出结果')
            ->setHelp(<<<EOT
执行包声明的卸载副作用（<comment>PackageInstallable::uninstall()</comment>）。

<info>php think wise:package:uninstall wiseadmin/wise-member</info>

<comment>时机</comment>：必须在 <info>composer remove</info> **之前**执行（包文件要能加载）。
composer 的 <info>pre-package-uninstall</info> 钩子会自动调用本命令（见 PackageHook）。

<comment>不做的事</comment>：不删文件、不删表、不清菜单/权限/配置 —— 那些由
<info>composer remove</info> 与 <info>php think wise:package:cleanup &lt;pkg&gt; --apply</info> 负责。
EOT
            );
    }

    protected function execute(Input $input, Output $output)
    {
        $package  = trim((string) ($input->getArgument('package') ?? ''));
        $silent   = (bool) $input->getOption('silent');
        $json     = (bool) $input->getOption('json');

        if ($package === '') {
            if (!$silent && !$json) {
                $output->writeln('<error>请指定包名（如 wiseadmin/wise-member）。</error>');
            }

            return 0;
        }

        $inspector = $this->app->make(PackageInspector::class);
        $meta      = $inspector->find($package);

        if ($meta === null) {
            if (!$silent && !$json) {
                $output->writeln(sprintf('<comment>%s 不是「接入型」Composer 包（或已不在已装集合），无需处理。</comment>', $package));
            }

            return 0;
        }

        $lifecycle = $this->app->make(PackageLifecycle::class);
        $classes   = $lifecycle->detect($meta);

        if ($classes === []) {
            if (!$silent && !$json) {
                $output->writeln(sprintf('<comment>%s 未声明 PackageInstallable，跳过。</comment>', $package));
            }

            return 0;
        }

        $result = $lifecycle->uninstall($meta);

        if ($json) {
            $output->writeln((string) json_encode([
                'package' => $package,
                'called'  => $result['called'],
                'errors'  => $result['errors'],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } elseif (!$silent) {
            foreach ($result['called'] as $class) {
                $output->writeln(sprintf('  <info>OK</info>  %s::uninstall()', $class));
            }

            foreach ($result['errors'] as $class => $message) {
                $output->writeln(sprintf('  <error>FAIL</error>  %s::uninstall()：%s', $class, $message));
            }

            $output->writeln($result['errors'] === []
                ? '<info>卸载副作用执行完成。</info>'
                : '<error>部分卸载副作用失败，请检查外部系统状态（本命令可重跑）。</error>');
        }

        if ($silent) {
            return 0;
        }

        return $result['errors'] === [] ? 0 : 1;
    }
}
