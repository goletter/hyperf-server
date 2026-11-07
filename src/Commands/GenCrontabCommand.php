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

use Hyperf\Command\Annotation\Command;
use Hyperf\Stringable\Str;
use RuntimeException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Throwable;

/**
 * gen:crontab 生成定时任务两件套：app/Crontab/{Name}Crontab.php（#[Crontab] 注解壳）
 * + app/Command/{Name}Command.php（任务骨架，继承 BaseCommand）。
 *
 * 产出形态调整改 stubs/crontab/*.stub，勿改本类渲染逻辑。
 */
#[Command]
final class GenCrontabCommand extends BaseCommand
{
    /** 两件套 kind 清单 = --only 合法取值。 */
    private const KINDS = ['crontab', 'command'];

    public function __construct()
    {
        parent::__construct('gen:crontab');
    }

    public function configure(): void
    {
        parent::configure();
        $this->setDescription('生成定时任务两件套（Crontab 注解壳 + Command 任务骨架）');
        $this->setHelp(<<<'HELP'
生成 app/Crontab/{Name}Crontab.php + app/Command/{Name}Command.php。

示例
  php bin/hyperf.php gen:crontab google_token --rule="0 0 2 * * *" --memo="Google Token 刷新"
  php bin/hyperf.php gen:crontab google_token --force
  php bin/hyperf.php gen:crontab google_token --only=command

新类需删 runtime/container 后才认得到；常驻 worker 另需重启。
排障可先手动：php bin/hyperf.php {任务签名}
HELP);
        $this->addArgument('name', InputArgument::REQUIRED, '任务名（如 google_token），推导 GoogleTokenCrontab/GoogleTokenCommand');
        $this->addOption('rule', null, InputOption::VALUE_OPTIONAL, 'cron 调度规则，5/6 段（6 段含秒）', '0 */30 * * * *');
        $this->addOption('memo', null, InputOption::VALUE_OPTIONAL, '一句话说明（进 memo 与类注释），缺省回填任务名', '');
        $this->addOption('command', null, InputOption::VALUE_OPTIONAL, '壳 callback 指向的任务签名，默认 task:{kebab(类名)}', '');
        $this->addOption('force', 'f', InputOption::VALUE_NONE, '覆盖全部已存在的生成文件');
        $this->addOption('only', null, InputOption::VALUE_OPTIONAL, '强制重新生成指定件（逗号分隔）：crontab,command', '');
    }

    public function handle(): int
    {
        $this->printContext();

        try {
            $name = (string) $this->input->getArgument('name');
            if (! preg_match('/^[A-Za-z0-9_]+$/', $name)) {
                $this->error('任务名只允许字母/数字/下划线: ' . $name);

                return self::FAILURE;
            }

            $rule = trim((string) $this->input->getOption('rule'));
            if (preg_match('/^\S+(\s+\S+){4,5}$/', $rule) !== 1) {
                $this->error('cron 规则需 5 或 6 段（空格分隔，6 段含秒）: ' . $rule);

                return self::FAILURE;
            }

            $onlyRaw = trim((string) $this->input->getOption('only'));
            $only = $onlyRaw === '' ? [] : array_map('trim', explode(',', strtolower($onlyRaw)));
            $unknown = array_diff($only, self::KINDS);
            if ($unknown !== []) {
                $this->error('--only 取值无效: ' . implode(',', $unknown) . '（可选：' . implode(',', self::KINDS) . '）');

                return self::FAILURE;
            }

            $class = Str::studly($name);
            $memoOption = trim((string) $this->input->getOption('memo'));
            $memo = $memoOption === '' ? $name : $memoOption;
            $commandOption = trim((string) $this->input->getOption('command'));
            $command = $commandOption === '' ? 'task:' . Str::kebab($class) : $commandOption;

            $created = $this->render($class, $rule, $memo, $command, $only);

            foreach ($created as $path => $status) {
                $status === 'created'
                    ? $this->info('生成 ' . $path)
                    : $this->warn('跳过（已存在，--force/--only 覆盖）' . $path);
            }

            $this->comment(sprintf(
                '完成：%sCrontab 按 %s 调度 %s（手动排障：php bin/hyperf.php %s）。新类需删 runtime/container 后才认得到，常驻 worker 另需重启。',
                $class,
                $rule,
                $command,
                $command
            ));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * 渲染两件套；--only 命中的件强制覆盖，其余已存在则跳过（--force 全量覆盖）。
     *
     * @param list<string> $only
     *
     * @return array<string, string> 路径 => created|skipped
     */
    private function render(string $class, string $rule, string $memo, string $command, array $only): array
    {
        $force = (bool) $this->input->getOption('force');
        $comment = str_replace('*/', '* /', $memo);
        $stubDir = __DIR__ . '/stubs/crontab/';

        $specs = [
            [
                'kind' => 'crontab',
                'stub' => 'crontab.stub',
                'path' => '/app/Crontab/' . $class . 'Crontab.php',
                'ns' => 'App\Crontab',
                'class' => $class . 'Crontab',
                'vars' => [
                    '{{memo_comment}}' => $comment,
                    '{{commandClass}}' => $class . 'Command',
                    '{{rule}}' => addslashes($rule),
                    '{{command}}' => addslashes($command),
                    '{{memo}}' => addslashes($memo),
                ],
            ],
            [
                'kind' => 'command',
                'stub' => 'command.stub',
                'path' => '/app/Command/' . $class . 'Command.php',
                'ns' => 'App\Command',
                'class' => $class . 'Command',
                'vars' => [
                    '{{memo_comment}}' => $comment,
                    '{{crontabClass}}' => $class . 'Crontab',
                    '{{rule_comment}}' => str_replace('*/', '* /', $rule),
                    '{{command}}' => addslashes($command),
                ],
            ],
        ];

        $result = [];
        foreach ($specs as $spec) {
            $target = BASE_PATH . $spec['path'];
            $overwrite = $force || in_array($spec['kind'], $only, true);
            if (is_file($target) && ! $overwrite) {
                $result[$spec['path']] = 'skipped';

                continue;
            }

            $content = file_get_contents($stubDir . $spec['stub']);
            if ($content === false) {
                throw new RuntimeException('stub 缺失: ' . $spec['stub']);
            }

            $replacements = array_merge($spec['vars'], [
                '{{namespace}}' => $spec['ns'],
                '{{class}}' => $spec['class'],
            ]);
            $content = str_replace(array_keys($replacements), array_values($replacements), $content);

            $dir = dirname($target);
            if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
                throw new RuntimeException('无法创建目录: ' . $dir);
            }
            if (file_put_contents($target, $content) === false) {
                throw new RuntimeException('写入失败: ' . $target);
            }

            $result[$spec['path']] = 'created';
        }

        return $result;
    }
}
