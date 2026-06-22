<?php

namespace Goletter\Server\Casts;

use Hyperf\Contract\CastsAttributes;
use Hyperf\Filesystem\FilesystemFactory;
use Hyperf\Collection\Arr;
use League\Flysystem\FilesystemOperator;
use Psr\Container\ContainerInterface;
use function Hyperf\Support\env;

class ResourceUrl implements CastsAttributes
{
    protected FilesystemOperator $filesystem;

    public function __construct(
        protected string $type = 'string',
        protected string $disk = 'oss',
        protected ?ContainerInterface $container = null
    ) {
        $factory = $this->container?->get(FilesystemFactory::class) ?? \Hyperf\Context\ApplicationContext::getContainer()->get(FilesystemFactory::class);

        $this->filesystem = $factory->get($this->disk);
    }

    public function get($model, string $key, mixed $value, array $attributes): mixed
    {
        return match ($this->type) {
            'array' => array_map([$this, 'getUrl'], json_decode($value ?? '[]', true) ?? Arr::wrap($value)),
            default => $this->getUrl($value ?? ''),
        };
    }

    public function set($model, string $key, mixed $value, array $attributes): mixed
    {
        if ($this->type == 'array') {
            if (is_string($value)) {
                $value = json_decode($value, true) ?? Arr::wrap($value);
            }
            assert(is_array($value));

            return json_encode(array_map([$this, 'setUrl'], $value));
        }

        return $this->setUrl($value);
    }

    protected function isUrl(string $url): bool
    {
        return preg_match('/^\s*(https?)?:\/\//i', $url);
    }

    protected function getUrl(string $path): string
    {
        if ($this->isUrl($path)) {
            return $path;
        }

        return $path ? sprintf(
            'https://%s.%s/%s',
            env('OSS_BUCKET'),
            env('OSS_ENDPOINT'),
            ltrim($path, '/')
        ) : $path;
    }

    protected function setUrl(string $path): string
    {
        if ($this->isUrl($path)) {
            return ltrim(parse_url($path)['path'], '\/');
        }

        return ltrim(trim($path), '/');
    }
}
