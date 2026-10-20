<?php
/**
 * ================================================================
 * WiseAdmin 错误代码统一配置
 * ================================================================
 * 
 * 编码规范：
 *   【系统级】直接使用 HTTP 状态码（3位数字），与 HTTP 语义一致
 *   【业务级】HTTP状态码(前3位) + 模块号段(后3位)，共6位数字
 * 
 * 设计原则：
 *   1. 前端判断成功只需 `code === 200`，简洁无歧义
 *   2. 前3位即 HTTP 状态码，一眼可知错误类别（40x/401/403/404/409/50x）
 *   3. 后3位按模块分段（100码/模块），永不冲突，一目了然
 *   4. 与 JsonResponse::success()/error() 默认值天然对齐
 * 
 * 模块号段（后3位）：
 *   000-099  PARAM 参数校验 / AUTH 登录认证
 *   100-199  AUTH 密码验证 / USER 用户
 *   200-299  ROLE 角色
 *   300-399  UPLOAD 文件上传
 *   400-499  预留扩展
 *   500-599  PLUGIN 插件
 *   600-699  DATA 数据
 *   700-799  预留扩展
 *   800-899  EXTERNAL 第三方
 *   900-999  预留扩展
 * 
 * 结构：
 *   '常量名' => [
 *       'code'        => 业务码 (int),
 *       'msg'         => 默认消息 (string, 支持 {var} 模板变量),
 *       'http_status' => HTTP状态码 (int)
 *   ]
 * 
 * 支持通过 {var} 占位符实现消息模板，调用时传入对应变量即可替换
 */

return [
    // ==================== 系统级（3位HTTP状态码） ====================
    'SYSTEM' => [
        'SUCCESS'           => ['code' => 200, 'msg' => '操作成功'],
        'FAILED'            => ['code' => 400, 'msg' => '操作失败'],
        'ERROR'             => ['code' => 500, 'msg' => '服务器内部错误'],
        'NOT_FOUND'         => ['code' => 404, 'msg' => '资源不存在'],
        'METHOD_NOT_ALLOW'  => ['code' => 405, 'msg' => '请求方式不允许'],
        'REQUEST_TOO_FAST'  => ['code' => 429, 'msg' => '请求过于频繁，请稍后再试'],
        'SERVICE_MAINTAIN'  => ['code' => 503, 'msg' => '服务维护中'],
        'CSRF_ERROR'        => ['code' => 419, 'msg' => '页面已过期，请刷新后重试'],
        'FORM_DUPLICATE'    => ['code' => 409, 'msg' => '请勿重复提交表单'],
    ],

    // ==================== 参数校验 (400000-400099) ====================
    'PARAM' => [
        'INVALID'           => ['code' => 400000, 'msg' => '请求参数错误'],
        'MISSING'           => ['code' => 400001, 'msg' => '缺少必要参数：{field}'],
        'TYPE_ERROR'        => ['code' => 400002, 'msg' => '参数类型错误：{field}'],
        'FORMAT_ERROR'      => ['code' => 400003, 'msg' => '参数格式错误'],
        'OUT_OF_RANGE'      => ['code' => 400004, 'msg' => '参数超出有效范围'],
        'LENGTH_TOO_LONG'   => ['code' => 400005, 'msg' => '{field}长度超出限制'],
        'VALIDATE_FAILED'   => ['code' => 400006, 'msg' => '数据验证失败'],
    ],

    // ==================== 认证-密码/验证码 (400100-400199) ====================
    'AUTH' => [
        // --- 密码/验证码 ---
        'CAPTCHA_ERROR'     => ['code' => 400100, 'msg' => '验证码错误'],
        'CAPTCHA_EXPIRED'   => ['code' => 400101, 'msg' => '验证码已过期'],
        'PASSWORD_ERROR'    => ['code' => 400102, 'msg' => '密码错误'],
        'OLD_PASSWORD_ERROR'=> ['code' => 400103, 'msg' => '原密码错误'],
        'PASSWORD_WEAK'     => ['code' => 400104, 'msg' => '密码强度不足'],

        // --- 登录/Token/会话 (401000-401099) ---
        'UNAUTHORIZED'      => ['code' => 401000, 'msg' => '请先登录'],
        'LOGIN_FAILED'      => ['code' => 401001, 'msg' => '账号或密码错误'],
        'TOKEN_EXPIRED'     => ['code' => 401002, 'msg' => '登录已过期，请重新登录'],
        'TOKEN_INVALID'     => ['code' => 401003, 'msg' => '无效的登录凭证'],
        'SESSION_EXPIRED'   => ['code' => 401004, 'msg' => '会话已过期，请重新登录'],
        'DEVICE_UNTRUSTED'  => ['code' => 401005, 'msg' => '设备未受信任，请验证身份'],

        // --- 权限/鉴权 (403000-403099) ---
        'FORBIDDEN'         => ['code' => 403000, 'msg' => '暂无操作权限'],
        'ACCOUNT_LOCKED'    => ['code' => 403001, 'msg' => '账号已被锁定，请联系管理员'],
        'ACCOUNT_DISABLED'  => ['code' => 403002, 'msg' => '账号已被禁用'],
        'ROLE_FORBIDDEN'    => ['code' => 403003, 'msg' => '当前角色无权执行此操作'],
        'IP_NOT_ALLOWED'    => ['code' => 403004, 'msg' => '当前IP不允许访问'],

        // --- 账号 (404000-404099) ---
        'ACCOUNT_NOT_FOUND' => ['code' => 404000, 'msg' => '账号不存在'],
    ],

    // ==================== 用户管理 (404100-404199, 409100-409199, 403100-403199, 500000-500099) ====================
    'USER' => [
        'NOT_FOUND'         => ['code' => 404100, 'msg' => '用户不存在'],
        'ALREADY_EXISTS'    => ['code' => 409100, 'msg' => '用户已存在'],
        'USERNAME_EXISTS'   => ['code' => 409101, 'msg' => '用户名已被占用'],
        'EMAIL_EXISTS'      => ['code' => 409102, 'msg' => '邮箱已被占用'],
        'MOBILE_EXISTS'     => ['code' => 409103, 'msg' => '手机号已被占用'],
        'CREATE_FAILED'     => ['code' => 500000, 'msg' => '用户创建失败'],
        'UPDATE_FAILED'     => ['code' => 500001, 'msg' => '用户信息更新失败'],
        'DELETE_FAILED'     => ['code' => 500002, 'msg' => '用户删除失败'],
        'DELETE_SELF'       => ['code' => 403100, 'msg' => '不能删除自己的账号'],
    ],

    // ==================== 角色权限 (404200-404299, 409200-409299, 403200-403299, 500100-500199) ====================
    'ROLE' => [
        'NOT_FOUND'         => ['code' => 404200, 'msg' => '角色不存在'],
        'ALREADY_EXISTS'    => ['code' => 409200, 'msg' => '角色已存在'],
        'NAME_EXISTS'       => ['code' => 409201, 'msg' => '角色名称已存在'],
        'CONFLICT'          => ['code' => 409202, 'msg' => '角色互斥，无法同时分配'],
        'CREATE_FAILED'     => ['code' => 500100, 'msg' => '角色创建失败'],
        'UPDATE_FAILED'     => ['code' => 500101, 'msg' => '角色更新失败'],
        'DELETE_FAILED'     => ['code' => 500102, 'msg' => '角色删除失败'],
        'DELETE_SYSTEM'     => ['code' => 403200, 'msg' => '系统角色不可删除'],
    ],

    // ==================== 文件上传 (400300-400399, 404300-404399, 500300-500399) ====================
    'UPLOAD' => [
        'FILE_TOO_LARGE'    => ['code' => 400300, 'msg' => '文件大小超出限制，最大允许 {max}'],
        'TYPE_NOT_ALLOW'    => ['code' => 400301, 'msg' => '文件类型不允许'],
        'EXT_NOT_ALLOW'     => ['code' => 400302, 'msg' => '不允许的文件扩展名'],
        'DANGEROUS_FILE'    => ['code' => 400303, 'msg' => '检测到危险文件，已拒绝上传'],
        'UPLOAD_FAILED'     => ['code' => 500300, 'msg' => '文件上传失败'],
        'MOVE_FAILED'       => ['code' => 500301, 'msg' => '文件保存失败'],
        'NOT_FOUND'         => ['code' => 404300, 'msg' => '文件不存在'],
        'CHUNK_ERROR'       => ['code' => 500302, 'msg' => '分片上传异常'],
        'STORAGE_ERROR'     => ['code' => 500303, 'msg' => '存储驱动异常'],
    ],

    // ==================== 插件系统 (400500-400599, 403500-403599, 404500-404599, 409500-409599, 500500-500599) ====================
    'PLUGIN' => [
        'NOT_FOUND'         => ['code' => 404500, 'msg' => '插件不存在'],
        'NOT_INSTALLED'     => ['code' => 400500, 'msg' => '插件未安装'],
        'ALREADY_INSTALLED' => ['code' => 409500, 'msg' => '插件已安装'],
        'DISABLED'          => ['code' => 403500, 'msg' => '插件已被禁用'],
        'INSTALL_FAILED'    => ['code' => 500500, 'msg' => '插件安装失败'],
        'UNINSTALL_FAILED'  => ['code' => 500501, 'msg' => '插件卸载失败'],
        'ENABLE_FAILED'     => ['code' => 500502, 'msg' => '插件启用失败'],
        'DISABLE_FAILED'    => ['code' => 500503, 'msg' => '插件禁用失败'],
        'HOOK_NOT_FOUND'    => ['code' => 404501, 'msg' => '插件钩子不存在'],
        'MANIFEST_ERROR'    => ['code' => 400501, 'msg' => '插件配置文件错误'],
        'DEPENDENCY_MISSING'=> ['code' => 400502, 'msg' => '插件依赖缺失：{plugin}'],
        'VERSION_CONFLICT'  => ['code' => 400503, 'msg' => '插件版本不兼容'],
    ],

    // ==================== 数据库/缓存 (404600-404699, 409600-409699, 500600-500699) ====================
    'DATA' => [
        'SAVE_FAILED'       => ['code' => 500600, 'msg' => '数据保存失败'],
        'DELETE_FAILED'     => ['code' => 500601, 'msg' => '数据删除失败'],
        'QUERY_FAILED'      => ['code' => 500602, 'msg' => '数据查询失败'],
        'RECORD_NOT_FOUND'  => ['code' => 404600, 'msg' => '记录不存在'],
        'DUPLICATE_KEY'     => ['code' => 409600, 'msg' => '数据重复，{field}已存在'],
        'CACHE_ERROR'       => ['code' => 500603, 'msg' => '缓存操作失败'],
        'DB_CONNECT_FAILED' => ['code' => 500604, 'msg' => '数据库连接失败'],
        'TRANSACTION_FAILED'=> ['code' => 500605, 'msg' => '事务执行失败'],
    ],

    // ==================== 第三方服务 (500800-500899, 502800-502899, 504800-504899) ====================
    'EXTERNAL' => [
        'REQUEST_FAILED'    => ['code' => 500800, 'msg' => '第三方服务请求失败'],
        'TIMEOUT'           => ['code' => 504800, 'msg' => '第三方服务请求超时'],
        'INVALID_RESPONSE'  => ['code' => 502800, 'msg' => '第三方服务返回异常'],
        'CONFIG_ERROR'      => ['code' => 500801, 'msg' => '第三方服务配置错误'],
    ],
];
