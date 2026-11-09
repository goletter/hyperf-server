<?php

declare(strict_types=1);

namespace Goletter\Server\Http;

use Hyperf\Contract\ConfigInterface;
use Hyperf\DbConnection\Model\Model;
use Hyperf\HttpMessage\Exception\NotFoundHttpException;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\Stringable\Str;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use Hyperf\Validation\Request\FormRequest;
use Hyperf\Validation\ValidationException;
use Psr\Container\ContainerInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * 多版本资源网关基类：/api/{resource}/... → {Namespace}\V{n}\{Resource}Controller.
 *
 * 版本列表优先读 config/autoload/api_dispatcher.php（vendor:publish id=api_dispatcher）；
 * 应用侧薄封装只需设置 $controllerNamespace，例如：
 * - App\Controller\Api\ApiController → App\Controller\Api
 * - App\Controller\Admin\ApiController → App\Controller\Admin
 */
abstract class BaseDispatcherController
{
    /**
     * 版本从新到旧（配置缺失时的回退；优先 api_dispatcher.versions）。
     *
     * @var string[]
     */
    protected array $versions = ['v3', 'v2', 'v1'];

    /** 配置缺失时的回退；优先 api_dispatcher.version_header */
    protected string $versionHeader = 'X-API-Version';

    /**
     * 未传版本头时的默认版本；空则取 versions 第一个（最新）。
     * 优先 api_dispatcher.default。
     */
    protected string $defaultVersion = '';

    /** 资源名，空则从类名推断：UserController -> User */
    protected string $resourceName = '';
    /**
     * 版本实现所在命名空间前缀。
     * - API：App\Controller\Api → App\Controller\Api\V1\UserController
     * - Admin：App\Controller\Admin → App\Controller\Admin\V1\UserController.
     */
    protected string $controllerNamespace = 'App\Controller';

    /**
     * HTTP 方法 => [集合动作, 单资源动作]（主动作名，别名由 $actionGroups 展开）.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    protected array $httpActions = [
        'get' => ['index', 'index'],
        'post' => ['create', 'create'],
        'put' => ['update', 'update'],
        'patch' => ['patch', 'patch'],
        'delete' => ['delete', 'delete'],
    ];

    /**
     * 同义方法组。命中组内任一名称时，以当前动作为先，再按组顺序尝试其余。
     *
     * @var list<list<string>>
     */
    protected array $actionGroups = [
        ['index', 'index'],
        ['get', 'show'],
        ['create', 'store'],
        ['patch', 'update'],
        ['delete', 'destroy'],
    ];

    public function __construct(protected ContainerInterface $container)
    {
        if ($this->resourceName === '') {
            $this->resourceName = $this->guessResourceName();
        }
    }

    /**
     * 统一资源网关入口：/api/{resource}/... → Vn\{Resource}Controller
     * users → User，order-items → OrderItem.
     */
    protected function handleResource(
        RequestInterface $request,
        string $resource,
        ?string $seg1 = null,
        ?string $seg2 = null
    ): mixed {
        $this->resourceName = Str::studly(Str::singular($resource));

        return $this->dispatch($request, $seg1, $seg2);
    }

    /**
     * 约定：
     * - /resource                  REST 集合
     * - /resource/{id}             REST 单资源（id 默认可为数字）
     * - /resource/{action}         自定义动作
     * - /resource/{id}/{action}    带 id 的自定义动作
     * - /resource/{action}/{id}    同上（动作在前）.
     */
    protected function dispatch(RequestInterface $request, ?string $seg1 = null, ?string $seg2 = null): mixed
    {
        [$action, $id] = $this->resolveRoute($request, $seg1, $seg2);

        return $this->dispatchAction($request, $action, $id);
    }

    /**
     * @return array{0: string, 1: null|string}
     */
    protected function resolveRoute(RequestInterface $request, ?string $seg1 = null, ?string $seg2 = null): array
    {
        if ($seg1 === null) {
            return [$this->restAction($request->getMethod(), false), null];
        }

        if ($seg2 === null) {
            if ($this->isResourceId($seg1)) {
                return [$this->restAction($request->getMethod(), true), $seg1];
            }

            return [$this->normalizeAction($seg1), null];
        }

        if ($this->isResourceId($seg1)) {
            return [$this->normalizeAction($seg2), $seg1];
        }

        if ($this->isResourceId($seg2)) {
            return [$this->normalizeAction($seg1), $seg2];
        }

        // export/csv -> exportCsv
        return [$this->normalizeAction($seg1 . '-' . $seg2), null];
    }

    /**
     * 多版本降级分发：v3 无方法 → 试 v2 → 再试 v1。
     * 只认「该版本类自己声明」的方法，继承自父类的不算已实现。
     */
    protected function dispatchAction(RequestInterface $request, string $action, ?string $id = null): mixed
    {
        $version = $request->getHeaderLine($this->getVersionHeader()) ?: $this->resolveDefaultVersion();

        foreach ($this->getFallbackChain($version) as $ver) {
            $class = $this->controllerClass($ver);
            if (! class_exists($class)) {
                continue;
            }

            foreach ($this->actionCandidates($action) as $method) {
                if (! $this->versionHasAction($class, $method)) {
                    continue;
                }

                $controller = $this->container->get($class);

                return $this->invoke($controller, $method, $request, $id);
            }
        }

        return $this->notImplemented($action, $id);
    }

    /**
     * 方法是否由该版本 Controller 自身声明（排除父类 / Trait 继承）。
     */
    protected function versionHasAction(string $class, string $method): bool
    {
        if (! method_exists($class, $method)) {
            return false;
        }

        $ref = new ReflectionMethod($class, $method);

        return $ref->getDeclaringClass()->getName() === ltrim($class, '\\');
    }

    /**
     * @return string[]
     */
    protected function getVersions(): array
    {
        $configured = $this->dispatcherConfig('versions');
        if (is_array($configured) && $configured !== []) {
            return array_values(array_map('strval', $configured));
        }

        return $this->versions;
    }

    protected function getVersionHeader(): string
    {
        $configured = $this->dispatcherConfig('version_header');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return $this->versionHeader;
    }

    protected function resolveDefaultVersion(): string
    {
        $configured = $this->dispatcherConfig('default');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        if ($this->defaultVersion !== '') {
            return $this->defaultVersion;
        }

        return $this->getVersions()[0] ?? 'v1';
    }

    protected function dispatcherConfig(string $key): mixed
    {
        if (! $this->container->has(ConfigInterface::class)) {
            return null;
        }

        return $this->container->get(ConfigInterface::class)->get('api_dispatcher.' . $key);
    }

    /**
     * 无显式动作时按 HTTP 方法取主动作；未知方法直接用小写方法名。
     */
    protected function restAction(string $method, bool $hasId): string
    {
        $method = strtolower($method);
        $pair = $this->httpActions[$method] ?? [$method, $method];

        return $hasId ? $pair[1] : $pair[0];
    }

    /**
     * 同一语义的方法名候选（当前动作优先）。
     *
     * @return string[]
     */
    protected function actionCandidates(string $action): array
    {
        foreach ($this->actionGroups as $group) {
            if (in_array($action, $group, true)) {
                return array_values(array_unique([$action, ...$group]));
            }
        }

        return [$action];
    }

    protected function isResourceId(string $segment): bool
    {
        return (bool) preg_match('/^\d+$/', $segment);
    }

    /** send-email / send_email -> sendEmail */
    protected function normalizeAction(string $action): string
    {
        $action = str_replace(['-', '_'], ' ', strtolower($action));

        return lcfirst(str_replace(' ', '', ucwords($action)));
    }

    /**
     * @return string[]
     */
    protected function getFallbackChain(string $version): array
    {
        $version = strtolower($version);
        if ($version !== '' && $version[0] !== 'v') {
            $version = 'v' . $version;
        }

        $all = array_map('strtolower', $this->getVersions());
        $idx = array_search($version, $all, true);

        if ($idx === false) {
            $default = strtolower($this->resolveDefaultVersion());
            $idx = array_search($default, $all, true);
            if ($idx === false) {
                return $all;
            }
        }

        // 从请求版本起，向更旧版本降级：v3 → v2 → v1
        return array_slice($all, $idx);
    }

    protected function controllerClass(string $version): string
    {
        return sprintf(
            '%s\%s\%sController',
            rtrim($this->controllerNamespace, '\\'),
            strtoupper($version),
            $this->resourceName
        );
    }

    protected function guessResourceName(): string
    {
        $short = (new ReflectionClass($this))->getShortName();

        return str_ends_with($short, 'Controller')
            ? substr($short, 0, -10)
            : $short;
    }

    /**
     * 按目标方法签名注入 $request / $id，避免多余参数报错。
     */
    protected function invoke(object $controller, string $method, RequestInterface $request, ?string $id): mixed
    {
        $ref = new ReflectionMethod($controller, $method);
        $args = [];
        $idUsed = false;

        foreach ($ref->getParameters() as $param) {
            $type = $param->getType();
            $typeName = $type instanceof ReflectionNamedType ? $type->getName() : null;
            $name = $param->getName();

            // FormRequest 也实现 RequestInterface，须先于通用 Request 注入。
            // 网关分发不会经过 ValidationMiddleware（它只看路由到的 ApiController），需在此主动校验。
            if ($typeName && is_a($typeName, FormRequest::class, true)) {
                /** @var FormRequest $formRequest */
                $formRequest = $this->container->get($typeName);
                $this->validate(
                    $formRequest->all(),
                    $formRequest->rules(),
                    $formRequest->messages(),
                    $formRequest->attributes()
                );
                $args[] = $formRequest;
                continue;
            }

            if ($name === 'request' || ($typeName && is_a($typeName, RequestInterface::class, true))) {
                $args[] = $request;
                continue;
            }

            if ($typeName && is_a($typeName, Model::class, true) && $id !== null && ! $idUsed) {
                /** @var class-string<Model> $typeName */
                $model = $typeName::query()->find($id);
                if (! $model) {
                    throw new NotFoundHttpException(sprintf('%s not found for id %s', $typeName, $id));
                }
                $args[] = $model;
                $idUsed = true;
                continue;
            }

            if (! $idUsed && $id !== null && (
                in_array($name, ['id', 'resourceId'], true)
                || in_array($typeName, ['int', 'string'], true)
                || $typeName === null
            )) {
                $args[] = $typeName === 'int' ? (int) $id : $id;
                $idUsed = true;
                continue;
            }

            if ($typeName && class_exists($typeName) && $this->container->has($typeName)) {
                $args[] = $this->container->get($typeName);
                continue;
            }

            if ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
                continue;
            }

            if ($param->allowsNull()) {
                $args[] = null;
                continue;
            }

            break;
        }

        return $controller->{$method}(...$args);
    }

    protected function notImplemented(string $action, ?string $id = null): array
    {
        return [
            'code' => 404,
            'message' => sprintf(
                'API method [%s]%s not implemented',
                $action,
                $id !== null ? " id={$id}" : ''
            ),
        ];
    }

    /**
     * @param mixed ...$arg
     */
    protected function validate(...$arg): void
    {
        $validator = $this->container->get(ValidatorFactoryInterface::class)->make(...$arg);
        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }
}
