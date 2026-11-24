<?php

declare(strict_types=1);

namespace wise\plugin\command\tool;

use think\console\Command;
use think\console\Input;
use think\console\input\Option as InputOption;
use think\console\Output;
use wise\command\concern\HasJsonOutput;
use wise\plugin\audit\AuditIssue;
use wise\plugin\audit\AuditPackage;
use wise\plugin\audit\AuditRuleSet;
use wise\plugin\audit\AuditRunner;
use wise\plugin\contract\ContractCaseSet;
use wise\plugin\contract\ContractRunner;
use wise\plugin\PluginContainerKeys;
use wise\plugin\publish\PublishClient;
use wise\plugin\publish\PublishPackager;
use wise\plugin\scanner\PluginMetadata;

/**
 * 插件打包发布（`plugin:publish`）。
 *
 * 一条命令完成「做完 → 市场出现新版本」：本地预检 → 打包 → 算 SHA256 → 上传。
 * 设计边界见方桼�
 *  - **preflight 先行**：本地 `error` 即中止，`warn` 只提示不阻断；
 *  - **`--dry-run` 只产包**：不上传，供后台上传/存档兜底；
 *  - **上传后以服务端结论为准**，客户端不猜「是否通过审核」。
 *
 * 用法：
 * ```bash
 * php think plugin:publish -p demo                          # 自动判断首版/新版本
 * php think plugin:publish -p demo --dry-run                # 只打包 + 预检 + 算 sha256
 * php think plugin:publish -p demo --update --force         # 指定发新版本并跳过确认
 * php think plugin:publish -p demo --first                  # 强制首版投稿
 * php think plugin:publish -p demo --json --force           # CI：stdout 只有 JSON
 * ```
 *
 * 身份：会员 JWT，来源优先级 `--token` > env `PLUGIN_PUBLISH_TOKEN` > `config('plugin.publish_token')`。
 * **不接受账号密码**（会把明文留在 shell history / 进程列表里），也**不落盘**。
 *
 * 退出码：`0` 成功 ｜ `1` 预检未通过或用户取消（未上传） ｜ `2` 参数/超限/网络/服务端拒绝。
 *
 * @phpstan-type PreflightShape array{
 *     audit: array{ruleset: string, error: int, warn: int, issues: array<int, AuditIssue>},
 *     contract: array{caseset: string, error: int, warn: int, checks: int, issues: array<int, AuditIssue>, skipped: array<string, string>}
 * }
 */
class PluginPublish extends Command
{
    use HasJsonOutput;

    protected const EXIT_OK = 0;

    protected const EXIT_BLOCKED = 1;

    protected const EXIT_ERROR = 2;

    /** 版本号口径与服务端 `validateSubmitData()` 完全一致 */
    protected const VERSION_PATTERN = '/^\d+\.\d+(\.\d+)?(-[a-zA-Z0-9]+)?$/';

    protected function configure(): void
    {
        $this->setName('plugin:publish')
            ->setDescription('打包并投稿插件：本地预检 → zip → SHA256 → 上传市场（首版或新版本）')
            ->addOption('plugin', 'p', InputOption::VALUE_REQUIRED, '插件标识（目录名，必填）')
            ->addOption('plugin-version', null, InputOption::VALUE_REQUIRED, '版本号（默认取 manifest.version）')
            ->addOption('changelog', null, InputOption::VALUE_REQUIRED, '更新说明，支持 @文件路径；缺省取 CHANGELOG.md 摘要')
            ->addOption('category-id', null, InputOption::VALUE_REQUIRED, '分类 ID（首版投稿用，默认 0）')
            ->addOption('public', null, InputOption::VALUE_NONE, '公开到市场（默认）')
            ->addOption('private', null, InputOption::VALUE_NONE, '不公开（仅自己可见）')
            ->addOption('charge', null, InputOption::VALUE_NONE, '标记为收费插件（需配合 --charge-standard）')
            ->addOption('charge-standard', null, InputOption::VALUE_REQUIRED, '收费标准（JSON 字符串）')
            ->addOption('url', null, InputOption::VALUE_REQUIRED, '插件主页（首版投稿用）')
            ->addOption('doc-url', null, InputOption::VALUE_REQUIRED, '文档地址（首版投稿用）')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, '产物目录（相对应用根或绝对路径）', 'dist')
            ->addOption('market-url', null, InputOption::VALUE_REQUIRED, '市场根地址（默认 config(plugin.service_url)）')
            ->addOption('token', null, InputOption::VALUE_REQUIRED, '会员 JWT（也可用环境变量 PLUGIN_PUBLISH_TOKEN）')
            ->addOption('plugin-id', null, InputOption::VALUE_REQUIRED, '直接指定市场插件 ID（发新版本，跳过查询）')
            ->addOption('first', null, InputOption::VALUE_NONE, '强制首版投稿（市场已有同标识时报错）')
            ->addOption('update', null, InputOption::VALUE_NONE, '强制发新版本（市场无此插件时报错）')
            ->addOption('max-size', null, InputOption::VALUE_REQUIRED, '包体上限（MB，默认 10，与服务端 wise-market.max_size 对齐）', '10')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, '上传超时（秒）', '60')
            ->addOption('skip', null, InputOption::VALUE_REQUIRED, '额外排除项（逗号分隔；按文件名匹配，支持 * ? [abc] glob，大小写不敏感）')
            ->addOption('skip-preflight', null, InputOption::VALUE_NONE, '跳过本地预检（不推荐：服务端仍会拦）')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, '只打包 + 预检 + 算 sha256，不上传')
            ->addOption('force', 'f', InputOption::VALUE_NONE, '跳过上传确认（非交互与 --json 必须显式使用）')
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

        $json   = $this->isJsonOutput($input);
        $dryRun = (bool) $input->getOption('dry-run');
        $force  = (bool) $input->getOption('force');

        $ident = trim((string) $input->getOption('plugin'));

        if ($ident === '') {
            $output->error('请用 --plugin=<标识>（或 -p）指定插件');

            return self::EXIT_ERROR;
        }

        if (preg_match('/^[A-Za-z0-9_-]+$/', $ident) !== 1) {
            $output->error('插件标识不合法（只允许字母、数字、下划线、连字符）：' . $ident);

            return self::EXIT_ERROR;
        }

        $manager = $this->app->get(PluginContainerKeys::MANAGER);
        $plugins = $manager->getAllPlugins();

        if (!isset($plugins[$ident])) {
            $output->error('插件不存在：' . $ident . '（plugins/ 下需有含 manifest.json 的目录）');

            return self::EXIT_ERROR;
        }

        $metadata = $plugins[$ident];

        if ($metadata->isDevelop()) {
            $output->error('该插件 manifest 标记了 develop=true（开发态），不允许发布。');

            return self::EXIT_ERROR;
        }

        $manifestVersion = trim($metadata->getVersion());
        $version         = trim((string) $input->getOption('plugin-version')) ?: $manifestVersion;

        if (preg_match(self::VERSION_PATTERN, $version) !== 1) {
            $output->error(sprintf(
                '版本号格式不正确：%s（应为 1.0.0 或 2.1.0-beta）',
                $version === '' ? '(空)' : $version
            ));

            return self::EXIT_ERROR;
        }

        $versionMismatch = $manifestVersion !== '' && strcasecmp($manifestVersion, $version) !== 0;

        if ($versionMismatch && !$json) {
            $output->writeln(sprintf(
                '<comment>提示：发布版本 %s 与 manifest.version %s 不一致（以 --plugin-version 为准）。</comment>',
                $this->escape($version),
                $this->escape($manifestVersion)
            ));
        }

        $preflight = null;

        if (!(bool) $input->getOption('skip-preflight')) {
            try {
                $preflight = $this->runPreflight($ident);
            } catch (\Throwable $e) {
                $output->error('预检无法完成：' . $e->getMessage());

                return self::EXIT_ERROR;
            }

            if ($preflight['audit']['error'] + $preflight['contract']['error'] > 0) {
                return $this->reportPreflightBlocked($output, $json, $ident, $preflight);
            }
        }

        $packager = new PublishPackager();
        $package  = $packager->package(
            $ident,
            $version,
            $this->resolveOutputDir((string) $input->getOption('output')),
            $this->splitList((string) $input->getOption('skip'))
        );

        if (!$package['ok']) {
            $output->error('打包失败：' . $package['error']);

            return self::EXIT_ERROR;
        }

        $maxBytes = max(1, (int) $input->getOption('max-size')) * 1024 * 1024;

        if ($package['size'] > $maxBytes) {
            $output->error(sprintf(
                '包体 %.2f MB 超过上限 %d MB（服务端 wise-market.max_size）：%s',
                $package['size'] / 1048576,
                (int) $input->getOption('max-size'),
                $package['path']
            ));
            $output->writeln('<comment>请剔除大文件后用 --skip 追加排除，或经后台调整服务端上限。</comment>');

            return self::EXIT_ERROR;
        }

        $fields = $this->buildFields($input, $output, $metadata, $version, $json);

        if ($dryRun) {
            return $this->finishDryRun(
                $output,
                $json,
                $ident,
                $version,
                $metadata,
                $package,
                $preflight,
                $this->intendedTarget($input),
                $this->dryRunEndpoint($input)
            );
        }

        $token = $this->resolveToken($input);

        if ($token === '') {
            $message = '缺少会员 JWT：用 --token=<jwt>，或设置环境变量 PLUGIN_PUBLISH_TOKEN，'
                . '或在 config/plugin.php 的 publish_token 中配置。';

            if ($json) {
                $this->writeJson($output, ['ok' => false, 'error' => 'token_missing', 'message' => $message]);

                return self::EXIT_ERROR;
            }

            $output->error($message);

            return self::EXIT_ERROR;
        }

        $marketBase = $this->resolveMarketBase($input, $output);

        if ($marketBase === null) {
            return self::EXIT_ERROR;
        }

        $timeout = max(5, (int) $input->getOption('timeout'));
        $client  = new PublishClient($marketBase, $token, null, $timeout);

        $target = $this->resolveTarget($input, $output, $client, $ident, $json);

        if ($target === null) {
            return self::EXIT_ERROR;
        }

        if ($json && !$force) {
            $this->writeJson($output, [
                'ok'      => false,
                'error'   => 'force_required',
                'message' => '--json 模式下上传需显式加 --force（不交互，stdout 只输出 JSON）',
            ]);

            return self::EXIT_ERROR;
        }

        if (!$force) {
            if (!$input->isInteractive()) {
                $output->error('非交互模式（-n）不会执行上传：请显式加 --force');

                return self::EXIT_ERROR;
            }

            $this->renderPlan($output, $ident, $version, $package, $preflight, $client->endpoint($target['action']), $target);

            if (!$output->confirm($input, '确认上传到市场？', false)) {
                $output->writeln('<comment>已取消（包已保留在 ' . $package['path'] . '）</comment>');

                return self::EXIT_BLOCKED;
            }
        }

        $payload = $target['fields'] + $fields;

        $result = $target['action'] === 'version'
            ? $client->submitVersion($payload, $package['path'])
            : $client->submit($payload, $package['path']);

        return $this->finishUpload(
            $output,
            $json,
            $ident,
            $version,
            $manifestVersion,
            $package,
            $preflight,
            $client->endpoint($target['action']),
            $target,
            $result
        );
    }


    /**
     * 预检编排：`plugin:audit`（上架闸门口径）+ `plugin:test`（契约口径）串行跑。
     *
     * 两者复用同一份实现与同一个 `AuditPackage` 视图，**不重新扫描、不起子进程**：
     * upload 前多花的是 CPU 上的一次解析，省掉的是「本地绿、服务端红」的往返。
     *
     * @return array{
     *     audit: array{ruleset:string, error:int, warn:int, issues:array<int, AuditIssue>},
     *     contract: array{caseset:string, error:int, warn:int, checks:int, issues:array<int, AuditIssue>, skipped:array<string,string>}
     * }
     */
    protected function runPreflight(string $ident): array
    {
        $package = AuditPackage::open($this->pluginDir($ident));

        $rules       = AuditRuleSet::load(null, null);
        $auditIssues = (new AuditRunner($rules['regex']))->run($package);

        $caseset     = ContractCaseSet::load();
        $contractRun = (new ContractRunner($caseset['cases'], $ident))->run($package);

        return [
            'audit'    => [
                'ruleset' => (string) $rules['version'],
                'error'   => $this->countIssues($auditIssues)['error'],
                'warn'    => $this->countIssues($auditIssues)['warn'],
                'issues'  => $auditIssues,
            ],
            'contract' => [
                'caseset' => (string) $caseset['version'],
                'error'   => $this->countIssues($contractRun['issues'])['error'],
                'warn'    => $this->countIssues($contractRun['issues'])['warn'],
                'checks'  => (int) $contractRun['checks'],
                'issues'  => $contractRun['issues'],
                'skipped' => $contractRun['skipped'],
            ],
        ];
    }

    /**
     * 预检阻断：不打包、不上传。
     *
     * @param array{
     *     audit: array{ruleset:string, error:int, warn:int, issues:array<int, AuditIssue>},
     *     contract: array{caseset:string, error:int, warn:int, checks:int, issues:array<int, AuditIssue>, skipped:array<string,string>}
     * } $preflight
     */
    protected function reportPreflightBlocked(Output $output, bool $json, string $ident, array $preflight): int
    {
        if ($json) {
            $this->writeJson($output, [
                'ok'        => false,
                'error'     => 'preflight_failed',
                'plugin'    => $ident,
                'preflight' => $this->preflightSummary($preflight, true),
                'message'   => '本地预检存在 error 级问题，已中止（未打包、未上传）',
            ]);

            return self::EXIT_BLOCKED;
        }

        $output->writeln(sprintf(
            '<error>预检未通过</error>：audit %d error / %d warn（%s）｜ contract %d error / %d warn（%s，%d 项用例）',
            $preflight['audit']['error'],
            $preflight['audit']['warn'],
            $this->escape($preflight['audit']['ruleset']),
            $preflight['contract']['error'],
            $preflight['contract']['warn'],
            $this->escape($preflight['contract']['caseset']),
            $preflight['contract']['checks']
        ));
        $output->writeln('');

        foreach (['audit', 'contract'] as $group) {
            $errors = array_values(array_filter(
                $preflight[$group]['issues'],
                static fn (AuditIssue $issue): bool => $issue->isError()
            ));

            if ($errors === []) {
                continue;
            }

            $output->writeln(sprintf('<comment>%s（%d 项阻断）</comment>', $group, count($errors)));

            foreach ($errors as $issue) {
                $location = $issue->location();

                $output->writeln(sprintf(
                    '  <error>[ERROR]</error> %s%s',
                    $this->escape($issue->rule),
                    $location === '' ? '' : '  ' . $this->escape($location)
                ));
                $output->writeln('        ' . $this->escape($issue->message));

                if ($issue->hint !== '') {
                    $output->writeln('        <comment>↳ ' . $this->escape($issue->hint) . '</comment>');
                }
            }

            $output->writeln('');
        }

        $skipped = count($preflight['contract']['skipped']);

        if ($skipped > 0) {
            $output->writeln(sprintf(
                '<comment>提示：%d 个契约用例被跳过（本地用例集 %s 可能落后于服务端导出版本）。</comment>',
                $skipped,
                $this->escape($preflight['contract']['caseset'])
            ));
        }

        $output->writeln('<comment>修正后用 `php think plugin:audit -p ' . $ident . '` 与 `php think plugin:test -p '
            . $ident . '` 复查；AST 深度扫描与人工审核仍在服务端。</comment>');

        return self::EXIT_BLOCKED;
    }


    /**
     * 演练/预览时能确定的动作（不查询市场）。
     *
     * @return array{action: string, plugin_id: int}
     */
    protected function intendedTarget(Input $input): array
    {
        $pluginId = (int) $input->getOption('plugin-id');

        if ($pluginId > 0) {
            return ['action' => 'version', 'plugin_id' => $pluginId];
        }

        if ((bool) $input->getOption('first')) {
            return ['action' => 'submit', 'plugin_id' => 0];
        }

        if ((bool) $input->getOption('update')) {
            return ['action' => 'version', 'plugin_id' => 0];
        }

        return ['action' => 'auto', 'plugin_id' => 0];
    }

    /**
     * 演练时展示的目标 URL（市场地址不可用时返回空串，不阻断演练）。
     */
    protected function dryRunEndpoint(Input $input): string
    {
        $base = trim((string) $input->getOption('market-url'));

        if ($base === '') {
            $base = trim((string) config('plugin.service_url', ''));
        }

        $base = rtrim($base, '/');

        if ($base === '' || preg_match('#^https?://#i', $base) !== 1) {
            return '';
        }

        $action = $this->intendedTarget($input)['action'];

        if ($action === 'auto') {
            return $base . '/api/market/member/{submit|version}';
        }

        return $base . '/api/market/member/' . $action;
    }


    /**
     * 决定走「首版投稿」还是「发新版本」，并给出该动作的附加字段。
     *
     * 自动模式：市场已有同标识插件 → 新版本；否则首版投稿。
     * 查询失败（网络/鉴权）时不算致命：让真正的上传给出权威结论，避免因一次探活失败而拒绝发版。
     *
     * @return array{action: string, plugin_id: int, fields: array<string, string>, note: string}|null
     */
    protected function resolveTarget(
        Input $input,
        Output $output,
        PublishClient $client,
        string $ident,
        bool $json
    ): ?array {
        $pluginIdOpt = (int) $input->getOption('plugin-id');
        $first       = (bool) $input->getOption('first');
        $update      = (bool) $input->getOption('update');

        if ($first && $update) {
            $output->error('--first 与 --update 互斥');

            return null;
        }

        if ($pluginIdOpt > 0) {
            if ($first) {
                $output->error('--first 与 --plugin-id 互斥（指定了 ID 就是发新版本）');

                return null;
            }

            return [
                'action'    => 'version',
                'plugin_id' => $pluginIdOpt,
                'fields'    => ['plugin_id' => (string) $pluginIdOpt],
                'note'      => '按 --plugin-id 发新版本',
            ];
        }

        $lookup     = $client->lookup($ident);
        $existingId = $lookup['ok'] ? $lookup['id'] : null;

        if (!$json && !$lookup['ok']) {
            $output->writeln('<comment>提示：无法查询市场现有插件（' . $this->escape($lookup['message'])
                . '），将按首版投稿处理；若已存在会由服务端拒绝。</comment>');
        }

        if ($first) {
            if (!empty($existingId)) {
                $output->error(sprintf(
                    '市场已存在标识为 %s 的插件（#%d），--first 会撞唯一索引；去掉 --first 将自动发新版本。',
                    $ident,
                    (int) $existingId
                ));

                return null;
            }

            return ['action' => 'submit', 'plugin_id' => 0, 'fields' => [], 'note' => '按 --first 首版投稿'];
        }

        if (!empty($existingId)) {
            return [
                'action'    => 'version',
                'plugin_id' => (int) $existingId,
                'fields'    => ['plugin_id' => (string) $existingId, 'identifier' => $ident],
                'note'      => sprintf('市场已有该插件（#%d），发新版本', (int) $existingId),
            ];
        }

        if ($update) {
            $message = $lookup['ok']
                ? '市场未找到标识为 ' . $ident . ' 的插件，无法发新版本（首次上架请去掉 --update）'
                : '无法查询市场现有插件：' . $lookup['message'];

            if ($json) {
                $this->writeJson($output, ['ok' => false, 'error' => 'lookup_failed', 'message' => $message]);
            } else {
                $output->error($message);
            }

            return null;
        }

        return [
            'action'    => 'submit',
            'plugin_id' => 0,
            'fields'    => ['identifier' => $ident],
            'note'      => '市场无同标识插件，按首版投稿',
        ];
    }

    /**
     * 组装服务端表单字段（两端口径一致）。
     *
     * @return array<string, string>
     */
    protected function buildFields(
        Input $input,
        Output $output,
        PluginMetadata $metadata,
        string $version,
        bool $json
    ): array {
        $isPublic = !(bool) $input->getOption('private');
        $isCharge = (bool) $input->getOption('charge');
        $standard = trim((string) $input->getOption('charge-standard'));

        if ($isCharge && $standard === '' && !$json) {
            $output->writeln('<comment>提示：--charge 已开启但未提供 --charge-standard，服务端不会记录收费标准。</comment>');
        }

        if ($isCharge && $standard !== '' && json_decode($standard, true) === null && !$json) {
            $output->writeln('<comment>提示：--charge-standard 不是合法 JSON，服务端会丢弃该字段。</comment>');
        }

        $fields = [
            'name'                  => $metadata->getDisplayName(),
            'identifier'            => $metadata->getIdent(),
            'version'               => $version,
            'author'                => $metadata->getAuthor(),
            'description'           => $metadata->getDescription(),
            'category_id'           => (string) (int) $input->getOption('category-id'),
            'is_public'             => $isPublic ? '1' : '0',
            'is_charge'             => $isCharge ? '1' : '0',
            'compatible_with'        => (string) json_encode($metadata->getCompatibleWith(), JSON_UNESCAPED_SLASHES),
            'changelog'             => $this->resolveChangelog($input, $metadata->getIdent()),
            'url'                   => trim((string) $input->getOption('url')),
            'doc_url'               => trim((string) $input->getOption('doc-url')),
        ];

        if ($isCharge && $standard !== '') {
            $fields['charge_standard'] = $standard;
        }

        return $fields;
    }

    protected function resolveChangelog(Input $input, string $ident): string
    {
        $raw = trim((string) $input->getOption('changelog'));

        if ($raw !== '') {
            if (str_starts_with($raw, '@')) {
                $path = substr($raw, 1);
                $path = is_file($path) ? $path : $this->pluginDir($ident) . '/' . ltrim($path, '/');

                return is_file($path) ? $this->excerpt((string) file_get_contents($path)) : '';
            }

            return $raw;
        }

        $file = $this->pluginDir($ident) . '/CHANGELOG.md';

        return is_file($file) ? $this->excerpt((string) file_get_contents($file)) : '';
    }

    /**
     * 取 Markdown 前若干行作为摘要（去掉标题符号与代码围栏），最多 500 字。
     */
    protected function excerpt(string $markdown): string
    {
        $lines = preg_split('/\R/u', $markdown) ?: [];
        $out   = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '```') || preg_match('/^=+$|^-{3,}$/', $line) === 1) {
                continue;
            }

            $line = ltrim($line, '# ');
            $out[] = $line;

            if (count($out) >= 15) {
                break;
            }
        }

        $text = implode("\n", $out);

        return mb_substr($text, 0, 500);
    }


    /**
     * 会员 JWT：`--token` > env `PLUGIN_PUBLISH_TOKEN` > `config('plugin.publish_token')`。
     */
    protected function resolveToken(Input $input): string
    {
        $token = trim((string) $input->getOption('token'));

        if ($token !== '') {
            return $token;
        }

        $env = trim((string) env('PLUGIN_PUBLISH_TOKEN', ''));

        if ($env !== '') {
            return $env;
        }

        return trim((string) config('plugin.publish_token', ''));
    }

    /**
     * 市场根地址（不含 `/api/market/member`）。
     *
     * 默认取 `config('plugin.service_url')`；非 https 且非本机时拒绝 —— 会员 JWT 走明文
     * HTTP 等同于把身份公开在链路上。
     */
    protected function resolveMarketBase(Input $input, Output $output): ?string
    {
        $base = trim((string) $input->getOption('market-url'));

        if ($base === '') {
            $base = trim((string) config('plugin.service_url', ''));
        }

        $base = rtrim($base, '/');

        if ($base === '' || preg_match('#^https?://#i', $base) !== 1) {
            $output->error('市场地址无效：' . ($base === '' ? '(空)' : $base) . '（需以 http(s):// 开头）');

            return null;
        }

        if (stripos($base, 'http://') === 0 && preg_match('#^http://(localhost|127\.0\.0\.1|\[::1\])(:\d+)?$#i', $base) !== 1) {
            $output->error('拒绝用明文 HTTP 上传会员 JWT：请改用 https，或仅对本机地址使用 http。');

            return null;
        }

        return $base;
    }


    /**
     * @param array{ok: bool, path: string, size: int, sha256: string, error: string, skip: array<int, string>} $package
     * @param PreflightShape|null                                                                             $preflight
     * @param array{action: string, plugin_id: int}                                                          $intended
     */
    protected function finishDryRun(
        Output $output,
        bool $json,
        string $ident,
        string $version,
        PluginMetadata $metadata,
        array $package,
        ?array $preflight,
        array $intended,
        string $endpoint
    ): int {
        if ($json) {
            $this->writeJson($output, [
                'ok'        => true,
                'dry_run'   => true,
                'plugin'    => $ident,
                'version'   => $version,
                'mode'      => $intended['action'],
                'plugin_id' => $intended['plugin_id'] ?: null,
                'endpoint'  => $endpoint,
                'package'   => [
                    'path'   => $package['path'],
                    'size'   => $package['size'],
                    'sha256' => $package['sha256'],
                    'skip'   => $package['skip'],
                ],
                'manifest_version' => $metadata->getVersion(),
                'version_mismatch' => strcasecmp((string) $metadata->getVersion(), $version) !== 0,
                'preflight' => $this->preflightSummary($preflight),
            ]);

            return self::EXIT_OK;
        }

        $this->renderPackage($output, $ident, $version, $package, $preflight);
        $output->writeln('  目标    ' . $this->describeIntent($intended));

        if ($endpoint !== '') {
            $output->writeln('          POST ' . $this->escape($endpoint));
        }

        $output->writeln('<info>演练完成</info>：未上传（包已生成，可用于后台上传或存档）。');

        return self::EXIT_OK;
    }

    /**
     * @param array{action: string, plugin_id: int} $intended
     */
    protected function describeIntent(array $intended): string
    {
        if ($intended['action'] === 'version') {
            return $intended['plugin_id'] > 0
                ? sprintf('发新版本（插件 #%d）', $intended['plugin_id'])
                : '发新版本（上传时按标识查询插件 ID）';
        }

        if ($intended['action'] === 'submit') {
            return '首版投稿';
        }

        return '自动判断（上传时查询市场：已有同标识则发新版本，否则首版投稿）';
    }

    /**
     * @param array{ok: bool, path: string, size: int, sha256: string, error: string, skip: array<int, string>} $package
     * @param PreflightShape|null                                                                             $preflight
     * @param array{action: string, plugin_id: int, fields: array<string, string>, note: string}            $target
     * @param array{ok: bool, message: string, error: string, data: array<string, mixed>}                   $result
     */
    protected function finishUpload(
        Output $output,
        bool $json,
        string $ident,
        string $version,
        string $manifestVersion,
        array $package,
        ?array $preflight,
        string $endpoint,
        array $target,
        array $result
    ): int {
        $serverSha = (string) ($result['data']['sha256'] ?? '');
        $shaMatch  = $serverSha !== '' ? hash_equals($package['sha256'], $serverSha) : null;

        if ($json) {
            $this->writeJson($output, [
                'ok'       => (bool) $result['ok'],
                'dry_run'  => false,
                'plugin'   => $ident,
                'version'  => $version,
                'mode'     => $target['action'],
                'endpoint' => $endpoint,
                'package'  => [
                    'path'   => $package['path'],
                    'size'   => $package['size'],
                    'sha256' => $package['sha256'],
                ],
                'manifest_version' => $manifestVersion,
                'version_mismatch' => $manifestVersion !== '' && strcasecmp($manifestVersion, $version) !== 0,
                'preflight' => $this->preflightSummary($preflight),
                'error'      => $result['error'],
                'message'    => $result['message'],
                'sha256_ok'  => $shaMatch,
                'response'   => $result['data'],
            ]);

            return $result['ok'] ? self::EXIT_OK : self::EXIT_ERROR;
        }

        $output->writeln(sprintf(
            '<info>上传</info>  POST %s  （%s）',
            $this->escape($endpoint),
            $target['action'] === 'version' ? '新版本 #' . $target['plugin_id'] : '首版投稿'
        ));

        if (!$result['ok']) {
            $output->writeln('<error>失败</error>：' . $this->escape($result['message']));
            $this->renderFailureHint($output, $result['error']);
            $output->writeln('<comment>包已保留在 ' . $this->escape($package['path']) . '，可到后台上传兜底。</comment>');

            return self::EXIT_ERROR;
        }

        $output->writeln('<info>成功</info>：' . $this->escape($result['message']));

        $data = $result['data'];

        if (isset($data['plugin_id'])) {
            $output->writeln('  插件 ID   ' . (int) $data['plugin_id']);
        }

        if (isset($data['version_id'])) {
            $output->writeln(sprintf(
                '  版本      %s（version_id %d，%s）',
                (string) ($data['version'] ?? $version),
                (int) $data['version_id'],
                (string) ($data['status_text'] ?? '待审核')
            ));
        }

        if ($shaMatch === true) {
            $output->writeln('  SHA256    与服务端落库一致 ✓');
        } elseif ($shaMatch === false) {
            $output->writeln('<error>  SHA256    与服务端不一致，请核对上传的包！</error>');
        }

        $output->writeln('<comment>审核结论以服务端为准（24h 内），本地预检不构成上架依据。</comment>');

        return self::EXIT_OK;
    }

    /**
     * 失败类型 → 可执行建议（服务端只回人话，这里补「下一步做什么」）。
     */
    protected function renderFailureHint(Output $output, string $error): void
    {
        $hints = [
            'auth'      => 'JWT 无效或已过期：重新登录会员端获取 token，再 --token=<jwt>。',
            'forbidden' => '该插件不属于当前会员账号：换用投稿者账号的 token。',
            'conflict'  => '版本号或标识已存在：改 --plugin-version，或去掉 --first 让命令自动发新版本。',
            'server'    => '服务端异常：稍后重试；先到后台确认该版本是否已入库，避免重复提交。',
            'network'   => '网络不可达：检查市场地址与网络；不要盲目重试以免重复提交。',
            'protocol'  => '响应不是 JSON：可能是网关/反向代理拦截或地址写错。',
            'rejected'  => '服务端按规则拒绝：按上方文案修改后重试。',
        ];

        $hint = $hints[$error] ?? '';

        if ($hint !== '') {
            $output->writeln('<comment>↳ ' . $this->escape($hint) . '</comment>');
        }
    }

    /**
     * @param array{ok: bool, path: string, size: int, sha256: string, error: string, skip: array<int, string>} $package
     * @param PreflightShape|null                                                                             $preflight
     */
    protected function renderPackage(
        Output $output,
        string $ident,
        string $version,
        array $package,
        ?array $preflight
    ): void {
        $output->writeln(sprintf('<info>plugin:publish</info>  %s  %s', $this->escape($ident), $this->escape($version)));

        if ($preflight === null) {
            $output->writeln('  预检    <comment>已跳过（--skip-preflight）</comment>');
        } else {
            $audit    = $preflight['audit'];
            $contract = $preflight['contract'];

            $output->writeln(sprintf(
                '  预检    audit %s（%s / %d warn）｜ contract %s（%s / %d warn，%d 项用例）',
                $this->escape($audit['ruleset']),
                $audit['error'] === 0 ? '<info>0 error</info>' : '<error>' . $audit['error'] . ' error</error>',
                $audit['warn'],
                $this->escape($contract['caseset']),
                $contract['error'] === 0 ? '<info>0 error</info>' : '<error>' . $contract['error'] . ' error</error>',
                $contract['warn'],
                $contract['checks']
            ));

            if ($contract['skipped'] !== []) {
                $output->writeln(sprintf(
                    '          <comment>%d 个契约用例被跳过（本地用例集可能落后于服务端）</comment>',
                    count($contract['skipped'])
                ));
            }
        }

        $output->writeln(sprintf(
            '  包      %s  %.2f MB  sha256 %s',
            $this->escape($package['path']),
            $package['size'] / 1048576,
            $package['sha256']
        ));
    }

    /**
     * @param array{ok: bool, path: string, size: int, sha256: string, error: string, skip: array<int, string>} $package
     * @param PreflightShape|null                                                                             $preflight
     * @param array{action: string, plugin_id: int, fields: array<string, string>, note: string}            $target
     */
    protected function renderPlan(
        Output $output,
        string $ident,
        string $version,
        array $package,
        ?array $preflight,
        string $endpoint,
        array $target
    ): void {
        $this->renderPackage($output, $ident, $version, $package, $preflight);
        $output->writeln('  目标    ' . $this->escape($target['note']));
        $output->writeln('           POST ' . $this->escape($endpoint));
        $output->writeln('');
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
     * 预检摘要（JSON 输出用；`$withIssues` 时附完整明细）。
     *
     * @param array<string, mixed>|null $preflight
     *
     * @return array<string, mixed>|null
     */
    protected function preflightSummary(?array $preflight, bool $withIssues = false): ?array
    {
        if ($preflight === null) {
            return null;
        }

        $summary = [
            'audit'    => [
                'ruleset' => $preflight['audit']['ruleset'],
                'error'   => $preflight['audit']['error'],
                'warn'    => $preflight['audit']['warn'],
            ],
            'contract' => [
                'caseset' => $preflight['contract']['caseset'],
                'error'   => $preflight['contract']['error'],
                'warn'    => $preflight['contract']['warn'],
                'checks'  => $preflight['contract']['checks'],
                'skipped' => count($preflight['contract']['skipped']),
            ],
        ];

        if (!$withIssues) {
            return $summary;
        }

        $summary['audit']['issues']    = array_map(
            static fn (AuditIssue $issue): array => $issue->toArray(),
            $preflight['audit']['issues']
        );
        $summary['contract']['issues'] = array_map(
            static fn (AuditIssue $issue): array => $issue->toArray(),
            $preflight['contract']['issues']
        );
        $summary['contract']['skipped'] = (object) $preflight['contract']['skipped'];

        return $summary;
    }

    protected function pluginDir(string $ident): string
    {
        return rtrim(str_replace('\\', '/', plugin_path()), '/') . '/' . $ident;
    }

    protected function resolveOutputDir(string $option): string
    {
        $path = trim(str_replace('\\', '/', $option));

        if ($path === '') {
            $path = 'dist';
        }

        if (preg_match('#^([A-Za-z]:/|/)#', $path) === 1) {
            return rtrim($path, '/');
        }

        return rtrim(str_replace('\\', '/', $this->app->getRootPath()), '/') . '/' . trim($path, '/');
    }

    /**
     * @return array<int, string>
     */
    protected function splitList(string $raw): array
    {
        $out = [];

        foreach (explode(',', $raw) as $item) {
            $item = trim($item);

            if ($item !== '') {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * 转义 Symfony 输出格式标签，避免路径/文案里的 `<` 被当作样式标签。
     */
    protected function escape(string $text): string
    {
        return str_replace('<', '\\<', $text);
    }
}
