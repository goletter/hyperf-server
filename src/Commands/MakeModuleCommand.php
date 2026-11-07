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

        $columns = $this->resolveTableColumns($name);
        $methods = $this->buildFilterMethods($columns);
        if ($methods === '') {
            $this->warn("⚠️ 未从 migrations 解析到 {$name} 表字段，已生成空 Filter（可补迁移后 --force 重生成）");
        } else {
            $this->line(sprintf('   Filter 字段：%s', implode(', ', array_keys($columns))));
        }

        $stub = file_get_contents(__DIR__ . '/stubs/filter.stub');
        $content = str_replace(
            ['{{namespace}}', '{{class}}', '{{model}}', '{{author}}', '{{date}}', '{{methods}}'],
            [$namespace, $class, $name, 'yong', date('Y-m-d'), $methods],
            $stub
        );

        file_put_contents($path, $content);
        $this->info("✅ 创建 Filter: {$path}");
    }

    /**
     * 从 migrations 合并 create/alter，推断表字段及类型。
     *
     * @return array<string, string> column => blueprint method（string/integer/...）
     */
    protected function resolveTableColumns(string $modelBase): array
    {
        if (! defined('BASE_PATH')) {
            return [];
        }
        $table = Str::plural(Str::snake($modelBase));
        $dir = BASE_PATH . '/migrations';
        if ($table === '' || ! is_dir($dir)) {
            return [];
        }

        $files = array_values(array_unique(array_merge(
            glob($dir . '/*_create_' . $table . '_table.php') ?: [],
            glob($dir . '/*_to_' . $table . '_table.php') ?: [],
            glob($dir . '/*_' . $table . '_table.php') ?: [],
        )));
        if ($files === []) {
            $files = glob($dir . '/*' . $table . '*.php') ?: [];
        }
        if ($files === []) {
            return [];
        }
        sort($files);

        $columns = [];
        foreach ($files as $file) {
            $code = @file_get_contents((string) $file);
            if ($code === false) {
                continue;
            }
            $up = $this->extractMigrationUpBody($code);
            foreach ($this->parseMigrationColumns($up !== '' ? $up : $code) as $field => $type) {
                if ($type === '__drop__') {
                    unset($columns[$field]);
                    continue;
                }
                $columns[$field] = $type;
            }
        }

        return $columns;
    }

    protected function extractMigrationUpBody(string $code): string
    {
        if (! preg_match('/function\s+up\s*\([^)]*\)\s*(?::\s*\??[\w|\\\\]+\s*)?\{/s', $code, $m, PREG_OFFSET_CAPTURE)) {
            return '';
        }
        $start = (int) $m[0][1] + strlen($m[0][0]);
        if (preg_match('/function\s+down\s*\(/s', $code, $dm, PREG_OFFSET_CAPTURE, $start)) {
            return substr($code, $start, (int) $dm[0][1] - $start);
        }

        return substr($code, $start);
    }

    /**
     * @return array<string, string> field => type|__drop__
     */
    protected function parseMigrationColumns(string $code): array
    {
        $columns = [];
        $skipMethods = [
            'index', 'unique', 'primary', 'foreign', 'timestamps', 'datetimes',
            'softDeletes', 'rememberToken', 'comment', 'renameColumn', 'rename',
        ];
        foreach (preg_split('/\r\n|\n|\r/', $code) ?: [] as $line) {
            if (! preg_match('/\$table->(\w+)\(\s*[\'"]([\w]+)[\'"]/', $line, $m)) {
                continue;
            }
            $method = $m[1];
            $field = $m[2];
            if (in_array($method, $skipMethods, true)) {
                continue;
            }
            if (strcasecmp($method, 'dropColumn') === 0) {
                $columns[$field] = '__drop__';
                continue;
            }
            $columns[$field] = strtolower($method);
        }

        // Db::statement ALTER ... ADD|MODIFY COLUMN `field` TYPE
        if (preg_match_all(
            '/\b(?:ADD|MODIFY)(?:\s+COLUMN)?\s+[`]?(\w+)[`]?\s+(\w+)/is',
            $code,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $row) {
                $columns[$row[1]] = strtolower($row[2]);
            }
        }

        return $columns;
    }

    /**
     * @param array<string, string> $columns
     */
    protected function buildFilterMethods(array $columns): string
    {
        $skipFields = [
            'id', 'created_at', 'updated_at', 'deleted_at',
            'password', 'remember_token', 'token',
        ];
        // json 不生成筛选；仅 varchar 类用 like，其余精确 where
        $skipTypes = ['json', 'jsonb'];
        $likeTypes = ['string', 'varchar', 'char', 'nvarchar'];

        $blocks = [];
        foreach ($columns as $field => $type) {
            $type = strtolower($type);
            if (in_array($field, $skipFields, true) || in_array($type, $skipTypes, true)) {
                continue;
            }
            $method = Str::camel($field);
            if (in_array($type, $likeTypes, true)) {
                $where = '$this->where(\'' . $field . '\', \'like\', "%{$' . $method . '}%");';
            } else {
                $where = '$this->where(\'' . $field . '\', $' . $method . ');';
            }
            $blocks[] = "    public function {$method}(\${$method})\n    {\n        {$where}\n    }";
        }

        return $blocks === [] ? '' : implode("\n\n", $blocks) . "\n";
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
