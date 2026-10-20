<?php

declare(strict_types=1);

use think\migration\Migrator;
use think\migration\db\Column;

/**
 * 批次 7：正文「双保存」——新增渲染后的 HTML 字段
 *
 * 为 SEO / 展示：
 *   - `wisedocs_documents.content_html`：正文渲染后的 HTML（前台直接输出，不再运行时渲染）
 *     · 富文本（html）：= 已净化的 content
 *     · Markdown：= 后端 MarkdownRenderer 渲染产物
 *     · 代码型：= <pre><code class="language-x"> 转义后的代码
 *   - `wisedocs_document_revisions.content_html`：发布快照同样记录渲染后的 HTML
 *
 * 存量数据不回填：前台读取时对空 content_html 走旧渲染路径降级。
 */
class AddContentHtmlToWiseDocsDocuments extends Migrator
{
    public function up(): void
    {
        $docsTable = $this->table('wisedocs_documents');
        if (!$docsTable->hasColumn('content_html')) {
            $this->table('wisedocs_documents')
                ->addColumn(Column::longText('content_html')->setNullable()->setComment('正文渲染后的HTML(SEO/展示用)'))
                ->update();
        }

        if ($this->hasTable('wisedocs_document_revisions')) {
            $revTable = $this->table('wisedocs_document_revisions');
            if (!$revTable->hasColumn('content_html')) {
                $this->table('wisedocs_document_revisions')
                    ->addColumn(Column::longText('content_html')->setNullable()->setComment('正文渲染后的HTML快照'))
                    ->update();
            }
        }
    }

    public function down(): void
    {
        $docsTable = $this->table('wisedocs_documents');
        if ($docsTable->hasColumn('content_html')) {
            $this->table('wisedocs_documents')->removeColumn('content_html')->update();
        }

        if ($this->hasTable('wisedocs_document_revisions')) {
            $revTable = $this->table('wisedocs_document_revisions');
            if ($revTable->hasColumn('content_html')) {
                $this->table('wisedocs_document_revisions')->removeColumn('content_html')->update();
            }
        }
    }
}
