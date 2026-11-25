# 参与 WiseAdmin 贡献指南（CONTRIBUTING）

欢迎参与 WiseAdmin 的开发！本指南说明如何反馈问题、在哪里改代码、提交前要跑哪些检查，
以及贡献必须遵循的许可约定。

> 仓库结构：`wiseadmin/framework` 是基于 ThinkPHP 8 的多应用后台工程；
> `wiseadmin/wise-*` 系列是独立版本化的 Composer 组件，开发源码位于本仓
> `packages/wiseadmin/*`，对组件的修改同样在本仓进行。

## 一、行为准则

- 友善、专业、对事不对人；
- 尊重既有架构与命名规范，先讨论后动手；
- 涉及破坏性变更（接口、表结构、权限点、配置项）务必在 PR 描述中高亮说明。

## 二、问题反馈与安全漏洞

- **功能建议 / Bug**：提交到 <https://github.com/hiwiseadmin/framework/issues>，先检索是否已有同类 Issue。
- 描述请包含：运行环境（PHP 版本、数据库类型与版本、ThinkPHP 版本、是否 Swoole）、
  复现步骤与最小示例、期望行为与实际行为、相关日志与报错堆栈（注意脱敏）。
- **安全漏洞请勿公开提交**，直接邮件联系 `wiseadmin@126.com`。

## 三、代码改在哪里（重要）

- **开发真相源**是本仓（`src/` 宿主 + `src/packages/wiseadmin/*` 组件），开发分支为 `develop`。
- GitHub 上的 `hiwiseadmin/framework` 与各 `hiwiseadmin/wise-*` 是**发布快照仓**：
  每次发布都会重建为单 commit 并强制推送默认分支，历史会被整体覆盖。
  **不要对快照仓提 PR** —— 改动会在下一次发布时被冲掉。
- 外部贡献请把补丁发到 `wiseadmin@126.com`（`git diff` / `git format-patch` 或完整文件），
  由维护者合入本仓后随下一版快照同步到公开仓。

## 四、开发环境

1. PHP >= 8.1，所需扩展参考 `composer.json` 的 `require`；
2. `composer install` 后执行 `php think run` 启动开发服务；
3. 修改组件后需 `composer dump-autoload`，并验证 `php think service:discover` /
   `vendor:publish` / `wise:vendor:publish` 等发布链路仍然可用；
4. 数据库迁移使用 Phinx（`php think migrate:*`），不要手改线上结构。

## 五、提交前的检查（必须通过）

| 范围 | 命令 |
|---|---|
| 包级单测（自动发现） | `php scripts/run-package-tests.php`（单包：`--only=wise-auth`） |
| 静态分析（阻断） | `php src/vendor/bin/phpstan analyse -c phpstan.neon --autoload-file=src/vendor/autoload.php --memory-limit=1G` |
| 前端 / 中间件契约 | `cd src && npm run verify:ci` |
| 插件契约 | `cd src && php think plugin:test --unit --all && php think plugin:audit --all` |

> 涉及 `wise-*` 组件版本、`composer.json` 约束或发布流程的改动，还需按
> `release/README.md` 跑发布前自检（如 `release.bat scan`、`release.bat strip --all --main`）。

## 六、代码与命名规范

代码提交须遵守本仓已建立的工程约定（分层架构、Trait 复用、控制器/模型继承体系、
`kebab-case` 组件命名、`{ident}_` 插件函数前缀等）。具体约定请阅读仓内 `AGENTS.md`、
`src/AGENTS.md` 与既有代码，保持风格一致。

## 七、提交补丁 / PR 的要求

1. 从 `develop` 切出特性分支，命名如 `feature/xxx`、`fix/xxx`；
2. 一个 PR 只做一件事，提交信息说明"为什么改"（中英文均可）；
3. 涉及新增 / 修改公共 API 时补充或更新测试；
4. 需要保留可回滚点时用 git（临时分支、`git stash`、`git commit`），
   **不要生成 `*.bak` / 日期后缀的备份文件**（这类文件无法比对且容易误入包）；
5. 合入前请确保第五节列出的检查全部通过。

## 八、许可与贡献者协议（重要）

- **主项目许可**：本仓以 **Apache License 2.0** 发布，完整文本见 `LICENSE.txt`，
  第三方与商标声明见 `NOTICE`。
- **组件许可**：`wise-*` 组件与主项目一致，均采用 **Apache License 2.0** 发布
  （以各组件 `composer.json` 与包内 `LICENSE` 为准）；如项目未来依法调整许可策略，
  将提前公示并依据《贡献者许可协议》办理。
- **贡献者许可协议（CLA）**：为保证项目可持续地调整许可策略与商业化运营，
  向本仓或任何 WiseAdmin 维护的组件提交代码、文档或其它受版权保护的内容，
  即视为同意签署本项目的《贡献者许可协议》：

  - 个人贡献者：请阅读并签署 `CLA/INDIVIDUAL.md`；
  - 以公司/组织名义贡献：请签署 `CLA/CORPORATE.md`；
  - 简化签署方式：在首个 PR 中追加一行评论——

    ```
    I agree to the terms of the WiseAdmin Contributor License Agreement.
    （本人同意 WiseAdmin 贡献者许可协议之条款。）
    ```

  维护者有权要求贡献者补签正式 CLA 后再合入其贡献。

## 九、版权声明

新文件请按仓库惯例在文件头保留版权与许可注释；勿移除既有文件的版权声明。

## 十、联系与沟通

- 项目主页：<https://www.wiseadmin.top>
- 问题反馈：<https://github.com/hiwiseadmin/framework/issues>
- 邮箱（含安全漏洞）：`wiseadmin@126.com`

感谢你的贡献！
