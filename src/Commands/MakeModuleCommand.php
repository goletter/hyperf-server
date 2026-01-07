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
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Input\InputArgument;

#[Command]
class MakeModuleCommand extends HyperfCommand
{
    public function __construct(protected ContainerInterface $container)
    {
        parent::__construct('goletter:module');
    }

    public function configure()
    {
        parent::configure();
        $this->setDescription('Model + Service + Controller + Resource + Filter five-layer structure is generated')
            ->addArgument('name', InputArgument::REQUIRED, 'Module name (e.g., User)');
    }

    public function handle()
    {
        $name = ucfirst($this->input->getArgument('name'));

        $this->makeModel($name);
        $this->makeService($name);
        $this->makeController($name);
        $this->makeResource($name);
        $this->makeFilter($name);

        $this->line("<info>✅ 模块 {$name} 生成完成！</info>");
    }

    protected function makeModel(string $name)
    {
        $namespace = 'App\Model';
        $path = BASE_PATH . "/app/Model/{$name}.php";

        if (file_exists($path)) {
            $this->warn("⚠️ Model 已存在: {$path}");
            return;
        }

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
        $modeName = \Hyperf\Stringable\Str::camel($name);
        $path = BASE_PATH . "/app/Service/{$class}.php";

        if (file_exists($path)) {
            $this->warn("⚠️ Service 已存在: {$path}");
            return;
        }

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
        $namespace = 'App\Controller';
        $class = "{$name}Controller";
        $service = "{$name}Service";
        $modeName = \Hyperf\Stringable\Str::camel($name);
        $path = BASE_PATH . "/app/Controller/{$class}.php";

        if (file_exists($path)) {
            $this->warn("⚠️ Controller 已存在: {$path}");
            return;
        }

        $stub = file_get_contents(__DIR__ . '/stubs/controller.stub');
        $content = str_replace(
            ['{{namespace}}', '{{class}}', '{{service}}', '{{model}}', '{{modelName}}'],
            [$namespace, $class, $service, $name, $modeName],
            $stub
        );

        file_put_contents($path, $content);
        $this->info("✅ 创建 Controller: {$path}");
    }

    protected function makeResource(string $name)
    {
        $namespace = 'App\Resource';
        $class = "{$name}Resource";
        $path = BASE_PATH . "/app/Resource/{$name}Resource.php";

        if (file_exists($path)) {
            $this->warn("⚠️ Resource 已存在: {$path}");
            return;
        }

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

        $stub = file_get_contents(__DIR__ . '/stubs/filter.stub');
        $content = str_replace(
            ['{{namespace}}', '{{class}}', '{{model}}'],
            [$namespace, $class, $name],
            $stub
        );

        file_put_contents($path, $content);
        $this->info("✅ 创建 Filter: {$path}");
    }
}
