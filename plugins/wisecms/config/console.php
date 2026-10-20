<?php
/**
 * CMS 插件命令行配置
 */
return [
    'commands' => [
        \plugin\wisecms\command\QualityRescoreCommand::class,
        \plugin\wisecms\command\SeoAuditAllCommand::class,
        \plugin\wisecms\command\SitemapRegenerateCommand::class,
        \plugin\wisecms\command\SeoPushCommand::class,
        \plugin\wisecms\command\InternalLinkRebuildCommand::class,
        \plugin\wisecms\command\PublishScheduledCommand::class,
        \plugin\wisecms\command\SchemaSyncCommand::class,
        \plugin\wisecms\command\UrlRebuildCommand::class,
        \plugin\wisecms\command\VersionRebaseCommand::class,
        \plugin\wisecms\command\VersionCleanupCommand::class,
        \plugin\wisecms\command\TagAliasCommand::class,
        \plugin\wisecms\command\TagSiteCommand::class,
        \plugin\wisecms\command\CommentSiteCommand::class,
        \plugin\wisecms\command\MaintainCommand::class,
    ],
];
