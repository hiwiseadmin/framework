<?php
    // PHP 原生 Error / TypeError 没有 getStatusCode()（只有 HttpException 等有）。
    // 不做保护会在此处二次报错，把真实异常彻底掩盖成一个无意义的
    // 「Call to undefined method Error::getStatusCode()」，严重妨碍排障。
    $code = method_exists($exception, 'getStatusCode')
        ? $exception->getStatusCode()
        : 500;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title><?php echo $code; ?> - 系统异常</title>
    <style>
        /* 基础样式 */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", sans-serif;
            line-height: 1.6;
            color: #333;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        /* 错误容器 */
        .error-container {
            max-width: 800px;
            width: 100%;
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
            overflow: hidden;
            backdrop-filter: blur(10px);
        }
        
        /* 头部 */
        .error-header {
            background: linear-gradient(to right, #667eea, #764ba2);
            padding: 30px 40px;
            color: white;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .error-code {
            font-size: 80px;
            font-weight: 300;
            line-height: 1;
            text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.2);
        }
        
        .error-title {
            font-size: 28px;
            font-weight: 500;
            margin-top: 10px;
            opacity: 0.9;
        }
        
        /* 内容区域 */
        .error-content {
            padding: 40px;
        }
        
        .error-message {
            font-size: 16px;
            color: #2d3748;
            margin-bottom: 30px;
            line-height: 1.8;
        }
        
        .error-icon {
            font-size: 100px;
            text-align: center;
            margin-top: 30px;
            opacity: 0.2;
            color: #667eea;
        }
        
        /* 帮助信息 */
        .error-help {
            background: #f8fafc;
            border-radius: 12px;
            padding: 25px;
            margin: 30px 0;
            border-left: 4px solid #667eea;
        }
        
        .help-title {
            font-size: 16px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
        }
        
        .help-title::before {
            content: "💡";
            margin-right: 10px;
            font-size: 20px;
        }
        
        .help-list {
            list-style: none;
        }
        
        .help-list li {
            padding: 8px 0;
            color: #4a5568;
            display: flex;
            align-items: center;
        }
        
        .help-list li::before {
            content: "→";
            color: #667eea;
            margin-right: 10px;
            font-weight: bold;
        }
        
        /* 操作按钮 */
        .error-actions {
            display: flex;
            gap: 20px;
            margin-top: 40px;
            flex-wrap: wrap;
        }
        
        .btn {
            flex: 1;
            min-width: 180px;
            padding: 16px 24px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: 500;
            font-size: 16px;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
        }
        
        .btn-primary {
            background: linear-gradient(to right, #667eea, #764ba2);
            color: white;
        }
        
        .btn-secondary {
            background: #edf2f7;
            color: #4a5568;
        }
        
        .btn-outline {
            background: transparent;
            color: #667eea;
            border: 2px solid #667eea;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }
        
        .btn-primary:hover {
            background: linear-gradient(to right, #5a67d8, #6b46c1);
        }
        
        .btn-secondary:hover {
            background: #e2e8f0;
        }
        
        .btn-outline:hover {
            background: #667eea;
            color: white;
        }
        
        .btn-icon {
            margin-right: 10px;
            font-size: 20px;
        }
        
        /* 页脚 */
        .error-footer {
            padding: 30px 40px;
            background: #f8fafc;
            border-top: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: #718096;
            font-size: 14px;
        }
        
        .logo {
            font-weight: 600;
            color: #2d3748;
            font-size: 18px;
        }
        
        .contact-info {
            text-align: right;
        }
        
        .contact-info a {
            color: #667eea;
            text-decoration: none;
        }
        
        .contact-info a:hover {
            text-decoration: underline;
        }
        
        /* 响应式设计 */
        @media (max-width: 768px) {
            .error-header {
                flex-direction: column;
                text-align: center;
                padding: 25px 20px;
            }
            
            .error-code {
                font-size: 60px;
            }
            
            .error-title {
                font-size: 22px;
            }
            
            .error-content {
                padding: 25px;
            }
            
            .error-message {
                font-size: 18px;
            }
            
            .error-actions {
                flex-direction: column;
            }
            
            .btn {
                min-width: 100%;
            }
            
            .error-footer {
                flex-direction: column;
                text-align: center;
                gap: 15px;
            }
            
            .contact-info {
                text-align: center;
            }
        }

        /* 特殊状态 */
        .error-404 { background: linear-gradient(to right, #4299e1, #63b3ed); }
        .error-500 { background: linear-gradient(to right, #f56565, #fc8181); }
        .error-403 { background: linear-gradient(to right, #ed8936, #f6ad55); }
        .error-503 { background: linear-gradient(to right, #38b2ac, #81e6d9); }
        
        .status-icon-404::before { content: "🔍"; }
        .status-icon-500::before { content: "⚙️"; }
        .status-icon-403::before { content: "🚫"; }
        .status-icon-503::before { content: "🛠️"; }
        .status-icon-default::before { content: "⚠️"; }
    </style>
</head>
<body>
    <div class="error-container">
        <!-- 头部 -->
        <div class="error-header error-<?php echo $code; ?>">
            <div>
                <div class="error-code"><?php echo $code; ?></div>
                <!-- <div class="error-title">{{$message}}</div> -->
            </div>
            <div class="status-icon status-icon-<?php echo $code; ?>"></div>
        </div>
        
        <!-- 内容区域 -->
        <div class="error-content">
            <div class="error-icon">⚠️</div>
            
            <p class="error-message">
                抱歉，系统在处理您的请求时遇到了问题。这可能是由于临时故障或维护导致的。
                我们已经记录了此问题，并将尽快修复。
            </p>
            
            <!-- 帮助信息 -->
            <div class="error-help">
                <div class="help-title">您可以尝试以下操作：</div>
                <ul class="help-list">
                    <li>刷新页面重新尝试</li>
                    <li>返回首页查看其他内容</li>
                    <li>如果问题持续存在，请联系技术支持</li>
                </ul>
            </div>
            
            <!-- 操作按钮 -->
            <div class="error-actions">
                <button onclick="window.history.back()" class="btn btn-secondary">
                    <span class="btn-icon">←</span> 返回上一页
                </button>
                
                <a href="/" class="btn btn-primary">
                    <span class="btn-icon">🏠</span> 返回首页
                </a>
                
                <button onclick="location.reload()" class="btn btn-outline">
                    <span class="btn-icon">🔄</span> 刷新页面
                </button>
            </div>
        </div>
        
        <!-- 页脚 -->
        <div class="error-footer">
            <div class="logo"><?php echo config('app.name'); ?></div>
            <div class="contact-info">
                <div style="margin-top: 5px; font-size: 12px;">
                    时间: <?php echo date('Y-m-d H:i:s'); ?>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // 自动隐藏URL中的错误信息
        if (window.history && window.history.replaceState) {
            window.history.replaceState(null, null, window.location.pathname);
        }
        
        // 错误类型特定处理
        document.addEventListener('DOMContentLoaded', function() {
            const errorCode = parseInt('<?php echo $code; ?>');
            const statusIcon = document.querySelector('.status-icon');
            
            // 根据错误码设置不同的图标和样式
            const statusMap = {
                404: 'status-icon-404',
                500: 'status-icon-500',
                403: 'status-icon-403',
                503: 'status-icon-503'
            };
            
            if (statusMap[errorCode]) {
                statusIcon.className = 'status-icon ' + statusMap[errorCode];
            } else {
                statusIcon.className = 'status-icon status-icon-default';
            }
        });
    </script>
</body>
</html>