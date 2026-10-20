<?php
declare(strict_types=1);

namespace plugin\wisecms\taglib;

use think\template\TagLib;

/**
 * WiseCMS 模板标签库（编译期标签）
 *
 * 注册方式（P0 实测结论）：插件 config/view.php 声明 `taglib_pre_load`，经 boot 链
 * Step 2c（WiseBootServiceProvider::mergeGlobalConfig）并入全局 view 域；该键支持完整类名，
 * think\template\Template::parseTagLib() 取类名末段（Cms）交给 TagLib::parseTag()，
 * 后者内部 `strtolower($lib) . ':'` 得到前缀 `cms:`，故模板语法固定为 `{cms:xxx}`。
 * 引擎 getRegex() 以 `/is` 匹配，标签名大小写不敏感；命中后调用 `tagXxx`（PHP 方法名不区分大小写）。
 *
 * 编译机制：闭合标签的 `$content` 参数是引擎传入的「占位标记」（非标签体内容），
 * 方法需把该标记放在「循环体应出现的位置」原样返回；引擎再按标记切分，
 * 用前半段替换开始标签、后半段替换结束标签，中间的模板内容原地保留并继续解析。
 * 因此产物是纯 PHP，模板首次编译后进入 runtime 缓存，后续请求零解析开销。
 *
 * ── 标签与可用属性（真实白名单在运行时 TagQueryService/TagRuntime 收口）──
 *   {cms:arclist  typeid catid sub model limit row orderby order flag keyword day ids exclude cache id}
 *   {cms:channel  parentid typeid limit showhidden id}
 *   {cms:tag      limit orderby order cache id}        （别名 tags）
 *   {cms:link     group limit cache id}                （别名 links；对接 B-1 友情链接）
 *   {cms:page     class prev next /}                   （别名 pager /）
 *   {cms:field    name default /}
 *   {cms:position separator home /}                    （别名 breadcrumb /）
 *   {cms:sql      table field where order limit id}    （默认禁用，见 config wisecms.tag_sql_enable）
 *
 * ── 与设计文档 §4 的两处刻意偏差（均为引擎行为所迫，已实测）──
 *   1. `attr` 一律声明为 `''`：think-template 对声明了非空 attr 的标签强制「至少一个属性」，
 *      否则编译期抛 `tag error:xxx`。而 §5.3 的规范写法 `{cms:page /}` / `{cms:position /}`
 *      恰恰不带属性，故必须以 `''` 关闭该校验；真实属性白名单改由 $allow 数组在编译期收口。
 *   2. `alias` 用数组形式（`['tags']`）而非裸字符串：TagLib::parseAttr() 会取 `$tag['alias'][1]`
 *      作为「命中别名的属性名」，裸字符串会被当作字符串下标取出一个字符，产生脏属性。
 */
class Cms extends TagLib
{
    /** 编译期自增序号：保证同页多个标签的局部变量互不覆盖 */
    protected static int $seq = 0;

    /** 标签定义（close=1 闭合；close=0 自闭合。attr 留空的原因见类注释） */
    protected $tags = [
        'arclist'  => ['attr' => '', 'close' => 1, 'level' => 1],
        'channel'  => ['attr' => '', 'close' => 1, 'level' => 1],
        'tag'      => ['attr' => '', 'close' => 1, 'level' => 1, 'alias' => ['tags']],
        'link'     => ['attr' => '', 'close' => 1, 'level' => 1, 'alias' => ['links']],
        'sql'      => ['attr' => '', 'close' => 1, 'level' => 1],
        'page'     => ['attr' => '', 'close' => 0, 'alias' => ['pager']],
        'field'    => ['attr' => '', 'close' => 0],
        'position' => ['attr' => '', 'close' => 0, 'alias' => ['breadcrumb']],
    ];

    // ══════════════════════════ 闭合标签（生成 foreach 循环） ══════════════════════════

    /** 内容列表：{cms:arclist ...}...{/cms:arclist} */
    public function tagArclist(array $tag, string $content): string
    {
        return $this->buildLoop('arclist', $tag, $content, [
            'typeid', 'catid', 'sub', 'model', 'limit', 'row',
            'orderby', 'order', 'flag', 'keyword', 'day', 'ids', 'exclude', 'cache',
        ]);
    }

    /** 栏目列表：{cms:channel parentid="0"}...{/cms:channel} */
    public function tagChannel(array $tag, string $content): string
    {
        return $this->buildLoop('channel', $tag, $content, ['parentid', 'typeid', 'limit', 'showhidden', 'cache']);
    }

    /** 标签云：{cms:tags limit="20"}...{/cms:tags}（也可写 {cms:tag}） */
    public function tagTag(array $tag, string $content): string
    {
        return $this->buildLoop('tags', $tag, $content, ['limit', 'orderby', 'order', 'cache']);
    }

    /** 友情链接：{cms:links group="合作媒体"}...{/cms:links}（对接 B-1） */
    public function tagLink(array $tag, string $content): string
    {
        return $this->buildLoop('links', $tag, $content, ['group', 'limit', 'cache']);
    }

    /** 自定义 SQL：{cms:sql table="wisecms_content" ...}...{/cms:sql}（默认禁用） */
    public function tagSql(array $tag, string $content): string
    {
        return $this->buildLoop('sql', $tag, $content, ['table', 'field', 'where', 'order', 'limit']);
    }

    // ══════════════════════════ 自闭合标签（直接输出） ══════════════════════════

    /** 分页条：{cms:page /}，渲染当前 $pager */
    public function tagPage(array $tag, string $content = ''): string
    {
        return '<?php echo \\plugin\\wisecms\\taglib\\TagRuntime::page($pager ?? [], '
            . $this->buildArgs($tag, ['class', 'prev', 'next']) . '); ?>';
    }

    /** 详情页字段：{cms:field name="custom_price" default="-" /} */
    public function tagField(array $tag, string $content = ''): string
    {
        return '<?php echo \\plugin\\wisecms\\taglib\\TagRuntime::field($content ?? [], '
            . var_export((string) ($tag['name'] ?? ''), true) . ', '
            . var_export((string) ($tag['default'] ?? ''), true) . '); ?>';
    }

    /** 面包屑：{cms:position /}（也可写 {cms:breadcrumb /}） */
    public function tagPosition(array $tag, string $content = ''): string
    {
        return '<?php echo \\plugin\\wisecms\\taglib\\TagRuntime::position($content ?? [], '
            . $this->buildArgs($tag, ['separator', 'home']) . '); ?>';
    }

    // ══════════════════════════ 编译工具 ══════════════════════════

    /**
     * 生成「取数 → foreach → 标签体 → endforeach」的编译片段
     *
     * @param string $method  运行时方法名（arclist/channel/tags/links/sql）
     * @param array  $tag     解析后的标签属性
     * @param string $content 引擎传入的占位标记（须原样放回循环体位置）
     * @param array  $allow   允许下传给运行时的属性名（编译期白名单，其余直接丢弃）
     */
    protected function buildLoop(string $method, array $tag, string $content, array $allow): string
    {
        $seq  = ++self::$seq;
        $item = self::safeVarName((string) ($tag['id'] ?? ''));
        $args = $this->buildArgs($tag, $allow);
        $var  = '$__cms_' . $method . '_' . $seq;

        return '<?php ' . $var . ' = \\plugin\\wisecms\\taglib\\TagRuntime::' . $method . '(' . $args . ');'
            . ' if (!empty(' . $var . ')): foreach (' . $var . ' as $' . $item . '): ?>'
            . $content
            . '<?php endforeach; endif; ?>';
    }

    /**
     * 按白名单把属性编译为 PHP 数组字面量
     *
     * 白名单同时解决两件事：① 属性名收口（设计 §5.1）；
     * ② 丢弃 TagLib::parseAttr() 因别名回填的脏键（如 `type`）。
     *
     * @param string[] $allow 允许的属性名（同时决定产物中的参数顺序）
     */
    protected function buildArgs(array $tag, array $allow): string
    {
        $parts = [];
        foreach ($allow as $key) {
            if (!array_key_exists($key, $tag)) {
                continue;
            }

            $parts[] = var_export($key, true) . ' => ' . $this->buildValue((string) $tag[$key]);
        }

        return '[' . implode(', ', $parts) . ']';
    }

    /**
     * 动态变量白名单（P1-A8）
     *
     * 仅允许 `$var` 与 `$var.key.subkey` 两种形态：
     * 阻断 think-template `autoBuildVar()` 可能支持的**过滤器/表达式**写法
     * （如 `$x|system`、`$a.b()`），避免模板属性被编译成可执行表达式。
     */
    const VAR_PATTERN = '/^\$[A-Za-z_]\w*(?:\.[A-Za-z0-9_]+)*$/';

    /**
     * 危险变量名（小写比较）：请求对象 / 超全局 / 引擎保留变量
     *
     * 模板不得绕过控制器直接读取请求数据或写入超全局。
     */
    const VAR_DENY = [
        'request', 'think', 'globals', 'this',
        '_get', '_post', '_cookie', '_session', '_request', '_server', '_env', '_files',
    ];

    /**
     * 单个属性值编译
     *
     * 形如 `$var` 的动态值走 autoBuildVar（仅允许控制器 assign 的受信变量）；
     * 其余一律按字符串常量直传，且**不 trim**——`separator=" &gt; "` / `default=" - "`
     * 等展示型属性的首尾空白是有意义的。
     *
     * P1-A8：动态变量先过 {@see VAR_PATTERN} 形态白名单 + {@see VAR_DENY} 名称黑名单，
     * 任一不通过即按空串处理（fail-closed）。
     *
     * 说明：不支持 think-template 的 `:func()` 函数式入参，避免与「以 : 开头的字面量」冲突。
     */
    protected function buildValue(string $value): string
    {
        if ($value === '') {
            return "''";
        }

        $trimmed = ltrim($value);
        if (str_starts_with($trimmed, '$')) {
            if (preg_match(self::VAR_PATTERN, $trimmed) !== 1) {
                return "''";
            }

            // 取根变量名（`$a.b.c` → `a`）做危险名单比对
            $root = (string) strtok(substr($trimmed, 1), '.');
            if (in_array(strtolower($root), self::VAR_DENY, true)) {
                return "''";
            }

            return $this->autoBuildVar($trimmed);
        }

        return var_export($value, true);
    }

    /**
     * 循环变量名安全化：非法/保留名回落 vo
     */
    protected static function safeVarName(string $name): string
    {
        $name = (string) preg_replace('/[^A-Za-z0-9_]/', '', $name);
        if ($name === '' || ctype_digit($name[0]) || str_starts_with($name, '__cms_')) {
            return 'vo';
        }

        $reserved = [
            'this', 'GLOBALS', '_SERVER', '_GET', '_POST', '_COOKIE',
            '_SESSION', '_REQUEST', '_FILES', '_ENV',
        ];

        return in_array($name, $reserved, true) ? 'vo' : $name;
    }
}
