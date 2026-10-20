<?php

declare(strict_types=1);

namespace wise\plugin\command\tool;

use think\console\Command;
use think\console\Input;
use think\console\input\Option as InputOption;
use think\console\Output;
use wise\plugin\derive\MenuDeriver;
use wise\plugin\manifest\ManifestMenuWriter;

/**
 * 插件菜单声明推导 / 一致性校验（`plugin:menu-derive`）。
 *
 * **纯静态、纯开发期工具**：只读控制器源码与 manifest.json，**不落库、不写文件**
 * （对齐方案 `docs/menu-permission-auto-derivation-plan.md` 的 D3 / D4 与 §5 命令设计）。
 *
 * 用法：
 * ```bash
 * php think plugin:menu-derive --plugin=mycms                 # 预览候选（三类分区）
 * php think plugin:menu-derive --plugin=mycms --format=json    # 供 CI / AI 消费
 * php think plugin:menu-derive --all --check                   # CI 门禁：有漂移退出码 2
 * php think plugin:menu-derive --plugin=mycms --print          # 打印可粘贴的 menus 片段
 * php think plugin:menu-derive --plugin=mycms --apply          # 写回 manifest（幂等、非破坏）
 * php think plugin:menu-derive --plugin=mycms --apply --prune   # 顺手回收带标记的陈旧项
 * php think plugin:menu-derive --plugin=mycms --apply --sort     # 追加后按 (sort, name) 重排各级
 * php think plugin:menu-derive --plugin=mycms --only=Article --exclude=Debug
 * php think plugin:menu-derive --plugin=mycms --group="内容配置"
 * ```
 *
 * 按钮节点必须显式声明，未注解的方法不会被猜测：
 *
 * ```php
 * // 方法 docblock 里写：
 * // @menu type="button" name="修改密码" icon="bi bi-key" sort=30
 * public function changePassword() { ... }
 * ```
 *
 * > `resetPassword` / `test` / `sql` 等含敏感词的方法会被硬拦截，不会被推导成按钮。
 *
 * 退出码：`0` 成功；`1` 参数或数据错误（插件不存在 / manifest 非法 JSON / 写盘失败等）；
 * `2` 检测到漂移（仅 `--check`）。
 *
 * ## 写盘（`--apply` / `--prune`）
 *
 * 复用 {@see \wise\plugin\manifest\ManifestMenuWriter}（与 `wise:crud --merge-manifest`
 * 同一套语义）：只追加缺失项、**不覆盖手工项**、新增项打 `_auto_merged` 标记、幂等。
 * `--prune` 只回收「**带标记** 且 控制器/方法已不存在」的节点，且：
 * - 必须与 `--apply` 同用（单独 `--prune` 直接报错，避免误以为只读）；
 * - **忽略 `--only` / `--exclude`**：保留集用未过滤的全量扫描计算，防止过滤参数导致误删；
 * - 不带标记的节点（手工维护）**永不触碰**。
 *
 * `--sort`（同样需与 `--apply` 同用）按 `(sort, name)` 稳定重排各级节点：**只改数组顺序，
 * 不改任何字段值** —— 后台展示顺序由 `sort` 字段决定，重排只是为了 manifest 好读、diff 稳定。
 * 「真的没有变化就不写盘」由**文档级快照比对**保证（新增 / 回收 / 重排任一发生变化才落盘）。
 */
class MenuDerive extends Command
{
    /** 退出码：参数/数据错误 */
    protected const EXIT_ERROR = 1;

    /** 退出码：检测到漂移 */
    protected const EXIT_DRIFT = 2;

    /** 未归组节点的展示占位 */
    protected const UNGROUPED = '（未分组）';

    protected function configure(): void
    {
        $this->setName('plugin:menu-derive')
            ->addOption('plugin', 'p', InputOption::VALUE_REQUIRED, '只处理指定插件（目录名，可逗号分隔）')
            ->addOption('all', null, InputOption::VALUE_NONE, '处理全部插件')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, '输出格式：text / json', 'text')
            ->addOption('check', null, InputOption::VALUE_NONE, '只校验漂移（存在漂移时退出码 2）')
            ->addOption('print', null, InputOption::VALUE_NONE, '打印可直接粘贴的 menus 片段（仅 --plugin 可用）')
            ->addOption('apply', null, InputOption::VALUE_NONE, '写回 manifest.json（幂等、不覆盖手工项、新增项打 _auto_merged 标记）')
            ->addOption('prune', null, InputOption::VALUE_NONE, '回收带 _auto_merged 标记且控制器已不存在的陈旧项（需与 --apply 同用）')
            ->addOption('sort', null, InputOption::VALUE_NONE, '按 (sort, name) 重排各级菜单（只改数组顺序，不改字段值；需与 --apply 同用）')
            ->addOption('only', null, InputOption::VALUE_REQUIRED, '只扫描这些控制器（类名，逗号分隔）')
            ->addOption('exclude', null, InputOption::VALUE_REQUIRED, '跳过这些控制器（类名，逗号分隔）')
            ->addOption('group', null, InputOption::VALUE_REQUIRED, '未标注分组的节点统一归入该分组')
            ->setDescription('静态推导插件 manifest 的菜单声明，并检测与控制器的一致性（不落库、不写文件）');
    }

    protected function execute(Input $input, Output $output): int
    {
        $format = strtolower(trim((string) $input->getOption('format')));
        $check  = (bool) $input->getOption('check');
        $print  = (bool) $input->getOption('print');
        $apply  = (bool) $input->getOption('apply');
        $prune  = (bool) $input->getOption('prune');
        $sort   = (bool) $input->getOption('sort');

        if (!in_array($format, ['text', 'json'], true)) {
            $output->error(sprintf('未知输出格式：%s（可选 text / json）', $format));

            return self::EXIT_ERROR;
        }

        $conflict = $this->resolveConflicts($output, $check, $print, $apply, $prune, $sort);

        if ($conflict !== null) {
            return $conflict;
        }

        $targets = $this->resolveTargets($input, $output);

        if ($targets === null) {
            return self::EXIT_ERROR;
        }

        if ($print && count($targets) !== 1) {
            $output->error('--print 只支持单个 --plugin（避免多份片段混淆）');

            return self::EXIT_ERROR;
        }

        $deriver = new MenuDeriver();
        $options = [
            'only'    => (array) $input->getOption('only'),
            'exclude' => (array) $input->getOption('exclude'),
            'group'   => (string) $input->getOption('group'),
        ];

        $results = [];
        $failed  = false;

        foreach ($targets as $ident) {
            $result = $deriver->derive($ident, $options);

            if ($result['error'] !== null) {
                $failed = true;
            } elseif ($apply) {
                $result['apply'] = $this->applyPlugin($deriver, $result, $prune, $sort);

                if (!$result['apply']['ok']) {
                    $failed = true;
                }
            }

            $results[$ident] = $result;
        }

        $drift = false;

        foreach ($results as $result) {
            if ($result['drift'] && $result['error'] === null) {
                $drift = true;
            }
        }

        if ($format === 'json') {
            $output->writeln($this->renderJson($results, $failed, $drift));
        } else {
            $this->renderText($results, $output, $check, $print, $apply, $failed, $drift);
        }

        if ($failed) {
            return self::EXIT_ERROR;
        }

        return $check && $drift ? self::EXIT_DRIFT : 0;
    }

    /**
     * 选项互斥 / 组合校验（写操作必须显式、且不与只读模式混用）。
     *
     * @return int|null null = 通过；否则为应返回的退出码
     */
    protected function resolveConflicts(
        Output $output,
        bool $check,
        bool $print,
        bool $apply,
        bool $prune,
        bool $sort
    ): ?int {
        if ($prune && !$apply) {
            $output->error('--prune 是写操作，必须与 --apply 同用（避免误以为只读）');

            return self::EXIT_ERROR;
        }

        if ($sort && !$apply) {
            $output->error('--sort 是写操作，必须与 --apply 同用（避免误以为只读）');

            return self::EXIT_ERROR;
        }

        if ($apply && $print) {
            $output->error('--apply 与 --print 互斥（一个写盘、一个只打印片段）');

            return self::EXIT_ERROR;
        }

        if ($apply && $check) {
            $output->error('--apply 与 --check 互斥（--check 是只读门禁）');

            return self::EXIT_ERROR;
        }

        return null;
    }

    /**
     * 解析目标插件列表（`--plugin` / `--all`）。
     *
     * @return array<int, string>|null null = 参数非法（已报错）
     */
    protected function resolveTargets(Input $input, Output $output): ?array
    {
        $plugin = trim((string) $input->getOption('plugin'));
        $all    = (bool) $input->getOption('all');

        if ($plugin === '' && !$all) {
            $output->error('请指定 --plugin=插件标识 或 --all（本命令不会静默扫描全部插件）');

            return null;
        }

        if ($plugin !== '') {
            $targets = [];

            foreach (explode(',', $plugin) as $item) {
                $item = trim($item);

                if ($item !== '') {
                    $targets[] = $item;
                }
            }

            if ($targets === []) {
                $output->error('--plugin 为空');

                return null;
            }

            return $targets;
        }

        $targets = (new MenuDeriver())->plugins();

        if ($targets === []) {
            $output->error('未发现任何含 manifest.json 的插件');

            return null;
        }

        return $targets;
    }

    /**
     * 终端输出：按插件分区展示「待新增 / 推导不出 / 重复声明 / 已一致」。
     *
     * @param array<string, array<string, mixed>> $results
     */
    protected function renderText(
        array $results,
        Output $output,
        bool $check,
        bool $print,
        bool $apply,
        bool $failed,
        bool $drift
    ): void {
        foreach ($results as $ident => $result) {
            if ($result['error'] !== null) {
                $output->writeln(sprintf('<error>%s</error>  %s', $ident, (string) $result['error']));
                $output->writeln('');
                continue;
            }

            $form = $result['form'] === 'short' ? '短式' : '长式';

            $output->writeln(sprintf(
                '<info>%s</info>  产出风格=%s  控制器=%d%s',
                (string) $ident,
                $form,
                (int) $result['scanned'],
                (int) $result['blocked'] > 0
                    ? sprintf('  忽略=%d（名称含敏感词，按安全策略不产出）', (int) $result['blocked'])
                    : ''
            ));

            if ($print) {
                $this->renderSnippet($result, $output);
                $output->writeln('');
                continue;
            }

            if ($apply) {
                $this->renderApply($result, $output);
                $output->writeln('');
                continue;
            }

            if ((bool) $result['drift']) {
                $this->renderSection(
                    $output,
                    sprintf('待新增（%d）', count($result['missing'])),
                    array_map(
                        static fn (array $c): string => sprintf(
                            '  + %-16s %-14s %s',
                            (string) ($c['group'] ?? self::UNGROUPED),
                            (string) $c['name'],
                            (string) $c['path']
                        ),
                        $result['missing']
                    )
                );

                $this->renderSection(
                    $output,
                    sprintf('重复声明（%d）', count($result['duplicates'])),
                    array_map(
                        static fn (array $d): string => sprintf(
                            '  ! %s  ←  %s',
                            (string) $d['identity'],
                            implode(' | ', (array) $d['paths'])
                        ),
                        $result['duplicates']
                    )
                );
            }

            if (!$check) {
                $this->renderSection(
                    $output,
                    sprintf('声明了但推导不出（%d，仅提示）', count($result['extra'])),
                    array_map(
                        static fn (array $n): string => sprintf('  ? %s', (string) $n['path']),
                        $result['extra']
                    )
                );

                if (!$result['drift']) {
                    $output->writeln('  <info>✅ 控制器与 manifest 声明一致</info>');
                }
            }

            $output->writeln('');
        }

        if ($failed) {
            $output->writeln('<error>存在错误，未完成校验（详见上方）</error>');
            return;
        }

        if ($apply) {
            $added  = 0;
            $pruned = 0;

            foreach ($results as $result) {
                $added  += count((array) ($result['apply']['added'] ?? []));
                $pruned += count((array) ($result['apply']['pruned'] ?? []));
            }

            $output->writeln(sprintf('<info>写盘完成</info>：新增 %d 项，回收 %d 项。', $added, $pruned));
            $output->writeln('提示：新增项带 `_auto_merged` 标记，请在后台确认排版后删除该字段；不带标记的手工节点永不被 --prune 回收。');

            return;
        }

        if ($drift) {
            $output->writeln('<error>检测到漂移</error>：请按上方「待新增 / 重复声明」修正，或用 --print 取片段手工粘贴');
            $output->writeln('提示：`--apply` 可写入缺失项（幂等、不覆盖手工项），`--apply --prune` 顺带回收带标记的陈旧项。');

            return;
        }

        $output->writeln('<info>一致</info>：控制器与 manifest 声明无漂移。');
    }

    /**
     * @param array<int, string> $lines
     */
    protected function renderSection(Output $output, string $title, array $lines): void
    {
        if ($lines === []) {
            return;
        }

        $output->writeln('  ' . $title);

        foreach ($lines as $line) {
            $output->writeln($line);
        }
    }

    /**
     * 写盘：追加缺失项（可选回收陈旧项）。
     *
     * 全部改写都通过 {@see ManifestMenuWriter}（与 `wise:crud --merge-manifest` 同一套语义）：
     * 幂等、不覆盖手工项、新增项打标记；`--prune` 只删「带标记 且 控制器已不存在」的节点。
     *
     * @param array<string, mixed> $result 该插件的推导结果
     * @param bool                 $prune  是否回收带标记的陈旧项
     * @param bool                 $sort   是否按 (sort, name) 重排各级
     * @return array{ok: bool, message: string, added: array<int, array{path: string, target: string}>, pruned: array<int, array{path: string, name: string}>, kept: array<int, string>}
     */
    protected function applyPlugin(MenuDeriver $deriver, array $result, bool $prune, bool $sort): array
    {
        $writer = new ManifestMenuWriter($deriver->normalizer());
        $loaded = $writer->load((string) $result['manifest']);

        if (!$loaded['ok']) {
            return ['ok' => false, 'message' => $loaded['message'], 'added' => [], 'pruned' => [], 'kept' => []];
        }

        $data   = $loaded['data'];
        $before = $loaded['data'];      // 文档级快照：用于「真的没变化就不写盘」判定
        $menus  = (array) ($data['menus'] ?? []);

        $added = [];
        $kept  = [];

        foreach ($result['missing'] as $candidate) {
            $entry   = $this->candidateEntry($candidate);
            $group   = isset($candidate['group']) && $candidate['group'] !== null ? (string) $candidate['group'] : null;
            $outcome = $writer->append($menus, $entry, $group);

            if ($outcome['added']) {
                $added[] = ['path' => (string) $entry['path'], 'target' => (string) $outcome['target']];
            } else {
                $kept[] = $outcome['message'];
            }
        }

        $pruned = [];

        if ($prune) {
            // 保留集用**未经过滤**的全量扫描计算：--only / --exclude 绝不参与回收判定
            $full   = $deriver->derive((string) $result['ident']);
            $keptId = [];

            foreach ($full['candidates'] as $candidate) {
                $keptId[$deriver->normalizer()->identity((string) $candidate['path'])] = true;
            }

            $pruned = $writer->prune($menus, $keptId)['removed'];
        }

        if ($sort) {
            $writer->sortLevels($menus);
        }

        // 原本没有 menus 键且本次也没产出任何节点时，不凭空添加该键
        if (array_key_exists('menus', $data) || $menus !== []) {
            $data['menus'] = $menus;
        }

        if ($data === $before) {
            return [
                'ok'      => true,
                'message' => '无需写盘（幂等：无待新增、无待回收、无待重排）',
                'added'   => [],
                'pruned'  => [],
                'kept'    => $kept,
            ];
        }

        // 带上原始形态：避免把 `"dependencies": {}` 这类空对象写成 `[]`
        $saved = $writer->save((string) $result['manifest'], $data, $loaded['shape']);

        if (!$saved['ok']) {
            return ['ok' => false, 'message' => $saved['message'], 'added' => [], 'pruned' => [], 'kept' => $kept];
        }

        return [
            'ok'      => true,
            'message' => sprintf(
                '%s（新增 %d 项，回收 %d 项%s）',
                $saved['message'],
                count($added),
                count($pruned),
                $sort ? '，已按 sort/name 重排各级' : ''
            ),
            'added'   => $added,
            'pruned'  => $pruned,
            'kept'    => $kept,
        ];
    }

    /**
     * 候选节点 → manifest 兼容条目（键与 `CrudWriter::manifestMenuEntry()` 对齐）。
     *
     * @param array<string, mixed> $candidate
     * @return array<string, mixed>
     */
    protected function candidateEntry(array $candidate, bool $withMarker = true): array
    {
        $type = strtolower((string) ($candidate['type'] ?? 'menu'));
        $type = in_array($type, ['menu', 'button'], true) ? $type : 'menu';

        $entry = [
            'name'   => (string) $candidate['name'],
            'path'   => (string) $candidate['path'],
            'type'   => $type,
            'sort'   => (int) ($candidate['sort'] ?? 0),
            'status' => 1,
        ];

        // 按钮不使用 icon / children；菜单型才补默认图标与子节点占位
        if ($type === 'menu') {
            $entry['icon']     = (string) ($candidate['icon'] ?? '') ?: 'bi bi-circle';
            $entry['children'] = [];
        } elseif (isset($candidate['icon']) && $candidate['icon'] !== null) {
            $entry['icon'] = (string) $candidate['icon'];
        }

        if ($withMarker) {
            $entry[ManifestMenuWriter::AUTO_MERGED_KEY] = ManifestMenuWriter::AUTO_MERGED_NOTE;
        }

        return $entry;
    }

    /**
     * 渲染 `--apply` / `--prune` 的单插件结果。
     *
     * @param array<string, mixed> $result
     */
    protected function renderApply(array $result, Output $output): void
    {
        $apply = (array) ($result['apply'] ?? []);

        if (!$apply || !($apply['ok'] ?? false)) {
            $output->writeln('  <error>写盘失败：' . (string) ($apply['message'] ?? '未执行') . '</error>');

            return;
        }

        $this->renderSection(
            $output,
            sprintf('已追加（%d）', count((array) $apply['added'])),
            array_map(
                static fn (array $item): string => sprintf(
                    '  + %s   ←  %s',
                    (string) $item['path'],
                    (string) $item['target'] === '' ? '顶级' : '分组「' . (string) $item['target'] . '」'
                ),
                (array) $apply['added']
            )
        );

        $this->renderSection(
            $output,
            sprintf('已回收（%d）', count((array) $apply['pruned'])),
            array_map(
                static fn (array $item): string => sprintf('  - %s   (%s)', (string) $item['path'], (string) $item['name']),
                (array) $apply['pruned']
            )
        );

        $this->renderSection(
            $output,
            sprintf('保留（%d，已存在等价节点，未改动）', count((array) $apply['kept'])),
            array_map(static fn (string $line): string => '  = ' . $line, (array) $apply['kept'])
        );

        $output->writeln('  <info>' . (string) $apply['message'] . '</info>');
    }

    /**
     * 打印可直接粘贴进 manifest 的 `menus` 片段（**不带** `_auto_merged` 标记：手工即刻意）。
     *
     * @param array<string, mixed> $result
     */
    protected function renderSnippet(array $result, Output $output): void
    {
        if ($result['missing'] === []) {
            $output->writeln('  <info>无需新增（无待补节点）</info>');

            return;
        }

        $entries = [];

        foreach ($result['missing'] as $candidate) {
            $entries[] = $this->candidateEntry($candidate, false);
        }

        $output->writeln('请把下面这段合并进 ' . $result['manifest'] . ' 的 "menus" 数组：');
        $output->writeln((string) json_encode(
            $entries,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
    }

    /**
     * JSON 输出（供 CI / AI 消费）。
     *
     * @param array<string, array<string, mixed>> $results
     */
    protected function renderJson(array $results, bool $failed, bool $drift): string
    {
        $payload = [
            'ok'      => !$failed,
            'drift'   => $drift,
            'plugins' => array_values($results),
        ];

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $json === false ? '{"ok":false,"error":"json_encode failed"}' : $json;
    }
}
