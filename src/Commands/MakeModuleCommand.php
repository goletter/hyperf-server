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

use Goletter\Server\Support\SqlCommentEnum;
use Hyperf\Command\Annotation\Command;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Stringable\Str;
use PDO;
use PDOException;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Throwable;

#[Command]
class MakeModuleCommand extends HyperfCommand
{
    /** 归一化后的版本目录名，如 V1；null 表示沿用旧版扁平路径 */
    protected ?string $version = null;

    /** api | admin，仅在指定 --version 时生效 */
    protected string $area = 'api';

    /** Logic 默认用例 */
    private const LOGIC_ACTIONS = ['index', 'show', 'create', 'update'];

    public function __construct(protected ContainerInterface $container)
    {
        parent::__construct('gen:crud');
    }

    public function configure()
    {
        parent::configure();
        $this->setDescription('按数据表生成 CRUD 六件套（Model/Filter/Service/Request/Resource/Controller）')
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

        $columns = $this->resolveTableColumnMeta($name);
        $constants = $this->buildEnumConstants($columns);
        if ($constants !== '') {
            $fields = [];
            foreach ($columns as $field => $meta) {
                $base = SqlCommentEnum::normalizeBaseType((string) ($meta['type'] ?? ''));
                if (SqlCommentEnum::tryParse((string) ($meta['comment'] ?? ''), $base) !== null) {
                    $fields[] = $field;
                }
            }
            $this->line(sprintf('   Model 枚举常量：%s', implode(', ', $fields)));
        }

        $stub = file_get_contents(__DIR__ . '/stubs/model.stub');
        $content = str_replace(
            ['{{namespace}}', '{{class}}', '{{constants}}'],
            [$namespace, $name, $constants],
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
                ],
                [$namespace, $class, $name, $modelName],
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
                ],
                [
                    $namespace,
                    $class,
                    $name,
                    $modeName,
                    $name . 'Trait',
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
                ['{{namespace}}', '{{class}}', '{{model}}', '{{modelName}}', '{{trait}}'],
                [$namespace, $class, $name, $modeName, $name . 'Trait'],
                $stub
            );
        }

        file_put_contents($path, $content);
        $this->info("✅ 创建 Service: {$path}");
    }

    protected function makeController(string $name)
    {
        [$namespace, $relativeDir] = $this->versionedNamespace('Controller');
        [$requestBaseNamespace] = $this->versionedNamespace('Request');
        [$resourceNamespace] = $this->versionedNamespace('Resource');
        $requestNamespace = $requestBaseNamespace . '\\' . $name;
        $class = "{$name}Controller";
        $service = "{$name}Service";
        $resource = "{$name}Resource";
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
                '{{resourceNamespace}}',
                '{{resource}}',
                '{{desc}}',
            ],
            [
                $namespace,
                $class,
                $service,
                $name,
                $modeName,
                $requestNamespace,
                $resourceNamespace,
                $resource,
                $name,
            ],
            $stub
        );

        file_put_contents($path, $content);
        $this->info("✅ 创建 Controller: {$path}");
    }

    protected function makeRequest(string $name)
    {
        [$baseNamespace, $relativeDir] = $this->versionedNamespace('Request');
        $namespace = $baseNamespace . '\\' . $name;
        $dir = BASE_PATH . "/app/{$relativeDir}/{$name}";
        $this->ensureDirectory($dir);

        $schema = $this->buildRequestSchema($this->resolveTableColumnMeta($name));
        $specs = [
            'Index' => [
                'rules' => [
                    'page' => 'integer|min:1',
                    'pageSize' => 'integer|min:1|max:100',
                ],
                'attributes' => [
                    'page' => '页码',
                    'pageSize' => '每页数量',
                ],
            ],
            'Show' => [
                'rules' => [
                    'id' => 'required|integer|min:1',
                ],
                'attributes' => [
                    'id' => 'ID',
                ],
            ],
            'Store' => [
                'rules' => $schema['rules_store'],
                'attributes' => $schema['attributes_store'],
            ],
            'Update' => [
                'rules' => $schema['rules_update'],
                'attributes' => $schema['attributes_update'],
            ],
        ];

        $stub = file_get_contents(__DIR__ . '/stubs/request.stub');
        foreach ($specs as $action => $spec) {
            $class = "{$action}{$name}Request";
            $path = "{$dir}/{$class}.php";
            if (file_exists($path)) {
                $this->warn("⚠️ Request 已存在: {$path}");
                continue;
            }
            $content = str_replace(
                ['{{namespace}}', '{{class}}', '{{rules}}', '{{attributes}}'],
                [
                    $namespace,
                    $class,
                    $this->renderPhpStringArray($spec['rules'], 8),
                    $this->renderPhpStringArray($spec['attributes'], 8),
                ],
                $stub
            );
            file_put_contents($path, $content);
            $this->info("✅ 创建 Request: {$path}");
        }
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
            ['{{namespace}}', '{{class}}', '{{model}}', '{{methods}}'],
            [$namespace, $class, $name, $methods],
            $stub
        );

        file_put_contents($path, $content);
        $this->info("✅ 创建 Filter: {$path}");
    }

    /**
     * @return array<string, string> column => type
     */
    protected function resolveTableColumns(string $modelBase): array
    {
        $out = [];
        foreach ($this->resolveTableColumnMeta($modelBase) as $field => $meta) {
            $out[$field] = (string) ($meta['type'] ?? '');
        }

        return $out;
    }

    /**
     * 优先读库表 COMMENT，失败则回退 migrations。
     *
     * @return array<string, array{type: string, comment: string}>
     */
    protected function resolveTableColumnMeta(string $modelBase): array
    {
        $table = Str::snake($modelBase);
        if ($table === '') {
            return [];
        }

        $fromDb = $this->fetchTableColumnsFromDb($table);
        if ($fromDb !== []) {
            return $fromDb;
        }

        return $this->parseMigrationColumnMeta($table);
    }

    /**
     * @return array<string, array{type: string, raw_type: string, comment: string, nullable: bool, default: mixed, key: string, extra: string}>
     */
    protected function fetchTableColumnsFromDb(string $table): array
    {
        try {
            $host = (string) (env('DB_HOST') ?: '127.0.0.1');
            $port = (string) (env('DB_PORT') ?: '3306');
            $database = (string) (env('DB_DATABASE') ?: '');
            $username = (string) (env('DB_USERNAME') ?: 'root');
            $password = (string) (env('DB_PASSWORD') ?: '');
            if ($database === '') {
                return [];
            }

            $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $database);
            $pdo = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $stmt = $pdo->query('SHOW FULL COLUMNS FROM `' . str_replace('`', '``', $table) . '`');
            if ($stmt === false) {
                return [];
            }

            $columns = [];
            foreach ($stmt->fetchAll() as $row) {
                $field = (string) ($row['Field'] ?? '');
                if ($field === '') {
                    continue;
                }
                $rawType = (string) ($row['Type'] ?? '');
                $columns[$field] = [
                    'type' => SqlCommentEnum::normalizeBaseType($rawType),
                    'raw_type' => $rawType,
                    'comment' => trim((string) ($row['Comment'] ?? '')),
                    'nullable' => strtoupper((string) ($row['Null'] ?? 'YES')) === 'YES',
                    'default' => $row['Default'] ?? null,
                    'key' => (string) ($row['Key'] ?? ''),
                    'extra' => (string) ($row['Extra'] ?? ''),
                ];
            }

            return $columns;
        } catch (PDOException|Throwable) {
            return [];
        }
    }

    /**
     * @return array<string, array{type: string, raw_type: string, comment: string, nullable: bool, default: mixed, key: string, extra: string}>
     */
    protected function parseMigrationColumnMeta(string $table): array
    {
        if (! defined('BASE_PATH')) {
            return [];
        }
        $dir = BASE_PATH . '/migrations';
        if (! is_dir($dir)) {
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
            foreach ($this->parseMigrationColumns($up !== '' ? $up : $code) as $field => $meta) {
                if (($meta['type'] ?? '') === '__drop__') {
                    unset($columns[$field]);
                    continue;
                }
                $type = SqlCommentEnum::normalizeBaseType((string) ($meta['type'] ?? ''));
                $columns[$field] = [
                    'type' => $type,
                    'raw_type' => $type,
                    'comment' => (string) ($meta['comment'] ?? ($columns[$field]['comment'] ?? '')),
                    'nullable' => true,
                    'default' => null,
                    'key' => $field === 'id' ? 'PRI' : '',
                    'extra' => $field === 'id' ? 'auto_increment' : '',
                ];
            }
        }

        return $columns;
    }

    /**
     * @param array<string, array{type?: string, raw_type?: string, comment?: string, nullable?: bool, default?: mixed, key?: string, extra?: string}> $columns
     * @return array{rules_store: array<string, string>, rules_update: array<string, string>, attributes_store: array<string, string>, attributes_update: array<string, string>}
     */
    protected function buildRequestSchema(array $columns): array
    {
        $rulesStore = [];
        $rulesUpdate = [];
        $attributes = [];

        foreach ($columns as $field => $meta) {
            $comment = trim((string) ($meta['comment'] ?? ''));
            $attributes[$field] = SqlCommentEnum::label($comment, $field);

            $key = (string) ($meta['key'] ?? '');
            $extra = (string) ($meta['extra'] ?? '');
            if ($key === 'PRI' || str_contains($extra, 'auto_increment')) {
                continue;
            }
            if (in_array($field, ['created_at', 'updated_at', 'deleted_at'], true)) {
                continue;
            }

            $rule = $this->columnValidationRule($meta);
            $required = ! ($meta['nullable'] ?? true) && ($meta['default'] ?? null) === null;
            $rulesStore[$field] = ($required ? 'required|' : '') . $rule;
            $rulesUpdate[$field] = ($required ? 'sometimes|required|' : '') . $rule;
        }

        return [
            'rules_store' => $rulesStore,
            'rules_update' => $rulesUpdate,
            'attributes_store' => array_intersect_key($attributes, $rulesStore),
            'attributes_update' => array_intersect_key($attributes, $rulesUpdate),
        ];
    }

    /**
     * @param array{type?: string, raw_type?: string, comment?: string} $meta
     */
    protected function columnValidationRule(array $meta): string
    {
        $typeMap = [
            'bigint' => 'integer',
            'int' => 'integer',
            'integer' => 'integer',
            'smallint' => 'integer',
            'mediumint' => 'integer',
            'tinyint' => 'integer',
            'decimal' => 'numeric',
            'double' => 'numeric',
            'float' => 'numeric',
            'json' => 'array',
            'date' => 'date',
            'datetime' => 'date',
            'timestamp' => 'date',
        ];

        $base = SqlCommentEnum::normalizeBaseType((string) ($meta['type'] ?? ''));
        $rawType = (string) ($meta['raw_type'] ?? $base);
        $comment = trim((string) ($meta['comment'] ?? ''));

        if (str_contains($comment, '雪花')) {
            return 'string|regex:/^\d+$/';
        }

        $rule = $typeMap[$base] ?? 'string';

        if ($base === 'enum' && preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $rawType, $matches)) {
            return 'string|in:' . implode(',', $matches[1]);
        }

        if (in_array($base, ['varchar', 'char', 'string'], true) && preg_match('/\((\d+)\)/', $rawType, $m)) {
            $rule .= '|max:' . $m[1];
        }

        $parsed = SqlCommentEnum::tryParse($comment, $base);
        if ($parsed !== null) {
            $values = array_map(static fn (array $item): string => (string) $item['value'], $parsed['items']);
            $rule .= '|in:' . implode(',', $values);
        }

        return $rule;
    }

    /**
     * @param array<string, string> $items
     */
    protected function renderPhpStringArray(array $items, int $indent = 8): string
    {
        if ($items === []) {
            return '[]';
        }

        $pad = str_repeat(' ', $indent + 4);
        $close = str_repeat(' ', $indent);
        $lines = [];
        foreach ($items as $key => $value) {
            $lines[] = sprintf("%s'%s' => '%s',", $pad, addslashes((string) $key), addslashes((string) $value));
        }

        return "[\n" . implode("\n", $lines) . "\n{$close}]";
    }

    /**
     * @param array<string, array{type?: string, comment?: string}> $columns
     */
    protected function buildEnumConstants(array $columns): string
    {
        $blocks = '';
        foreach ($columns as $field => $meta) {
            $comment = trim((string) ($meta['comment'] ?? ''));
            $base = SqlCommentEnum::normalizeBaseType((string) ($meta['type'] ?? ''));
            $parsed = SqlCommentEnum::tryParse($comment, $base);
            if ($parsed === null) {
                continue;
            }
            $blocks .= SqlCommentEnum::renderConstants($field, $parsed['items'], $comment);
        }

        return $blocks;
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
     * @return array<string, array{type: string, comment: string}>
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
            $comment = '';
            if (preg_match("/->comment\(\s*['\"]([^'\"]*)['\"]\s*\)/u", $line, $cm)) {
                $comment = $cm[1];
            }
            if (strcasecmp($method, 'dropColumn') === 0) {
                $columns[$field] = ['type' => '__drop__', 'comment' => ''];
                continue;
            }
            $columns[$field] = ['type' => strtolower($method), 'comment' => $comment];
        }

        // Db::statement ALTER ... ADD|MODIFY COLUMN `field` TYPE ... COMMENT '...'
        if (preg_match_all(
            "/\b(?:ADD|MODIFY)(?:\s+COLUMN)?\s+[`]?(\w+)[`]?\s+(\w+)[^;]*?(?:COMMENT\s+['\"]([^'\"]*)['\"])?/is",
            $code,
            $matches,
            PREG_SET_ORDER
        )) {
            foreach ($matches as $row) {
                $columns[$row[1]] = [
                    'type' => strtolower($row[2]),
                    'comment' => isset($row[3]) ? (string) $row[3] : ($columns[$row[1]]['comment'] ?? ''),
                ];
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
            $blocks[] = "    /**\n      * @param mixed \${$method}\n      * @return void\n      */\n    public function {$method}(\${$method})\n    {\n        {$where}\n    }";
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

        return $area;
    }

    protected function ensureDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
}
