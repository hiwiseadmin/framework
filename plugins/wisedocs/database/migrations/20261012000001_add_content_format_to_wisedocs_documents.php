<?php

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * 为文档表增加「正文格式」标记（content_format）。
 *
 * 背景：正文默认编辑器切换为 Markdown（wiseeditor / Milkdown），
 * 而历史数据均为富文本 HTML。两种格式在前台的渲染方式完全不同
 * （HTML 直出 / Markdown 需渲染），因此必须显式记录格式，
 * 否则无法正确渲染，也无法安全地编辑旧文档。
 *
 * 取值：`html`（富文本，历史数据）、`markdown`（新文档默认）。
 * 历史行由默认值 `html` 覆盖，无需数据回填。
 */
class AddContentFormatToWiseDocsDocuments extends Migrator
{
    public function up(): void
    {
        $table = $this->table('wisedocs_documents');

        if (!$table->hasColumn('content_format')) {
            $table->addColumn(
                Column::string('content_format', 20)
                    ->setDefault('html')
                    ->setComment('正文格式:html(富文本),markdown')
            )->update();
        }
    }

    public function down(): void
    {
        $table = $this->table('wisedocs_documents');

        if ($table->hasColumn('content_format')) {
            $table->removeColumn('content_format')->update();
        }
    }
}
