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
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Stringable\Str;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

#[Command]
class MakeModuleCommand extends HyperfCommand
{
    /** 归一化后的版本目录名，如 V1；null 表示沿用旧版扁平路径 */
    protected ?string $version = null;

    /** api | admin，仅在指定 --version 时生效 */
    protected string $area = 'api';

    /** Logic 默认用例 */
    private const LOGIC_ACTIONS = ['index', 'detail', 'create', 'update'];

    public function __construct(protected ContainerInterface $container)
    {
        parent::__construct('goletter:module');
    }

    public function configure()
    {
        parent::configure();
        $this->setDescription('生成 Model + Logic + Service + Controller + Request + Resource + Filter')
            ->addArgument('name', InputArgument::REQUIRED, '模块名（如 User）')
            ->addOption(
                'api-version',
                null,
                InputOption::VALUE_REQUIRED,
                'API 版本，如 v1 / V2；不传默认 v1（与现有 Api\\V1 一致）；传 none 则生成旧版扁平目录'
            )
            ->addOption(
                'area',
                null,
                InputOption::VALUE_REQUIRED,
                '作用域：api 或 admin（扁平模式忽略）',
                'api'
            )
            ->addOption(
                'skip-logic',
                null,
                InputOption::VALUE_NONE,
                '不生成 Logic / Trait（Service 仍按旧内联风格，需自行调整）'
            );
    }

    public function handle()
    {
        $name = Str::studly((string) $this->input->getArgument('name'));
        $this->version = $this->normalizeVersion($this->input->getOption('api-version'));
        $this->area = $this->normalizeArea((string) $this->input->getOption('area'));
        $skipLogic = (bool) $this->input->getOption('skip-logic');

        $this->makeModel($name);
        if (! $skipLogic) {
            $this->makeLogic($name);
        }
        $this->makeService($name, ! $skipLogic);
        $this->makeRequest($name);
        $this->makeController($name);
        $this->makeResource($name);
        $this->makeFilter($name);

        $hint = $this->version === null
            ? '（无版本，扁平目录）'
            : sprintf('（%s / %s）', $this->area, $this->version);
        $this->line("<info>✅ 模块 {$name} 生成完成！{$hint}</info>");
    }

    /** 默认 API 版本（未传 --api-version 时使用）。 */
    protected function defaultApiVersion(): string
    {
        return 'V1';
    }

    protected function makeModel(string $name)
    {
        $namespace = 'App\Model';
        $path = BASE_PATH . "/app/Model/{$name}.php";

        if (file_exists($path)) {
            $this->warn("⚠️ Model 已存在: {$path}");
            return;
        }

        $this->ensureDirectory(dirname($path));

        $stub = file_get_contents(__DIR__ . '/stubs/model.stub');
        $content = str_replace(
            ['{{namespace}}', '{{class}}'],
            [$namespace, $name],
            $stub
        );

        file_put_contents($path, $content);
        $this->info("✅ 创建 Model: {$path}");
    }

    protected function makeLogic(string $name): void
    {
        $namespace = 'App\\Logic\\' . $name;
        $dir = BASE_PATH . '/app/Logic/' . $name;
        $this->ensureDirectory($dir);

        $author = 'yong';
        $date = date('Y-m-d');
        $modelName = Str::camel($name);

        foreach (self::LOGIC_ACTIONS as $action) {
            $studly = Str::studly($action);
            $class = $studly . 'Logic';
            $path = $dir . '/' . $class . '.php';
            if (file_exists($path)) {
                $this->warn("⚠️ Logic 已存在: {$path}");
                continue;
            }

            $stub = file_get_contents(__DIR__ . '/stubs/logic/' . $action . '.stub');
            $content = str_replace(
                [
                    '{{namespace}}',
                    '{{class}}',
                    '{{model}}',
                    '{{modelName}}',
                    '{{author}}',
                    '{{date}}',
                ],
                [$namespace, $class, $name, $modelName, $author, $date],
                $stub
            );
            file_put_contents($path, $content);
            $this->info("✅ 创建 Logic: {$path}");
        }

        $this->makeLogicTrait($name, $namespace, $dir);
    }

    protected function makeLogicTrait(string $name, string $namespace, string $dir): void
    {
        $traitClass = $name . 'Trait';
        $path = $dir . '/' . $traitClass . '.php';
        $existed = file_exists($path);

        $injectsMap = [];
        if ($existed) {
            $code = (string) file_get_contents($path);
            if (preg_match_all('/private\s+(\w+Logic)\s+\$(\w+)\s*;/', $code, $m, PREG_SET_ORDER)) {
                foreach ($m as $row) {
                    $injectsMap[$row[1]] = $row[2];
                }
            }
        }

        foreach (self::LOGIC_ACTIONS as $action) {
            $logicClass = Str::studly($action) . 'Logic';
            $prop = Str::camel(Str::studly($action)) . 'Logic';
            $injectsMap[$logicClass] = $prop;
        }

        $lines = [];
        foreach ($injectsMap as $logicClass => $prop) {
            $lines[] = '    #[Inject]';
            $lines[] = "    private {$logicClass} \${$prop};";
            $lines[] = '';
        }
        $injects = rtrim(implode("\n", $lines));

        $stub = file_get_contents(__DIR__ . '/stubs/logic/trait.stub');
        $content = str_replace(
            ['{{namespace}}', '{{class}}', '{{injects}}'],
            [$namespace, $traitClass, $injects === '' ? '' : $injects . "\n"],
            $stub
        );
        file_put_contents($path, $content);
        $this->info(($existed ? '✅ 更新 Trait: ' : '✅ 创建 Trait: ') . $path);
    }

    protected function makeService(string $name, bool $withLogic = true)
    {
        $namespace = 'App\Service';
        $class = "{$name}Service";
        $modeName = Str::camel($name);
        $path = BASE_PATH . "/app/Service/{$class}.php";

        if (file_exists($path)) {
            $this->warn("⚠️ Service 已存在: {$path}");
            return;
        }

        $this->ensureDirectory(dirname($path));

        if ($withLogic) {
            $stub = file_get_contents(__DIR__ . '/stubs/service.stub');
            $content = str_replace(
                [
                    '{{namespace}}',
                    '{{class}}',
                    '{{model}}',
                    '{{modelName}}',
                    '{{trait}}',
                    '{{author}}',
                    '{{date}}',
                ],
                [
                    $namespace,
                    $class,
                    $name,
                    $modeName,
                    $name . 'Trait',
                    'yong',
                    date('Y-m-d'),
                ],
                $stub
            );
        } else {
            // 兼容旧版内联 Service（无 Logic）
            $stub = file_get_contents(__DIR__ . '/stubs/service_inline.stub');
            if ($stub === false) {
                $stub = file_get_contents(__DIR__ . '/stubs/service.stub');
            }
            $content = str_replace(
                ['{{namespace}}', '{{class}}', '{{model}}', '{{modelName}}', '{{trait}}', '{{author}}', '{{date}}'],
                [$namespace, $class, $name, $modeName, $name . 'Trait', 'yong', date('Y-m-d')],
                $stub
            );
        }

        file_put_contents($path, $content);
        $this->info("✅ 创建 Service: {$path}");
    }

    protected function makeController(string $name)
    {
        [$namespace, $relativeDir] = $this->versionedNamespace('Controller');
        [$requestNamespace] = $this->versionedNamespace('Request');
        $class = "{$name}Controller";
        $service = "{$name}Service";
        $request = "{$name}Request";
        $modeName = Str::camel($name);
        $path = BASE_PATH . "/app/{$relativeDir}/{$class}.php";

        if (file_exists($path)) {
            $this->warn("⚠️ Controller 已存在: {$path}");
            return;
        }

        $this->ensureDirectory(dirname($path));

        $stub = file_get_contents(__DIR__ . '/stubs/controller.stub');
        $content = str_replace(
            [
                '{{namespace}}',
                '{{class}}',
                '{{service}}',
                '{{model}}',
                '{{modelName}}',
                '{{requestNamespace}}',
                '{{request}}',
                '{{desc}}',
                '{{author}}',
                '{{date}}',
            ],
            [
                $namespace,
                $class,
                $service,
                $name,
                $modeName,
                $requestNamespace,
                $request,
                $name,
                'yong',
                date('Y-m-d'),
            ],
            $stub
        );

        file_put_contents($path, $content);
        $this->info("✅ 创建 Controller: {$path}");
    }

    protected function makeRequest(string $name)
    {
        [$namespace, $relativeDir] = $this->versionedNamespace('Request');
        $class = "{$name}Request";
        $path = BASE_PATH . "/app/{$relativeDir}/{$class}.php";

        if (file_exists($path)) {
            $this->warn("⚠️ Request 已存在: {$path}");
            return;
        }

        $this->ensureDirectory(dirname($path));

        $stub = file_get_contents(__DIR__ . '/stubs/request.stub');
        $content = str_replace(
            ['{{namespace}}', '{{class}}'],
            [$namespace, $class],
            $stub
        );

        file_put_contents($path, $content);
        $this->info("✅ 创建 Request: {$path}");
    }

    protected function makeResource(string $name)
    {
        [$namespace, $relativeDir] = $this->versionedNamespace('Resource');
        $class = "{$name}Resource";
        $path = BASE_PATH . "/app/{$relativeDir}/{$class}.php";

        if (file_exists($path)) {
            $this->warn("⚠️ Resource 已存在: {$path}");
            return;
        }

        $this->ensureDirectory(dirname($path));

        $stub = file_get_contents(__DIR__ . '/stubs/resource.stub');
        $content = str_replace(
            ['{{namespace}}', '{{class}}', '{{model}}'],
            [$namespace, $class, $name],
            $stub
        );

        file_put_contents($path, $content);
        $this->info("✅ 创建 Resource: {$path}");
    }

    protected function makeFilter(string $name)
    {
        $namespace = 'App\Model\Filters';
        $class = "{$name}Filter";
        $path = BASE_PATH . "/app/Model/Filters/{$name}Filter.php";

        if (file_exists($path)) {
            $this->warn("⚠️ Filter 已存在: {$path}");
            return;
        }

        $this->ensureDirectory(dirname($path));

        $stub = file_get_contents(__DIR__ . '/stubs/filter.stub');
        $content = str_replace(
            ['{{namespace}}', '{{class}}', '{{model}}'],
            [$namespace, $class, $name],
            $stub
        );

        file_put_contents($path, $content);
        $this->info("✅ 创建 Filter: {$path}");
    }

    /**
     * @return array{0: string, 1: string} [namespace, relativeDir under app/]
     */
    protected function versionedNamespace(string $layer): array
    {
        // 未指定版本：保持历史行为（扁平）
        if ($this->version === null) {
            return ["App\\{$layer}", $layer];
        }

        $areaStudly = Str::studly($this->area);

        return [
            "App\\{$layer}\\{$areaStudly}\\{$this->version}",
            "{$layer}/{$areaStudly}/{$this->version}",
        ];
    }

    /**
     * null = 显式 none（扁平）；否则返回 V1 / V2 ...
     * 未传 --api-version 时用 defaultApiVersion()（当前默认 V1）。
     */
    protected function normalizeVersion(mixed $version): ?string
    {
        if ($version === null || $version === false || $version === '') {
            return $this->defaultApiVersion();
        }

        $version = strtolower(trim((string) $version));
        if ($version === 'none' || $version === 'flat' || $version === '0') {
            return null;
        }

        if (preg_match('/^v?(\d+)$/i', $version, $m)) {
            return 'V' . $m[1];
        }

        return Str::studly($version);
    }

    protected function normalizeArea(string $area): string
    {
        $area = strtolower(trim($area));
        if (! in_array($area, ['api', 'admin'], true)) {
            $this->warn("⚠️ 未知 area={$area}，已回退为 api");

            return 'api';
        }

        return $area;
    }

    protected function ensureDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
}
