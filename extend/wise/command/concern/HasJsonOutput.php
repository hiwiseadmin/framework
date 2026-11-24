<?php

declare(strict_types=1);

namespace wise\command\concern;

use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * 命令行 JSON 输出约定（框架统一口径）。
 *
 * 约定只回答两件事，不改变各命令自己的 payload 结构：
 *
 *  1. **选项名统一为 `--json`**：AI / 脚本只需认这一个开关，不必记 `--format`；
 *  2. **`--json` 意味着 stdout 只有一份 JSON**：装饰符、进度行、表格、提示语都不能出现，
 *     否则 `jq` / `ConvertFrom-Json` / Agent 解析会直接失败。
 *
 * 与 `--format` 的关系：
 *  - 命令仍可保留 `--format`（例如 `text|json|csv`），用于人类选择输出形态；
 *  - `--format=json` 继续可用（向后兼容），`--json` 与之等价**且优先级更高**；
 *  - 若某命令的 `--format=json` 历史上夹带了说明文字（非纯 JSON），
 *    则 `--json` 走严格模式：只输出 JSON。
 *
 * 用法：
 * ```php
 * class MyCommand extends Command
 * {
 *     use HasJsonOutput;
 *
 *     protected function configure(): void
 *     {
 *         $this->addJsonOption();          // --json
 *         $this->addOption('format', 'f', Option::VALUE_OPTIONAL, 'text|json', 'text');
 *     }
 *
 *     protected function execute(Input $input, Output $output): int
 *     {
 *         $json = $this->isJsonOutput($input);
 *
 *         if ($json) {
 *             $this->writeJson($output, ['ok' => true, 'data' => $data]);
 *         } else {
 *             $output->writeln('done');
 *         }
 *     }
 * }
 * ```
 */
trait HasJsonOutput
{
    /**
     * 声明统一的 `--json` 选项。
     *
     * 不设短选项：各命令已有 `-p` / `-f` 等短选项，强塞 `-j` 容易与既有语义冲突；
     * `--json` 足够短，且是 AI 唯一需要记住的写法。
     */
    protected function addJsonOption(string $description = '以 JSON 输出（stdout 只有 JSON，供 AI / 脚本消费）'): static
    {
        $this->addOption('json', null, Option::VALUE_NONE, $description);

        return $this;
    }

    /**
     * 当前是否要求 JSON 输出（`--json` 优先，兼容 `--format=json`）。
     *
     * 命令未声明 `format` 选项时安全返回 false，不会抛异常。
     */
    protected function isJsonOutput(Input $input): bool
    {
        if ($input->hasOption('json') && (bool) $input->getOption('json')) {
            return true;
        }

        return $this->formatIsJson($input);
    }

    /**
     * `--format=json`（历史写法）判定。
     */
    protected function formatIsJson(Input $input): bool
    {
        if (!$input->hasOption('format')) {
            return false;
        }

        return strtolower(trim((string) $input->getOption('format'))) === 'json';
    }

    /**
     * 输出 JSON（stdout 唯一内容）。
     *
     * - 关闭装饰，避免 ANSI 颜色码污染 stdout；
     * - 使用 OUTPUT_RAW，避免 JSON 里的 `<` 被当成格式标签；
     * - 编码失败时输出一个仍可解析的错误对象，而不是空串。
     */
    protected function writeJson(
        Output $output,
        mixed $payload,
        int $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    ): void {
        try {
            $output->setDecorated(false);
        } catch (\Throwable) {
        }

        $json = json_encode($payload, $flags);

        if ($json === false) {
            $json = (string) json_encode([
                'ok'    => false,
                'error' => 'json_encode failed: ' . json_last_error_msg(),
            ], JSON_UNESCAPED_UNICODE);
        }

        $output->writeln($json, Output::OUTPUT_RAW);
    }
}
