<?php
declare(strict_types=1);

namespace wise\command\package;

use app\common\service\PackageInspector;
use app\common\service\PackageLifecycle;
use app\common\service\PackageRegistry;
use Phinx\Seed\AbstractSeed;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;

/**
 * wise:package:install — 标准 Composer 包的**安装单一入口**
 *
 * 解决「`composer require` 之后还要手工做一串事」的问题：把
 *   services 重新发现 → 数据库迁移 → 包内 seeds → 菜单同步 → 静态资源发布 → 登记快照
 * 收敛为一条命令，与已有的 {@see PackageCleanup}（`wise:package:cleanup`）对称。
 *
 * ## 为什么是独立命令，而不是 composer 脚本
 *
 * Composer **只执行根包**的 `scripts`，依赖包 `composer.json` 里的 scripts 永远不会被执行
 * （见 {@see \wise\composer\PackageHook} 类注释）；而且迁移/种子是**有状态、不可回滚**的，
 * 挂在 `post-autoload-dump` 上会在 CI、只读盘、无数据库、多节点并发部署等场景空跑。
 * 因此：声明同步留在 composer 钩子，**有状态的动作只在显式命令里执行**。
 *
 * ## 用法
 *
 *   php think wise:package:install wiseadmin/wise-member
 *   php think wise:package:install --all
 *   php think wise:package:install wiseadmin/wise-member --dry-run
 *   php think wise:package:install --all --skip-migrate --skip-seed
 *
 * ## 幂等性
 *
 * 每一步都可重复执行：`service:discover` 重写 `vendor/services.php`、
 * `migrate:run` 只跑未执行版本、seeds 由包作者保证幂等、`menu:sync` 幂等落库、
 * `wise:vendor:publish` 增量复制、`PackageRegistry::record()` 内容不变则不写盘。
 *
 * 继承 {@see \think\migration\Command} 只为复用它的 `getAdapter()`（全局
 * `{prefix}migrations` 语义，与宿主迁移一致；插件的 `ws_plugin_migrations` 是另一套）。
 */
class PackageInstall extends \think\migration\Command
{
    protected const TIMEOUT = 600;

    protected function configure(): void
    {
        $this->setName('wise:package:install')
            ->setDescription('安装标准 Composer 包：迁移 + seeds + 菜单同步 + 资源发布 + 登记')
            ->addArgument('package', Argument::OPTIONAL, 'composer 包名，如 wiseadmin/wise-member（--all 时可省略）')
            ->addOption('all', null, Option::VALUE_NONE, '处理全部「接入型」Composer 包')
            ->addOption('dry-run', null, Option::VALUE_NONE, '只打印将要执行的动作，不做任何变更')
            ->addOption('skip-discover', null, Option::VALUE_NONE, '跳过 service:discover')
            ->addOption('skip-migrate', null, Option::VALUE_NONE, '跳过数据库迁移')
            ->addOption('skip-seed', null, Option::VALUE_NONE, '跳过包内 seeds')
            ->addOption('skip-lifecycle', null, Option::VALUE_NONE, '跳过 PackageInstallable::install()')
            ->addOption('skip-menu', null, Option::VALUE_NONE, '跳过菜单同步')
            ->addOption('skip-publish', null, Option::VALUE_NONE, '跳过静态资源发布')
            ->addOption('skip-register', null, Option::VALUE_NONE, '跳过登记卸载快照')
            ->addOption('json', null, Option::VALUE_NONE, '以 JSON 输出结果')
            ->setHelp(<<<EOT
安装标准 Composer 包（一条命令完成「装完之后该做的事」）。

<info>php think wise:package:install wiseadmin/wise-member</info>   安装指定包
<info>php think wise:package:install --all</info>                   处理全部接入型包
<info>php think wise:package:install --all --dry-run</info>         只预览

判据：包的 <comment>composer.json</comment> 声明了 extra.think.{services,config,publish,menus}
或包内有 database/migrations，即为「接入型包」。

<comment>前置</comment>：包必须先通过 <info>composer require</info> 装入 vendor（本命令不跑 composer）。
<comment>对称命令</comment>：<info>php think wise:package:cleanup &lt;pkg&gt; --apply</info>
EOT
            );
    }

    protected function execute(Input $input, Output $output)
    {
        $packageName = trim((string) ($input->getArgument('package') ?? ''));
        $all         = (bool) $input->getOption('all');
        $dryRun      = (bool) $input->getOption('dry-run');
        $json        = (bool) $input->getOption('json');

        $flags = [
            'discover' => !$input->getOption('skip-discover'),
            'migrate'  => !$input->getOption('skip-migrate'),
            'seed'      => !$input->getOption('skip-seed'),
            'lifecycle' => !$input->getOption('skip-lifecycle'),
            'menu'      => !$input->getOption('skip-menu'),
            'publish'  => !$input->getOption('skip-publish'),
            'register' => !$input->getOption('skip-register'),
        ];

        $inspector = $this->app->make(PackageInspector::class);

        try {
            $targets = $this->resolveTargets($inspector, $packageName, $all);
        } catch (\InvalidArgumentException $e) {
            if ($json) {
                $output->writeln(
                    (string) json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    Output::OUTPUT_RAW
                );
            } else {
                $output->writeln('<error>' . $e->getMessage() . '</error>');
            }

            return 1;
        }

        if ($targets === []) {
            if ($json) {
                $output->writeln((string) json_encode(['ok' => true, 'packages' => []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            } else {
                $output->writeln('<comment>没有找到「接入型」Composer 包（未声明 extra.think 且无 database/migrations）。</comment>');
            }

            return 0;
        }

        if ($dryRun) {
            $this->renderPlan($targets, $flags, $output, $json);

            return 0;
        }

        if (!$json) {
            $output->writeln(sprintf('<info>wise:package:install</info> 目标 %d 个包', count($targets)));
        }

        $result = [
            'ok'       => true,
            'steps'    => [],
            'packages' => [],
        ];

        if ($flags['discover']) {
            $step = $this->step($output, $json, 'service:discover', function () {
                return $this->runThink(['service:discover']);
            });
            $result['steps'][] = $step;
            if (!$step['ok']) {
                $result['ok'] = false;
                $this->renderResult($result, $output, $json);

                return 1;
            }
        }

        $migrationProblems = [];

        if ($flags['migrate']) {
            $unregistered      = $this->unregisteredMigrationPaths($targets);
            $migrationProblems = $unregistered;

            if ($unregistered !== []) {
                $result['ok'] = false;

                if ($json) {
                    $result['steps'][] = [
                        'name'   => 'migrate:run',
                        'ok'     => false,
                        'code'   => 1,
                        'output' => implode('; ', $unregistered),
                    ];
                } else {
                    $output->writeln(sprintf(
                        '  <comment>▸ migrate:run         </comment> <error>SKIP</error>（%d 个包的迁移目录未注册，详见下方包级错误）',
                        count($unregistered)
                    ));
                }
            } else {
                $hasMigrations = false;
                foreach ($targets as $meta) {
                    if (($meta['migrations'] ?? []) !== []) {
                        $hasMigrations = true;
                        break;
                    }
                }

                if ($hasMigrations) {
                    $register = $this->step($output, $json, 'plugin:migrate:register', function () {
                        return $this->runThink(['plugin:migrate:register']);
                    });
                    $result['steps'][] = $register;

                    if (!$register['ok']) {
                        $result['ok'] = false;
                    }

                    $before = $this->pendingSnapshot($targets);

                    $step = $this->step($output, $json, 'migrate:run', function () {
                        return $this->runThink(['migrate:run']);
                    });
                    $result['steps'][] = $step;

                    if (!$step['ok']) {
                        $result['ok'] = false;
                    } elseif ($before !== []) {
                        $after = $this->pendingSnapshot($targets);

                        foreach ($after as $name => $count) {
                            $migrationProblems[$name] = sprintf(
                                '%s 迁移后仍有 %d 个版本未执行（请检查 migrate:run 是否为多路径实现，或该迁移本身执行失败）',
                                $name,
                                $count
                            );
                            $result['ok'] = false;
                        }

                        if ($after !== [] && !$json) {
                            $output->writeln(sprintf(
                                '      <error>迁移未完全生效：%d 个包仍有待执行版本</error>',
                                count($after)
                            ));
                        }
                    }
                }
            }
        }

        foreach ($targets as $name => $meta) {
            $entry = [
                'package'   => $name,
                'ident'     => $meta['ident'],
                'seeds'     => [],
                'lifecycle' => [],
                'errors'    => isset($migrationProblems[$name]) ? [$migrationProblems[$name]] : [],
            ];

            if ($flags['seed'] && ($meta['seeds'] ?? []) !== []) {
                try {
                    $entry['seeds'] = $this->runSeeds($meta, $output, $json);
                } catch (\Throwable $e) {
                    $entry['errors'][] = 'seeds: ' . $e->getMessage();
                    $result['ok']        = false;
                    if (!$json) {
                        $output->writeln('  <error>seeds 失败：' . $e->getMessage() . '</error>');
                    }
                }
            }

            if ($flags['lifecycle']) {
                $lifecycle = $this->app->make(PackageLifecycle::class);

                if ($lifecycle->detect($meta) !== []) {
                    if (!$json) {
                        $output->write(sprintf('  <comment>▸ %-20s</comment> ', 'PackageInstallable'));
                    }

                    $done                = $lifecycle->install($meta);
                    $entry['lifecycle']  = $done;

                    if (!$json) {
                        $output->writeln($done['errors'] === [] ? '<info>OK</info>' : '<error>FAIL</error>');
                    }

                    foreach ($done['errors'] as $class => $message) {
                        $entry['errors'][] = sprintf('lifecycle: %s: %s', $class, $message);
                        $result['ok']      = false;

                        if (!$json) {
                            $output->writeln(sprintf('      <error>%s：%s</error>', $class, $message));
                        }
                    }
                }
            }

            $result['packages'][$name] = $entry;
        }

        if ($flags['menu']) {
            $step = $this->step($output, $json, 'menu:sync', function () {
                return $this->runThink(['menu:sync']);
            });
            $result['steps'][] = $step;
            if (!$step['ok']) {
                $result['ok'] = false;
            }
        }

        if ($flags['publish']) {
            $step = $this->step($output, $json, 'wise:vendor:publish', function () {
                return $this->runThink(['wise:vendor:publish']);
            });
            $result['steps'][] = $step;
            if (!$step['ok']) {
                $result['ok'] = false;
            }
        }

        if ($flags['register']) {
            $registry = $this->app->make(PackageRegistry::class);

            foreach ($targets as $name => $meta) {
                try {
                    $registry->record($name, $meta['root']);
                    $result['packages'][$name]['registered'] = true;
                } catch (\Throwable $e) {
                    $result['packages'][$name]['errors'][] = 'register: ' . $e->getMessage();
                    $result['ok']                           = false;
                }
            }
        }

        $this->renderResult($result, $output, $json);

        return $result['ok'] ? 0 : 1;
    }


    /**
     * @return array<string, array<string, mixed>>
     * @throws \InvalidArgumentException 目标非法
     */
    protected function resolveTargets(PackageInspector $inspector, string $packageName, bool $all): array
    {
        if ($packageName !== '') {
            $meta = $inspector->find($packageName);
            if ($meta === null) {
                throw new \InvalidArgumentException(sprintf(
                    '包 %s 不是「接入型」Composer 包（未声明 extra.think 且无 database/migrations），或尚未安装。',
                    $packageName
                ));
            }

            return [$packageName => $meta];
        }

        if ($all) {
            return $inspector->candidates();
        }

        throw new \InvalidArgumentException('请指定包名（如 wiseadmin/wise-member），或使用 --all。');
    }


    /**
     * 执行一个可失败的步骤
     *
     * @param  callable():array{code:int,output:string} $runner
     * @return array{name:string,ok:bool,code:int,output:string}
     */
    protected function step(Output $output, bool $json, string $name, callable $runner): array
    {
        if (!$json) {
            $output->write(sprintf('  <comment>▸ %-24s</comment> ', $name));
        }

        $run = $runner();
        $ok  = ((int) ($run['code'] ?? 1)) === 0;

        if (!$json) {
            $output->writeln($ok ? '<info>OK</info>' : '<error>FAIL</error>');
            if (!$ok) {
                foreach (preg_split('/\r?\n/', (string) ($run['output'] ?? '')) ?: [] as $line) {
                    if (trim($line) !== '') {
                        $output->writeln('      <error>' . trim($line) . '</error>');
                    }
                }
            }
        }

        return [
            'name'   => $name,
            'ok'     => $ok,
            'code'   => (int) ($run['code'] ?? 1),
            'output' => $this->tail((string) ($run['output'] ?? '')),
        ];
    }

    /**
     * 各包「待执行迁移」数量的快照（数据库不可用时返回空数组）
     *
     * @param  array<string, array<string, mixed>> $targets
     * @return array<string, int> 包名 => 待执行版本数（仅包含 > 0 的包）
     */
    protected function pendingSnapshot(array $targets): array
    {
        $inspector = $this->app->make(PackageInspector::class);

        $snapshot = [];

        foreach ($targets as $name => $meta) {
            $pending = $inspector->pendingVersions($meta);

            if (is_array($pending) && $pending !== []) {
                $snapshot[$name] = count($pending);
            }
        }

        return $snapshot;
    }

    /**
     * 找出「有迁移文件、但迁移目录没被注册」的包
     *
     * 迁移目录的唯一注册途径是包 ServiceProvider 里的 `registerMigrations()`
     * （`Console::starting` 阶段写入 `config('migration.paths')`）。本命令运行到这里时
     * 服务已 boot，因此可以直接判定：目录不在 paths 里 ⇒ 作者漏写了注册 ⇒ 迁移不会执行。
     *
     * 这种情况下**不静默通过**：装完没表比装失败更难排查。
     *
     * @param  array<string, array<string, mixed>> $targets
     * @return array<string, string> 包名 => 可直接展示的错误信息
     */
    protected function unregisteredMigrationPaths(array $targets): array
    {
        $paths = [];
        foreach ((array) config('migration.paths', []) as $path) {
            $real    = realpath((string) $path);
            $paths[] = $real !== false ? $real : (string) $path;
        }

        $problems = [];

        foreach ($targets as $name => $meta) {
            $dir = (string) ($meta['migration_dir'] ?? '');
            if ($dir === '' || ($meta['migrations'] ?? []) === []) {
                continue;
            }

            $real   = realpath($dir);
            $needle = $real !== false ? $real : $dir;

            if (!in_array($needle, $paths, true)) {
                $problems[$name] = sprintf(
                    '%s 有 %d 个迁移文件，但目录未注册：请在 %s 的 ServiceProvider::boot() 中调用 '
                    . '$this->registerMigrations()（或带路径参数），再执行本命令。',
                    $name,
                    count((array) $meta['migrations']),
                    $meta['services'][0] ?? '该包'
                );
            }
        }

        return $problems;
    }


    /**
     * 执行包内 `database/seeds/*.php`
     *
     * 约定与 think-migration 一致：文件名 = 类名（可带命名空间），类必须继承
     * {@see AbstractSeed}。与插件的区别仅在**使用宿主（全局）迁移表语义**，
     * 而不是 `ws_plugin_migrations`。
     *
     * @param  array<string, mixed> $meta
     * @return string[] 已执行的 Seeder 类名
     */
    protected function runSeeds(array $meta, Output $output, bool $json): array
    {
        $dir = (string) ($meta['seed_dir'] ?? '');
        if ($dir === '' || !is_dir($dir)) {
            return [];
        }

        $files = glob($dir . DIRECTORY_SEPARATOR . '*.php') ?: [];
        sort($files);

        $executed = [];

        foreach ($files as $file) {
            $shortName = pathinfo($file, PATHINFO_FILENAME);

            if (preg_match('/^[A-Z]\w*$/', $shortName) !== 1) {
                continue;
            }

            $before = get_declared_classes();
            require_once $file;

            $class = $this->resolveSeedClass($file, $before);
            if ($class === null) {
                throw new \RuntimeException(sprintf(
                    'Seeder 文件中未找到继承 Phinx\Seed\AbstractSeed 的类：%s',
                    $file
                ));
            }

            $seed = new $class($this->input, $this->output);

            if (!$json) {
                $output->write(sprintf('  <comment>▸ seed %-20s</comment> ', pathinfo($file, PATHINFO_FILENAME)));
            }

            $adapter       = $this->getAdapter();
            $inTransaction = $adapter->hasTransactions();

            $seed->setAdapter($adapter);

            if ($inTransaction) {
                $adapter->beginTransaction();
            }

            try {
                $seed->run();
                if ($inTransaction) {
                    $adapter->commitTransaction();
                }
            } catch (\Throwable $e) {
                if ($inTransaction) {
                    $adapter->rollbackTransaction();
                }

                throw $e;
            }

            $executed[] = $class;

            if (!$json) {
                $output->writeln('<info>OK</info>');
            }
        }

        return $executed;
    }

    /**
     * 从 require 后新增的类中挑出属于该文件的 Seeder 类（支持命名空间）
     *
     * @param string[] $before require 之前的 get_declared_classes()
     */
    protected function resolveSeedClass(string $file, array $before): ?string
    {
        $real = realpath($file);

        foreach (array_diff(get_declared_classes(), $before) as $class) {
            try {
                $reflection = new \ReflectionClass($class);
            } catch (\ReflectionException $e) {
                continue;
            }

            if ($reflection->isAbstract()) {
                continue;
            }

            if ($real !== false && $reflection->getFileName() !== false && realpath($reflection->getFileName()) !== $real) {
                continue;
            }

            if ($reflection->isSubclassOf(AbstractSeed::class)) {
                return $class;
            }
        }

        $short = pathinfo($file, PATHINFO_FILENAME);
        if (class_exists($short)) {
            return $short;
        }

        return null;
    }


    /**
     * 以子进程执行 `php think <args>`（保证 Console::starting 语义与生产一致）
     *
     * @param  string[] $args
     * @return array{code:int,output:string}
     */
    protected function runThink(array $args): array
    {
        $root    = rtrim((string) $this->app->getRootPath(), '/\\');
        $php     = PHP_BINARY !== '' ? PHP_BINARY : 'php';
        $command = array_merge([$php, $root . DIRECTORY_SEPARATOR . 'think'], $args);

        if (class_exists(\Symfony\Component\Process\Process::class)) {
            try {
                $process = new \Symfony\Component\Process\Process($command, $root, null, null, self::TIMEOUT);
                $process->run();

                return [
                    'code'   => (int) $process->getExitCode(),
                    'output' => trim($process->getOutput() . "\n" . $process->getErrorOutput()),
                ];
            } catch (\Throwable $e) {
                return ['code' => 1, 'output' => $e->getMessage()];
            }
        }

        $line     = implode(' ', array_map('escapeshellarg', $command)) . ' 2>&1';
        $lines    = [];
        $exitCode = 1;

        if (function_exists('exec')) {
            @exec($line, $lines, $exitCode);
        }

        return ['code' => $exitCode, 'output' => implode("\n", $lines)];
    }


    /**
     * @param array<string, array<string, mixed>> $targets
     * @param array<string, bool>                 $flags
     */
    protected function renderPlan(array $targets, array $flags, Output $output, bool $json): void
    {
        $plan      = [];
        $paths     = (array) config('migration.paths', []);
        $inspector = $this->app->make(PackageInspector::class);

        foreach ($targets as $name => $meta) {
            $migrationDir = (string) ($meta['migration_dir'] ?? '');

            $plan[$name] = [
                'ident'      => $meta['ident'],
                'version'    => $meta['version'],
                'declared'   => $inspector->declarationSummary($meta),
                'migrations' => count((array) ($meta['migrations'] ?? [])),
                'seeds'      => count((array) ($meta['seeds'] ?? [])),
                'migration_path_registered' => $migrationDir === '' || in_array($migrationDir, $paths, true),
                'actions'    => $this->planActions($meta, $flags),
            ];
        }

        $menus = ['registered' => [], 'skipped' => [], 'errors' => []];

        try {
            $menus = $this->app->make(\app\common\service\PackageMenuRegistrar::class)->register(true, true);
        } catch (\Throwable $e) {
            $menus['errors']['*'] = $e->getMessage();
        }

        if ($json) {
            $output->writeln((string) json_encode([
                'dry_run'  => true,
                'packages' => $plan,
                'menus'    => $menus,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return;
        }

        $output->writeln('<comment>[dry-run] 不执行任何变更，仅预览计划：</comment>');

        foreach ($plan as $name => $row) {
            $output->writeln('');
            $output->writeln(sprintf('  <info>%s</info>  <comment>(%s @ %s)</comment>', $name, $row['ident'], $row['version'] ?: '-'));
            $output->writeln(sprintf('    声明：%s', $row['declared']));
            $output->writeln(sprintf('    迁移 %d 个 / seeds %d 个', $row['migrations'], $row['seeds']));

            if (($row['migrations'] ?? 0) > 0) {
                $output->writeln(sprintf(
                    '    迁移目录已注册：%s%s',
                    $row['migration_path_registered'] ? '<info>是</info>' : '<error>否（ServiceProvider 未调用 registerMigrations()）</error>',
                    ''
                ));
            }

            $output->writeln(sprintf('    将执行：%s', $row['actions'] === [] ? '无' : implode(' → ', $row['actions'])));
        }

        if ($menus['registered'] !== [] || $menus['skipped'] !== [] || $menus['errors'] !== []) {
            $output->writeln('');
            $output->writeln('<comment>包菜单声明（extra.think.menus）：</comment>');

            foreach ($menus['registered'] as $ident => $info) {
                $output->writeln(sprintf(
                    '  <info>%s</info>  %s（%d 容器 / %d 节点）',
                    $ident,
                    $info['package'],
                    $info['containers'],
                    $info['items']
                ));
            }

            foreach ($menus['skipped'] as $package => $message) {
                $output->writeln(sprintf('  <comment>%s</comment>  %s', $package, $message));
            }

            foreach ($menus['errors'] as $package => $message) {
                $output->writeln(sprintf('  <error>%s</error>  %s', $package, $message));
            }
        }

        $output->writeln('');
    }

    /**
     * @param array<string, mixed> $meta
     * @param array<string, bool>  $flags
     * @return string[]
     */
    protected function planActions(array $meta, array $flags): array
    {
        $actions = [];

        if ($flags['discover']) {
            $actions[] = 'service:discover';
        }
        if ($flags['migrate'] && ($meta['migrations'] ?? []) !== []) {
            $actions[] = 'plugin:migrate:register';
            $actions[] = 'migrate:run';
        }
        if ($flags['seed'] && ($meta['seeds'] ?? []) !== []) {
            $actions[] = sprintf('seeds×%d', count((array) $meta['seeds']));
        }
        if ($flags['lifecycle']) {
            $installables = (new PackageLifecycle())->detect($meta);
            if ($installables !== []) {
                $actions[] = sprintf('install()×%d', count($installables));
            }
        }
        if ($flags['menu']) {
            $actions[] = 'menu:sync';
        }
        if ($flags['publish']) {
            $actions[] = 'wise:vendor:publish';
        }
        if ($flags['register']) {
            $actions[] = 'registry.record';
        }

        return $actions;
    }

    /**
     * @param array<string, mixed> $result
     */
    protected function renderResult(array $result, Output $output, bool $json): void
    {
        if ($json) {
            $output->writeln((string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return;
        }

        $output->writeln('');

        foreach ($result['packages'] as $name => $entry) {
            $status = $entry['errors'] === [] ? '<info>OK</info>' : '<error>FAIL</error>';
            $output->writeln(sprintf('  %s  %s', $status, $name));

            if ($entry['seeds'] !== []) {
                $output->writeln('      seeds：' . implode(', ', $entry['seeds']));
            }

            foreach ($entry['errors'] as $error) {
                $output->writeln('      <error>' . $error . '</error>');
            }
        }

        $output->writeln('');
        $output->writeln($result['ok']
            ? '<info>全部完成。</info>'
            : '<error>存在失败步骤，请根据上方输出处理后可重复执行本命令（幂等）。</error>');
    }

    /**
     * 截断过长输出（避免 JSON / 终端被刷屏）
     */
    protected function tail(string $output, int $max = 4000): string
    {
        $output = trim($output);

        return strlen($output) > $max ? '…' . substr($output, -$max) : $output;
    }
}
