<?php
// +----------------------------------------------------------------------
// | 追加 MigrationOverrideService 到 vendor/services.php 末尾
// +----------------------------------------------------------------------
// | 用法：php think plugin:migrate:register
// | 由 composer.json post-autoload-dump 自动调用。
// |
// | 说明：迁移命令覆盖服务必须“最后注册”（其 Console::starting 回调最后执行）
// | 才能覆盖 think-migration 的原生命令，因此不能放进 extend.json 的 commands。
// +----------------------------------------------------------------------

namespace wise\plugin\command\tool;

use think\console\Command;
use think\console\Input;
use think\console\Output;

class MigrationRegister extends Command
{
    /**
     * 迁移命令覆盖服务类名
     */
    protected const SERVICE_CLASS = 'wise\command\MigrationOverrideService';

    protected function configure()
    {
        $this->setName('plugin:migrate:register')
             ->setDescription('将 MigrationOverrideService 追加到 vendor/services.php 末尾');
    }

    protected function execute(Input $input, Output $output)
    {
        $servicesFile = $this->app->getRootPath() . 'vendor' . DIRECTORY_SEPARATOR . 'services.php';

        if (!is_file($servicesFile)) {
            $output->writeln('<error>vendor/services.php 不存在，请先执行 composer install</error>');
            return 0;
        }

        $content = (string) file_get_contents($servicesFile);

        // 已存在则跳过
        if (str_contains($content, self::SERVICE_CLASS)) {
            $output->writeln('<info>MigrationOverrideService 已存在于 vendor/services.php，无需操作</info>');
            return 0;
        }

        // 找到最后一个数字键，使用下一个数字
        preg_match_all('/\s+(\d+)\s+=>/', $content, $matches);
        $lastKey = !empty($matches[1]) ? max(array_map('intval', $matches[1])) : 0;
        $nextKey = $lastKey + 1;

        // 在结尾的 ");" 前插入
        $pos = strrpos($content, ');');
        if ($pos === false) {
            $output->writeln('<error>vendor/services.php 格式异常，无法定位结尾</error>');
            return 0;
        }

        $entry = "\n  // WiseAdmin: 迁移命令覆盖（由 plugin:migrate:register 自动追加）\n"
            . "  {$nextKey} => '" . self::SERVICE_CLASS . "',\n";

        $content = substr($content, 0, $pos) . $entry . substr($content, $pos);

        if (file_put_contents($servicesFile, $content) !== false) {
            $output->writeln("<info>MigrationOverrideService 已追加到 vendor/services.php 末尾（键 {$nextKey}）</info>");
        } else {
            $output->writeln('<error>写入 vendor/services.php 失败</error>');
        }

        return 0;
    }
}
