# 工作流演示插件（workflowdemo）

WiseAdmin 工作流引擎演示插件，展示如何在插件中集成和使用工作流引擎。

## 功能说明

本插件实现了一个**订单审核工作流**，完整演示了工作流引擎的使用方式：

- 订单创建工作流（初始状态：草稿）
- 工作流状态转换（提交审核 → 分配审核员 → 审核通过/拒绝）
- 工作流历史记录
- 基于 RBAC 的权限控制

## 工作流定义

### 订单审核工作流（order_approval）

```
草稿(draft) ──提交审核(submit)──→ 已提交(submitted)
已提交(submitted) ──分配审核员(assign)──→ 审核中(reviewing)
审核中(reviewing) ──审核通过(approve)──→ 已通过(approved)
审核中(reviewing) ──审核拒绝(reject)──→ 已拒绝(rejected)
已拒绝(rejected) ──重新提交(resubmit)──→ 草稿(draft)
草稿(draft)/已提交(submitted) ──取消(cancel)──→ 已取消(cancelled)
```

### 权限标识

| 权限标识 | 说明 |
|---------|------|
| `workflow.order.submit` | 提交审核 |
| `workflow.order.assign` | 分配审核员 |
| `workflow.order.approve` | 审核通过 |
| `workflow.order.reject` | 审核拒绝 |
| `workflow.order.resubmit` | 重新提交 |
| `workflow.order.cancel` | 取消订单 |

## 安装步骤

### 1. 运行数据库迁移

```bash
# 进入项目根目录
cd wiseadmin/src

# 运行迁移（创建 workflow_orders 表）
php think migrate:run --plugin workflowdemo
```

### 2. 注册工作流服务提供者

在 `config/provider.php` 中添加：

```php
return [
    // ... 其他服务提供者
    plugin\workflowdemo\BootServiceProvider::class,
];
```

### 3. 启用插件

在后台管理 → 插件管理 中启用 `workflowdemo` 插件。

## 目录结构

```
plugins/workflowdemo/
├── Plugin.php                          # 插件主类
├── manifest.json                       # 插件清单（菜单、权限定义）
├── BootServiceProvider.php             # 启动服务提供者（注册工作流）
├── controller/
│   ├── Base.php                        # 控制器基类
│   └── Order.php                       # 订单控制器（工作流集成）
├── model/
│   └── WorkflowOrder.php               # 订单模型
├── route/
│   └── plugin.php                       # 后端路由
├── database/migrations/
│   └── 20260606000001_create_workflow_orders_table.php  # 数据库迁移
└── view/
    └── order/
        ├── index.html                   # 订单列表页面
        ├── detail.html                  # 订单详情页面（工作流操作）
        └── form.html                    # 订单创建/编辑页面
```

## 使用示例

### 在控制器中使用工作流

```php
use app\common\facade\Workflow;

// 1. 初始化工作流
$order = Order::create([...]);
Workflow::init('order_approval', $order);

// 2. 检查是否可执行转换
if (Workflow::can('order_approval', $order, 'submit')) {
    // 3. 执行转换
    Workflow::apply('order_approval', $order, 'submit');
}

// 4. 检查当前状态
if (Workflow::is('order_approval', $order, 'draft')) {
    echo '当前为草稿状态';
}

// 5. 获取可执行转换
$transitions = Workflow::transitions('order_approval', $order);

// 6. 获取工作流历史
$history = Workflow::history('order_approval', $order);
```

### 在插件中注册工作流

在 `Plugin.php` 中定义工作流：

```php
public static function getWorkflowDefinitions()
{
    return [
        Workflow::create('my_workflow', '我的工作流')
            ->setInitialPlace('draft')
            ->addPlaces(['draft' => '草稿', 'approved' => '已通过'])
            ->addTransition(Transition::create('submit', 'draft', 'approved', '提交', 'my.permission')),
    ];
}
```

在 `BootServiceProvider.php` 中注册：

```php
protected function registerWorkflows()
{
    $definitions = Plugin::getWorkflowDefinitions();
    $registry = app('workflow.registry');
    foreach ($definitions as $workflow) {
        $registry->add($workflow);
    }
}
```

## 工作流事件

工作流引擎会触发以下事件：

- `workflow.before_transition`：转换前触发
- `workflow.after_transition`：转换后触发

可以在 `BootServiceProvider.php` 中监听这些事件：

```php
Event::listen('workflow.after_transition', function ($params) {
    // $params = ['workflow' => $workflow, 'subject' => $subject, ...]
    // 发送通知、更新关联数据等
});
```

## 技术要点

1. **工作流引擎集成**：通过 `Workflow` 门面类简化调用
2. **RBAC 权限集成**：Transition 支持 guard 属性，绑定权限标识
3. **事件系统集成**：支持 before/after 事件监听
4. **数据库持久化**：使用 `ws_workflow_marking` 和 `ws_workflow_history` 表
5. **状态冗余字段**：`WorkflowOrder.status` 字段冗余存储当前状态，方便查询

## 依赖

- WiseAdmin 框架 >= 1.0.0
- 工作流引擎组件（`app/common/workflow/`）
