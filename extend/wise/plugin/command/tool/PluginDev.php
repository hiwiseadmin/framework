<?php

declare(strict_types=1);

namespace wise\plugin\command\tool;

use think\console\Command;
use think\console\Input;
use think\console\input\Option as InputOption;
use think\console\Output;
use wise\command\concern\HasJsonOutput;
use wise\plugin\dev\DevDriftDetector;
use wise\plugin\dev\DevSyncer;
use wise\plugin\PluginContainerKeys;
use wise\plugin\PluginManager;

/**
 * 插件开发期同步器（`plugin:dev`）。
 *
 * **不是 watcher**：不做文件监听、不起守护进程、不做 livereload —— 一条命令、显式触发、
 * 可预览、可重复。理由见方桼�在单人维护的框架里，"帮倒忙"的代价高于省几次手动执行。
 *
 * 用法：
 * ```bash
 * php think plugin:dev                          # 只读诊断四类漂移（默认行为）
 * php think plugin:dev --status                 # 同上（显式写法）
 * php think plugin:dev --assets                 # 按差异复制静态资源到 public/
 * php think plugin:dev --refresh                # 清扫描缓存 + 同步菜单/权限
 * php think plugin:dev --migrate                # 列出待执行迁移，确认后执行
 * php think plugin:dev --all                    # 依次 assets → refresh → migrate，遇错即停
 * php think plugin:dev -p demo --assets         # 只处理一个插件
 * php think plugin:dev --all --dry-run          # 只报告将做什么，不写文件/库
 * php think plugin:dev --all --force            # 跳过确认（CI 必须显式使用）
 * php think plugin:dev --json                   # 机器可读（stdout 只有 JSON）
 * ```
 *
 * 三条硬规则：
 *  1. **默认只读**：不带写选项时只诊断，不写任何东西；
 *  2. **写操作必须可预览**：`--refresh` / `--migrate` 先打印「将改什么」，`--force` 才跳过确认；
 *     非交互模式（`-n`）下**不猜默认值**，没有 `--force` 直接退出 2；
 *  3. **幂等**：`--assets` 只复制有差异的文件；菜单注册只补建/恢复；迁移跳过已执行项。
 *
 * 明确不做：安装/卸载（`plugin:install` / `plugin:uninstall`）、清 opcache / 路由缓存
 * （命令行与 FPM 是两套内存，要清缓存请用 `php think clear`）。
 *
 * 退出码：`0` 无漂移或执行成功 ｜ `1` 存在漂移（诊断/预览模式）｜ `2` 参数错误 / 执行失败。
 */
class PluginDev extends Command
{
    use HasJsonOutput;

    protected const EXIT_OK = 0;

    protected const EXIT_DRIFT = 1;

    protected const EXIT_ERROR = 2;

    /** 步骤标识（与选项名一致） */
    protected const STEP_ASSETS = 'assets';

    protected const STEP_REFRESH = 'refresh';

    protected const STEP_MIGRATE = 'migrate';

    protected function configure(): void
    {
        $this->setName('plugin:dev')
            ->setDescription('开发期同步器：只读诊断漂移，或显式同步静态资源 / 扫描缓存 / 迁移')
            ->addOption('plugin', 'p', InputOption::VALUE_REQUIRED, '只处理指定插件（目录名，可逗号分隔；默认全部插件）')
            ->addOption('status', null, InputOption::VALUE_NONE, '只读诊断（默认行为）')
            ->addOption('assets', null, InputOption::VALUE_NONE, '按差异复制静态资源到 public/statics/plugins/{ident}/')
            ->addOption('refresh', null, InputOption::VALUE_NONE, '清插件扫描缓存并按需同步菜单 / 权限')
            ->addOption('migrate', null, InputOption::VALUE_NONE, '列出并执行待执行迁移')
            ->addOption('all', null, InputOption::VALUE_NONE, '依次执行 assets → refresh → migrate，遇错即停')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, '只报告将做什么，不写文件 / 不写库（写操作降级为预览）')
            ->addOption('force', 'f', InputOption::VALUE_NONE, '跳过写操作的确认（非交互模式必须显式使用）')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, '输出格式：text / json（与 --json 等价）', 'text')
            ->addJsonOption();
    }

    protected function execute(Input $input, Output $output): int
    {
        $format = strtolower(trim((string) $input->getOption('format')));

        if (!in_array($format, ['text', 'json'], true)) {
            $output->error('未知输出格式：' . $format . '（可选 text / json）');

            return self::EXIT_ERROR;
        }

        $json    = $this->isJsonOutput($input);
        $steps   = $this->resolveSteps($input, $output);

        if ($steps === null) {
            return self::EXIT_ERROR;
        }

        $dryRun  = (bool) $input->getOption('dry-run');
        $force   = (bool) $input->getOption('force');
        $manager = $this->app->get(PluginContainerKeys::MANAGER);

        $targets = $this->resolveTargets($input, $output, $manager);

        if ($targets === null) {
            return self::EXIT_ERROR;
        }

        $plugins  = $manager->getAllPlugins();
        $detector = new DevDriftDetector($manager);
        $reports  = [];

        foreach ($targets as $ident) {
            if (!isset($plugins[$ident])) {
                $output->error('插件不存在：' . $ident);

                return self::EXIT_ERROR;
            }

            $reports[$ident] = $detector->detect($plugins[$ident]);
        }

        $drift = $this->anyDrift($reports);

        if ($steps === [] || $dryRun) {
            return $this->finishDiagnosis($output, $json, $reports, $drift, $steps, $dryRun, $targets);
        }

        if ($json && !$force) {
            $this->writeJson($output, [
                'ok'    => false,
                'mode'  => implode('+', $steps),
                'error' => '--json 模式下写操作需显式加 --force（不交互，stdout 只输出 JSON）',
            ]);

            return self::EXIT_ERROR;
        }

        $syncer   = new DevSyncer($manager, $this->app);
        $actions  = [];
        $failed   = false;
        $skipped  = [];
        $cascade  = count($steps) > 1;

        foreach ($steps as $step) {
            $plan = $this->planFor($step, $targets, $reports);

            if ($cascade && $plan['nothing']) {
                $skipped[] = ['step' => $step, 'message' => '无漂移，跳过（幂等）'];

                continue;
            }

            if (!$json) {
                $this->renderPlan($output, $step, $plan);
            }

            $answer = $this->confirmStep($input, $output, $step, $force);

            if ($answer === null) {
                return self::EXIT_ERROR;
            }

            if ($answer === false) {
                $skipped[] = ['step' => $step, 'message' => '已取消（未执行）'];

                break;
            }

            foreach ($targets as $ident) {
                $action    = $this->applyStep($syncer, $step, $ident);
                $actions[] = $action;

                if (!$action['ok']) {
                    $failed = true;

                    break 2;
                }
            }
        }

        return $this->finishExecution($output, $json, $reports, $drift, $steps, $actions, $skipped, $failed);
    }


    /**
     * 解析要执行的步骤。
     *
     * @return array<int, string>|null 空数组 = 只读诊断；null = 参数非法
     */
    protected function resolveSteps(Input $input, Output $output): ?array
    {
        $status  = (bool) $input->getOption('status');
        $assets  = (bool) $input->getOption('assets');
        $refresh = (bool) $input->getOption('refresh');
        $migrate = (bool) $input->getOption('migrate');
        $all     = (bool) $input->getOption('all');

        $write = [];

        if ($assets) {
            $write[] = self::STEP_ASSETS;
        }

        if ($refresh) {
            $write[] = self::STEP_REFRESH;
        }

        if ($migrate) {
            $write[] = self::STEP_MIGRATE;
        }

        if ($all) {
            if ($write !== []) {
                $output->error('--all 已包含 assets / refresh / migrate，不要再单独指定（语义重叠易误判）');

                return null;
            }

            return [self::STEP_ASSETS, self::STEP_REFRESH, self::STEP_MIGRATE];
        }

        if ($status && $write !== []) {
            $output->error('--status 是只读诊断，不能与 --assets / --refresh / --migrate 同时使用');

            return null;
        }

        if (count($write) > 1) {
            $output->error('写操作一次只能选一个（--assets / --refresh / --migrate），或用 --all 串联');

            return null;
        }

        return $write;
    }

    /**
     * 解析目标插件（默认全部；`--plugin` 可逗号分隔多个）。
     *
     * @return array<int, string>|null null = 参数非法
     */
    protected function resolveTargets(Input $input, Output $output, PluginManager $manager): ?array
    {
        $plugin = trim((string) $input->getOption('plugin'));

        if ($plugin === '') {
            $plugins = $manager->getAllPlugins();

            if ($plugins === []) {
                $output->error('未发现任何插件（plugins/ 下没有含 manifest.json 的目录）');

                return null;
            }

            return array_keys($plugins);
        }

        $targets = [];

        foreach (explode(',', $plugin) as $ident) {
            $ident = trim($ident);

            if ($ident === '') {
                continue;
            }

            if (preg_match('/^[A-Za-z0-9_-]+$/', $ident) !== 1) {
                $output->error('插件标识不合法（只允许字母、数字、下划线、连字符）：' . $ident);

                return null;
            }

            $targets[$ident] = $ident;
        }

        if ($targets === []) {
            $output->error('--plugin 为空');

            return null;
        }

        return array_values($targets);
    }


    /**
     * 生成某一步骤的预览计划（写操作之前必须打印）。
     *
     * @param array<int, string>               $targets
     * @param array<string, array<string,mixed>> $reports
     *
     * @return array{nothing: bool, lines: array<int, string>}
     */
    protected function planFor(string $step, array $targets, array $reports): array
    {
        $lines   = [];
        $nothing = true;

        foreach ($targets as $ident) {
            $report = $reports[$ident];
            $keys   = match ($step) {
                self::STEP_ASSETS  => ['assets'],
                self::STEP_REFRESH => ['cache', 'menus', 'permissions'],
                default            => ['migrations'],
            };

            foreach ($keys as $key) {
                $section = $this->sectionOf($report, $key);

                if ($section === null) {
                    continue;
                }

                if ($section['state'] === 'drift' || $section['state'] === 'error') {
                    $nothing = false;
                    $lines[] = sprintf('%s  %s：%s', $ident, $section['title'], $section['summary']);

                    foreach (array_slice($section['details'], 0, 5) as $detail) {
                        $lines[] = '    ' . $detail;
                    }
                }
            }
        }

        return ['nothing' => $nothing, 'lines' => $lines];
    }

    /**
     * 确认写操作。
     *
     * @return bool|null true = 执行；false = 用户取消；null = 非交互缺 --force（应退出 2）
     */
    protected function confirmStep(Input $input, Output $output, string $step, bool $force): ?bool
    {
        if ($force) {
            return true;
        }

        if (!$input->isInteractive()) {
            $output->error('非交互模式（-n）不会执行写操作：请显式加 --force');

            return null;
        }

        $questions = [
            self::STEP_ASSETS  => '确认按差异复制静态资源到 public/？',
            self::STEP_REFRESH => '确认清扫描缓存并同步菜单 / 权限（写库）？',
            self::STEP_MIGRATE => '确认执行待执行迁移（写库，不可自动回滚）？',
        ];

        return (bool) $output->confirm($input, $questions[$step] ?? '确认执行？', false);
    }

    /**
     * 真正执行一步。
     *
     * @return array{step: string, ident: string, ok: bool, message: string, data: mixed}
     */
    protected function applyStep(DevSyncer $syncer, string $step, string $ident): array
    {
        try {
            if (self::STEP_ASSETS === $step) {
                $result = $syncer->syncAssets($ident);

                return [
                    'step'    => $step,
                    'ident'   => $ident,
                    'ok'      => $result['errors'] === 0,
                    'message' => sprintf(
                        '已复制 %d 个文件（一致 %d，失败 %d）',
                        $result['copied'],
                        $result['same'],
                        $result['errors']
                    ),
                    'data'    => $result,
                ];
            }

            if (self::STEP_REFRESH === $step) {
                $result = $syncer->refresh($ident);

                return [
                    'step'    => $step,
                    'ident'   => $ident,
                    'ok'      => (bool) $result['ok'],
                    'message' => trim(implode('；', array_filter([
                        $result['menus']['message'],
                        $result['permissions']['message'],
                        $result['error'],
                    ]))) ?: '扫描缓存已刷新',
                    'data'    => $result,
                ];
            }

            $result = $syncer->migrate($ident);

            return [
                'step'    => $step,
                'ident'   => $ident,
                'ok'      => (bool) $result['ok'],
                'message' => $result['message'] === '' ? '迁移执行完成' : $result['message'],
                'data'    => $result,
            ];
        } catch (\Throwable $e) {
            return [
                'step'    => $step,
                'ident'   => $ident,
                'ok'      => false,
                'message' => $e->getMessage(),
                'data'    => [],
            ];
        }
    }


    /**
     * @param array<string, array<string,mixed>> $reports
     */
    protected function finishDiagnosis(
        Output $output,
        bool $json,
        array $reports,
        bool $drift,
        array $steps,
        bool $dryRun,
        array $targets = []
    ): int {
        if ($json) {
            $this->writeJson($output, [
                'ok'      => !$drift,
                'mode'    => $dryRun && $steps !== [] ? 'dry-run' : 'status',
                'dry_run' => $dryRun,
                'drift'   => $drift,
                'counts'  => $this->counts($reports),
                'plugins' => array_values($reports),
                'actions' => [],
            ]);

            return $drift ? self::EXIT_DRIFT : self::EXIT_OK;
        }

        $output->writeln('<info>plugin:dev</info>  只读诊断（静态资源 / 扫描缓存 / 数据库迁移 / 菜单权限）');

        if ($dryRun && $steps !== []) {
            $output->writeln('<comment>--dry-run：仅预览，不写文件、不写库。</comment>');
        }

        $output->writeln('');

        foreach ($reports as $report) {
            $this->renderPlugin($output, $report);
        }

        if ($dryRun && $steps !== [] && $targets !== []) {
            foreach ($steps as $step) {
                $this->renderPlan($output, $step, $this->planFor($step, $targets, $reports));
            }

            $output->writeln('');
        }

        $counts = $this->counts($reports);

        $output->writeln(str_repeat('─', 60));
        $output->writeln(sprintf(
            '合计：%d 个插件，%s',
            $counts['plugins'],
            $counts['drift'] === 0
                ? '<info>无漂移</info>'
                : sprintf('<error>%d 个存在漂移</error>', $counts['drift'])
        ));

        if ($drift) {
            $output->writeln('提示：--assets / --refresh / --migrate 逐项同步，--all 依次执行；写操作会先打印计划并确认。');
        }

        return $drift ? self::EXIT_DRIFT : self::EXIT_OK;
    }

    /**
     * @param array<string, array<string,mixed>> $reports
     */
    protected function finishExecution(
        Output $output,
        bool $json,
        array $reports,
        bool $drift,
        array $steps,
        array $actions,
        array $skipped,
        bool $failed
    ): int {
        $ok = !$failed;

        if ($json) {
            $this->writeJson($output, [
                'ok'      => $ok,
                'mode'    => implode('+', $steps),
                'dry_run' => false,
                'drift'   => $drift,
                'counts'  => $this->counts($reports),
                'plugins' => array_values($reports),
                'actions' => $actions,
                'skipped' => $skipped,
            ]);

            return $ok ? self::EXIT_OK : self::EXIT_ERROR;
        }

        $output->writeln('');
        $output->writeln(str_repeat('─', 60));

        foreach ($actions as $action) {
            $tag = $action['ok'] ? '<info>OK   </info>' : '<error>FAIL </error>';

            $output->writeln(sprintf(
                '%s %-8s %-16s %s',
                $tag,
                $action['step'],
                $this->escape($action['ident']),
                $this->escape($action['message'])
            ));
        }

        foreach ($skipped as $item) {
            $output->writeln(sprintf('<comment>SKIP</comment> %-8s %s', $item['step'], $this->escape($item['message'])));
        }

        if ($actions === [] && $skipped === []) {
            $output->writeln('<comment>未执行任何操作。</comment>');
        }

        if ($failed) {
            $output->writeln('<error>执行中断：遇到失败已停止（--all 不会继续后续步骤）。</error>');

            return self::EXIT_ERROR;
        }

        $output->writeln('<info>完成</info>：所有步骤执行成功（重复执行无副作用）。');

        return self::EXIT_OK;
    }

    protected function renderPlan(Output $output, string $step, array $plan): void
    {
        $output->writeln('');
        $output->writeln(sprintf('<info>计划</info>  %s', $step));

        if ($plan['lines'] === []) {
            $output->writeln('  <comment>无漂移：该步骤将什么都不做（幂等）</comment>');

            return;
        }

        foreach ($plan['lines'] as $line) {
            $output->writeln('  ' . $this->escape($line));
        }
    }

    /**
     * 渲染单个插件报告。
     *
     * @param array<string, mixed> $report
     */
    protected function renderPlugin(Output $output, array $report): void
    {
        $output->writeln(sprintf(
            '<info>%s</info>  %s  [%s · %s]',
            $this->escape((string) $report['ident']),
            $this->escape((string) $report['display_name']),
            $report['installed'] ? '已安装' : '未安装',
            $report['enabled'] ? '已启用' : '未启用'
        ));

        foreach ($report['sections'] as $section) {
            [$tag, $style] = match ($section['state']) {
                'drift' => ['DRIFT', 'error'],
                'error' => ['FAIL ', 'error'],
                'skipped' => ['SKIP ', 'comment'],
                default => ['OK   ', 'info'],
            };

            $output->writeln(sprintf(
                '  <%s>[%s]</%s> %-12s %s',
                $style,
                $tag,
                $style,
                $this->escape((string) $section['title']),
                $this->escape((string) $section['summary'])
            ));

            foreach ($section['details'] as $detail) {
                $output->writeln('           ' . $this->escape((string) $detail));
            }

            if ($section['note'] !== '' && $section['state'] !== 'clean') {
                $output->writeln('           <comment>↳ ' . $this->escape((string) $section['note']) . '</comment>');
            }
        }

        $output->writeln('');
    }


    /**
     * @param array<string, array<string,mixed>> $reports
     */
    protected function anyDrift(array $reports): bool
    {
        foreach ($reports as $report) {
            if ($report['drift']) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, array<string,mixed>> $reports
     *
     * @return array{plugins:int, drift:int}
     */
    protected function counts(array $reports): array
    {
        $drift = 0;

        foreach ($reports as $report) {
            if ($report['drift']) {
                $drift++;
            }
        }

        return ['plugins' => count($reports), 'drift' => $drift];
    }

    /**
     * @param array<string, mixed> $report
     *
     * @return array<string, mixed>|null
     */
    protected function sectionOf(array $report, string $key): ?array
    {
        foreach ((array) $report['sections'] as $section) {
            if ($section['key'] === $key) {
                return $section;
            }
        }

        return null;
    }

    /**
     * 转义 Symfony 输出格式标签，避免路径里的 `<` 被当作样式标签。
     */
    protected function escape(string $text): string
    {
        return str_replace('<', '\\<', $text);
    }
}
