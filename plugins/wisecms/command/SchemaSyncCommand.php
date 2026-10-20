<?php
namespace plugin\wisecms\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\console\input\Option;
use plugin\wisecms\model\Model;
use plugin\wisecms\service\model\ModelService;

/**
 * 同步所有模型的副表结构（存量数据对齐）
 *
 * 用于修复：模型已存在但副表缺失 / 字段已定义但副表缺列的情况。
 */
class SchemaSyncCommand extends Command
{
    protected function configure()
    {
        $this->setName('wisecms:schema:sync')
            ->addOption('drop-orphan', null, Option::VALUE_NONE, '删除 D1 改造前遗留的孤立空主表（wisecms_content_{name}）')
            ->setDescription('同步所有模型的副表结构（补齐缺失列与索引）');
    }

    protected function execute(Input $input, Output $output)
    {
        $service = new ModelService();
        $models = Model::where('status', 1)->select();

        $totalColumns = 0;
        foreach ($models as $model) {
            try {
                $result = $service->syncModelSchema((int) $model->id);
                $count = count($result['added_columns']);
                if ($count > 0) {
                    $output->writeln("<info>模型[{$model->title}] 补齐 {$count} 列：</info>" . implode(', ', $result['added_columns']));
                } else {
                    $output->writeln("模型[{$model->title}] 副表结构已就绪");
                }
                $totalColumns += $count;
            } catch (\Exception $e) {
                $output->writeln("<error>模型[{$model->title}] 同步失败：{$e->getMessage()}</error>");
            }
        }

        $output->writeln("<info>同步完成，共补齐 {$totalColumns} 列</info>");

        // 可选：清理历史孤立空主表（默认不清理，需显式传 --drop-orphan）
        if ($input->getOption('drop-orphan')) {
            $dropped = $service->dropOrphanMainTables();
            if (!empty($dropped)) {
                foreach ($dropped as $table) {
                    $output->writeln("<info>已删除孤立主表：{$table}</info>");
                }
            } else {
                $output->writeln('未发现孤立主表');
            }
        }
    }
}
