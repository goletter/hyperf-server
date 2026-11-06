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

    public function __construct(protected ContainerInterface $container)
    {
        parent::__construct('goletter:module');
    }

    public function configure()
    {
        parent::configure();
        $this->setDescription('生成 Model + Service + Controller + Request + Resource + Filter 六层结构')
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
            );
    }

    public function handle()
    {
        $name = ucfirst((string) $this->input->getArgument('name'));
        $this->version = $this->normalizeVersion($this->input->getOption('api-version'));
        $this->area = $this->normalizeArea((string) $this->input->getOption('area'));

        $this->makeModel($name);
        $this->makeService($name);
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

    protected function makeService(string $name)
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

        $stub = file_get_contents(__DIR__ . '/stubs/service.stub');
        $content = str_replace(
            ['{{namespace}}', '{{class}}', '{{model}}', '{{modelName}}'],
            [$namespace, $class, $name, $modeName],
            $stub
        );

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
