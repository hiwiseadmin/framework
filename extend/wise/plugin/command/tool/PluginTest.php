<?php

declare(strict_types=1);

namespace wise\plugin\command\tool;

use RuntimeException;
use think\console\Command;
use think\console\Input;
use think\console\input\Option as InputOption;
use think\console\Output;
use think\facade\Db;
use wise\command\concern\HasJsonOutput;
use wise\plugin\audit\AuditIssue;
use wise\plugin\audit\AuditPackage;
use wise\plugin\contract\ContractCaseSet;
use wise\plugin\contract\ContractRunner;
use wise\plugin\PluginContainerKeys;
use wise\plugin\test\IntegrationRunner;
use wise\plugin\test\TestEnvConfig;

/**
 * 插件契约检查（`plugin:test`）。
 *
 * 两种模式，互不替代：
 *
 * | 模式 | 覆盖 | 依赖 | 用途 |
 * |:--|:--|:--|:--|
 * | `unit`（默认） | 静态契约 15 项（见 {@see ContractRunner}）：语法 / namespace / provider / 菜单权限 / 迁移 / 资源 / 路由 | 无 DB、无网络 | 提交前秒级自检 |
 * | `integration` | 真实装 → 停 → 启 → 卸全链路：建表 / 菜单权限落库 / 迁移回滚 / 残留检查 / 资源清理 | **独立测试库** | 上架前把关 |
 *
 * ## integration 的安全边界
 *
 * 它会**建表、删表、写菜单权限、删 public 资源**，因此：
 *  1. 目标库由「测试环境」决定：
 *     - `--test-env=<name>` 或 `config('plugin.test_env')` 非空 → 子进程加载 `.env.{name}`（推荐）；
 *     - 都为空 → **直接在当前真实库执行**，此时会打印醒目警告；
 *  2. `--expect-db=<库名>`：提供时必须与当前连接一致；**真实库 + `--force`（非交互）时必填**，
 *     测试环境（`.env.{name}`）模式下可选 —— 只靠 `--force` 拦不住脚本误连生产；
 *  3. 必须显式确认；非交互或 `--json` 下必须 `--force`；
 *  4. 默认先跑一遍 `unit`，静态有 error 就不进库（坏包没必要建表）；
 *  5. 任一步失败会尝试无痕卸载把当前库恢复干净，避免下次从"装了一半"开始。
 *
 * 模式既可用 `--mode=unit|integration` 指定，也可用 `--unit` / `--integration` 简写；
 * 两者同时出现视为参数错误。简写优先于 `--mode`，避免脚本里两种写法混用时行为漂移。
 *
 * 用法：
 * ```bash
 * # unit（默认，无 DB）
 * php think plugin:test -p demo                 # 单个插件
 * php think plugin:test --unit -p demo          # 显式 unit（与 README / 方案文档一致）
 * php think plugin:test --path=dist/mycms.zip   # 最终产物（推荐）
 * php think plugin:test --all --strict          # 全量 + CI 门禁
 * php think plugin:test -p demo --json          # 机器可读
 * php think plugin:test --show-cases            # 打印内置用例清单
 *
 * # integration（写库）
 * php think plugin:test -p demo --integration --test-env=test --expect-db=wise_test   # 推荐：切到 .env.test
 * php think plugin:test -p demo --integration --expect-db=wiseadmin_test              # 当前环境 + 库名断言
 * php think plugin:test -p demo --integration --force                                 # 当前真实库（会警告）
 * php think plugin:test -p demo --integration --dry-run                              # 只预览目标库/计划
 * ```
 *
 * 退出码：`0` 无不一致；`1` 存在 error（或 `--strict` 下的 warn）；`2` 参数 / 读取 / 用例集 / 库守卫错误。
 */
class PluginTest extends Command
{
    use HasJsonOutput;

    protected const EXIT_OK = 0;

    protected const EXIT_FOUND = 1;

    protected const EXIT_ERROR = 2;

    protected function configure(): void
    {
        $this            ->setName('plugin:test')
            ->setDescription('插件契约检查：unit（静态）/ integration（真实装-启-停-卸；目标库由 --test-env 或当前环境决定）')
            ->addOption('plugin', 'p', InputOption::VALUE_REQUIRED, '插件标识（目录名，可逗号分隔）')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, '待检查目录或 zip 包路径（仅 unit）')
            ->addOption('all', null, InputOption::VALUE_NONE, '检查 plugins/ 下全部插件')
            ->addOption('mode', null, InputOption::VALUE_REQUIRED, '检查模式：unit（默认，无 DB）/ integration（写库；目标库见 --test-env）', 'unit')
            ->addOption('unit', null, InputOption::VALUE_NONE, '等价 --mode=unit（静态契约，不写库）')
            ->addOption('integration', null, InputOption::VALUE_NONE, '等价 --mode=integration（真实装-启-停-卸；优先 --test-env，未配置则写当前库）')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, '输出格式：text / json（与 --json 等价）', 'text')
            ->addJsonOption()
            ->addOption('strict', null, InputOption::VALUE_NONE, '把 warn 也视为失败（CI 用）')
            ->addOption('caseset', null, InputOption::VALUE_REQUIRED, '外部用例集文件（仅本地路径；版本不一致会提示）')
            ->addOption('caseset-sha256', null, InputOption::VALUE_REQUIRED, '外部用例集的 sha256（提供则强校验）')
            ->addOption('show-cases', null, InputOption::VALUE_NONE, '打印内置用例清单后退出')
            ->addOption('test-env', null, InputOption::VALUE_REQUIRED, 'integration：切到 .env.{name} 后执行（如 --test-env=test）；省略则读 config(plugin.test_env)，仍为空则直接在当前真实库执行')
            ->addOption('expect-db', null, InputOption::VALUE_REQUIRED, 'integration：库名断言；提供时必须与当前连接一致。真实库 + --force 时**必填**（防脚本误连生产）')
            ->addOption('purge', null, InputOption::VALUE_NONE, 'integration：卸载时用无痕模式（物理删菜单/权限、清迁移记录、DROP 残留表）')
            ->addOption('skip-unit', null, InputOption::VALUE_NONE, 'integration：跳过前置静态契约检查（不推荐）')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'integration：只解析目标库与执行计划，不写库、不需 --force')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'integration：跳过写库确认（非交互与 --json 必须显式使用）');
    }

    protected function execute(Input $input, Output $output): int
    {
        $format = strtolower(trim((string) $input->getOption('format')));

        if (!in_array($format, ['text', 'json'], true)) {
            $output->error('未知输出格式：' . $format . '（可选 text / json）');

            return self::EXIT_ERROR;
        }

        $mode = $this->resolveMode($input, $output);

        if ($mode === null) {
            return self::EXIT_ERROR;
        }

        if ((bool) $input->getOption('show-cases')) {
            $this->renderCases($output);

            return self::EXIT_OK;
        }

        if ($mode === 'unit' && !$this->isJsonOutput($input)) {
            $ignored = [];

            foreach ([
                'test-env'  => '--test-env',
                'expect-db' => '--expect-db',
                'purge'     => '--purge',
                'skip-unit' => '--skip-unit',
                'dry-run'   => '--dry-run',
                'force'     => '--force',
            ] as $option => $flag) {
                if ((bool) $input->getOption($option)) {
                    $ignored[] = $flag;
                }
            }

            if ($ignored !== []) {
                $output->writeln(sprintf(
                    '<comment>提示：%s 仅对 integration 生效，unit 模式已忽略。</comment>',
                    implode(' / ', $ignored)
                ));
            }
        }

        if ($mode === 'integration') {
            $delegated = $this->maybeRunInTestEnv($input, $output);

            if ($delegated !== null) {
                return $delegated;
            }

            return $this->executeIntegration($input, $output);
        }

        return $this->executeUnit($input, $output);
    }

    /**
     * 测试环境委派：有 `.env.{name}` 就用子进程跑，否则返回 null 走当前环境。
     *
     * 目标库来源优先级：`--test-env` > `config('plugin.test_env')` > 当前 `.env`。
     *
     * @return int|null null = 不需要委派（当前进程直接执行）
     */
    protected function maybeRunInTestEnv(Input $input, Output $output): ?int
    {
        if (TestEnvConfig::isChild()) {
            return null;
        }

        if (trim((string) $input->getOption('path')) !== '') {
            return null;
        }

        $name = TestEnvConfig::resolve(
            (string) $input->getOption('test-env'),
            (string) config('plugin.test_env', '')
        );

        if ($name === '') {
            return null;
        }

        $json = $this->isJsonOutput($input);

        $fail = function (string $message) use ($json, $output): int {
            if ($json) {
                $this->writeJson($output, ['ok' => false, 'error' => 'test_env', 'message' => $message]);
            } else {
                $output->error($message);
            }

            return self::EXIT_ERROR;
        };

        try {
            TestEnvConfig::assertValidName($name);
        } catch (\InvalidArgumentException $e) {
            return $fail($e->getMessage());
        }

        $root = (string) $this->app->getRootPath();

        if (!TestEnvConfig::exists($root, $name)) {
            return $fail(sprintf(
                '警告：测试环境不可用 —— 找不到 %s。请复制一份完整的 .env（不能只写 DB_*）并把 DB_NAME 改成测试库，'
                . '或清空 config(plugin.test_env) / 去掉 --test-env 回到当前真实库执行（退出码 2，未写库）',
                TestEnvConfig::envFile($root, $name)
            ));
        }

        if (!function_exists('proc_open')) {
            return $fail(
                'proc_open 不可用（可能被 disable_functions 禁用），无法拉起测试环境子进程。'
                . '请开启 proc_open，或清空 config(plugin.test_env) / 不传 --test-env 直接在当前环境执行'
            );
        }

        if (!$json) {
            $output->writeln(sprintf(
                '<comment>测试环境</comment> .env.%s（子进程执行；父进程不连接数据库）',
                $this->escape($name)
            ));
        }

        return $this->spawnTestEnvChild($input, $output, $root, $name, $json);
    }

    /**
     * 用 `PHP_ENV_NAME` 拉起子进程（子进程 bootstrap 时会加载 `.env.{name}`）。
     */
    protected function spawnTestEnvChild(Input $input, Output $output, string $root, string $name, bool $json): int
    {
        $command = $this->buildChildCommand($input, $root);

        $env = getenv();
        $env['PHP_ENV_NAME']          = $name;
        $env[TestEnvConfig::CHILD_MARKER] = '1';

        $descriptors = [1 => STDOUT, 2 => STDERR];
        $process     = @proc_open($command, $descriptors, $pipes, $root, $env);

        if (!is_resource($process)) {
            if ($json) {
                $this->writeJson($output, [
                    'ok'      => false,
                    'error'   => 'test_env_spawn',
                    'message' => '测试环境子进程启动失败',
                ]);
            } else {
                $output->error('测试环境子进程启动失败（proc_open 返回非资源）');
            }

            return self::EXIT_ERROR;
        }

        $code = proc_close($process);

        return $code >= 0 ? $code : self::EXIT_ERROR;
    }

    /**
     * 重建子进程命令：只带 integration 相关参数，**故意不传 `--test-env`**（避免递归）。
     */
    protected function buildChildCommand(Input $input, string $root): string
    {
        $think = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . 'think';

        $parts = [
            escapeshellarg(PHP_BINARY),
            escapeshellarg($think),
            'plugin:test',
            '--integration',
        ];

        $plugin = trim((string) $input->getOption('plugin'));

        if ($plugin !== '') {
            $parts[] = '-p';
            $parts[] = escapeshellarg($plugin);
        }

        if ((bool) $input->getOption('all')) {
            $parts[] = '--all';
        }

        foreach ([
            'expect-db'      => '--expect-db=',
            'caseset'        => '--caseset=',
            'caseset-sha256' => '--caseset-sha256=',
        ] as $option => $prefix) {
            $value = trim((string) $input->getOption($option));

            if ($value !== '') {
                $parts[] = $prefix . escapeshellarg($value);
            }
        }

        foreach ([
            'purge'     => '--purge',
            'skip-unit' => '--skip-unit',
            'dry-run'   => '--dry-run',
            'force'     => '--force',
            'strict'    => '--strict',
        ] as $option => $flag) {
            if ((bool) $input->getOption($option)) {
                $parts[] = $flag;
            }
        }

        if ($this->isJsonOutput($input)) {
            $parts[] = '--json';
        }

        return implode(' ', $parts);
    }

    /**
     * 解析检查模式：`--mode` 与 `--unit` / `--integration` 简写共用同一出口。
     *
     * 规则：
     *  - 两个简写同时出现 → 参数错误（不猜用户意图）；
     *  - 简写优先于 `--mode`（脚本里两种写法混用时不因默认值而静默改变行为）；
     *  - `--unit` 与显式的 `--mode=integration` 冲突 → 参数错误。
     *
     * @return string|null null = 参数非法（已输出错误）
     */
    protected function resolveMode(Input $input, Output $output): ?string
    {
        $unit        = (bool) $input->getOption('unit');
        $integration = (bool) $input->getOption('integration');
        $mode        = strtolower(trim((string) $input->getOption('mode')));

        if ($unit && $integration) {
            $output->error('--unit 与 --integration 互斥，请只指定一个');

            return null;
        }

        if ($unit && $mode === 'integration') {
            $output->error('--unit 与 --mode=integration 冲突，请只指定一个');

            return null;
        }

        if ($integration) {
            $mode = 'integration';
        } elseif ($unit) {
            $mode = 'unit';
        }

        if (!in_array($mode, ['unit', 'integration'], true)) {
            $output->error('未知检查模式：' . $mode . '（可选 unit / integration）');

            return null;
        }

        return $mode;
    }

    /**
     * `--mode=unit`：静态契约检查（无 DB / 无网络）。
     */
    protected function executeUnit(Input $input, Output $output): int
    {
        $caseset = $this->loadCaseset($input, $output);

        if ($caseset === null) {
            return self::EXIT_ERROR;
        }

        $targets = $this->resolveTargets($input, $output);

        if ($targets === null) {
            return self::EXIT_ERROR;
        }

        $results = [];
        $fatal   = false;

        foreach ($targets as $path) {
            $result = $this->inspectTarget($path, $caseset);

            if ($result['fatal'] !== null) {
                $fatal = true;
            }

            $results[] = $result;
        }

        $summary = $this->summarize($results);

        if ($this->isJsonOutput($input)) {
            $this->writeJson($output, $this->buildJson(
                $results,
                $summary,
                $caseset['version'],
                (bool) $input->getOption('strict'),
                $fatal
            ));
        } else {
            $this->renderText($results, $summary, $caseset['version'], $output);
        }

        if ($fatal) {
            return self::EXIT_ERROR;
        }

        if ($summary['error'] > 0) {
            return self::EXIT_FOUND;
        }

        if ((bool) $input->getOption('strict') && $summary['warn'] > 0) {
            return self::EXIT_FOUND;
        }

        return self::EXIT_OK;
    }

    /**
     * 检查单个目标（目录或 zip），返回渲染用的结果数组。
     *
     * @param array{version:string, cases:array<string, array{group:string, level:string, title:string}>, mismatch:bool, builtin_version:string} $caseset
     *
     * @return array{path:string,label:string,type:string,ident:string,fatal:string|null,issues:array<int,AuditIssue>,checks:int,skipped:array<string,string>}
     */
    protected function inspectTarget(string $path, array $caseset): array
    {
        $result = [
            'path'    => $path,
            'label'   => basename($path),
            'type'    => is_dir($path) ? AuditPackage::TYPE_DIR : AuditPackage::TYPE_ZIP,
            'ident'   => basename($path),
            'fatal'   => null,
            'issues'  => [],
            'checks'  => 0,
            'skipped' => [],
        ];

        try {
            $package = AuditPackage::open($path);
            $ident   = $this->resolveIdent($package, $path);

            $run = (new ContractRunner(
                $caseset['cases'],
                $ident,
                $caseset['mismatch'],
                $caseset['version'],
                $caseset['builtin_version']
            ))->run($package);

            $result['label']   = $package->label();
            $result['type']    = $package->type();
            $result['ident']   = $ident;
            $result['issues']  = $run['issues'];
            $result['checks']  = $run['checks'];
            $result['skipped'] = $run['skipped'];
        } catch (RuntimeException $e) {
            $result['fatal'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * @return array{version:string, cases:array<string, array{group:string, level:string, title:string}>, mismatch:bool, builtin_version:string}|null
     */
    protected function loadCaseset(Input $input, Output $output): ?array
    {
        try {
            return ContractCaseSet::load(
                $this->nullableString($input->getOption('caseset')),
                $this->nullableString($input->getOption('caseset-sha256'))
            );
        } catch (RuntimeException $e) {
            $output->error('用例集加载失败：' . $e->getMessage());

            return null;
        }
    }

    /**
     * 解析检查目标（与 `plugin:audit` 同一口径：--plugin / --path / --all 互斥组合）。
     *
     * @return array<int, string>|null null = 参数非法（已输出错误）
     */
    protected function resolveTargets(Input $input, Output $output): ?array
    {
        $plugin = trim((string) $input->getOption('plugin'));
        $path   = trim((string) $input->getOption('path'));
        $all    = (bool) $input->getOption('all');

        if ($all && ($plugin !== '' || $path !== '')) {
            $output->error('--all 与 --plugin / --path 互斥');

            return null;
        }

        if (!$all && $plugin === '' && $path === '') {
            $output->error('请指定 --plugin=插件标识、--path=目录或 zip，或 --all');

            return null;
        }

        $targets = [];

        if ($path !== '') {
            $normalized = $this->normalizePath($path);
            $targets[$normalized] = $normalized;
        }

        if ($plugin !== '') {
            foreach (explode(',', $plugin) as $ident) {
                $ident = trim($ident);

                if ($ident === '') {
                    continue;
                }

                if (preg_match('/^[A-Za-z0-9_-]+$/', $ident) !== 1) {
                    $output->error('插件标识不合法（只允许字母、数字、下划线、连字符）：' . $ident);

                    return null;
                }

                $dir = rtrim(str_replace('\\', '/', plugin_path()), '/') . '/' . $ident;

                if (!is_dir($dir)) {
                    $output->error('插件目录不存在：' . $dir);

                    return null;
                }

                $targets[$this->normalizePath($dir)] = $this->normalizePath($dir);
            }
        }

        if ($all) {
            foreach (glob(rtrim(str_replace('\\', '/', plugin_path()), '/') . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
                if (!is_file($dir . '/manifest.json')) {
                    continue;
                }

                $targets[$this->normalizePath($dir)] = $this->normalizePath($dir);
            }

            if ($targets === []) {
                $output->error('未发现任何含 manifest.json 的插件目录');

                return null;
            }
        }

        ksort($targets);

        return array_values($targets);
    }

    /**
     * 由 manifest 的 name/ident 推断插件标识；解析不出来时退回目录名。
     */
    protected function resolveIdent(AuditPackage $package, string $path): string
    {
        if ($package->structureType() !== 'broken') {
            $raw = $package->read($package->manifestPath());

            if ($raw !== null) {
                $data = json_decode($raw, true);

                if (is_array($data)) {
                    $ident = trim((string) ($data['name'] ?? $data['ident'] ?? ''));

                    if ($ident !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $ident) === 1) {
                        return $ident;
                    }
                }
            }
        }

        $base = $package->rootDir() !== ''
            ? trim((string) $package->rootDir(), '/')
            : basename($this->normalizePath($path));

        return preg_match('/^[A-Za-z0-9_-]+$/', $base) === 1 ? $base : 'plugin';
    }


    /**
     * `--dry-run`：只解析目标库与执行计划，不写库、不需 `--force`。
     *
     * 安全用途：在真正建表/删表之前，先确认「会打到哪个库、会动哪些插件」。
     * 与真实的 integration 共用同一套「测试环境 / 真实库」解析逻辑，避免预览与执行不一致。
     *
     * @param array<int, string>                                                       $targets
     * @param array{name:string,host:string,port:string,mode:'env'|'real',env:string}  $database
     */
    protected function renderIntegrationDryRun(
        Output $output,
        bool $json,
        array $database,
        array $targets,
        bool $purge,
        bool $skipUnit
    ): int {
        $steps = [];

        if (!$skipUnit) {
            $steps[] = '静态预检（unit）';
        }

        $steps[] = '安装';
        $steps[] = '停用';
        $steps[] = '启用';
        $steps[] = '卸载' . ($purge ? '（无痕）' : '');

        $targetRows = array_map(
            static fn (string $path): array => ['ident' => basename($path), 'path' => $path],
            array_values($targets)
        );

        if ($json) {
            $this->writeJson($output, [
                'ok'        => true,
                'mode'      => 'integration',
                'dry_run'   => true,
                'database'  => $database,
                'purge'     => $purge,
                'skip_unit' => $skipUnit,
                'steps'     => $steps,
                'targets'   => $targetRows,
                'message'   => 'dry-run：未写库、未改动任何数据',
            ]);

            return self::EXIT_OK;
        }

        $output->writeln(sprintf(
            '<info>plugin:test --mode=integration（dry-run）</info>  %s ｜ 库 %s（%s:%s） ｜ %s',
            ($database['mode'] ?? 'real') === 'env'
                ? '测试环境 .env.' . $this->escape($database['env'])
                : '<error>当前环境（真实库）</error>',
            $this->escape($database['name']),
            $this->escape($database['host']),
            $this->escape($database['port']),
            $purge ? '无痕卸载' : '普通卸载（菜单/权限软删）'
        ));

        if (($database['mode'] ?? 'real') === 'real') {
            $output->writeln(
                '<error>警告：未配置测试环境，真实执行时会在该库建表/删表/写菜单权限。</error>'
            );
        }

        $output->writeln('将执行：' . implode(' → ', $steps));
        $output->writeln(sprintf('目标 %d 个：', count($targetRows)));

        foreach ($targetRows as $row) {
            $output->writeln(sprintf('  - %s  %s', $this->escape($row['ident']), $this->escape($row['path'])));
        }

        $output->writeln('');
        $output->writeln('<info>dry-run：未写库、未改动任何数据。</info>');

        return self::EXIT_OK;
    }

    /**
     * `--mode=integration`：真实装 → 停 → 启 → 卸（会写库；目标库见类注释「安全边界」）。
     */
    protected function executeIntegration(Input $input, Output $output): int
    {
        $json  = $this->isJsonOutput($input);
        $force = (bool) $input->getOption('force');

        if (trim((string) $input->getOption('path')) !== '') {
            $message = 'integration 模式（--integration / --mode=integration）只支持 --plugin / --all：zip 与任意目录无法走安装生命周期';

            if ($json) {
                $this->writeJson($output, ['ok' => false, 'error' => 'bad_option', 'message' => $message]);
            } else {
                $output->error($message);
            }

            return self::EXIT_ERROR;
        }

        $database = $this->guardIntegrationDb($input, $output, $json);

        if ($database === null) {
            return self::EXIT_ERROR;
        }

        $targets = $this->resolveTargets($input, $output);

        if ($targets === null) {
            return self::EXIT_ERROR;
        }

        $skipUnit = (bool) $input->getOption('skip-unit');
        $dryRun   = (bool) $input->getOption('dry-run');

        if ($dryRun) {
            return $this->renderIntegrationDryRun(
                $output,
                $json,
                $database,
                $targets,
                (bool) $input->getOption('purge'),
                $skipUnit
            );
        }

        $expectDb = trim((string) $input->getOption('expect-db'));

        if ($force && ($database['mode'] ?? 'real') === 'real' && $expectDb === '') {
            $message = '非交互（--force）写真实库必须显式 --expect-db=<当前库名>；'
                . '推荐改用 --test-env=<name> 指向 .env.{name}，或先 --dry-run 确认目标库';

            if ($json) {
                $this->writeJson($output, ['ok' => false, 'error' => 'expect_db_required', 'message' => $message]);
            } else {
                $output->error($message);
            }

            return self::EXIT_ERROR;
        }

        $caseset = null;

        if (!$skipUnit) {
            $caseset = $this->loadCaseset($input, $output);

            if ($caseset === null) {
                return self::EXIT_ERROR;
            }
        }

        if (!$json && ($database['mode'] ?? 'real') === 'real') {
            $output->writeln(sprintf(
                '<error>警告：未配置测试环境（plugin.test_env / --test-env），本次将在**当前真实库** %s（%s:%s）执行。</error>',
                $this->escape($database['name']),
                $this->escape($database['host']),
                $this->escape($database['port'])
            ));
        }

        if ($json && !$force) {
            $this->writeJson($output, [
                'ok'      => false,
                'error'   => 'force_required',
                'message' => '--json 模式下 integration 会写库，需显式加 --force',
            ]);

            return self::EXIT_ERROR;
        }

        if (!$force) {
            if (!$input->isInteractive()) {
                $output->error('非交互模式（-n）不会写库：请显式加 --force');

                return self::EXIT_ERROR;
            }

            $output->writeln(sprintf(
                '<comment>即将在 %s 的库 %s（%s:%s）上对 %d 个插件执行：安装 → 停用 → 启用 → 卸载（建表/删表/写菜单权限）</comment>',
                ($database['mode'] ?? 'real') === 'env'
                    ? '测试环境 .env.' . $this->escape((string) ($database['env'] ?? ''))
                    : '当前环境（真实库）',
                $this->escape($database['name']),
                $this->escape($database['host']),
                $this->escape($database['port']),
                count($targets)
            ));

            if (!$output->confirm($input, '确认继续？', false)) {
                $output->writeln('<comment>已取消（未写库）</comment>');

                return self::EXIT_OK;
            }
        }

        $manager = $this->app->get(PluginContainerKeys::MANAGER);
        $plugins = $manager->getAllPlugins();
        $runner  = new IntegrationRunner($manager);

        $results = [];
        $failed  = false;

        foreach ($targets as $path) {
            $ident = basename($path);

            if (!isset($plugins[$ident])) {
                $failed    = true;
                $results[] = [
                    'ident'  => $ident,
                    'path'   => $path,
                    'fatal'  => '插件未出现在扫描列表（检查目录名与 manifest.json）',
                    'unit'   => null,
                    'steps'  => [],
                    'issues' => [],
                    'counts' => ['error' => 1, 'warn' => 0],
                ];

                continue;
            }

            $unit = null;

            if (!$skipUnit) {
                $unit       = $this->inspectTarget($path, $caseset);
                $unitCounts = $this->countIssues($unit['issues']);

                if ($unit['fatal'] !== null || $unitCounts['error'] > 0) {
                    $failed    = true;
                    $results[] = [
                        'ident'  => $ident,
                        'path'   => $path,
                        'fatal'  => '静态契约检查未通过，未进入数据库集成测试',
                        'unit'   => $unit,
                        'steps'  => [],
                        'issues' => $unit['issues'],
                        'counts' => $unitCounts,
                    ];

                    continue;
                }
            }

            $run    = $runner->run($ident, $plugins[$ident], (bool) $input->getOption('purge'));
            $counts = $this->countIssues($run['issues']);

            if ($counts['error'] > 0) {
                $failed = true;
            }

            $results[] = [
                'ident'  => $ident,
                'path'   => $path,
                'fatal'  => null,
                'unit'   => $unit,
                'steps'  => $run['steps'],
                'issues' => $run['issues'],
                'counts' => $counts,
            ];
        }

        if ($json) {
            $this->writeJson($output, [
                'ok'       => !$failed,
                'mode'     => 'integration',
                'database' => $database,
                'purge'    => (bool) $input->getOption('purge'),
                'counts'   => $this->summarizeIntegration($results),
                'targets'  => array_map(function (array $result): array {
                    return [
                        'ident'  => $result['ident'],
                        'path'   => $result['path'],
                        'fatal'  => $result['fatal'],
                        'counts' => $result['counts'],
                        'steps'  => $result['steps'],
                        'issues' => array_map(static fn (AuditIssue $issue): array => $issue->toArray(), $result['issues']),
                        'unit'   => $result['unit'] === null ? null : [
                            'checks' => (int) $result['unit']['checks'],
                            'counts' => $this->countIssues($result['unit']['issues']),
                        ],
                    ];
                }, $results),
                'note'     => '本模式会建表/删表：只允许对独立测试库执行，结果不代表上架审核结论。',
            ]);

            return $failed ? self::EXIT_FOUND : self::EXIT_OK;
        }

        $this->renderIntegration($output, $results, $database, (bool) $input->getOption('purge'), $skipUnit);

        return $failed ? self::EXIT_FOUND : self::EXIT_OK;
    }

    /**
     * 数据库守卫：确认目标库（`--expect-db` 可选，提供则强校验）。
     *
     * @return array{name:string,host:string,port:string,mode:'env'|'real',env:string}|null
     */
    protected function guardIntegrationDb(Input $input, Output $output, bool $json): ?array
    {
        $expect  = trim((string) $input->getOption('expect-db'));
        $envName = (string) getenv('PHP_ENV_NAME');

        $fail = function (string $message) use ($output, $json): ?array {
            if ($json) {
                $this->writeJson($output, ['ok' => false, 'error' => 'db_guard', 'message' => $message]);
            } else {
                $output->error($message);
            }

            return null;
        };

        try {
            $rows = Db::query('SELECT DATABASE() AS db');
            $name = (string) ($rows[0]['db'] ?? '');

            $conn    = (string) Db::getConfig('default', 'mysql');
            $connCfg = (array) ((Db::getConfig('connections')[$conn] ?? []));
            $host    = (string) ($connCfg['hostname'] ?? '');
            $port    = (string) ($connCfg['hostport'] ?? '');
        } catch (\Throwable $e) {
            return $fail('数据库守卫查询失败（integration 依赖 `SELECT DATABASE()`，仅支持 MySQL 连接）：' . $e->getMessage());
        }

        if ($name === '') {
            return $fail('当前连接没有选中数据库，integration 拒绝执行（检查 .env 的 DB_NAME）');
        }

        if ($expect !== '' && $name !== $expect) {
            return $fail(sprintf(
                '数据库守卫拒绝执行：当前连接库为 %s（%s:%s），与 --expect-db=%s 不一致。'
                . '请切到测试库，或用 --test-env=<name> / config(plugin.test_env) 指定 .env.{name}。',
                $name,
                $host,
                $port,
                $expect
            ));
        }

        return [
            'name' => $name,
            'host' => $host,
            'port' => $port,
            'mode' => $envName !== '' ? 'env' : 'real',
            'env'  => $envName,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $results
     */
    protected function renderIntegration(
        Output $output,
        array $results,
        array $database,
        bool $purge,
        bool $skipUnit
    ): void {
        $output->writeln(sprintf(
            '<info>plugin:test --mode=integration</info>  %s ｜ 库 %s（%s:%s）%s',
            ($database['mode'] ?? 'real') === 'env'
                ? '测试环境 .env.' . $this->escape((string) ($database['env'] ?? ''))
                : '<error>当前环境（真实库）</error>',
            $this->escape($database['name']),
            $this->escape($database['host']),
            $this->escape($database['port']),
            $purge ? ' ｜ 无痕卸载' : ' ｜ 普通卸载（菜单/权限软删）'
        ));

        if (($database['mode'] ?? 'real') === 'real') {
            $output->writeln('<error>注意：未配置测试环境，本次直接写当前真实库；结果不代表上架审核结论。</error>');
        } else {
            $output->writeln('<comment>本模式会建表/删表/写菜单权限：结果不代表上架审核结论。</comment>');
        }
        $output->writeln('');

        foreach ($results as $result) {
            $output->writeln(sprintf(
                '<info>▶ %s</info>  %s',
                $this->escape((string) $result['ident']),
                $this->escape((string) $result['path'])
            ));

            if ($skipUnit) {
                $output->writeln('  静态预检 <comment>已跳过（--skip-unit）</comment>');
            } elseif ($result['unit'] !== null) {
                $unitCounts = $this->countIssues($result['unit']['issues']);
                $output->writeln(sprintf(
                    '  静态预检 %s ｜ %d 项用例',
                    $unitCounts['error'] === 0 ? '<info>0 error</info>' : '<error>' . $unitCounts['error'] . ' error</error>',
                    (int) $result['unit']['checks']
                ));
            }

            if ($result['fatal'] !== null) {
                $output->writeln('  <error>[ERROR] ' . $this->escape((string) $result['fatal']) . '</error>');
            }

            foreach ((array) $result['steps'] as $step) {
                $output->writeln(sprintf(
                    '  %s %-34s %6dms',
                    $step['ok'] ? '<info>[OK  ]</info>' : '<error>[FAIL]</error>',
                    $this->escape((string) $step['title']),
                    (int) $step['ms']
                ));

                foreach ((array) $step['details'] as $line) {
                    $output->writeln('         ' . $this->escape((string) $line));
                }
            }

            foreach ((array) $result['issues'] as $issue) {
                $tag   = $issue->isError() ? 'error' : 'comment';
                $label = $issue->isError() ? 'ERROR' : 'WARN ';

                $output->writeln(sprintf(
                    '  <%s>[%s]</%s> %s',
                    $tag,
                    $label,
                    $tag,
                    $this->escape($issue->message)
                ));

                if ($issue->rule !== '') {
                    $output->writeln('         <comment>' . $this->escape($issue->rule) . '</comment>');
                }
            }

            $counts = (array) $result['counts'];

            $output->writeln(sprintf(
                '  结果：<error>%d error</error> / <comment>%d warn</comment>',
                (int) ($counts['error'] ?? 0),
                (int) ($counts['warn'] ?? 0)
            ));
            $output->writeln('');
        }

        $summary = $this->summarizeIntegration($results);

        $output->writeln(str_repeat('─', 60));
        $output->writeln(sprintf(
            '合计：%d 个插件 ｜ %d 个步骤（失败 %d）｜ <error>%d error</error> / <comment>%d warn</comment>',
            $summary['targets'],
            $summary['steps'],
            $summary['step_errors'],
            $summary['error'],
            $summary['warn']
        ));

        if ($summary['error'] === 0) {
            $output->writeln('<info>装 → 停 → 启 → 卸 全链路未发现残留或状态异常。</info>');
        } else {
            $output->writeln('<comment>失败项多为「迁移 down() 未删表 / 菜单权限未清 / 资源未删」，按提示修插件而不是改测试。</comment>');
        }
    }

    /**
     * @param array<int, array<string, mixed>> $results
     *
     * @return array{targets:int, steps:int, step_errors:int, error:int, warn:int}
     */
    protected function summarizeIntegration(array $results): array
    {
        $summary = ['targets' => count($results), 'steps' => 0, 'step_errors' => 0, 'error' => 0, 'warn' => 0];

        foreach ($results as $result) {
            $counts = (array) ($result['counts'] ?? []);
            $summary['error'] += (int) ($counts['error'] ?? 0);
            $summary['warn'] += (int) ($counts['warn'] ?? 0);

            foreach ((array) ($result['steps'] ?? []) as $step) {
                $summary['steps']++;

                if (empty($step['ok'])) {
                    $summary['step_errors']++;
                }
            }
        }

        return $summary;
    }

    /**
     * @param array<int, array<string, mixed>> $results
     */
    protected function renderText(array $results, array $summary, string $casesetVersion, Output $output): void
    {
        $output->writeln(sprintf('<info>plugin:test</info>  契约用例集 %s（unit，无 DB / 无网络）', $casesetVersion));
        $output->writeln('<comment>本地契约检查不构成上架依据：装/启/停/卸全链路、多框架矩阵与人工审核在服务端进行。</comment>');
        $output->writeln('');

        foreach ($results as $result) {
            $output->writeln(sprintf(
                '<info>▶ %s</info>  [%s]  %s  ident=%s',
                $this->escape((string) $result['label']),
                $result['type'],
                $this->escape((string) $result['path']),
                $this->escape((string) $result['ident'])
            ));

            if ($result['fatal'] !== null) {
                $output->writeln('  <error>[ERROR] 无法读取，已跳过：' . $this->escape((string) $result['fatal']) . '</error>');
                $output->writeln('');

                continue;
            }

            foreach ($result['issues'] as $issue) {
                $tag   = $issue->isError() ? 'error' : 'comment';
                $label = $issue->isError() ? 'ERROR' : 'WARN ';
                $where = $issue->location();

                $output->writeln(sprintf(
                    '  <%s>[%s]</%s> %s%s',
                    $tag,
                    $label,
                    $tag,
                    $this->escape($issue->rule),
                    $where === '' ? '' : '  ' . $this->escape($where)
                ));

                $output->writeln('        ' . $this->escape($issue->message));

                if ($issue->hint !== '') {
                    $output->writeln('        <comment>↳ ' . $this->escape($issue->hint) . '</comment>');
                }
            }

            if ($result['issues'] === []) {
                $output->writeln('  <info>未发现与用例集不一致的地方。</info>');
            }

            foreach ((array) $result['skipped'] as $caseId => $reason) {
                $output->writeln(sprintf('  <comment>SKIP</comment> %s  %s', $this->escape((string) $caseId), $this->escape((string) $reason)));
            }

            $counts = $this->countIssues($result['issues']);

            $output->writeln(sprintf(
                '  结果：<error>%d error</error> / <comment>%d warn</comment> ｜ %d 项用例 / %d 项跳过',
                $counts['error'],
                $counts['warn'],
                (int) $result['checks'],
                count((array) $result['skipped'])
            ));
            $output->writeln('');
        }

        $output->writeln(str_repeat('─', 60));
        $output->writeln(sprintf(
            '合计：<error>%d error</error> / <comment>%d warn</comment>（%d 个目标）',
            $summary['error'],
            $summary['warn'],
            count($results)
        ));

        if ($summary['error'] === 0) {
            $output->writeln('<info>未发现阻断级不一致。</info>');
        }
    }

    /**
     * @param array<int, array<string, mixed>> $results
     */
    protected function buildJson(array $results, array $summary, string $casesetVersion, bool $strict, bool $fatal): array
    {
        $targets = [];

        foreach ($results as $result) {
            $targets[] = [
                'path'    => $result['path'],
                'label'   => $result['label'],
                'type'    => $result['type'],
                'ident'   => $result['ident'],
                'fatal'   => $result['fatal'],
                'checks'  => (int) $result['checks'],
                'counts'  => $this->countIssues($result['issues']),
                'issues'  => array_map(static fn (AuditIssue $issue): array => $issue->toArray(), $result['issues']),
                'skipped' => (object) $result['skipped'],
            ];
        }

        return [
            'ok'       => !$fatal && $summary['error'] === 0 && (!$strict || $summary['warn'] === 0),
            'mode'     => 'unit',
            'caseset'  => $casesetVersion,
            'strict'   => $strict,
            'note'     => '本地契约检查不构成上架依据；装/启/停/卸全链路与服务端 CI 在服务端进行。',
            'counts'   => [
                'error'   => $summary['error'],
                'warn'    => $summary['warn'],
                'checks'  => $summary['checks'],
                'skipped' => $summary['skipped'],
                'targets' => count($results),
            ],
            'targets'  => $targets,
        ];
    }

    protected function renderCases(Output $output): void
    {
        $cases = ContractCaseSet::metadata();

        $output->writeln(sprintf('<info>契约用例集</info>  %s（%d 项）', ContractCaseSet::load()['version'], count($cases)));
        $output->writeln('<comment>ERROR 阻断；contract.route_targets / menus_path_scope 等启发式用例只提示（静态无法判定上下文）。</comment>');
        $output->writeln('');

        foreach ($cases as $id => $meta) {
            $tag = $meta['level'] === AuditIssue::ERROR ? 'error' : 'comment';

            $output->writeln(sprintf(
                '  <%s>%-5s</%s> %-32s %-10s %s',
                $tag,
                strtoupper($meta['level']),
                $tag,
                $this->escape($id),
                $this->escape($meta['group']),
                $this->escape($meta['title'])
            ));
        }

        $output->writeln('');
        $output->writeln('未知 case id（外部用例集比本地新）会被跳过，而不判失败 —— 那是用例版本不一致，不是插件的问题。');
    }

    /**
     * @param array<int, AuditIssue> $issues
     *
     * @return array{error:int, warn:int}
     */
    protected function countIssues(array $issues): array
    {
        $counts = ['error' => 0, 'warn' => 0];

        foreach ($issues as $issue) {
            $issue->isError() ? $counts['error']++ : $counts['warn']++;
        }

        return $counts;
    }

    /**
     * @param array<int, array<string, mixed>> $results
     *
     * @return array{error:int, warn:int, checks:int, skipped:int}
     */
    protected function summarize(array $results): array
    {
        $summary = ['error' => 0, 'warn' => 0, 'checks' => 0, 'skipped' => 0];

        foreach ($results as $result) {
            $counts = $this->countIssues($result['issues']);
            $summary['error'] += $counts['error'];
            $summary['warn'] += $counts['warn'];
            $summary['checks'] += (int) $result['checks'];
            $summary['skipped'] += count((array) $result['skipped']);

            if ($result['fatal'] !== null) {
                $summary['error']++;
            }
        }

        return $summary;
    }

    protected function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    protected function normalizePath(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    /**
     * 转义 Symfony 输出格式标签，避免代码片段里的 `<` 被当作样式标签。
     */
    protected function escape(string $text): string
    {
        return str_replace('<', '\\<', $text);
    }
}
