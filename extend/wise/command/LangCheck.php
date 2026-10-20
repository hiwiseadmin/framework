<?php
declare(strict_types=1);

namespace wise\command;

use app\common\library\LangPack;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * lang:check —— 语言包发现结果与覆盖度自检
 *
 * 用途（对应 T-02 的验收标准「语言包可加载」）：
 *   1. 确认 `lang.extend_list` 注册是否生效（包 / 插件 / 宿主语言包是否被登记）；
 *   2. 逐语言统计键数量与相对默认语言的**缺失键**，防止「未翻译文案原样露出」；
 *   3. `--key` 演示一次真实解析，验证 `lang()` 命中语言包而非回退为键名。
 *
 * ⚠️ 运行时 `Lang::get()` 未命中时返回**键名本身**（Lang.php:247），而不是空串 ——
 * 所以「漏翻译」在页面上表现为英文键名，本命令的缺失键报告即为该风险的量化。
 *
 * 用法：
 *   php think lang:check                          # 概览 + 覆盖度审计
 *   php think lang:check -k demo.title            # 演示解析某个键
 *   php think lang:check -k demo.title --locale=en-us
 *   php think lang:check --strict                 # 有缺失键时返回 1（供 CI 使用）
 */
class LangCheck extends Command
{
    protected function configure(): void
    {
        $this->setName('lang:check')
            ->addOption('key', 'k', Option::VALUE_OPTIONAL, '演示解析一个语言变量，如 -k demo.title', '')
            ->addOption('locale', null, Option::VALUE_OPTIONAL, '配合 --key 指定解析语言，如 --locale=en-us', '')
            ->addOption('strict', null, Option::VALUE_NONE, '存在缺失键时返回非 0（供 CI 使用）')
            ->setDescription('检查语言包发现结果与覆盖度（lang.extend_list 注册是否生效）');
    }

    protected function execute(Input $input, Output $output)
    {
        /** @var array<string, array<int, string>> $packs locale => 文件列表（顺序即优先级） */
        $packs   = LangPack::extendList();
        $default = (string) config('lang.default_lang', 'zh-cn');

        $this->reportConfig($output, $default);
        $this->reportPacks($output, $packs);

        $key = trim((string) $input->getOption('key'));
        if ($key !== '') {
            $this->reportLookup($output, $key, trim((string) $input->getOption('locale')) ?: $default, $packs);
        }

        $missing = $this->reportAudit($output, $default, $packs);

        if ($missing > 0) {
            $output->writeln('<comment>提示：未命中的键在运行时会原样输出键名本身，因此缺失项即「会露出的英文键」。</comment>');
        }

        $output->writeln('');
        $output->writeln('<info>语言包自检完成。</info>');

        return ($input->getOption('strict') && $missing > 0) ? 1 : 0;
    }

    /**
     * 打印语言相关配置
     */
    protected function reportConfig(Output $output, string $default): void
    {
        $allow = config('lang.allow_lang_list', []);
        $allow = is_array($allow) ? $allow : [];

        $output->writeln('<info>========== 语言配置 ==========</info>');
        $output->writeln(sprintf('默认语言     : <comment>%s</comment>', $default));
        $output->writeln(sprintf('允许语言列表 : %s', $allow ? implode(', ', $allow) : '<error>（空 —— 语言侦测会被全部拒绝）</error>'));
        $output->writeln(sprintf('语言显示名   : %s', $this->pairList(LangPack::locales())));
        $output->writeln(sprintf('当前语言     : %s', \think\facade\Lang::getLangSet()));
        $output->writeln('');
    }

    /**
     * 打印自动发现的语言包（按层，顺序即加载顺序）
     *
     * @param array<string, array<int, string>> $packs
     */
    protected function reportPacks(Output $output, array $packs): void
    {
        $output->writeln('<info>========== 已登记语言包（lang.extend_list）==========</info>');

        if (empty($packs)) {
            $output->writeln('<error>未发现任何语言包。请确认目录符合约定：</error>');
            $output->writeln('  packages/{vendor}/{pkg}/lang/{locale}.php');
            $output->writeln('  plugins/{ident}/lang/{locale}.php');
            $output->writeln('  app/lang/{locale}.php');
            $output->writeln('');

            return;
        }

        foreach ($packs as $locale => $files) {
            $output->writeln(sprintf('<comment>%s</comment>（%d 个文件）', $locale, count($files)));
            foreach ($files as $file) {
                $output->writeln(sprintf('  · [%s] %s', $this->relative($file), basename($file)));
            }
        }

        $output->writeln('');
    }

    /**
     * 演示一次真实解析
     */
    protected function reportLookup(Output $output, string $key, string $locale, array $packs): void
    {
        $value     = \think\facade\Lang::get($key, [], $locale);
        $hit       = $value !== $key;
        $definers  = $this->definers($key, $locale, $packs);

        $output->writeln('<info>========== 解析演示 ==========</info>');
        $output->writeln(sprintf('键           : %s', $key));
        $output->writeln(sprintf('语言         : %s', $locale));
        $output->writeln(sprintf('解析结果     : %s', $hit ? $value : '<error>' . $value . '（未命中，回退为键名）</error>'));

        if ($definers) {
            $output->writeln('生效来源     :');
            foreach ($definers as $file) {
                $output->writeln(sprintf('  · [%s] %s', $this->relative($file), basename($file)));
            }
        }

        $output->writeln('');
    }

    /**
     * 以默认语言为基准统计其他语言的缺失键
     *
     * @param  array<string, array<int, string>> $packs
     * @return int 缺失键总数（0 = 全量覆盖）
     */
    protected function reportAudit(Output $output, string $default, array $packs): int
    {
        $output->writeln('<info>========== 覆盖度审计（基准：' . $default . '）==========</info>');

        $baseKeys = $this->collectKeys($packs[$default] ?? []);

        if (empty($baseKeys)) {
            $output->writeln('<error>基准语言没有任何语言包，无法审计。</error>');
            $output->writeln('');

            return 0;
        }

        $allow   = config('lang.allow_lang_list', []);
        $allow   = is_array($allow) ? $allow : [];
        $locales = array_values(array_unique(array_merge(
            [$default],
            $allow,
            array_keys($packs),
            array_keys(LangPack::locales())
        )));
        sort($locales);

        $totalMissing = 0;

        foreach ($locales as $locale) {
            $locale = (string) $locale;
            $keys   = $this->collectKeys($packs[$locale] ?? []);
            $missing = array_diff_key($baseKeys, $keys);

            $coverage = count($baseKeys) > 0
                ? sprintf('%.1f%%', (count($baseKeys) - count($missing)) / count($baseKeys) * 100)
                : '-';

            $flag = empty($missing) ? '<info>完整</info>' : sprintf('<error>缺失 %d</error>', count($missing));

            $output->writeln(sprintf(
                '  %-8s 文件 %-3d 键 %-5d 覆盖率 %-7s %s',
                $locale,
                count($packs[$locale] ?? []),
                count($keys),
                $coverage,
                $flag
            ));

            if (!empty($missing) && count($missing) <= 50) {
                foreach (array_slice(array_keys($missing), 0, 20) as $missKey) {
                    $output->writeln(sprintf('      <comment>- %s</comment>', $missKey));
                }
                if (count($missing) > 20) {
                    $output->writeln(sprintf('      <comment>… 另有 %d 个缺失键</comment>', count($missing) - 20));
                }
            }

            if ($locale !== $default) {
                $totalMissing += count($missing);
            }
        }

        $output->writeln('');

        return $totalMissing;
    }

    /**
     * 汇总语言包文件的键集合
     *
     * 键统一小写 —— 与 `Lang::load()` 的 `array_change_key_case()` 行为保持一致，
     * 否则审计结果与运行时不一致。后加载的文件覆盖先加载的（与运行时同序）。
     *
     * @param  array<int, string> $files（按加载顺序，后者优先）
     * @return array<string, string> 键 => 定义它的文件
     */
    protected function collectKeys(array $files): array
    {
        $keys = [];

        foreach ($files as $file) {
            if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'php') {
                continue;
            }

            try {
                $data = include $file;
            } catch (\Throwable $e) {
                continue;
            }

            if (!is_array($data)) {
                continue;
            }

            foreach (array_change_key_case($data) as $key => $value) {
                if ($key === LangPack::LOCALE_NAME_KEY) {
                    continue;
                }
                $keys[(string) $key] = $file;
            }
        }

        return $keys;
    }

    /**
     * 找出定义某个键的文件（按优先级，命中即止）
     *
     * @param  array<string, array<int, string>> $packs
     * @return array<int, string>
     */
    protected function definers(string $key, string $locale, array $packs): array
    {
        $definers = [];

        foreach ($this->collectKeys($packs[$locale] ?? []) as $k => $file) {
            if ($k === strtolower($key)) {
                $definers[] = $file;
            }
        }

        return $definers;
    }

    /**
     * 把绝对路径压缩为项目内的相对路径
     */
    protected function relative(string $file): string
    {
        $root = rtrim(root_path(), '/\\') . DIRECTORY_SEPARATOR;

        return strpos($file, $root) === 0 ? str_replace('\\', '/', substr($file, strlen($root))) : $file;
    }

    /**
     * 关联数组转 `k=v` 列表
     */
    protected function pairList(array $pairs): string
    {
        if (empty($pairs)) {
            return '（无）';
        }

        $items = [];
        foreach ($pairs as $key => $value) {
            $items[] = $key . '=' . $value;
        }

        return implode('，', $items);
    }
}
