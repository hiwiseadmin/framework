## 目录结构
```
project/ # 插件项目根目录
├─controller      控制器目录
├─model           模型目录
├─ ...            更多类库目录
│
├─common.php         公共函数文件
├─event.php          事件定义文件
│
├─config                配置目录
│  ├─电动.php            应用配置
│
├─view            视图目录
├─route                 路由定义目录
│  ├─route.php          路由定义文件
│  └─ ...   
│
├─public                WEB目录（对外访问目录）
│  ├─index.php          入口文件
│  ├─router.php         快速测试文件
│  └─.htaccess          用于apache的重写
│
├─extend                扩展类库目录
├─runtime               应用的运行时目录（可写，可定制）
├─vendor                Composer类库目录
├─.example.env          环境变量示例文件
├─composer.json         composer 定义文件
├─LICENSE.txt           授权说明文件
├─README.md             README 文件
├─think                 命令行入口文件
```

## 技能接口设计
```php
namespace skills\interfaces;

interface SkillInterface
{
    // 返回技能唯一标识
    public static function getName(): string;
    
    // 返回技能描述
    public static function getDescription(): string;
    
    // 执行技能，接收参数数组，返回结果
    public function execute(array $params = []): array;
    
    // 可选：验证参数
    public function validateParams(array $params): bool;
}
```

