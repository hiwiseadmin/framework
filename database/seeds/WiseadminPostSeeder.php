<?php
declare(strict_types=1);

use think\migration\Seeder;
use think\facade\Db;

/**
 * WiseAdmin 官网文章（Phase C）
 *
 * 特性：**幂等**，按 slug 更新；已存在的文章只校正字段与正文。
 *
 * 用法：
 *   php think seed:run -s WiseadminPostSeeder
 *
 * 说明：
 *   - 全部文章放 `blog` 栏目，版本更新放 `changelog` 栏目。
 *   - 正文为 HTML，按主题 `ws-prose` 排版；直接写库，不经模板解析。
 *   - 内容取自仓库内真实工程记录，不编造 changelog 与 API 名。
 */
class WiseadminPostSeeder extends Seeder
{
    private const SITE_ID = 1;
    private const MODEL_ARTICLE = 1;

    /**
     * 文章 Meta 描述（70–160 字）
     *
     * SEO 审计对「Meta描述」的硬要求是 70–160 字；初版描述只有 30–50 字，
     * 10 篇全部被扣分。此处统一覆盖，避免逐篇维护两份文案。
     */
    private const DESCRIPTIONS = [
        'wise-crud-three-layers'    => 'wise:crud 用「声明 > 启发式 > 兜底」三层判定把字段翻译成后台表单，--dry-run 会把每条判定连同命中规则一起打印出来，猜的部分可以逐条核对再落盘。',
        'tp8-plugin-hotload'        => '插件在 Web 正常、CLI 却报未启用，根因通常是加载发生在状态读取之后。把插件加载提前到 Console::starting() 回调，并确认状态存在 plugin_config 而非插件表，问题即可消失。',
        'why-form-schema-dsl'       => '同一套校验规则散落在控制器、前端 JS 与数据库三处，改一处漏两处。wise-form-schema 用一份 Fluent 定义同时产出表单 HTML、服务端校验与前端校验，并保持零框架依赖。',
        'plugin-state-not-in-table' => '一次耗时三小时的排查实录：插件启用状态并不在 ws_plugins 表，而在 plugin_config 的 {ident}.__state。查错表会让后续所有判断建立在错误事实上，排查方向完全跑偏。',
        'php-mcp-server'            => 'MCP 的公开实现几乎都在 Python 与 Node 生态，PHP 侧接近空白。wise-mcp 提供 stdio 与 Streamable HTTP 双传输、Server/Client 双角色与能力注册，让老后台的数据可被 AI 工具直接调用。',
        'rbac2-in-database'         => '角色继承只是 RBAC1。互斥角色与基数限制才是审计合规真正关心的部分，且必须落成数据：可查询、在授予时校验、在审计中留痕，而不是散落在控制器里的 if 判断。',
        'url-engine-migration'      => '老 CMS 的伪静态规则是一套有语法的表达式，同时承担生成、反解与分页三种职责。迁移时只对齐格式不对齐语义，必然产生 404；正确做法是全量抓旧 URL 逐条反解比对。',
        'plugin-assets-publish-timing' => '插件 statics/ 不会实时对外提供，它只在安装、启用、升级三个时机发布到 public/。改了源码却没重新启用，浏览器拿到的永远是上一次发布的旧文件，强刷与换浏览器都无效。',
        'crontab-distributed-lock'  => '多机部署时每台机器都挂同一条 crontab，等于同一任务执行 N 次。锁的粒度必须按「任务标识」而不是「进程」；同时要处理锁超时、执行日志与失败告警三件事。',
        'v1-0-1'                    => '1.0.1 是首个可对外使用的稳定版本：ThinkPHP 8 多应用骨架、manifest 声明式插件系统与完整生命周期、20 个可独立安装的 wise-* Composer 包，以及 wise:crud 与 plugin:create 两个 CLI。',
    ];

    /**
     * 文末「相关阅读」内链表（每篇 3 条）
     *
     * SEO 审计要求正文含 2–3 个站内链接；这里按主题相关性建立固定映射，
     * 既满足内链要求，也让读者能沿着一条线读下去。
     *
     * @var array<string, array<int, array{0:string, 1:string}>>
     */
    private const RELATED = [
        'wise-crud-three-layers' => [
            ['/quickstart.html', '快速开始：生成你的第一个业务模块'],
            ['/features.html', '核心特性：三层判定的完整说明'],
            ['/blog/plugin-state-not-in-table.html', '插件启用状态到底存在哪张表'],
        ],
        'tp8-plugin-hotload' => [
            ['/blog/plugin-state-not-in-table.html', '插件启用状态不在插件表里'],
            ['/blog/plugin-assets-publish-timing.html', '插件静态资源的三个发布时机'],
            ['/features.html', '核心特性：插件化与生命周期'],
        ],
        'why-form-schema-dsl' => [
            ['/packages.html', '能力包清单：wise-form-schema'],
            ['/blog/wise-crud-three-layers.html', '生成器如何复用同一套字段语义'],
            ['/features.html', '核心特性：插件级脚手架'],
        ],
        'plugin-state-not-in-table' => [
            ['/blog/tp8-plugin-hotload.html', 'CLI 加载插件的时机问题'],
            ['/blog/plugin-assets-publish-timing.html', '改了代码为什么不生效'],
            ['/quickstart.html', '快速开始：三个常见卡点'],
        ],
        'php-mcp-server' => [
            ['/packages.html', '能力包清单：wise-mcp'],
            ['/features.html', '核心特性：AI 能力三层结构'],
            ['/blog/wise-crud-three-layers.html', 'AI 工具最终要落到具体业务操作上'],
        ],
        'rbac2-in-database' => [
            ['/features.html', '核心特性：RBAC2 三类约束'],
            ['/packages.html', '能力包清单：wise-auth'],
            ['/blog/plugin-state-not-in-table.html', '权限之外，配置也可能查错表'],
        ],
        'url-engine-migration' => [
            ['/packages.html', '能力包清单：wise-url'],
            ['/features.html', '核心特性：SEO 与伪静态'],
            ['/blog/wise-crud-three-layers.html', '从表结构反推的另一种"翻译"'],
        ],
        'plugin-assets-publish-timing' => [
            ['/blog/plugin-state-not-in-table.html', '插件状态与加载顺序'],
            ['/blog/tp8-plugin-hotload.html', 'CLI 下插件为什么"消失"'],
            ['/quickstart.html', '快速开始：常见卡点'],
        ],
        'crontab-distributed-lock' => [
            ['/packages.html', '能力包清单：wise-crontab'],
            ['/features.html', '核心特性：高级能力开箱'],
            ['/blog/plugin-assets-publish-timing.html', '同样是"看不见的时机"问题'],
        ],
        'v1-0-1' => [
            ['/quickstart.html', '快速开始：安装与升级步骤'],
            ['/download.html', '下载 v1.0.1'],
            ['/packages.html', '能力包清单：20 个可独立安装的包'],
        ],
    ];

    public function run(): void
    {
        $now = date('Y-m-d H:i:s');

        foreach ($this->posts() as $i => $post) {
            $this->upsertPost($post, $now, $i);
        }

        $this->upsertPost($this->releasePost(), $now, 0);

        $this->syncAliases();

        echo PHP_EOL . '官网文章写入完成。' . PHP_EOL;
    }

    /**
     * @param array{title:string,slug:string,cat:string,description:string,keywords:string,content:string,recommend:int} $post
     */
    private function upsertPost(array $post, string $now, int $order): void
    {
        $catid = (int) Db::name('wisecms_category')
            ->where('alias', $post['cat'])
            ->where('site_id', self::SITE_ID)
            ->value('id');

        if ($catid <= 0) {
            echo '  跳过（栏目不存在）：' . $post['cat'] . ' / ' . $post['title'] . PHP_EOL;
            return;
        }

        $time = date('Y-m-d H:i:s', time() - max(0, $order) * 3600);

        $description = self::DESCRIPTIONS[$post['slug']] ?? $post['description'];
        $content     = $post['content'] . $this->relatedBlock($post['slug']);
        $plain       = mb_strlen(strip_tags($content));

        $data = [
            'site_id'      => self::SITE_ID,
            'catid'        => $catid,
            'model_id'     => self::MODEL_ARTICLE,
            'title'        => $post['title'],
            'content'      => $content,
            'alias'        => '',
            'slug'         => $post['slug'],
            'keywords'     => $post['keywords'],
            'description'  => $description,
            'read_time'    => max(1, (int) round($plain / 400)),
            'word_count'   => $plain,
            'thumb'        => '',
            'og_image'     => '',
            'canonical_url' => '',
            'url'          => '',
            'username'     => 'WiseAdmin',
            'source_type'  => 'manual',
            'source_url'   => '',
            'status'       => 2,
            'is_top'       => 0,
            'is_recommend' => $post['recommend'],
            'posids'       => 0,
            'groupids_view' => '',
            'listorder'    => 0,
            'publish_time' => $time,
            'update_time'  => $now,
        ];

        $exists = Db::name('wisecms_content')
            ->where('slug', $post['slug'])
            ->where('site_id', self::SITE_ID)
            ->find();

        if ($exists) {
            Db::name('wisecms_content')->where('id', $exists['id'])->update($data);
            echo '  更新文章：' . $post['title'] . PHP_EOL;
            return;
        }

        $data['create_time'] = $time;
        $data['view_count']  = 0;
        Db::name('wisecms_content')->insert($data);
        echo '  新建文章：' . $post['title'] . PHP_EOL;
    }

    /**
     * 重建已发布内容的 URL 别名（旧表），供 wisecms:url:migrate 迁入 wise-url
     */
    private function syncAliases(): void
    {
        try {
            $service = new \plugin\wisecms\service\url\UrlService();

            foreach (Db::name('wisecms_content')->where('status', 2)->where('site_id', self::SITE_ID)->select() as $row) {
                $service->syncContent(\plugin\wisecms\model\Content::find($row['id']));
            }
        } catch (\Throwable $e) {
            echo '  别名同步跳过：' . $e->getMessage() . PHP_EOL;
        }
    }

    /**
     * 文末「相关阅读」区块（站内内链）
     *
     * SEO 审计要求正文含 2–3 个站内链接，这里按 RELATED 映射生成。
     * 全部为站内相对路径，不依赖站点域名，换域名无需重新生成。
     */
    private function relatedBlock(string $slug): string
    {
        $links = self::RELATED[$slug] ?? [];
        if ($links === []) {
            return '';
        }

        $items = '';
        foreach ($links as [$url, $title]) {
            $items .= '  <li><a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">'
                . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</a></li>' . PHP_EOL;
        }

        return PHP_EOL . '<h2>相关阅读</h2>' . PHP_EOL . '<ul>' . PHP_EOL . $items . '</ul>' . PHP_EOL;
    }


    /**
     * @return array<int, array<string, mixed>>
     */
    private function posts(): array
    {
        return [
            $this->postCrudThreeLayers(),
            $this->postPluginHotload(),
            $this->postFormSchemaDsl(),
            $this->postPluginState(),
            $this->postMcpServer(),
            $this->postRbac2(),
            $this->postUrlEngine(),
            $this->postAssetsPublish(),
            $this->postCrontabLock(),
        ];
    }

    private function postCrudThreeLayers(): array
    {
        $content = <<<'HTML'
<p>WiseAdmin 的 CRUD 生成器只做一件事：把一张表的字段，翻译成后台能用的增删改查。但"翻译"这个词掩盖了真正困难的部分——<strong>它凭什么认为某个字段应该渲染成下拉框，而不是文本框？</strong></p>

<h2>三层判定：声明 &gt; 启发式 &gt; 兜底</h2>

<p>生成器给每个字段做一次判定，判定结果来自三个层次，优先级从高到低：</p>

<table>
<thead><tr><th>层</th><th>来源</th><th>可采信度</th></tr></thead>
<tbody>
<tr><td>P1 声明</td><td><code>--field</code> / <code>config/crud_map.php</code></td><td>直接采信</td></tr>
<tr><td>P2 启发式</td><td>字段名、注释、DB 类型</td><td>需要核对</td></tr>
<tr><td>P3 兜底</td><td>未命中任何规则时的默认渲染</td><td>通常要改</td></tr>
</tbody>
</table>

<h2>为什么必须把"猜的"标出来</h2>

<p>常见的一键生成工具，产物看起来完整，问题在于你<strong>不知道哪些字段是它猜的</strong>。等发现 <code>status</code> 被渲染成文本框时，已经改了三处代码。</p>

<p>WiseAdmin 的做法是把判定过程完全暴露：</p>

<pre><code>$ php think wise:crud ws_article --dry-run

字段        DB类型        判定    层    命中规则
id          int           隐藏    P1    主键
title       varchar(120)  text    P1    命名启发式
created_at  datetime      跳过    P2    系统时间戳

共 9 字段：声明 0 / 启发式推断 8 / 兜底 0 / 跳过 1</code></pre>

<blockquote>
<p>标注 <code>[推断]</code> 的字段由命名/注释启发式得出，建议核对；标注 <code>[声明]</code> 的为显式覆盖，可直接采信。</p>
</blockquote>

<h2>不合预期？显式覆盖，不改表结构</h2>

<pre><code>$ php think wise:crud ws_article \
    --field=status:radio:0=草稿,1=已发布 \
    --relation=author_id:ws_admin:id:username</code></pre>

<p>加 <code>--save-map</code> 可把声明固化进 <code>config/crud_map.php</code>，下次重新生成时自动复用。</p>

<h2>与"生成整页代码"的区别</h2>

<p>产物是控制器 + 模型 + 菜单迁移 + 列表 JS，<strong>页面由运行时渲染</strong>。想改页面，改共享模板或表单元数据，所有页面同步——而不是改生成的 HTML 然后下次生成时被覆盖。</p>

<p>这就是"能长期维护"与"一次性脚手架"的分界线。</p>
HTML;

        return [
            'title'       => '一条命令把存量表变成后台 CRUD：wise:crud 的三层判定',
            'slug'        => 'wise-crud-three-layers',
            'cat'         => 'blog',
            'description' => '声明 > 启发式 > 兜底，三层判定把"生成的"和"猜的"分开，猜的部分可以逐条核对。',
            'keywords'    => 'wise:crud,CRUD生成器,字段判定,ThinkPHP8',
            'recommend'   => 1,
            'content'     => $content,
        ];
    }

    private function postPluginHotload(): array
    {
        $content = <<<'HTML'
<p>插件在 Web 请求里工作正常，一到 CLI 就"消失"——这是 ThinkPHP 插件体系最典型的一类故障。原因通常不在插件本身，而在<strong>加载时机</strong>。</p>

<h2>现象</h2>

<pre><code>$ php think myplugin:sync
# 提示：插件未启用 / 命令不存在</code></pre>

<p>但后台插件列表里它明明是"已启用"。</p>

<h2>两个独立的坑</h2>

<h3>坑一：状态读成了 false，并且被固化</h3>

<p>CLI 启动时，框架还没加载插件配置就去读插件状态，读到的是默认值 false。更麻烦的是这个 false 会被<strong>写回缓存</strong>，之后即使配置正确也一直读到 false——看起来像"插件坏了"。</p>

<p>正确做法是把插件加载挂在 <code>Console::starting()</code> 回调里，让它在命令解析之前完成：</p>

<pre><code>// 位置决定成败：不在 starting 里做，状态会被读成 false 并固化
Console::starting(function () {
    // 加载插件 / 注册命名空间 / 注册命令
});</code></pre>

<h3>坑二：状态根本不在你以为的那张表</h3>

<p>插件的启用状态不在 <code>ws_plugins</code> 表，而在 <code>plugin_config</code> 的 <code>{ident}.__state</code>。直接查插件表判断"是否启用"，结论一定是错的。</p>

<h2>排查顺序</h2>

<table>
<thead><tr><th>#</th><th>检查项</th><th>怎么确认</th></tr></thead>
<tbody>
<tr><td>1</td><td>状态存在哪</td><td>查 <code>plugin_config</code> 的 <code>{ident}.__state</code></td></tr>
<tr><td>2</td><td>CLI 是否加载</td><td>确认加载逻辑在 <code>Console::starting()</code> 内</td></tr>
<tr><td>3</td><td>缓存是否脏</td><td>清缓存后重试，观察是否复现</td></tr>
<tr><td>4</td><td>命名空间是否注册</td><td>用 <code>class_exists('plugin\{ident}\...')</code> 直接验证</td></tr>
</tbody>
</table>

<h2>一句话结论</h2>

<blockquote>
<p>插件在 CLI 下"未启用"，九成不是配置问题，而是<strong>加载发生在状态读取之后</strong>。把加载提前到 <code>Console::starting()</code>，问题消失。</p>
</blockquote>
HTML;

        return [
            'title'       => 'ThinkPHP 8 插件热加载：一个回调位置决定成败',
            'slug'        => 'tp8-plugin-hotload',
            'cat'         => 'blog',
            'description' => 'CLI 里加载插件必须在 Console::starting() 回调里做，否则状态读成 false 并固化。',
            'keywords'    => 'ThinkPHP8,插件加载,Console::starting',
            'recommend'   => 1,
            'content'     => $content,
        ];
    }

    private function postFormSchemaDsl(): array
    {
        $content = <<<'HTML'
<p>一个后台表单的校验规则，通常散落在三个地方：</p>

<ul>
<li>控制器里 <code>validate()</code> 写一遍；</li>
<li>前端 JS 再写一遍（为了即时提示）；</li>
<li>数据库的 <code>NOT NULL</code> / <code>VARCHAR(50)</code> 是第三份事实。</li>
</ul>

<p>改一处、漏两处，是这类项目的常态。<code>wise-form-schema</code> 存在的唯一理由，就是把这三种表述收敛成一份可执行的定义。</p>

<h2>Fluent API：定义即文档</h2>

<pre><code>$form = Form::make('article')
    ->text('title', '标题')->required()->max(120)
    ->select('status', '状态')->options([0 =&gt; '草稿', 1 =&gt; '已发布'])->default(0)
    ->editor('content', '正文')
    ->image('thumb', '封面图');</code></pre>

<p>这段定义同时产出三样东西：表单 HTML、服务端校验规则、前端校验规则。三者不会漂移，因为它们同源。</p>

<h2>为什么做成零框架依赖</h2>

<p>表单定义本身是纯数据结构 + 纯函数变换，没有理由绑死 ThinkPHP。做成零依赖包之后：</p>

<table>
<thead><tr><th>好处</th><th>说明</th></tr></thead>
<tbody>
<tr><td>可单测</td><td>不需要启动框架就能验证字段类型与规则生成</td></tr>
<tr><td>可移植</td><td>同一份定义能用在普通 PHP 项目、CLI 工具或代码生成器里</td></tr>
<tr><td>可复用</td><td>CRUD 生成器与后台表单共用同一套字段语义</td></tr>
</tbody>
</table>

<h2>字段类型的取舍</h2>

<p>不追求"覆盖所有 HTML 控件"，只覆盖后台高频场景：文本、长文本、数字、日期时间、下拉、单选、复选、开关、颜色、标签、图片、多图、文件、富文本、Markdown、密码。新增类型的成本必须低于"在业务里 hack 一下"，否则这个 DSL 就失去意义。</p>

<h2>一句话结论</h2>

<blockquote>
<p>表单 DSL 不是为了少写几行代码，而是为了消除"同一规则存在三份"的结构性重复。</p>
</blockquote>
HTML;

        return [
            'title'       => '为什么表单要有一门自己的 DSL',
            'slug'        => 'why-form-schema-dsl',
            'cat'         => 'blog',
            'description' => '校验规则散落在控制器、前端和数据库三处，改一处漏两处——这是表单 DSL 存在的唯一理由。',
            'keywords'    => 'wise-form-schema,表单DSL,Fluent API',
            'recommend'   => 0,
            'content'     => $content,
        ];
    }

    private function postPluginState(): array
    {
        $content = <<<'HTML'
<p>一个真实耗时三小时的排查记录。结论很短，过程值得写下来。</p>

<h2>现象</h2>

<p>后台插件列表显示"已启用"，Web 访问一切正常；但只要跑 CLI 命令，插件里的命令就不存在，日志里还提示插件未启用。</p>

<h2>错误假设（浪费了两小时）</h2>

<table>
<thead><tr><th>假设</th><th>验证结果</th></tr></thead>
<tbody>
<tr><td>插件安装不完整</td><td>文件齐全，manifest 可解析</td></tr>
<tr><td>命名空间注册失败</td><td>Web 请求里 <code>class_exists</code> 为 true</td></tr>
<tr><td>缓存没清</td><td>清了三次，仍复现</td></tr>
</tbody>
</table>

<h2>真正的原因</h2>

<p>插件启用状态<strong>不在 <code>ws_plugins</code> 表</strong>，而在 <code>plugin_config</code> 的 <code>{ident}.__state</code> 键里。</p>

<pre><code>-- 查错表：永远是空或过期值
SELECT * FROM ws_plugins WHERE name = 'myplugin';

-- 正确位置：键为 {ident}.__state
SELECT * FROM ws_plugin_config WHERE name = 'myplugin.__state';</code></pre>

<p>因为查错了表，所有"状态核对"都建立在错误事实上，导致排查方向完全跑偏。</p>

<h2>为什么设计成这样</h2>

<p>插件状态与插件配置放在同一处，是为了让"启用/禁用"能与其它配置项一起被统一读写、统一缓存、统一失效。代价是它不符合"状态应该在 plugins 表"的直觉。</p>

<h2>排查清单（可直接照用）</h2>

<ol>
<li>确认状态的真实位置：<code>plugin_config</code> 的 <code>{ident}.__state</code>；</li>
<li>确认 CLI 侧加载时机在 <code>Console::starting()</code>；</li>
<li>确认没有把读到的 false 写回缓存（清缓存后重试观察）；</li>
<li>用 <code>class_exists</code> 直接验证命名空间，而不是看列表页。</li>
</ol>

<blockquote>
<p>教训：排查问题时，<strong>先确认自己读的是不是那份事实</strong>，比加班两小时更有用。</p>
</blockquote>
HTML;

        return [
            'title'       => '排查 3 小时：插件明明启用了，为什么 CLI 里加载不到',
            'slug'        => 'plugin-state-not-in-table',
            'cat'         => 'blog',
            'description' => '插件启用状态不在 ws_plugins 表，而在 plugin_config 的 {ident}.__state。',
            'keywords'    => '插件状态,plugin_config,CLI排查',
            'recommend'   => 0,
            'content'     => $content,
        ];
    }

    private function postMcpServer(): array
    {
        $content = <<<'HTML'
<p>MCP（Model Context Protocol）的公开实现几乎全部集中在 Python 与 Node 生态。如果你维护的是一套 PHP 后台系统，想让它被 AI 工具调用，中间几乎是一片空白。</p>

<p><code>wise-mcp</code> 补的就是这块。</p>

<h2>MCP 到底解决什么</h2>

<p>它把"AI 能做什么"从提示词里搬到协议里：每个能力是一个带 schema 的 tool，模型通过标准 JSON-RPC 调用，宿主决定是否放行。</p>

<table>
<thead><tr><th>层</th><th>职责</th></tr></thead>
<tbody>
<tr><td>传输</td><td>stdio（本地进程）/ Streamable HTTP（远程）</td></tr>
<tr><td>协议</td><td>JSON-RPC 消息、初始化握手、能力协商</td></tr>
<tr><td>角色</td><td>Server（暴露能力）/ Client（调用能力）双角色</td></tr>
<tr><td>注册</td><td>能力提供者注册：tool / resource / prompt</td></tr>
</tbody>
</table>

<h2>为什么用 PHP 做这件事值得</h2>

<p>因为<strong>数据在老后台里</strong>。让 AI 读一份导出文件，和让它直接查业务库，是两件事。把 MCP Server 做进既有后台，意味着：</p>

<ul>
<li>权限沿用既有 RBAC，不需要重建一套授权体系；</li>
<li>数据不搬家，不存在"同步延迟"；</li>
<li>操作可审计——每次 tool 调用都落在操作日志里。</li>
</ul>

<h2>两种传输怎么选</h2>

<table>
<thead><tr><th>场景</th><th>选型</th><th>原因</th></tr></thead>
<tbody>
<tr><td>桌面客户端、本地工具</td><td>stdio</td><td>零网络配置，进程生命周期即会话</td></tr>
<tr><td>多客户端、远程接入</td><td>Streamable HTTP</td><td>可复用现有 Web 服务与鉴权链路</td></tr>
</tbody>
</table>

<h2>与 Agent 的分工</h2>

<p><code>wise-agent</code> 是"会用工具的运行时"，<code>wise-mcp</code> 是"工具的对外接口"。前者决定调用谁，后者规定怎么被调用。两者可以独立使用，也可以组合成完整闭环。</p>

<blockquote>
<p>关键不是"PHP 能不能做 MCP"，而是——你的业务数据本来就在 PHP 里，绕开它接 AI，只会多出一个同步问题。</p>
</blockquote>
HTML;

        return [
            'title'       => '用 PHP 给老后台接一个 MCP Server',
            'slug'        => 'php-mcp-server',
            'cat'         => 'blog',
            'description' => 'MCP 的公开实现几乎全在 Python/Node 生态，PHP 这边几乎空白。wise-mcp 补的就是这块。',
            'keywords'    => 'MCP,PHP,wise-mcp,AI工具',
            'recommend'   => 1,
            'content'     => $content,
        ];
    }

    private function postRbac2(): array
    {
        $content = <<<'HTML'
<p>大部分后台框架的"权限"到角色继承就结束了。但真实业务里的约束远不止此——"制单人和审核人不能是同一个人"这类规则，用继承表达不出来。</p>

<p>这就是 RBAC2（职责分离）要解决的问题。</p>

<h2>三类约束</h2>

<table>
<thead><tr><th>约束</th><th>语义</th><th>典型场景</th></tr></thead>
<tbody>
<tr><td>角色继承（RBAC1）</td><td>子角色继承父角色权限</td><td>「编辑」继承「访客」的只读权限</td></tr>
<tr><td>互斥角色</td><td>同一主体不可同时持有两个角色</td><td>制单人 / 审核人分离</td></tr>
<tr><td>基数限制</td><td>某角色持有人数上限</td><td>超级管理员最多 2 人</td></tr>
</tbody>
</table>

<h2>难点：约束必须能被查询</h2>

<p>把约束写在控制器里做 <code>if</code> 判断是最容易的做法，也是最容易失效的做法。正确的落地方式是把约束变成<strong>数据</strong>：</p>

<ul>
<li><strong>可查询</strong>：任意时刻能回答"用户 X 是否违反互斥"；</li>
<li><strong>可校验</strong>：授权动作发生时就拦住，而不是等到业务执行时报错；</li>
<li><strong>可审计</strong>：谁在什么时候被授予了什么，事后能回溯。</li>
</ul>

<h2>校验时机比校验逻辑更重要</h2>

<table>
<thead><tr><th>时机</th><th>能拦住什么</th></tr></thead>
<tbody>
<tr><td>授予角色时</td><td>互斥冲突、基数超限</td></tr>
<tr><td>请求鉴权时</td><td>越权访问</td></tr>
<tr><td>定期巡检时</td><td>历史数据里已存在的冲突</td></tr>
</tbody>
</table>

<p>只在鉴权时校验是不够的：冲突一旦写入数据库，后续每次鉴权都要带着这个"脏状态"运行。授予时拦截成本最低。</p>

<h2>一句话结论</h2>

<blockquote>
<p>RBAC1 解决"能不能做"，RBAC2 解决"该不该由这个人做"。后者才是审计合规真正关心的部分。</p>
</blockquote>
HTML;

        return [
            'title'       => 'RBAC2 落地：角色互斥与基数限制怎么进数据库',
            'slug'        => 'rbac2-in-database',
            'cat'         => 'blog',
            'description' => '角色继承只是 RBAC1。真正难的是互斥约束和基数限制——它们必须能被查询、被校验、被审计。',
            'keywords'    => 'RBAC2,职责分离,权限设计',
            'recommend'   => 0,
            'content'     => $content,
        ];
    }

    private function postUrlEngine(): array
    {
        $content = <<<'HTML'
<p>老 CMS 的伪静态规则不是"配置项"，而是一套有语法的表达式。迁移这类系统时，最危险的做法是只对齐格式，不对齐语义。</p>

<h2>规则其实是模板</h2>

<p>一条 URL 规则长这样：</p>

<pre><code>/{catdir}/{slug}.html
/category/{catdir}/list_{page}.html
/tag/{alias}/</code></pre>

<p>它同时承担三种职责：</p>

<table>
<thead><tr><th>方向</th><th>用途</th></tr></thead>
<tbody>
<tr><td>生成</td><td>给一个实体，算出它的规范 URL</td></tr>
<tr><td>反解</td><td>给一个 URL，算出是哪个实体</td></tr>
<tr><td>分页</td><td>同一实体的第 N 页如何表达</td></tr>
</tbody>
</table>

<p>三者必须由<strong>同一份规则</strong>驱动。生成走一套、解析走另一套，是绝大多数"伪静态后 404"的根因。</p>

<h2>迁移保的是语义，不是字符串</h2>

<p>PHPCMS v9 风格的占位符（<code>{$catdir}</code>、<code>{$id}</code>）与新的 token（<code>{catdir}</code>、<code>{slug}</code>）看起来只是写法不同，但语义未必等价：</p>

<ul>
<li><code>{$id}</code> 是数字主键，<code>{slug}</code> 是可读别名——后者能改，前者不能；</li>
<li>栏目目录可能有多级，<code>{catdir}</code> 取的是哪一级需要明确；</li>
<li>分页表达式在 archive 规则里是 <code>{page}</code>，在 list 规则里可能是路径段。</li>
</ul>

<p>所以迁移的第一步不是写转换脚本，而是<strong>把旧站的 URL 全量抓一遍</strong>，用新引擎反解，逐条比对实体 ID 是否一致。</p>

<h2>历史 URL 必须留 301</h2>

<p>改 URL 结构一定会产生死链。正确做法是维护一张历史表：旧路径 → 新路径，命中即 301。这样搜索引擎的权重能平滑转移，用户收藏也不会失效。</p>

<pre><code>旧：/news/tech/hello.html
新：/blog/hello.html
→ 301 到新地址，旧路径保留在历史表中</code></pre>

<h2>一句话结论</h2>

<blockquote>
<p>URL 规则引擎的价值不在"能配伪静态"，而在<strong>生成与解析共用一份规则</strong>——这是它不会自我矛盾的唯一保证。</p>
</blockquote>
HTML;

        return [
            'title'       => '方案化 URL 引擎：从 PHPCMS v9 规则迁移到 wise-url',
            'slug'        => 'url-engine-migration',
            'cat'         => 'blog',
            'description' => '老 CMS 的伪静态规则不是"配置"，是一套有语法的表达式。迁移要保语义，不只是保格式。',
            'keywords'    => 'wise-url,伪静态,URL规则,PHPCMS迁移',
            'recommend'   => 0,
            'content'     => $content,
        ];
    }

    private function postAssetsPublish(): array
    {
        $content = <<<'HTML'
<p>"我改了 JS，刷新页面没变化"——在插件体系里，这通常不是浏览器缓存的问题。</p>

<h2>静态资源不是实时读取的</h2>

<p>插件的 <code>statics/</code> 目录不会直接对外提供服务。它需要被<strong>发布</strong>到 <code>public/</code> 下，而发布只发生在三个时机：</p>

<table>
<thead><tr><th>时机</th><th>是否触发发布</th></tr></thead>
<tbody>
<tr><td>安装插件</td><td>是</td></tr>
<tr><td>启用插件</td><td>是</td></tr>
<tr><td>升级插件</td><td>是</td></tr>
<tr><td>直接改源码</td><td><strong>否</strong></td></tr>
</tbody>
</table>

<p>也就是说：改了 <code>statics/js/x.js</code> 却没有重新启用插件，浏览器拿到的永远是上一次发布的那份文件。</p>

<h2>怎么判断是不是这个问题</h2>

<pre><code># 比对源文件与已发布文件的修改时间
stat plugins/myplugin/statics/js/x.js
stat public/statics/plugins/myplugin/js/x.js</code></pre>

<p>两者 mtime 差很远，说明没发布，不是缓存。</p>

<h2>三个容易误判的现象</h2>

<table>
<thead><tr><th>现象</th><th>真实原因</th></tr></thead>
<tbody>
<tr><td>强刷也没用</td><td>发布文件本身是旧的</td></tr>
<tr><td>换浏览器也一样</td><td>服务端文件未更新</td></tr>
<tr><td>只有新装环境正常</td><td>新装触发了发布</td></tr>
</tbody>
</table>

<h2>正确的工作方式</h2>

<ol>
<li>开发期直接改 <code>public/statics/plugins/{ident}/</code> 下的文件，立即生效；</li>
<li>改完再同步回 <code>plugins/{ident}/statics/</code>，保证下次安装/升级时发布的是正确版本；</li>
<li>或者每次改完执行一次"重新启用"（会触发发布）。</li>
</ol>

<blockquote>
<p>记住这条：<strong>插件静态资源是"发布会话"的产物，不是"实时视图"</strong>。理解这一点，能省掉大量误判为缓存的时间。</p>
</blockquote>
HTML;

        return [
            'title'       => '改了代码不生效？插件静态资源发布的三个隐形时机',
            'slug'        => 'plugin-assets-publish-timing',
            'cat'         => 'blog',
            'description' => '静态资源只在安装、启用、升级时发布。改了不重新启用，就是在用旧文件。',
            'keywords'    => '插件静态资源,资源发布,缓存排查',
            'recommend'   => 0,
            'content'     => $content,
        ];
    }

    private function postCrontabLock(): array
    {
        $content = <<<'HTML'
<p>单机部署时，定时任务最大的问题是"跑不跑得起来"；多机部署时，问题变成"跑了几次"。</p>

<h2>重复执行是怎么发生的</h2>

<p>每台机器都挂了同一条 crontab：</p>

<pre><code>*/5 * * * * php /path/think app:sync</code></pre>

<p>5 台机器 = 每 5 分钟执行 5 次。如果这个任务是"发邮件提醒"，用户会收到 5 封；如果是"扣减库存"，数据直接错乱。</p>

<h2>锁的粒度决定它管不管用</h2>

<table>
<thead><tr><th>锁粒度</th><th>结果</th></tr></thead>
<tbody>
<tr><td>按机器加锁</td><td>无效——每台机器锁自己</td></tr>
<tr><td>按进程加锁</td><td>无效——进程各自独立</td></tr>
<tr><td>按<strong>任务标识</strong>加锁</td><td>有效——所有节点争同一把锁</td></tr>
</tbody>
</table>

<p>关键在最后一行：锁的键必须是"这个任务"，而不是"这个进程"。</p>

<h2>还要处理三件事</h2>

<ol>
<li><strong>锁超时</strong>：任务异常退出没释放锁，会导致后续永远拿不到锁。必须设 TTL。</li>
<li><strong>执行日志</strong>：多机环境下"到底哪台跑了、跑了多久、成功没有"必须可查，否则出问题无从定位。</li>
<li><strong>失败可见</strong>：任务连续失败要能告警，而不是等业务方发现数据不对。</li>
</ol>

<h2>不用分布式锁的替代方案</h2>

<p>如果不想引入 Redis，也可以把调度权收归单一节点：只有一台机器挂 crontab，其余机器只跑 Web。简单可靠，代价是没有冗余。</p>

<table>
<thead><tr><th>方案</th><th>适用</th><th>代价</th></tr></thead>
<tbody>
<tr><td>分布式锁</td><td>多机都要参与调度</td><td>依赖 Redis / 共享存储</td></tr>
<tr><td>单点调度</td><td>中小规模</td><td>调度节点故障则任务停摆</td></tr>
</tbody>
</table>

<blockquote>
<p>先问"需不需要多点调度"，再决定"要不要分布式锁"。很多项目两者都不需要，只是习惯性把 crontab 复制到了每台机器。</p>
</blockquote>
HTML;

        return [
            'title'       => '多机部署下定时任务不重复执行：wise-crontab 的分布式锁',
            'slug'        => 'crontab-distributed-lock',
            'cat'         => 'blog',
            'description' => '多台机器都挂 crontab，不加锁就是重复执行。锁的粒度决定它到底管不管用。',
            'keywords'    => 'wise-crontab,分布式锁,定时任务',
            'recommend'   => 0,
            'content'     => $content,
        ];
    }


    private function releasePost(): array
    {
        $content = <<<'HTML'
<p>1.0.1 是首个可对外使用的稳定版本。</p>

<h2>本版本提供</h2>

<ul>
<li>ThinkPHP 8 多应用骨架：<code>admin</code> / <code>api</code> / <code>install</code> / <code>member</code></li>
<li>插件系统：manifest 声明 + 11 步 boot 链 + 完整生命周期（扫描 → 安装 → 启用 → 运行 → 禁用 → 卸载 → 升级）</li>
<li>20 个可独立安装的 <code>wise-*</code> Composer 包</li>
<li>CLI：<code>php think wise:crud</code>（从数据表反推后台 CRUD）、<code>php think plugin:create</code>（四档插件骨架）</li>
</ul>

<h2>环境要求</h2>

<table>
<thead><tr><th style="width:26%;">项</th><th>要求</th></tr></thead>
<tbody>
<tr><td>PHP</td><td>&gt;= 8.1（推荐 8.2 / 8.3）</td></tr>
<tr><td>数据库</td><td>MySQL 5.7+ / 8.0</td></tr>
<tr><td>Composer</td><td>2.x</td></tr>
<tr><td>Redis</td><td>可选</td></tr>
</tbody>
</table>

<h2>许可</h2>

<p>Apache License 2.0，可用于商业项目。</p>

<h2>安装与升级</h2>

<p>全新安装与已有部署升级步骤，见 <a href="/quickstart.html">快速开始</a> 页的「已有部署升级路径」。</p>
HTML;

        return [
            'title'       => 'WiseAdmin 1.0.1 发布',
            'slug'        => 'v1-0-1',
            'cat'         => 'changelog',
            'description' => '1.0.1 是首个可对外使用的稳定版本，包含框架内核、20 个 wise-* 能力包与插件系统。',
            'keywords'    => 'WiseAdmin版本,1.0.1,发布说明',
            'recommend'   => 0,
            'content'     => $content,
        ];
    }
}
