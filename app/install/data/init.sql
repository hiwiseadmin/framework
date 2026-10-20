-- =============================================================
-- WiseAdmin 框架级默认配置项初始化
--
-- 说明：
--   1. 表前缀使用 __PREFIX__ 占位符，安装时替换为实际前缀。
--   2. 站点名称 / 管理员邮箱使用 __SITE_NAME__ / __ADMIN_EMAIL__ 占位符。
--   3. 使用 INSERT IGNORE 保证幂等（依赖 config 表的 (namespace, group, key) 唯一索引），
--      重复执行 / 重装不会产生重复数据。
--   4. 键名与 app\common\service\ConfigService 的读取键保持一致。
-- =============================================================

-- ----------------------------
-- 系统级配置（namespace = system）
-- ----------------------------
INSERT IGNORE INTO `__PREFIX__config`
(`namespace`, `key`, `value`, `type`, `group`, `inherit`, `title`, `description`, `sort`, `status`, `create_time`, `update_time`)
VALUES
('system', 'site_name', '__SITE_NAME__', 'text', 'site', 1, '站点名称', '前台与后台展示的站点名称', 0, 1, NOW(), NOW()),
('system', 'site_title', '', 'text', 'site', 1, '站点副标题', '浏览器标题栏附加文案', 1, 1, NOW(), NOW()),
('system', 'site_keywords', '', 'text', 'site', 1, 'SEO 关键词', '多个关键词用逗号分隔', 2, 1, NOW(), NOW()),
('system', 'site_description', '', 'text', 'site', 1, 'SEO 描述', '站点摘要描述', 3, 1, NOW(), NOW()),
('system', 'site_logo', '', 'image', 'site', 1, '站点 LOGO', '首页与后台 Logo 图片', 4, 1, NOW(), NOW()),
('system', 'site_icp', '', 'text', 'site', 1, 'ICP 备案号', '如：京ICP备XXXXXXXX号', 5, 1, NOW(), NOW()),
('system', 'site_status', '1', 'number', 'site', 1, '站点开关', '1=正常 0=维护中', 6, 1, NOW(), NOW()),
('system', 'admin_email', '__ADMIN_EMAIL__', 'text', 'site', 1, '管理员邮箱', '系统告警与通知的收件邮箱', 7, 1, NOW(), NOW()),
('system', 'upload_max_size', '2', 'number', 'upload', 1, '上传大小上限(MB)', '单文件上传大小上限', 10, 1, NOW(), NOW()),
('system', 'upload_ext', 'jpg,png,gif,webp', 'text', 'upload', 1, '允许上传的扩展名', '多个扩展名用逗号分隔', 11, 1, NOW(), NOW()),
('system', 'upload_type', 'local', 'text', 'upload', 1, '存储驱动', 'local / oss / cos 等', 12, 1, NOW(), NOW()),
('system', 'smtp_host', '', 'text', 'smtp', 1, 'SMTP 主机', '邮件发送服务器地址', 20, 1, NOW(), NOW()),
('system', 'smtp_port', '465', 'number', 'smtp', 1, 'SMTP 端口', '默认 465(SSL) / 587(TLS)', 21, 1, NOW(), NOW()),
('system', 'smtp_user', '', 'text', 'smtp', 1, 'SMTP 账号', '发件邮箱账号', 22, 1, NOW(), NOW()),
('system', 'smtp_pass', '', 'text', 'smtp', 1, 'SMTP 密码', '发件邮箱授权码', 23, 1, NOW(), NOW()),
('system', 'smtp_secure', 'ssl', 'text', 'smtp', 1, 'SMTP 加密方式', 'ssl / tls / 空', 24, 1, NOW(), NOW());

-- ----------------------------
-- 常规角色（ws_auth_roles）
-- ----------------------------
-- 说明：
--   1. slug 为唯一键（ident 是 Role 模型对 slug 的别名，非独立字段），使用 INSERT IGNORE 保证幂等。
--   2. 字段顺序与 wise-auth 的 auth_roles 表迁移结构一致：
--      name, slug, description, parent_id, path, level, is_inherit, sort, status, create_time
--   3. 超级管理员 slug 必须为 super_admin（与 config('wise-auth.super_role') 保持一致）。
--   4. create_time 为 datetime 类型，故使用 NOW()。
--   5. 此处仅初始化“角色骨架”，权限分配（角色→权限）由安装期权限生成步骤/后台完成。
INSERT IGNORE INTO `__PREFIX__auth_roles`
(`name`, `slug`, `description`, `parent_id`, `path`, `level`, `is_inherit`, `sort`, `status`, `create_time`)
VALUES
('超级管理员', 'super_admin', '拥有系统全部权限，慎用', 0, '', 0, 1, 1, 1, NOW()),
('系统管理员', 'admin',       '管理后台配置、用户与角色', 0, '', 0, 1, 2, 1, NOW()),
('内容运营',   'operator',    '负责内容与日常运营操作', 0, '', 0, 1, 3, 1, NOW()),
('财务',       'finance',     '财务数据查看与核对', 0, '', 0, 1, 4, 1, NOW()),
('审计员',     'auditor',     '只读审计，不可变更业务数据', 0, '', 0, 1, 5, 1, NOW()),
('普通员工',   'staff',       '基础员工，受限访问', 0, '', 0, 1, 6, 1, NOW());
