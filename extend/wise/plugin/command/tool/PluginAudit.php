<?php

declare(strict_types=1);

namespace wise\plugin\command\tool;

use RuntimeException;
use think\console\Command;
use think\console\Input;
use think\console\input\Option as InputOption;
use think\console\Output;
use wise\command\concern\HasJsonOutput;
use wise\plugin\audit\AuditIssue;
use wise\plugin\audit\AuditPackage;
use wise\plugin\audit\AuditRuleSet;
use wise\plugin\audit\AuditRunner;

/**
 * 插件本地预检（`plugin:audit`）。
 *
 * **纯静态、零依赖、不连库、不联网（除非显式 --ruleset 指向 URL）**：
 * 只覆盖「结构 / 清单 / 低敏正则提示」三类，用于把**确定性的无效上传**挡在提交之前。
 *
 * 与 `wise-market` 的职责边界（重要）：
 *  - 本地：结构与清单判定（error，可阻断）+ 代码危险写法提示（warn，不阻断）；
 *  - 服务端：AST 深度扫描、后门/外传判定、抄袭比对、人工审核 —— 本地不做，也不假装能做。
 * 因此**本命令不输出「通过」结论**，只输出「预检结果」，避免制造安全幻觉。
 *
 * 用法：
 * ```bash
 * php think plugin:audit --plugin=demo                 # 审已安装插件目录
 * php think plugin:audit --path=dist/mycms             # 审任意目录（上架前的源码目录）
 * php think plugin:audit --path=dist/mycms.zip         # 审最终产物（推荐）
 * php think plugin:audit --all                         # 审全部插件
 * php think plugin:audit --plugin=demo --strict         # warn 也视为失败（CI）
 * php think plugin:audit --plugin=demo --format=json    # 供 CI / AI 消费
 * php think plugin:audit --show-rules                   # 查看本地规则清单
 * php think plugin:audit --path=x.zip --ruleset=rules.json --ruleset-sha256=...
 * ```
 *
 * 退出码：`0` 无 error；`1` 存在 error（或 --strict 下存在 warn）；`2` 参数/读取/规则库错误。
 */
class PluginAudit extends Command
{
    use HasJsonOutput;

    /** 退出码：无阻断问题 */
    protected const EXIT_OK = 0;

    /** 退出码：存在阻断问题（或 --strict 下的 warn） */
    protected const EXIT_FOUND = 1;

    /** 退出码：参数 / IO / 规则库错误（未能完成审计） */
    protected const EXIT_ERROR = 2;

    protected function configure(): void
    {
        $this->setName('plugin:audit')
            ->addOption('plugin', 'p', InputOption::VALUE_REQUIRED, '插件标识（目录名，可逗号分隔）')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, '待审计目录或 zip 包路径')
            ->addOption('all', null, InputOption::VALUE_NONE, '审计 plugins/ 下全部插件')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, '输出格式：text / json（与 --json 等价）', 'text')
            ->addJsonOption()
            ->addOption('strict', null, InputOption::VALUE_NONE, '把 warn 也视为失败（CI 用）')
            ->addOption('ruleset', null, InputOption::VALUE_REQUIRED, '外部规则库（本地路径或 http(s) URL），仅覆盖 code.regex.*')
            ->addOption('ruleset-sha256', null, InputOption::VALUE_REQUIRED, '外部规则库的 sha256（提供则强校验）')
            ->addOption('show-rules', null, InputOption::VALUE_NONE, '打印本地规则清单后退出')
            ->setDescription('本地预检插件包（结构 / 清单 / 正则提示），不做 AST 深度扫描');
    }

    protected function execute(Input $input, Output $output): int
    {
        $format = strtolower(trim((string) $input->getOption('format')));

        if (!in_array($format, ['text', 'json'], true)) {
            $output->error('未知输出格式：' . $format . '（可选 text / json）');

            return self::EXIT_ERROR;
        }

        if ((bool) $input->getOption('show-rules')) {
            try {
                $this->renderRules($output);
            } catch (RuntimeException $e) {
                $output->error('规则库加载失败：' . $e->getMessage());

                return self::EXIT_ERROR;
            }

            return self::EXIT_OK;
        }

        try {
            $rules = AuditRuleSet::load(
                $this->nullableString($input->getOption('ruleset')),
                $this->nullableString($input->getOption('ruleset-sha256'))
            );
        } catch (RuntimeException $e) {
            $output->error('规则库加载失败：' . $e->getMessage());

            return self::EXIT_ERROR;
        }

        $targets = $this->resolveTargets($input, $output);

        if ($targets === null) {
            return self::EXIT_ERROR;
        }

        $runner  = new AuditRunner($rules['regex']);
        $results = [];
        $fatal   = false;

        foreach ($targets as $path) {
            $result = [
                'path'   => $path,
                'label'  => basename($path),
                'type'   => is_dir($path) ? 'dir' : 'zip',
                'fatal'  => null,
                'issues' => [],
            ];

            try {
                $package            = AuditPackage::open($path);
                $result['label']    = $package->label();
                $result['type']     = $package->type();
                $result['issues']   = $runner->run($package);
            } catch (RuntimeException $e) {
                $result['fatal'] = $e->getMessage();
                $fatal           = true;
            }

            $results[] = $result;
        }

        $summary = $this->summarize($results);

        if ($this->isJsonOutput($input)) {
            $payload = $this->renderJson($results, $summary, $rules['version'], (bool) $input->getOption('strict'), $fatal);
            $output->writeln($payload, Output::OUTPUT_RAW);
        } else {
            $this->renderText($results, $summary, $rules['version'], $output);
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
     * 解析审计目标。
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
            $output->error('请指定 --plugin=插件标识、--path=目录或 zip，或 --all（本命令不会静默扫描全部插件）');

            return null;
        }

        $targets = [];

        if ($path !== '') {
            $targets[$this->normalizePath($path)] = $this->normalizePath($path);
        }

        if ($plugin !== '') {
            foreach (explode(',', $plugin) as $ident) {
                $ident = trim($ident);

                if ($ident === '') {
                    continue;
                }

                if (preg_match('~^[A-Za-z0-9_]+$~', $ident) !== 1) {
                    $output->error('插件标识不合法（只允许字母、数字、下划线）：' . $ident);

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
     * @param array<int, array{path: string, label: string, type: string, fatal: string|null, issues: array<int, AuditIssue>}> $results
     * @param array{error: int, warn: int, issues: int}                                                                       $summary
     */
    protected function renderText(array $results, array $summary, string $rulesetVersion, Output $output): void
    {
        $output->writeln(sprintf('<info>plugin:audit</info>  规则集 %s', $rulesetVersion));
        $output->writeln('<comment>本地仅覆盖结构 / 清单 / 低敏正则提示；AST 深度扫描与人工审核在服务端进行。</comment>');
        $output->writeln('');

        foreach ($results as $result) {
            $output->writeln(sprintf(
                '<info>▶ %s</info>  [%s]  %s',
                $this->escape($result['label']),
                $result['type'],
                $this->escape($result['path'])
            ));

            if ($result['fatal'] !== null) {
                $output->writeln('  <error>[ERROR] 无法读取，已跳过：' . $this->escape($result['fatal']) . '</error>');
                $output->writeln('');

                continue;
            }

            if ($result['issues'] === []) {
                $output->writeln('  <info>未发现结构 / 清单问题，也未命中低敏正则规则。</info>');
                $output->writeln('  <comment>注意：这不等于可通过上架审核，服务端仍会做 AST 扫描与人工审核。</comment>');
                $output->writeln('');

                continue;
            }

            foreach ($result['issues'] as $issue) {
                $tag   = $issue->isError() ? 'error' : 'comment';
                $label = $issue->isError() ? 'ERROR' : 'WARN ';

                $location = $issue->location();

                $output->writeln(sprintf(
                    '  <%s>[%s]</%s> %s%s',
                    $tag,
                    $label,
                    $tag,
                    $this->escape($issue->rule),
                    $location === '' ? '' : '  ' . $this->escape($location)
                ));

                $output->writeln('        ' . $this->escape($issue->message));

                if ($issue->hint !== '') {
                    $output->writeln('        <comment>↳ ' . $this->escape($issue->hint) . '</comment>');
                }
            }

            $counts = $this->countIssues($result['issues']);

            $output->writeln(sprintf(
                '  结果：<error>%d error</error> / <comment>%d warn</comment>',
                $counts['error'],
                $counts['warn']
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
        $output->writeln('<comment>预检结论不构成上架依据：AST 深度扫描与人工审核在服务端进行，本地无法覆盖。</comment>');

        if ($summary['error'] === 0) {
            $output->writeln('<info>本地未发现阻断级问题。请把包提交到市场，等待服务端审核结论。</info>');
        }
    }

    /**
     * @param array<int, array{path: string, label: string, type: string, fatal: string|null, issues: array<int, AuditIssue>}> $results
     * @param array{error: int, warn: int, issues: int}                                                                       $summary
     */
    protected function renderJson(
        array $results,
        array $summary,
        string $rulesetVersion,
        bool $strict,
        bool $fatal
    ): string {
        $targets = [];

        foreach ($results as $result) {
            $counts = $this->countIssues($result['issues']);

            $targets[] = [
                'path'   => $result['path'],
                'label'  => $result['label'],
                'type'   => $result['type'],
                'fatal'  => $result['fatal'],
                'counts' => $counts,
                'issues' => array_map(
                    static fn (AuditIssue $issue): array => $issue->toArray(),
                    $result['issues']
                ),
            ];
        }

        $payload = [
            'ok'       => !$fatal && $summary['error'] === 0 && (!$strict || $summary['warn'] === 0),
            'ruleset'  => $rulesetVersion,
            'strict'   => $strict,
            'note'     => '本地预检不构成上架依据；AST 深度扫描与人工审核在服务端进行。',
            'counts'   => [
                'error'   => $summary['error'],
                'warn'    => $summary['warn'],
                'targets' => count($results),
            ],
            'targets'  => $targets,
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? '{"ok":false,"error":"json_encode failed"}' : $json;
    }

    protected function renderRules(Output $output): void
    {
        $rules = AuditRuleSet::metadata();

        $output->writeln(sprintf('<info>本地规则集</info>  %s（%d 条）', AuditRuleSet::version(), count($rules)));
        $output->writeln('<comment>ERROR 会阻断；code.regex.* 一律只提示（正则无法判定上下文，不能阻断上架）。</comment>');
        $output->writeln('');

        foreach ($rules as $id => $meta) {
            $tag = $meta['level'] === AuditIssue::ERROR ? 'error' : 'comment';

            $output->writeln(sprintf(
                '  <%s>%-7s</%s> %-44s %s',
                $tag,
                strtoupper($meta['level']),
                $tag,
                $this->escape($id),
                $this->escape($meta['title'])
            ));
        }

        $output->writeln('');
        $output->writeln('说明：外部规则库（--ruleset）只能覆盖 code.regex.*，且强制按 warn 处理。');
    }

    /**
     * @param array<int, AuditIssue> $issues
     *
     * @return array{error: int, warn: int}
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
     * @param array<int, array{path: string, label: string, type: string, fatal: string|null, issues: array<int, AuditIssue>}> $results
     *
     * @return array{error: int, warn: int, issues: int}
     */
    protected function summarize(array $results): array
    {
        $summary = ['error' => 0, 'warn' => 0, 'issues' => 0];

        foreach ($results as $result) {
            $counts = $this->countIssues($result['issues']);
            $summary['error'] += $counts['error'];
            $summary['warn'] += $counts['warn'];
            $summary['issues'] += $counts['error'] + $counts['warn'];

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
