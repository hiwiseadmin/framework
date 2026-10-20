-- 短链接插件卸载数据清理
-- 注意：以下操作会删除所有短链接相关数据，请确认后执行
-- 如需保留数据，请手动备份后再卸载

DROP TABLE IF EXISTS `__PREFIX__shortlink_log`;
DROP TABLE IF EXISTS `__PREFIX__shortlink_link`;
DROP TABLE IF EXISTS `__PREFIX__shortlink_group`;
