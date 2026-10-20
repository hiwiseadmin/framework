<?php
declare(strict_types=1);

namespace wise\crud;

use wise\plugin\manifest\ManifestMenuWriter;
use wise\plugin\manifest\MenuPathNormalizer;

/**
 * CrudWriter — **判定计划 → 落盘**（含覆盖策略与 manifest 合并）。
 *
 * 这是 CRUD 生成器的第三段，是唯一有副作用的一段。它与 CrudGenerator 分开的理由：
 *   - 渲染是纯函数（可预览、可单测），落盘涉及覆盖策略、目录创建、写文件；
 *   - `write()` **返回结构化结果**而不是自己打印/自己返回退出码 ——
 *     这样 CLI 负责排版、Web 负责转 JSON，两者共用同一套落盘语义。
 *
 * 产物路径由 `artifactsFor()` 按 plan 里的 plugin 字段切换（框架级 / 插件级），
 * 模板文件本身是同一套，不做分叉。
 */
class CrudWriter
{
    /**
     * 自动合并标记的键名（转发自 {@see ManifestMenuWriter}，保持对外 API 不变）。
     */
    public const AUTO_MERGED_KEY = ManifestMenuWriter::AUTO_MERGED_KEY;

    /**
     * 自动合并标记的提示文案（转发自 {@see ManifestMenuWriter}）。
     */
    public const AUTO_MERGED_NOTE = ManifestMenuWriter::AUTO_MERGED_NOTE;

    /**
     * 框架级产物定义。
     *
     * 显式声明「产物路径模板 + 模板文件 + 必需性 + 开关选项」，
     * 不做路径推导（产物目录可由 config/crud.php 改写，推导会错位）。
     *
     * @var array<int, array{path: string, stub: string, required: bool, skip_option?: string}>
     */
    protected array $artifacts = [
        [
            'path'     => '{$controller_dir}/{$class}.php',
            'stub'     => 'Controller.stub',
            'required' => true,
        ],
        [
            'path'     => '{$model_dir}/{$class}.php',
            'stub'     => 'Model.stub',
            'required' => true,
        ],
        [
            'path'        => '{$js_dir}/{$controller_url}.js',
            'stub'        => 'js.stub',
            'required'    => true,
            'skip_option' => 'no-js',
        ],
        [
            'path'        => '{$migration_dir}/{$timestamp}_menu_{$table_short}.php',
            'stub'        => 'menu.stub',
            'required'    => false,
            'skip_option' => 'no-menu',
        ],
    ];

    /**
     * 插件模式产物定义。
     *
     * 与框架级的差异**只有路径模板**——模板文件是同一套。
     * 插件不生成菜单迁移：菜单由 `plugins/{ident}/manifest.json` 声明、由插件生命周期同步，
     * 走迁移会与 manifest 形成两个真相源。
     *
     * @var array<int, array{path: string, stub: string, required: bool, skip_option?: string}>
     */
    protected array $pluginArtifacts = [
        [
            'path'     => 'plugins/{$plugin}/controller/{$class}.php',
            'stub'     => 'Controller.stub',
            'required' => true,
        ],
        [
            'path'     => 'plugins/{$plugin}/model/{$class}.php',
            'stub'     => 'Model.stub',
            'required' => true,
        ],
        [
            'path'        => 'plugins/{$plugin}/statics/js/{$controller_url}.js',
            'stub'        => 'js.stub',
            'required'    => true,
            'skip_option' => 'no-js',
        ],
    ];

    protected CrudGenerator $generator;

    /** 项目根目录（含尾部分隔符）；null 表示运行时由 root_path() 解析 */
    protected ?string $projectRoot;

    /** 菜单 path 归一器（延迟构造：仅合并路径需要，且要读 route 配置） */
    protected ?MenuPathNormalizer $normalizer = null;

    /** manifest `menus` 读写内核（与 `plugin:menu-derive` 共用） */
    protected ?ManifestMenuWriter $menuWriter = null;

    /**
     * @param CrudGenerator $generator   渲染器
     * @param string|null   $projectRoot 项目根目录，默认 root_path()（延迟解析，便于单测注入）
     */
    public function __construct(CrudGenerator $generator, ?string $projectRoot = null)
    {
        $this->generator   = $generator;
        $this->projectRoot = $projectRoot;
    }

    public function generator(): CrudGenerator
    {
        return $this->generator;
    }

    public function normalizer(): MenuPathNormalizer
    {
        return $this->normalizer ??= MenuPathNormalizer::fromConfig();
    }

    public function menuWriter(): ManifestMenuWriter
    {
        return $this->menuWriter ??= new ManifestMenuWriter($this->normalizer());
    }

    /**
     * 按 plan 选择产物集（框架级 / 插件级）。
     *
     * @param array<string, mixed> $plan
     * @return array<int, array{path: string, stub: string, required: bool, skip_option?: string}>
     */
    public function artifactsFor(array $plan): array
    {
        return (string) ($plan['plugin'] ?? '') !== '' ? $this->pluginArtifacts : $this->artifacts;
    }

    /**
     * 把计划落盘。
     *
     * 选项：
     *   force          覆盖已存在的文件
     *   skip           跳过的产物开关列表（如 ['no-js', 'no-menu']）
     *   merge_manifest 插件模式：把菜单自动合并进 manifest.json
     *
     * 返回值（**结构化**，由上层决定如何呈现）：
     * ```
     * written          已写入的相对路径
     * skipped          已存在而跳过的相对路径
     * missing          缺失的模板文件名
     * required_missing 缺失且为必需产物 → 上层应报错
     * merge            mergeManifestMenu() 的结果（未请求则为 null）
     * merge_ignored    框架级却请求了 merge_manifest
     * fatal            一个文件都没产出且模板缺失 → 上层应立即失败
     * ```
     *
     * @param array<string, mixed> $plan
     * @param array<string, mixed> $options
     * @return array{
     *     written: array<int, string>,
     *     skipped: array<int, string>,
     *     missing: array<int, string>,
     *     required_missing: array<int, string>,
     *     merge: array{ok: bool, message: string}|null,
     *     merge_ignored: bool,
     *     fatal: bool
     * }
     */
    public function write(array $plan, array $options = []): array
    {
        $force = (bool) ($options['force'] ?? false);
        $skip  = (array) ($options['skip'] ?? []);

        $variables = $this->generator->variables($plan);
        $root      = $this->projectRoot();

        $written         = [];
        $skipped         = [];
        $missing         = [];
        $requiredMissing = [];

        foreach ($this->artifactsFor($plan) as $artifact) {
            $skipOption = $artifact['skip_option'] ?? '';

            if ($skipOption !== '' && in_array($skipOption, $skip, true)) {
                continue;
            }

            $relative = strtr($artifact['path'], $variables);
            $stub     = $artifact['stub'];
            $target   = $root . str_replace('/', DIRECTORY_SEPARATOR, $relative);

            $content = $this->generator->render($stub, $variables);

            if ($content === null) {
                // 模板缺失：记录并继续，让上层一次性报全，而不是生成一半才失败
                $missing[] = $stub;

                if ($artifact['required']) {
                    $requiredMissing[] = $stub;
                }

                continue;
            }

            if (is_file($target) && !$force) {
                $skipped[] = $relative;
                continue;
            }

            $dir = dirname($target);

            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            file_put_contents($target, $content);
            $written[] = $relative;
        }

        // 一个文件都没产出且模板全缺 → 会话无效，上层应直接失败
        $fatal = $written === [] && $skipped === [] && $missing !== [];

        $plugin        = (string) ($plan['plugin'] ?? '');
        $wantMerge     = (bool) ($options['merge_manifest'] ?? false);
        $mergeIgnored  = $wantMerge && $plugin === '';
        $merge         = null;

        if ($wantMerge && $plugin !== '' && !$fatal) {
            // 合并放在产物落盘之后：即使合并失败，已生成的文件仍然可用
            $merge = $this->mergeManifestMenu($plan);
        }

        return [
            'written'          => $written,
            'skipped'          => $skipped,
            'missing'          => $missing,
            'required_missing' => $requiredMissing,
            'merge'            => $merge,
            'merge_ignored'    => $mergeIgnored,
            'fatal'            => $fatal,
        ];
    }

    /**
     * 项目根目录（含尾部分隔符）。
     */
    protected function projectRoot(): string
    {
        $root = $this->projectRoot ?? (string) root_path();

        return rtrim($root, '/\\') . DIRECTORY_SEPARATOR;
    }

    /**
     * 插件 manifest.json 的绝对路径。
     *
     * public：上层（CLI 提示文案 / Web 报错）需要指明位置；同时便于测试注入临时路径。
     */
    public function manifestPath(string $plugin): string
    {
        return $this->projectRoot() . 'plugins' . DIRECTORY_SEPARATOR
            . $plugin . DIRECTORY_SEPARATOR . 'manifest.json';
    }

    /**
     * 生成写入 manifest.json 的菜单项。
     *
     * path 采用**显式长式** `/plugin/{ident}/{controller}/index`：它与短式
     * `/plugin/{ident}/{controller}` 运行时等价（动作段缺省即 `index`），两种写法
     * 并存是允许的 —— 合并去重按 {@see MenuPathNormalizer} 归一后的身份判定，
     * 不会因写法不同追加重复项（见 {@see self::mergeManifestMenu()}）。
     *
     * @param array<string, mixed> $plan
     * @param bool                 $withMarker 是否附带「自动合并」标记
     *                                         （手工粘贴的片段不带标记 —— 手工即刻意）
     * @param string|null          $form       产出风格（{@see MenuPathNormalizer::FORM_SHORT} /
     *                                         `FORM_LONG`）；null 等价于长式
     * @return array<string, mixed>
     */
    public function manifestMenuEntry(array $plan, bool $withMarker = false, ?string $form = null): array
    {
        $entry = [
            'name'     => (string) $plan['title'],
            'icon'     => 'bi bi-circle',
            'path'     => $this->menuPath($plan, $form),
            'type'     => 'menu',
            'sort'     => 0,
            'status'   => 1,
            'children' => [],
        ];

        if ($withMarker) {
            $entry[self::AUTO_MERGED_KEY] = self::AUTO_MERGED_NOTE;
        }

        return $entry;
    }

    /**
     * 生成 manifest.json 的 `menus` 片段（供手工粘贴）。
     *
     * @param array<string, mixed> $plan
     * @param string|null          $form 产出风格；null 等价于长式
     */
    public function manifestMenuSnippet(array $plan, ?string $form = null): string
    {
        return (string) json_encode(
            $this->manifestMenuEntry($plan, false, $form),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
    }

    /**
     * 该插件 manifest 既有的产出风格（无 manifest / 非法 JSON / 无有效条目 → null）。
     *
     * 供命令行「手工粘贴片段」与自动合并共用：让新条目与插件既有写法保持一致，
     * 避免同一个插件里长短混排。
     */
    public function preferredFormForPlugin(string $plugin): ?string
    {
        $loaded = $this->menuWriter()->load($this->manifestPath($plugin));

        if (!$loaded['ok']) {
            return null;
        }

        $paths = $this->menuWriter()->paths((array) ($loaded['data']['menus'] ?? []));

        return $paths === [] ? null : $this->normalizer()->preferredForm($paths);
    }

    /**
     * 按产出风格拼菜单 path（
     * 短式 `/plugin/{ident}/{controller}` / 长式 `/plugin/{ident}/{controller}/index`）。
     *
     * @param array<string, mixed> $plan
     */
    protected function menuPath(array $plan, ?string $form = null): string
    {
        $base = '/plugin/' . $plan['plugin'] . '/' . $plan['controller_url'];

        return $form === MenuPathNormalizer::FORM_SHORT ? $base : $base . '/index';
    }

    /**
     * 把菜单自动合并进插件的 manifest.json。
     *
     * 读写内核已抽到 {@see ManifestMenuWriter}（与 `plugin:menu-derive --apply` 共用），
     * 本方法只负责「按 plan 造条目 + 组合消息」。
     *
     * 语义（**幂等 + 非破坏**）：
     *   - 不存在等价菜单 → 追加（写法见下「风格继承」），并打上 `_auto_merged` 标记
     *   - 已存在等价菜单 → **原样保留**，既不覆盖开发者手工调整过的
     *     name/icon/sort，也不补标记 —— 手工维护的菜单不该被误标成「自动合并」
     *
     * 「已存在」的判定是**两层**的：
     *   1. 递归全树（含 `dir` 分组子节点）：生成器产出的是平铺菜单，开发者常把它手工
     *      挪进分组，只扫顶层会让同一控制器在子层留着手工条目、顶层再被追加一份；
     *   2. 按 `MenuPathNormalizer` 归一后的身份比对：`/plugin/x/y` 与
     *      `/plugin/x/y/index` 运行时是同一资源，不应判成两个节点（控制器段拼写
     *      `groupBattle` / `group_battle` 同理）。写法不同时**保留既有写法**，
     *      不追加、不改写（归一化只做比较键，见该类注释）。
     *
     * **风格继承**：新追加的条目沿用该 manifest 既有条目的多数派写法
     * （`MenuPathNormalizer::preferredForm()`），避免插件内长短混排；无有效条目时用长式。
     *
     * 失败不抛异常：产物文件此时已写入成功，合并失败不应让整条命令变成失败。
     *
     * @param array<string, mixed> $plan
     * @return array{ok: bool, message: string}
     */
    public function mergeManifestMenu(array $plan): array
    {
        $manifest = $this->manifestPath((string) $plan['plugin']);
        $writer   = $this->menuWriter();

        $loaded = $writer->load($manifest);

        if (!$loaded['ok']) {
            // 「不可读」类错误保持原样；其余补上「已跳过」语义
            return ['ok' => false, 'message' => str_contains($loaded['message'], '不可读')
                ? $loaded['message']
                : $loaded['message'] . '（已跳过自动合并）'];
        }

        $data  = $loaded['data'];
        $menus = (array) ($data['menus'] ?? []);

        // 风格继承：新条目沿用该 manifest 既有条目的多数派写法
        // （短式 / 长式运行时等价，见 `MenuPathNormalizer`；无有效条目 → 长式）
        $form = $this->normalizer()->preferredForm($writer->paths($menus));

        $entry   = $this->manifestMenuEntry($plan, true, $form);
        $outcome = $writer->append($menus, $entry);

        if (!$outcome['added']) {
            return ['ok' => true, 'message' => 'manifest.json ' . $outcome['message']];
        }

        $data['menus'] = $menus;

        // 带上原始形态：避免把 `"dependencies": {}` 这类空对象写成 `[]`
        $saved = $writer->save($manifest, $data, $loaded['shape']);

        if (!$saved['ok']) {
            return ['ok' => false, 'message' => $saved['message']];
        }

        return ['ok' => true, 'message' => sprintf(
            '已自动合并菜单到 manifest.json（path=%s，含 %s 标记，请手动排版）',
            $entry['path'],
            self::AUTO_MERGED_KEY
        )];
    }

    /**
     * 在菜单树中按「归一化身份」查找节点（**递归**，含分组子节点）。
     *
     * 实现已抽到 {@see ManifestMenuWriter::find()}（与 `plugin:menu-derive` 共用同一套
     * 递归 + 归一化判定）；本方法保留为 protected 委托，子类仍可覆写。
     *
     * @param array<int|string, mixed> $menus
     * @return array<string, mixed>|null 命中的节点（**原样**返回，供上层报出实际写法）
     */
    protected function findMenuByPath(array $menus, string $path): ?array
    {
        return $this->menuWriter()->find($menus, $path);
    }
}
