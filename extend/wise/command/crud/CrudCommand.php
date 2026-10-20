<?php
declare(strict_types=1);

namespace wise\command\crud;

use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use wise\crud\CrudGenerator;
use wise\crud\CrudPlanBuilder;
use wise\crud\CrudWriter;

/**
 * wise:crud — 从数据表生成后台 CRUD 模块（**只生成声明，页面由运行时渲染**）。
 *
 * 规范：docs/wiseadmin-crud-field-mapping.md ｜ 方案：docs/wiseadmin-crud-generator-proposal.md
 *
 *   - 生成**极薄控制器**（Schema 声明），不生成 form.html / index.html
 *   - 判定**可解释**：--dry-run 逐字段给出「命中层 + 命中规则 + 备选」，并区分
 *     「P0 声明的（权威）」与「P1~P3 猜的（启发式）」
 *   - 关联字段**不猜**表名，生成 `options([])` + TODO
 *   - 列表页 JS 沿用既有 EnhancedTable 模式（v1）；表单页由 wise-form-schema 运行时渲染
 *
 * ## 本类只做「命令行外壳」
 *
 * 生成能力已被拆成三个**与 console 无关**的服务，本类只负责
 * 「解析参数 → 调服务 → 排版输出」，不持有任何生成逻辑：
 *
 * ```
 *   wise\crud\CrudPlanBuilder   表名 + 选项 → 判定计划（读表 + 推导）
 *   wise\crud\CrudGenerator     计划 → 产物内容（纯函数，不落盘 → 天然支持「预览」）
 *   wise\crud\CrudWriter        计划 → 落盘 + manifest 合并（返回结构化结果）
 * ```
 *
 * 三者位于 `extend/wise/crud/`（含 stubs/ 与 tests/），本命令位于
 * `extend/wise/command/crud/`，是唯一的命令行适配层。
 *
 * 这样做的直接收益：**Web 可视化界面可以复用同一套服务**，
 * 「生成前预览」= 调 CrudGenerator 不调 CrudWriter。
 *
 * 用法：
 * ```
 * php think wise:crud ws_article --dry-run          # 只输出判定计划，不落盘
 * php think wise:crud ws_article --title=文章       # 生成（框架级 app/admin/）
 * php think wise:crud ws_article --plugin=mycms     # 生成到 plugins/mycms/
 * php think wise:crud ws_article --plugin=mycms --merge-manifest   # 并把菜单自动合并进 manifest.json
 * php think wise:crud ws_article --field=status:radio:0=禁用,1=启用
 * ```
 *
 * **框架级 vs 插件级**（`--plugin=` 只切换变量与产物路径，**模板文件同一套**）：
 *
 * | 维度 | 框架级（默认） | 插件级 `--plugin=I` |
 * |:--|:--|:--|
 * | 命名空间 | `app\{module}` | `plugin\I` |
 * | 控制器基类 | 同命名空间 `BaseController` | `plugin\BaseController`（含 AdminTrait + PluginTrait） |
 * | 模型基类 | `app\common\model\BaseModel` | `plugin\BaseModel` |
 * | 控制器 | `app/{module}/controller/X.php` | `plugins/I/controller/X.php` |
 * | 模型 | `app/{module}/model/X.php` | `plugins/I/model/X.php` |
 * | JS | `public/statics/admin/x.js` | `plugins/I/statics/js/x.js`（AssetManager 发布到 /statics/plugins/I/js/） |
 * | 菜单 | 生成迁移（幂等注册菜单+权限） | **不生成迁移**，菜单由 manifest.json 声明：默认打印可粘贴片段，加 `--merge-manifest` 则自动合并（幂等、不覆盖手工调整，并打 `_auto_merged` 标记提醒排版） |
 *
 * 插件模式的两处「自动生效」依据（无需额外接线）：
 *   1. **视图**：`plugin\BaseController::bindViewScope()` 声明 scope，而解析链 **L1 宿主优先**
 *      （`app/admin/view/public/crud/*`）→ 插件直接复用宿主共享模板，想定制就在 L1 放同名文件。
 *   2. **JS**：`plugin\BaseController::setupJsPath()` 把 `wsconfig.js` 指向
 *      `../plugins/{ident}/js/{controller}`，因此产物放 `plugins/I/statics/js/` 即为正确位置。
 */
class CrudCommand extends Command
{
    protected CrudPlanBuilder $planner;
    protected CrudGenerator $generator;
    protected CrudWriter $writer;

    protected function configure(): void
    {
        // 三个服务都无 I/O 副作用，构造期安全（root_path 由 CrudWriter 延迟解析）
        $this->generator = new CrudGenerator();
        $this->planner   = new CrudPlanBuilder();
        $this->writer    = new CrudWriter($this->generator);

        $this->setName('wise:crud')
            ->setDescription('从数据表生成后台 CRUD 模块（只生成声明，页面由运行时渲染）')
            ->addArgument('table', Argument::REQUIRED, '数据表名（可含前缀，如 ws_article）')
            ->addOption('class', 'c', Option::VALUE_OPTIONAL, '控制器/模型类名（默认由表名推导）')
            ->addOption('title', 't', Option::VALUE_OPTIONAL, '菜单与页面标题（默认取表注释）')
            ->addOption('module', 'm', Option::VALUE_OPTIONAL, '目标应用模块', 'admin')
            ->addOption('plugin', 'p', Option::VALUE_OPTIONAL, '插件标识：产物进 plugins/{ident}/ 并切换命名空间（省略则为框架级）')
            ->addOption('field', 'i', Option::VALUE_OPTIONAL | Option::VALUE_IS_ARRAY, '字段覆盖，可多次：--field=status:radio:0=禁用,1=启用')
            ->addOption('ignore-fields', null, Option::VALUE_OPTIONAL, '跳过字段，逗号分隔')
            ->addOption('no-menu', null, Option::VALUE_NONE, '不生成菜单迁移（插件模式本就不生成）')
            ->addOption('merge-manifest', null, Option::VALUE_NONE, '插件模式：把菜单自动合并进 plugins/{ident}/manifest.json（幂等、不覆盖手工调整）')
            ->addOption('no-js', null, Option::VALUE_NONE, '不生成列表页 JS 垫片')
            ->addOption('dry-run', null, Option::VALUE_NONE, '只输出判定计划，不写盘')
            ->addOption('force', 'f', Option::VALUE_NONE, '覆盖已存在的文件')
            ->addOption('connection', null, Option::VALUE_OPTIONAL, '数据库连接名', 'mysql');
    }

    protected function execute(Input $input, Output $output): int
    {
        try {
            $plan = $this->planner->build(
                (string) $input->getArgument('table'),
                $this->planOptions($input)
            );
        } catch (\Throwable $e) {
            $output->error('推导失败：' . $e->getMessage());

            return 1;
        }

        $this->renderPlan($plan, $output);

        if ($input->getOption('dry-run')) {
            $output->writeln('');
            $output->writeln('<comment>--dry-run：未写入任何文件。</comment>');
            $output->writeln('<comment>提示：预览可复用 CrudGenerator::variables() + render()，无需落盘。</comment>');

            return 0;
        }

        return $this->write($plan, $input, $output);
    }

    /**
     * 命令行选项 → 服务层选项数组。
     *
     * 这一层「翻译」是解耦的关键：CrudPlanBuilder 只认数组，
     * 因此 Web 控制器可以用表单数据构造同样的数组，复用同一套推导。
     *
     * @return array<string, mixed>
     */
    protected function planOptions(Input $input): array
    {
        return [
            'class'         => (string) $input->getOption('class'),
            'title'         => (string) $input->getOption('title'),
            'module'        => (string) $input->getOption('module'),
            'plugin'        => (string) $input->getOption('plugin'),
            'field'         => (array) $input->getOption('field'),
            'ignore_fields' => (string) $input->getOption('ignore-fields'),
            'connection'    => (string) $input->getOption('connection'),
        ];
    }

    // ────────────────────────────────────────────────
    //  落盘（调用服务 + 输出）
    // ────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $plan
     */
    protected function write(array $plan, Input $input, Output $output): int
    {
        $result = $this->writer->write($plan, [
            'force'          => (bool) $input->getOption('force'),
            'skip'           => array_values(array_filter([
                $input->getOption('no-js') ? 'no-js' : null,
                $input->getOption('no-menu') ? 'no-menu' : null,
            ])),
            'merge_manifest' => (bool) $input->getOption('merge-manifest'),
        ]);

        // 必需产物缺模板 → 明确报错（在文件清单之前，与逐产物检查的观感一致）
        foreach ($result['required_missing'] as $stub) {
            $output->error(sprintf(
                '模板缺失，必需产物未生成：%s（模板目录 %s）',
                $stub,
                $this->generator->stubDir()
            ));
        }

        // 非必需产物缺模板：原本是静默的，这里显式说明，避免「以为生成了其实没有」
        foreach (array_diff($result['missing'], $result['required_missing']) as $stub) {
            $output->writeln(sprintf(
                '<comment>模板缺失，已跳过：%s（模板目录 %s）</comment>',
                $stub,
                $this->generator->stubDir()
            ));
        }

        $output->writeln('');

        foreach ($result['written'] as $file) {
            $output->writeln('  <info>+</info> ' . $file);
        }

        foreach ($result['skipped'] as $file) {
            $output->writeln('  <comment>=</comment> ' . $file . '（已存在，跳过；--force 覆盖）');
        }

        if ($result['fatal']) {
            $output->error('没有任何文件被生成，请检查模板目录：' . $this->generator->stubDir());

            return 1;
        }

        $output->info(sprintf('完成：新增 %d，跳过 %d', count($result['written']), count($result['skipped'])));

        if ((string) $plan['plugin'] !== '') {
            $this->renderPluginNextSteps($plan, $output, $result['merge']);
        } else {
            if ($result['merge_ignored']) {
                $output->writeln('<comment>--merge-manifest 仅在插件模式（--plugin=）下生效，已忽略。</comment>');
            }

            $output->writeln('下一步：');
            $output->writeln('  1) php think migrate:run');
            $output->writeln('  2) 后台「权限 → 更新权限列表」');
        }

        return 0;
    }

    /**
     * 插件模式收尾提示。
     *
     * 插件**不复用菜单迁移**：菜单由 manifest.json 声明、由插件生命周期同步。
     * 因此这里要么报告自动合并结果，要么打印一段可直接粘贴的 `menus` 片段 ——
     * 两者都比生成一个与 manifest 冲突的迁移更安全。
     *
     * @param array<string, mixed>                  $plan
     * @param array{ok: bool, message: string}|null $merge mergeManifestMenu() 的结果
     */
    protected function renderPluginNextSteps(array $plan, Output $output, ?array $merge = null): void
    {
        $plugin = (string) $plan['plugin'];

        $output->writeln('');
        $output->writeln('<comment>插件模式：未生成菜单迁移</comment>（插件的菜单由 manifest.json 声明，避免两个真相源）');

        // 手工片段同样继承该插件既有写法（短式 / 长式），避免插件内长短混排
        $form = $this->writer->preferredFormForPlugin($plugin);

        if ($merge === null) {
            $output->writeln(sprintf('请把下面这段合并进 %s 的 "menus" 数组：', $this->writer->manifestPath($plugin)));
            $output->writeln('');
            $output->writeln($this->writer->manifestMenuSnippet($plan, $form));
        } elseif ($merge['ok']) {
            $output->info($merge['message']);
        } else {
            // 自动合并失败 → 退回手工路径，保证开发者不会两头空
            $output->error($merge['message']);
            $output->writeln('');
            $output->writeln('可手工把下面这段合并进 manifest.json 的 "menus" 数组：');
            $output->writeln('');
            $output->writeln($this->writer->manifestMenuSnippet($plan, $form));
        }

        $output->writeln('');
        $output->writeln('下一步：');
        // 注意：后台「插件管理」**没有**「重新同步」动作（原文案指向了不存在的功能，
        // 见 docs/menu-permission-auto-derivation-plan.md §8 G2），这里只列真实可用的三条路径。
        $output->writeln(sprintf('  1) 让菜单与权限生效（任选其一）：'));
        $output->writeln(sprintf('     · php think plugin:menu-derive --plugin=%s --check   校验声明与控制器是否一致（--apply 可直接写入缺失项）', $plugin));
        $output->writeln(sprintf('     · 后台「插件管理」把 %s 禁用后再启用（启用时会幂等注册菜单与权限）', $plugin));
        $output->writeln('     · php think plugin:menu-migrate                     批量重注册全部已安装插件的菜单');
        $output->writeln(sprintf(
            '  2) 静态资源随插件发布后，JS 位于 /statics/plugins/%s/js/%s.js',
            $plugin,
            (string) $plan['controller_url']
        ));
    }

    // ────────────────────────────────────────────────
    //  计划输出（可解释）—— 呈现层，留在命令行
    // ────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $plan
     */
    protected function renderPlan(array $plan, Output $output): void
    {
        $output->writeln(sprintf(
            '<info>表</info> %s  →  <info>类</info> %s  →  <info>标题</info> %s',
            $plan['table'],
            $plan['class'],
            $plan['title']
        ));
        $output->writeln('');

        $rows = [];

        foreach ($plan['fields'] as $field) {
            $rows[] = [
                (string) $field['name'],
                (string) $field['column_type'],
                $field['skip_form'] ? '跳过' : (string) $field['type'] . ($field['multiple'] ? '(多)' : ''),
                (string) $field['layer'],
                ($field['declared'] ? '[声明] ' : '[推断] ') . $this->truncate((string) $field['rule'], 46),
                $this->optionSummary($field),
                $this->truncate((string) $field['fallback'], 12),
            ];
        }

        $this->renderTable(
            ['字段', 'DB类型', '判定', '层', '命中规则', '选项', '备选'],
            $rows,
            $output
        );

        // 汇总：区分「声明的 / 启发式推断的 / 兜底的」——规范 §8 硬性要求
        $declared = 0;
        $heuristic = 0;
        $fallback = 0;
        $skipped = 0;
        $warnings = [];

        foreach ($plan['fields'] as $field) {
            if (!empty($field['warnings'])) {
                foreach ($field['warnings'] as $warning) {
                    $warnings[] = $field['name'] . '：' . $warning;
                }
            }

            if ($field['skip_form']) {
                $skipped++;
                continue;
            }

            match ($field['layer']) {
                'P0'    => $declared++,
                'P1', 'P2', 'P3' => $heuristic++,
                default => $fallback++,
            };
        }

        $output->writeln('');
        $output->writeln(sprintf(
            '共 %d 字段：<info>声明 %d</info> / <comment>启发式推断 %d</comment> / 兜底 %d / 跳过 %d',
            count($plan['fields']),
            $declared,
            $heuristic,
            $fallback,
            $skipped
        ));

        $output->writeln('<comment>提示：标注 [推断] 的字段由命名/注释启发式得出，建议核对；'
            . '标注 [声明] 的为显式覆盖，可直接采信。</comment>');

        if ($warnings !== []) {
            $output->writeln('');
            $output->warning('需要人工确认：');

            foreach ($warnings as $warning) {
                $output->writeln('  - ' . $warning);
            }
        }
    }

    /**
     * @param array<string, mixed> $field
     */
    protected function optionSummary(array $field): string
    {
        $options = (array) ($field['options'] ?? []);

        if (in_array($field['type'], ['select', 'radio', 'checkbox'], true) && $options === []) {
            return '<comment>TODO 关联</comment>';
        }

        if ($options === []) {
            return '';
        }

        return $this->truncate(count($options) . ' 项', 10);
    }

    /**
     * @param array<int, string>             $headers
     * @param array<int, array<int, string>> $rows
     */
    protected function renderTable(array $headers, array $rows, Output $output): void
    {
        // 中文字符按 2 列宽估算，保证表格不错位
        $widths = array_map(fn (string $h): int => $this->displayWidth($h), $headers);

        foreach ($rows as $row) {
            foreach ($row as $index => $cell) {
                $widths[$index] = max($widths[$index] ?? 0, $this->displayWidth($cell));
            }
        }

        $line = function (array $cells) use ($widths): string {
            $parts = [];

            foreach ($cells as $index => $cell) {
                $pad = ($widths[$index] ?? 0) - $this->displayWidth($cell);
                $parts[] = $cell . str_repeat(' ', max(0, $pad));
            }

            return implode('  ', $parts);
        };

        $output->writeln($line($headers));
        $output->writeln(implode('  ', array_map(static fn (int $w): string => str_repeat('-', $w), $widths)));

        foreach ($rows as $row) {
            $output->writeln($line($row));
        }
    }

    protected function displayWidth(string $text): int
    {
        $width = 0;

        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            $width += (strlen($char) > 1 && preg_match('/[\x{1100}-\x{FFFF}]/u', $char) === 1) ? 2 : 1;
        }

        return $width;
    }

    protected function truncate(string $text, int $limit): string
    {
        return $this->displayWidth($text) <= $limit ? $text : mb_substr($text, 0, (int) ($limit / 2)) . '…';
    }
}
