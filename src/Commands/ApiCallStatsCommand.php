<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

namespace Goletter\Server\Commands;

use Carbon\Carbon;
use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use Symfony\Component\Console\Input\InputOption;

use function Hyperf\Support\env;

/**
 * 从 JSON 调用日志统计第三方 API 耗时。
 *
 * 默认扫 LOG_PATH/app（或 runtime/logs/app）按日日志；可用多个 --log-dir。
 */
#[Command]
class ApiCallStatsCommand extends HyperfCommand
{
    /** 默认日志子目录名（对齐常见 Channels::APP） */
    private const DEFAULT_LOG_CHANNEL = 'app';

    public function __construct()
    {
        parent::__construct('gen:api-call-stats');
    }

    public function configure(): void
    {
        parent::configure();
        $this->setDescription('按时间从日志统计第三方 API 调用耗时（次数/中位数/P90/P99/最长）');
        $this->setHelp(<<<'HELP'
从 JSON 访问/调用日志中汇总第三方 API 耗时（按 message + api_interface）。

数据来源
  默认扫 LOG_PATH/app（或 runtime/logs/app）下按日切分的 *.log。
  多节点可用多个 --log-dir（或逗号分隔），结果合并统计。
  只统计 message 以「请求日志」结尾的行（如 facebook请求日志、tiktok请求日志），
  且需含 api_interface、duration_ms；时间字段用 created_at 或 @timestamp。
  不要扫 logs/adv（那是 AdvException，无调用耗时）。

必填
  --from / --to   仅日期时 from=当天 00:00:00，to=当天 23:59:59

示例
  # 按自然日统计（秒）
  php bin/hyperf.php goletter:api-call-stats --from=2026-11-01 --to=2026-11-06

  # 精确到时分秒 + 毫秒展示
  php bin/hyperf.php goletter:api-call-stats \
    --from="2026-11-06 10:00:00" --to="2026-11-06 18:00:00" --unit=ms

  # 只看 Facebook 且仅成功
  php bin/hyperf.php goletter:api-call-stats \
    --from=2026-11-01 --to=2026-11-06 --message=facebook请求日志 --success=1

  # 多目录（可重复 --log-dir，或逗号分隔）
  php bin/hyperf.php goletter:api-call-stats --from=2026-11-01 --to=2026-11-06 \
    --log-dir=runtime/app_a/logs/app --log-dir=runtime/app_b/logs/app
  php bin/hyperf.php goletter:api-call-stats --from=2026-11-01 --to=2026-11-06 \
    --log-dir=runtime/app_a/logs/app,runtime/app_b/logs/app

查看本说明: php bin/hyperf.php goletter:api-call-stats -h
HELP);
        $this->addOption('from', null, InputOption::VALUE_REQUIRED, '开始时间，如 2026-11-01 或 2026-11-01 10:00:00');
        $this->addOption('to', null, InputOption::VALUE_REQUIRED, '结束时间（含），如 2026-11-06 或 2026-11-06 18:00:00');
        $this->addOption('message', null, InputOption::VALUE_OPTIONAL, '只统计指定 message；默认所有 *请求日志');
        $this->addOption('success', null, InputOption::VALUE_OPTIONAL, '1=仅成功 0=仅失败 空=全部', '');
        $this->addOption(
            'log-dir',
            null,
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            '日志目录，可重复或逗号分隔；默认 LOG_PATH/app'
        );
        $this->addOption('unit', null, InputOption::VALUE_OPTIONAL, '耗时单位：s|ms', 's');
    }

    public function handle(): void
    {
        $fromOpt = (string) $this->input->getOption('from');
        $toOpt = (string) $this->input->getOption('to');
        if ($fromOpt === '' || $toOpt === '') {
            $this->error('必须指定 --from 与 --to');

            return;
        }

        $from = $this->parseBound($fromOpt, false);
        $to = $this->parseBound($toOpt, true);
        if ($from->gt($to)) {
            $this->error('--from 不能晚于 --to');

            return;
        }

        $messageFilter = (string) ($this->input->getOption('message') ?? '');
        $successFilter = (string) ($this->input->getOption('success') ?? '');
        $unit = strtolower((string) ($this->input->getOption('unit') ?? 's'));
        if (! in_array($unit, ['s', 'ms'], true)) {
            $this->error('--unit 仅支持 s 或 ms');

            return;
        }

        $logDirs = $this->resolveLogDirs();
        if ($logDirs === []) {
            $this->error('未解析到有效 --log-dir');

            return;
        }

        /** @var array<string, list<float>> $buckets key = message\tapi_interface */
        $buckets = [];
        $files = 0;
        $lines = 0;
        $matched = 0;
        $diag = [
            'non_json' => 0,
            'json' => 0,
            'call_log_msg' => 0,
            'in_range' => 0,
            'with_fields' => 0,
            'messages' => [],
        ];

        foreach ($logDirs as $logDir) {
            for ($day = $from->copy()->startOfDay(); $day->lte($to); $day->addDay()) {
                $file = $logDir . DIRECTORY_SEPARATOR . $day->format('Y-m-d') . '.log';
                if (! is_file($file)) {
                    continue;
                }
                ++$files;
                $fh = fopen($file, 'rb');
                if ($fh === false) {
                    $this->warn('无法打开: ' . $file);
                    continue;
                }
                while (($line = fgets($fh)) !== false) {
                    ++$lines;
                    $row = json_decode($line, true);
                    if (! is_array($row)) {
                        ++$diag['non_json'];
                        continue;
                    }
                    ++$diag['json'];
                    $message = (string) ($row['message'] ?? '');
                    if ($message !== '') {
                        $diag['messages'][$message] = ($diag['messages'][$message] ?? 0) + 1;
                    }
                    if (! $this->isCallLogMessage($message, $messageFilter)) {
                        continue;
                    }
                    ++$diag['call_log_msg'];
                    $at = $this->rowTime($row);
                    if ($at === null || $at->lt($from) || $at->gt($to)) {
                        continue;
                    }
                    ++$diag['in_range'];
                    if ($successFilter !== '' && (string) ($row['is_success'] ?? '') !== $successFilter) {
                        continue;
                    }
                    $iface = (string) ($row['api_interface'] ?? '');
                    if ($iface === '' || ! isset($row['duration_ms'])) {
                        continue;
                    }
                    ++$diag['with_fields'];
                    $ms = (float) $row['duration_ms'];
                    $key = $message . "\t" . $iface;
                    $buckets[$key][] = $ms;
                    ++$matched;
                }
                fclose($fh);
            }
        }

        $logDirLabel = implode(', ', $logDirs);
        if ($matched === 0) {
            $this->warn(sprintf(
                '未找到匹配日志（目录=%s，文件数=%d，扫描行=%d，区间=%s ~ %s）',
                $logDirLabel,
                $files,
                $lines,
                $from->toDateTimeString(),
                $to->toDateTimeString()
            ));
            $this->printDiagnostics($logDirs, $diag, $messageFilter);

            return;
        }

        $rows = [];
        foreach ($buckets as $key => $durations) {
            sort($durations, SORT_NUMERIC);
            [$message, $iface] = explode("\t", $key, 2);
            $n = count($durations);
            $rows[] = [
                'message' => $message,
                'api_interface' => $iface,
                'count' => $n,
                'p50' => $this->percentile($durations, 0.50),
                'p90' => $this->percentile($durations, 0.90),
                'p99' => $this->percentile($durations, 0.99),
                'max' => $durations[$n - 1],
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            return $b['count'] <=> $a['count']
                ?: strcmp($a['message'], $b['message'])
                ?: strcmp($a['api_interface'], $b['api_interface']);
        });

        $this->info(sprintf(
            '区间 %s ~ %s | 目录 %d | 文件 %d | 扫描 %d 行 | 命中 %d 次 | 接口 %d 个',
            $from->toDateTimeString(),
            $to->toDateTimeString(),
            count($logDirs),
            $files,
            $lines,
            $matched,
            count($rows)
        ));
        $this->line('目录: ' . $logDirLabel);

        $this->table(
            ['来源', '接口', '次数', '中位数', '90%', '99%', '最长'],
            array_map(function (array $r) use ($unit): array {
                return [
                    $r['message'],
                    $r['api_interface'],
                    $r['count'],
                    $this->formatDuration($r['p50'], $unit),
                    $this->formatDuration($r['p90'], $unit),
                    $this->formatDuration($r['p99'], $unit),
                    $this->formatDuration($r['max'], $unit),
                ];
            }, $rows)
        );
    }

    /**
     * @return list<string>
     */
    private function resolveLogDirs(): array
    {
        $raw = $this->input->getOption('log-dir');
        $parts = [];
        foreach (is_array($raw) ? $raw : [$raw] as $item) {
            if ($item === null || $item === '') {
                continue;
            }
            foreach (preg_split('/\s*,\s*/', (string) $item) ?: [] as $piece) {
                $piece = trim($piece);
                if ($piece !== '') {
                    $parts[] = $piece;
                }
            }
        }

        if ($parts === []) {
            $base = rtrim((string) (env('LOG_PATH') ?: BASE_PATH . '/runtime/logs'), '/\\');
            $parts[] = $base . DIRECTORY_SEPARATOR . self::DEFAULT_LOG_CHANNEL;
        }

        $dirs = [];
        foreach ($parts as $dir) {
            $normalized = rtrim($dir, '/\\');
            if ($normalized === '') {
                continue;
            }
            // 相对路径按仓库根解析，避免 cwd 在 runtime 子目录时找错
            if ($normalized[0] !== '/' && ! preg_match('/^[A-Za-z]:[\\\\\\/]/', $normalized)) {
                $normalized = BASE_PATH . DIRECTORY_SEPARATOR . $normalized;
            }
            $dirs[$normalized] = $normalized;
        }

        return array_values($dirs);
    }

    /**
     * @param list<string> $logDirs
     * @param array{non_json:int,json:int,call_log_msg:int,in_range:int,with_fields:int,messages:array<string,int>} $diag
     */
    private function printDiagnostics(array $logDirs, array $diag, string $messageFilter): void
    {
        $this->line(sprintf(
            '诊断: JSON=%d 非JSON=%d | *请求日志=%d | 时间命中=%d | 含 api_interface+duration_ms=%d',
            $diag['json'],
            $diag['non_json'],
            $diag['call_log_msg'],
            $diag['in_range'],
            $diag['with_fields']
        ));

        if ($diag['messages'] !== []) {
            arsort($diag['messages']);
            $top = array_slice($diag['messages'], 0, 8, true);
            $this->line('扫描目录 message 分布（Top）:');
            foreach ($top as $msg => $cnt) {
                $this->line(sprintf('  %6d  %s', $cnt, $msg));
            }
        }

        $hasAdv = false;
        foreach ($logDirs as $logDir) {
            if (basename($logDir) === 'adv') {
                $hasAdv = true;
                break;
            }
        }
        if ($hasAdv || isset($diag['messages']['第三方平台接口异常'])) {
            $this->warn('logs/adv 是 AdvException（第三方平台接口异常），不含调用耗时。');
            $this->line('请改扫含「*请求日志」的目录，例如:');
            $this->line('  --log-dir=runtime/app_a/logs/app --log-dir=runtime/app_b/logs/app');
        } elseif ($diag['call_log_msg'] === 0 && $messageFilter === '') {
            $this->warn('未出现 message 以「请求日志」结尾的行；确认调用日志是否写在这些目录。');
        } elseif ($diag['call_log_msg'] > 0 && $diag['with_fields'] === 0) {
            $this->warn('有请求日志，但缺少 api_interface / duration_ms 字段，请抽一行 JSON 核对字段名。');
        }
    }

    private function parseBound(string $value, bool $endOfDayIfDateOnly): Carbon
    {
        $carbon = Carbon::parse($value);
        // 仅日期时：from 取当天 00:00:00，to 取当天 23:59:59
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value)) === 1) {
            return $endOfDayIfDateOnly ? $carbon->endOfDay() : $carbon->startOfDay();
        }

        return $carbon;
    }

    private function isCallLogMessage(string $message, string $messageFilter): bool
    {
        if ($messageFilter !== '') {
            return $message === $messageFilter;
        }
        // 默认：facebook请求日志 / tiktok请求日志 等；排除失败兜底的「接口日志」以免双计
        return $message !== '' && str_ends_with($message, '请求日志');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function rowTime(array $row): ?Carbon
    {
        if (! empty($row['created_at']) && is_string($row['created_at'])) {
            return Carbon::parse($row['created_at']);
        }
        if (! empty($row['@timestamp']) && is_string($row['@timestamp'])) {
            return Carbon::parse($row['@timestamp']);
        }

        return null;
    }

    /**
     * @param list<float> $sorted 已升序
     */
    private function percentile(array $sorted, float $p): float
    {
        $n = count($sorted);
        if ($n === 0) {
            return 0.0;
        }
        if ($n === 1) {
            return $sorted[0];
        }
        $idx = ($n - 1) * $p;
        $lo = (int) floor($idx);
        $hi = (int) ceil($idx);
        if ($lo === $hi) {
            return $sorted[$lo];
        }
        $w = $idx - $lo;

        return $sorted[$lo] * (1 - $w) + $sorted[$hi] * $w;
    }

    private function formatDuration(float $ms, string $unit): string
    {
        if ($unit === 'ms') {
            return sprintf('%.0fms', $ms);
        }
        if ($ms < 100) {
            return '< 0.1s';
        }

        return sprintf('%.1fs', $ms / 1000);
    }
}
