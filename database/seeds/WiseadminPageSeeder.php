<?php
declare(strict_types=1);

use think\migration\Seeder;
use think\facade\Db;

/**
 * WiseAdmin 官网单页正文（Phase C）
 *
 * 特性：**幂等**，按单页 alias 更新，不重复插入。
 *
 * 用法：
 *   php think seed:run -s WiseadminPageSeeder
 *
 * 说明：
 *   - 正文按主题 `ws-prose` 排版编写（h2 / h3 / p / table / pre / blockquote / ul）。
 *   - 直接写 `wisecms_page.content`，不经 ContentService 净化：内容是仓库内静态文案，
 *     不含脚本；走净化反而会剥掉 `<table>` / `<pre>` 等排版标签。
 */
class WiseadminPageSeeder extends Seeder
{
    private const SITE_ID = 1;

    public function run(): void
    {
        $now = date('Y-m-d H:i:s');

        $pages = [
            'features'   => $this->features(),
            'packages'   => $this->packages(),
            'quickstart' => $this->quickstart(),
            'download'   => $this->download(),
            'about'      => $this->about(),
            'contact'    => $this->contact(),
        ];

        foreach ($pages as $alias => $page) {
            $this->upsertPage($alias, $page, $now);
        }

        echo PHP_EOL . '官网单页正文写入完成：' . count($pages) . ' 页。' . PHP_EOL;
    }

    /**
     * @param array{title:string,seo_title:string,seo_keywords:string,seo_description:string,content:string} $page
     */
    private function upsertPage(string $alias, array $page, string $now): void
    {
        $catid = (int) Db::name('wisecms_category')
            ->where('alias', $alias)
            ->where('site_id', self::SITE_ID)
            ->value('id');

        $data = [
            'site_id'         => self::SITE_ID,
            'catid'           => $catid,
            'title'           => $page['title'],
            'alias'           => $alias,
            'content'         => $page['content'],
            'seo_title'       => $page['seo_title'],
            'seo_keywords'    => $page['seo_keywords'],
            'seo_description' => $page['seo_description'],
            'status'          => 1,
            'update_time'     => $now,
        ];

        $exists = Db::name('wisecms_page')->where('alias', $alias)->where('site_id', self::SITE_ID)->find();

        if ($exists) {
            Db::name('wisecms_page')->where('id', $exists['id'])->update($data);
            echo '  更新单页：' . $page['title'] . PHP_EOL;
            return;
        }

        $data['create_time'] = $now;
        $data['view_count']  = 0;
        Db::name('wisecms_page')->insert($data);
        echo '  新建单页：' . $page['title'] . PHP_EOL;
    }


    private function features(): array
    {
        $content = <<<'HTML'
<p>以下六项能力全部开箱可用，且每一项都能单独拆出去——这是"可组合"的具体含义。</p>

<h2>一、多应用 + 插件化</h2>

<p>后台、API、前台、安装程序各是一个独立应用（<code>admin</code> / <code>api</code> / <code>install</code> / <code>member</code>），共享同一套基类与中间件。业务不以"改核心"的方式进入系统，而是以插件形式：<code>plugin\{ident}</code> 命名空间隔离，安装、启用、禁用、卸载、升级全生命周期可回退。</p>

<p>插件启动是一条 11 步 boot 链，每一步职责单一、顺序固定——这意味着任何一个插件的加载路径都是可预测的，出问题时知道该在哪一步看。</p>

<pre><code># 四档生成，按需要的完整度选择
$ php think plugin:create myplugin --level=1   # 7  个文件：最小可运行
$ php think plugin:create myplugin --level=2   # 13 个文件：+ 模型/迁移
$ php think plugin:create myplugin --level=3   # 16 个文件：+ 前台/API 控制器
$ php think plugin:create myplugin --level=4   # 17 个文件：+ 中间件/事件</code></pre>

<h2>二、完整 RBAC 与多层安全</h2>

<p>权限不止"角色-菜单"两级。除了常规的路由级鉴权，还实现了职责分离（RBAC2）的三类约束：</p>

<table>
<thead><tr><th>约束</th><th>作用</th><th>典型场景</th></tr></thead>
<tbody>
<tr><td>角色继承</td><td>子角色自动获得父角色权限，避免重复授权</td><td>「编辑」继承「访客」的只读权限</td></tr>
<tr><td>互斥角色</td><td>同一用户不可同时持有两个冲突角色</td><td>「制单人」与「审核人」必须分离</td></tr>
<tr><td>基数限制</td><td>限制某角色的持有人在某范围内的人数上限</td><td>「超级管理员」最多 2 人</td></tr>
</tbody>
</table>

<p>安全层还包括：登录设备指纹与失败锁定、操作日志与审计日志（完全解耦）、JWT / OAuth、API 签名与限流、CSRF 与 XSS 过滤、CSP nonce 下发。这些不是可选项——插件后台控制器继承基类即自动获得 CSRF 防护。</p>

<h2>三、插件级脚手架</h2>

<p>两个命令覆盖"从零建插件"和"从表建后台"：</p>

<ul>
<li><code>php think plugin:create</code>：四档生成插件骨架，生成的骨架可直接被框架加载运行。</li>
<li><code>php think wise:crud</code>：从现有数据表反推后台 CRUD，产物是控制器 + 模型 + 菜单迁移 + 列表 JS，<strong>页面由运行时渲染</strong>——改一处共享模板，所有页面同步。</li>
</ul>

<p>CRUD 生成器的字段判定分三层，优先级从高到低：</p>

<table>
<thead><tr><th>层</th><th>来源</th><th>可采信度</th></tr></thead>
<tbody>
<tr><td>P1 声明</td><td><code>--field</code> / <code>config/crud_map.php</code></td><td>直接采信</td></tr>
<tr><td>P2 启发式</td><td>字段名、注释、DB 类型</td><td>需要核对</td></tr>
<tr><td>P3 兜底</td><td>未命中任何规则时的默认渲染</td><td>通常要改</td></tr>
</tbody>
</table>

<p><code>--dry-run</code> 会把每一条判定连同命中规则一起打印出来，猜的部分可以逐条核对。</p>

<h2>四、现代后台前端</h2>

<p>AdminLTE 4 + Bootstrap 5.3 打底，自研 <code>ws-*</code> ESM 组件补齐管理端高频场景：</p>

<table>
<thead><tr><th>组件</th><th>解决什么</th></tr></thead>
<tbody>
<tr><td><code>Wise.table</code> / EnhancedTable</td><td>列表页：列格式化、工具栏、批量操作、树形、分页</td></tr>
<tr><td><code>ws-layer</code></td><td>弹层：iframe / 抽屉 / 二次确认，统一 Promise 接口</td></tr>
<tr><td><code>ws-upload</code></td><td>附件与图片上传，接 <code>wise-attachment</code> 多驱动</td></tr>
<tr><td>主题服务</td><td>亮/暗模式 + 配色方案，Cookie 优先避免闪烁</td></tr>
<tr><td>插槽（slot）</td><td>跨插件注入页面区块，不改对方代码</td></tr>
</tbody>
</table>

<p>前端是服务端渲染 + ESM 渐进增强，不要求独立前端工程即可完成深度定制。</p>

<h2>五、高级能力开箱</h2>

<p>以下能力都是独立 Composer 包，装上即用，不装不影响框架：</p>

<ul>
<li><strong>工作流</strong>：流程定义、多实例流转、条件分支、超时处理，内置状态机驱动；</li>
<li><strong>Webhook</strong>：发送与接收双角色，签名/重试/退避/多端点/队列；</li>
<li><strong>计划任务</strong>：语义化调度与 Cron 表达式、分布式锁、任务编排、执行日志与告警；</li>
<li><strong>多通道通知</strong>：邮件、短信、钉钉、站内信，含模板引擎与事件驱动；</li>
<li><strong>附件与图片</strong>：本地 / FTP / 七牛 / OSS / COS 多驱动，内置独立图片处理引擎；</li>
<li><strong>会员中心</strong>：多用户类型接入，与后台共用一套 RBAC。</li>
</ul>

<h2>六、AI 能力</h2>

<p>三个包各管一段，组合起来才是完整链路：</p>

<table>
<thead><tr><th>包</th><th>职责</th></tr></thead>
<tbody>
<tr><td><code>wise-agent</code></td><td>Agent 运行时：多模型接入（OpenAI / DeepSeek）、对话记忆、工具调用、事件驱动扩展</td></tr>
<tr><td><code>wise-mcp</code></td><td>协议层：stdio 与 Streamable HTTP 传输、Server/Client 双角色、能力提供者注册</td></tr>
<tr><td><code>wise-rag</code></td><td>知识层：文档摄取、向量检索、引用溯源，插件零接入</td></tr>
</tbody>
</table>

<p>关键点在于：AI 的每一步工具调用都走既有权限体系，受 RBAC 与审批约束，而不是绕过系统直接操作数据库。</p>

<h2>以上能力的装载方式</h2>

<p>六项能力均以独立 Composer 包提供，可只装你需要的那几个——不存在"装了不用"的负担。</p>

<p><a href="/packages.html">→ 查看 20 个能力包完整清单</a>　<a href="/quickstart.html">→ 5 分钟快速开始</a></p>
HTML;

        return [
            'title'           => '核心特性',
            'seo_title'       => '核心特性 - WiseAdmin | ThinkPHP 8 开源后台框架',
            'seo_keywords'    => 'ThinkPHP8特性,后台框架能力,RBAC2,插件化',
            'seo_description' => 'WiseAdmin 六大核心特性：多应用与插件化、RBAC2 权限、插件脚手架、现代前端组件、工作流与 Webhook 等高级能力、AI Agent 与 MCP 支持。',
            'content'         => $content,
        ];
    }


    private function packages(): array
    {
        $content = <<<'HTML'
<p>以下 20 个包均在仓库中维护（<code>src/packages/wiseadmin/</code>），每个都可单独 <code>composer require</code>，不依赖 WiseAdmin 后台也能用在普通 ThinkPHP 8 项目里。</p>

<table>
<thead><tr><th style="width:24%;">包名</th><th style="width:52%;">一句话能力</th><th style="width:24%;">典型场景</th></tr></thead>
<tbody>
<tr><td><code>wise-support</code></td><td>基础设施层：ExtPoint 扩展点、Auth+JWT 统一认证、ServiceBus、Connector、断路器、退避、分布式锁、限流、配置检查、请求上下文、AES-GCM、WebSocket、Pipeline、错误码体系</td><td>任何项目的地基</td></tr>
<tr><td><code>wise-auth</code></td><td>通用 RBAC 权限管理，支持多应用、多用户类型、角色继承（RBAC1）与职责分离约束（RBAC2）</td><td>后台权限</td></tr>
<tr><td><code>wise-api</code></td><td>API 快速开发工具包：限流、签名验证、JWT 鉴权、统一响应、调试面板、API 日志</td><td>对外接口</td></tr>
<tr><td><code>wise-jwt</code></td><td>JWT 令牌管理：签发、校验、吊销、续签，HMAC / RSA / ECDSA / EdDSA</td><td>无状态认证</td></tr>
<tr><td><code>wise-oauth</code></td><td>第三方 OAuth 登录：微信 / QQ / GitHub / 钉钉 / Gitee / Google / 微博，多账号绑定</td><td>会员登录</td></tr>
<tr><td><code>wise-attachment</code></td><td>多驱动附件管理：本地 / FTP / 七牛 / OSS / COS，内置独立图片处理引擎 <code>wise\image</code></td><td>上传与图床</td></tr>
<tr><td><code>wise-config</code></td><td>键值配置与字典管理：多类型值、分组缓存、历史版本追溯、HTML 净化</td><td>系统设置</td></tr>
<tr><td><code>wise-crontab</code></td><td>定时任务调度：语义化与 Cron 表达式、双驱动任务源、分布式锁、任务编排、告警、可视化</td><td>定时作业</td></tr>
<tr><td><code>wise-form-schema</code></td><td>统一表单定义语言内核：Fluent API 字段构建器 + 验证规则生成 + 数据过滤</td><td>表单复用</td></tr>
<tr><td><code>wise-health-check</code></td><td>环境与健康检测：服务器诊断、扩展检查、目录权限、系统状态监控</td><td>上线自检</td></tr>
<tr><td><code>wise-backup</code></td><td>数据库备份恢复：一键备份、多地容灾、自动清理，本地 / FTP / SFTP / S3</td><td>数据安全</td></tr>
<tr><td><code>wise-operation-log</code></td><td>基于 PHP 8 Attribute 的声明式操作日志：敏感脱敏、异常检测、自动归档</td><td>审计合规</td></tr>
<tr><td><code>wise-notification</code></td><td>多通道通知：邮件、短信、钉钉、站内信，含模板引擎与事件驱动</td><td>消息触达</td></tr>
<tr><td><code>wise-workflow</code></td><td>工作流引擎：流程定义、多实例流转、条件分支、超时处理、状态机驱动</td><td>审批流</td></tr>
<tr><td><code>wise-webhook</code></td><td>Webhook 发送与接收：签名、重试、退避、多端点、队列</td><td>系统集成</td></tr>
<tr><td><code>wise-url</code></td><td>URL 规则引擎：方案化生成与解析、前缀隔离、历史 301、PHPCMS v9 风格语法</td><td>SEO / 伪静态</td></tr>
<tr><td><code>wise-market</code></td><td>应用市场服务端：插件 / 主题 / 扩展的上架、审核、分发、许可签发、Composer 仓库</td><td>插件生态</td></tr>
<tr><td><code>wise-agent</code></td><td>AI Agent：多模型接入（OpenAI / DeepSeek）、对话记忆、工具调用、事件驱动扩展</td><td>后台智能化</td></tr>
<tr><td><code>wise-mcp</code></td><td>MCP 协议内核：stdio / Streamable HTTP 传输、Server/Client 双角色、能力提供者注册</td><td>AI 工具互操作</td></tr>
<tr><td><code>wise-rag</code></td><td>RAG 知识库：文档摄取、向量检索、引用溯源，插件零接入；内含 Wiki 知识编译</td><td>知识问答</td></tr>
</tbody>
</table>

<h2>怎么选</h2>

<p>不确定装哪个？先装 <code>wise-support</code> + <code>wise-auth</code>——这两个覆盖基础设施与权限，其余按需追加。</p>

<pre><code>composer require wiseadmin/wise-support wiseadmin/wise-auth</code></pre>

<p>所有包均为 Apache-2.0 许可，可用于商业项目。</p>

<p><a href="/quickstart.html">→ 5 分钟快速开始</a>　<a href="/features.html">→ 查看核心特性</a></p>
HTML;

        return [
            'title'           => '能力包清单',
            'seo_title'       => '能力包清单 - 20 个可独立安装的 wise-* Composer 包 | WiseAdmin',
            'seo_keywords'    => 'wise-* 组件,Composer包,PHP组件库',
            'seo_description' => 'WiseAdmin 的 20 个在役 Composer 能力包完整清单：权限、API、附件、配置、定时任务、表单、JWT、市场、MCP、通知、OAuth、操作日志、RAG、基础设施、URL、Webhook、工作流、AI Agent 等。',
            'content'         => $content,
        ];
    }


    private function quickstart(): array
    {
        $content = <<<'HTML'
<p>从克隆仓库到后台可用，正常网络环境下约 5 分钟。</p>

<h2>1. 获取代码</h2>

<pre><code>git clone https://github.com/hiwiseadmin/framework.git wiseadmin
cd wiseadmin/src
composer install</code></pre>

<h2>2. 配置数据库</h2>

<pre><code>cp .example.env .env          # 填写数据库连接</code></pre>

<h2>3. 启动</h2>

<pre><code>php think run                 # http://localhost:8000</code></pre>

<blockquote>
<p><strong>注意</strong>：到这一步还没看到登录页是正常的 —— 数据库尚未初始化。</p>
</blockquote>

<h2>4. 初始化（二选一）</h2>

<p>方式 A：浏览器打开 <code>http://localhost:8000</code>，按 6 步向导填写数据库与管理员账号。</p>

<p>方式 B：命令行。</p>

<pre><code>php think install --db_host=127.0.0.1 --db_name=wiseadmin \
  --db_user=root --db_pwd=你的密码 --admin_pwd=你的后台密码</code></pre>

<h2>5. 生成第一个业务模块</h2>

<pre><code># 先预览：只读表结构，不写任何文件
php think wise:crud ws_article --dry-run

# 确认判定无误后落盘
php think wise:crud ws_article --title=文章管理

# 执行迁移并更新权限列表
php think migrate:run</code></pre>

<p>最后回到后台「权限 → 更新权限列表」，菜单即可见。</p>

<h2>已有部署升级路径</h2>

<table>
<thead><tr><th style="width:8%;">步骤</th><th>命令</th></tr></thead>
<tbody>
<tr><td>1</td><td><code>php think migrate:run</code></td></tr>
<tr><td>2</td><td><code>php think service:discover</code></td></tr>
<tr><td>3</td><td><code>php think vendor:publish</code></td></tr>
<tr><td>4</td><td><code>php think wise:vendor:publish</code></td></tr>
<tr><td>5</td><td><code>php think wise:package:install --all</code></td></tr>
</tbody>
</table>

<h2>三个常见卡点</h2>

<h3>插件"已启用"但 CLI 里加载不到</h3>

<p>插件启用状态不在 <code>ws_plugins</code> 表，而在 <code>plugin_config</code> 的 <code>{ident}.__state</code>。CLI 侧还需在 <code>Console::starting()</code> 回调里加载插件，否则状态会被读成 false 并固化。</p>

<h3>改了代码不生效</h3>

<p>插件静态资源只在<strong>安装 / 启用 / 升级</strong>三个时机发布。改了 <code>statics/</code> 下的文件却没有重新启用插件，浏览器拿到的仍是旧文件。</p>

<h3>文件没进版本库，<code>git status</code> 也看不到</h3>

<p><code>.gitignore</code> 里的裸目录名（如 <code>public/</code>）会匹配任意层级，被忽略的文件不会出现在 <code>git status</code> 中——用 <code>git check-ignore -v 路径</code> 确认是谁忽略的。</p>

<h2>环境要求</h2>

<table>
<thead><tr><th style="width:26%;">项</th><th>要求</th></tr></thead>
<tbody>
<tr><td>PHP</td><td>&gt;= 8.1（推荐 8.2 / 8.3）</td></tr>
<tr><td>数据库</td><td>MySQL 5.7+ / 8.0</td></tr>
<tr><td>Composer</td><td>2.x</td></tr>
<tr><td>Redis</td><td>可选（队列、缓存、分布式锁场景需要）</td></tr>
</tbody>
</table>

<p><a href="/download.html">→ 下载 v1.0.1</a>　<a href="/packages.html">→ 查看能力包清单</a></p>
HTML;

        return [
            'title'           => '快速开始',
            'seo_title'       => '快速开始 - 5 分钟跑起 WiseAdmin',
            'seo_keywords'    => 'WiseAdmin安装,ThinkPHP8快速开始',
            'seo_description' => '从克隆仓库到后台可用的完整步骤：composer install、初始化数据库、php think install，然后用 wise:crud 生成第一个业务模块。',
            'content'         => $content,
        ];
    }


    private function download(): array
    {
        $content = <<<'HTML'
<p>当前稳定版：<strong>v1.0.1</strong></p>

<table>
<thead><tr><th style="width:26%;">渠道</th><th>地址</th></tr></thead>
<tbody>
<tr><td>GitHub（主仓库）</td><td><a href="https://github.com/hiwiseadmin/framework" target="_blank" rel="noopener noreferrer">https://github.com/hiwiseadmin/framework</a></td></tr>
</tbody>
</table>

<h2>环境要求</h2>

<table>
<thead><tr><th style="width:26%;">项</th><th>要求</th></tr></thead>
<tbody>
<tr><td>PHP</td><td>&gt;= 8.1（推荐 8.2 / 8.3）</td></tr>
<tr><td>数据库</td><td>MySQL 5.7+ / 8.0（其它 ThinkORM 支持的数据库亦可）</td></tr>
<tr><td>扩展</td><td><code>openssl</code>、<code>pdo</code>、<code>mbstring</code> 等</td></tr>
<tr><td>Composer</td><td>2.x</td></tr>
<tr><td>Redis</td><td>可选（队列、缓存、分布式锁场景需要）</td></tr>
</tbody>
</table>

<h2>安装</h2>

<pre><code>git clone https://github.com/hiwiseadmin/framework.git wiseadmin
cd wiseadmin/src
composer install
cp .example.env .env          # 填写数据库连接
php think run</code></pre>

<p>完整步骤见 <a href="/quickstart.html">快速开始</a>。</p>

<h2>许可说明</h2>

<p>主项目与全部 <code>wise-*</code> 组件均以 <strong>Apache License 2.0</strong> 发布，可用于商业项目，无需付费、无需授权申请。</p>

<p>官方应用与商业插件通过插件商城以商业授权方式单独提供，不随开源仓库分发。</p>

<p>WiseAdmin 名称与标识的使用须遵循 <code>TRADEMARK.md</code> 品牌政策——开源许可不构成商标授权。</p>
HTML;

        return [
            'title'           => '下载',
            'seo_title'       => '下载 WiseAdmin v1.0.1 - Apache-2.0 开源免费',
            'seo_keywords'    => 'WiseAdmin下载,开源后台框架下载',
            'seo_description' => '下载 WiseAdmin v1.0.1。GitHub 主仓库，Apache-2.0 许可，支持商业项目免费使用。环境要求 PHP 8.1+、MySQL 5.7+、Composer 2.x。',
            'content'         => $content,
        ];
    }


    private function about(): array
    {
        $content = <<<'HTML'
<p>WiseAdmin 是一套以「开放 + 插件化」为核心设计理念的后台框架。</p>

<p>框架本身开源免费，任何个人与组织均可免费用于商业项目；围绕框架维护了 <code>wise-*</code> 系列可复用 Composer 组件（组件生态）与插件商城（官方应用 + 第三方免费/商业插件），目标是让开发者用更少的时间交付<strong>能长期维护</strong>的后台业务系统。</p>

<h2>三条设计原则</h2>

<table>
<thead><tr><th style="width:18%;">原则</th><th>含义</th></tr></thead>
<tbody>
<tr><td><strong>可拆包</strong></td><td>能力做成独立 Composer 包，不要"装了不用"的整包</td></tr>
<tr><td><strong>可卸载</strong></td><td>业务以 <code>plugin\{ident}</code> 命名空间隔离，装和卸都不留残渣</td></tr>
<tr><td><strong>可验证</strong></td><td>生成器的每一步判定都可见，猜的部分能被逐条核对</td></tr>
</tbody>
</table>

<h2>技术栈</h2>

<table>
<thead><tr><th style="width:26%;">层</th><th>技术</th></tr></thead>
<tbody>
<tr><td>基础框架</td><td>ThinkPHP 8</td></tr>
<tr><td>后台 UI</td><td>AdminLTE 4 + Bootstrap 5.3 + 自研 <code>ws-*</code> ESM 组件</td></tr>
<tr><td>数据库</td><td>MySQL 5.7+ / 8.0（ThinkORM）</td></tr>
<tr><td>权限</td><td><code>wise-auth</code>（RBAC1 + RBAC2）</td></tr>
<tr><td>AI</td><td><code>wise-agent</code> + <code>wise-mcp</code> + <code>wise-rag</code></td></tr>
</tbody>
</table>

<h2>参与贡献</h2>

<p>欢迎通过 Issue 报告缺陷与建议、通过 Pull Request 提交代码。提交前请阅读：</p>

<ul>
<li><code>CONTRIBUTING.md</code> —— 贡献流程与约定</li>
<li><code>CLA/</code> —— 个人 / 企业《贡献者许可协议》（提交首个 PR 时按其中简化方式签署）</li>
</ul>

<h2>许可与品牌</h2>

<p>主项目与 <code>wise-*</code> 组件均采用 <strong>Apache License 2.0</strong>，全文见仓库 <code>LICENSE.txt</code>；版权、第三方与商标声明见 <code>NOTICE</code>。</p>

<p>官方应用与商业插件随插件商城以商业授权方式提供，不随开源仓库分发。</p>

<p>WiseAdmin 名称与标识的使用须遵循 <code>TRADEMARK.md</code> 品牌政策；开源许可不构成商标授权。</p>

<p><a href="/contact.html">→ 联系我们</a></p>
HTML;

        return [
            'title'           => '关于 WiseAdmin',
            'seo_title'       => '关于 WiseAdmin - 设计理念与开源治理',
            'seo_keywords'    => 'WiseAdmin介绍,开源后台框架',
            'seo_description' => 'WiseAdmin 是以"开放 + 插件化"为核心设计的 ThinkPHP 8 后台框架，框架开源免费，围绕它维护 20+ 个可复用 Composer 组件与插件生态。',
            'content'         => $content,
        ];
    }


    private function contact(): array
    {
        $content = <<<'HTML'
<p>我们刻意不在此页放表单——项目沟通全部走公开、可追溯的渠道，这样问题与答案都能被后来者搜到。</p>

<h2>项目问题 / 功能建议</h2>

<p>请提交 GitHub Issues：<a href="https://github.com/hiwiseadmin/framework/issues" target="_blank" rel="noopener noreferrer">github.com/hiwiseadmin/framework/issues</a></p>

<p>提交前建议先搜索已有 Issue；附上版本号、PHP 版本与复现步骤，能显著加快处理。</p>

<h2>安全漏洞</h2>

<p>请<strong>邮件上报</strong>，不要公开提交 Issue：</p>

<pre><code>wiseadmin@126.com</code></pre>

<p>我们会先确认影响范围，修复发布后再公开细节。</p>

<h2>商务合作 / 插件上架</h2>

<p>邮件联系：<a href="mailto:wiseadmin@126.com">wiseadmin@126.com</a></p>

<p>插件上架请附：插件标识、功能简介、依赖要求、许可方式（免费 / 商业）。</p>

<h2>代码仓库</h2>

<table>
<thead><tr><th style="width:26%;">渠道</th><th>地址</th></tr></thead>
<tbody>
<tr><td>GitHub</td><td><a href="https://github.com/hiwiseadmin/framework" target="_blank" rel="noopener noreferrer">github.com/hiwiseadmin/framework</a></td></tr>
</tbody>
</table>

<p><a href="/about.html">→ 了解 WiseAdmin 的设计理念</a></p>
HTML;

        return [
            'title'           => '联系我们',
            'seo_title'       => '联系我们 - WiseAdmin',
            'seo_keywords'    => 'WiseAdmin联系方式',
            'seo_description' => 'WiseAdmin 的联系方式：项目问题请提交 GitHub Issues，安全漏洞请邮件上报，商务与合作请发邮件。',
            'content'         => $content,
        ];
    }
}
