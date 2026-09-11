<?php

declare(strict_types=1);
/**
 * This file is part of goletter/mail.
 *
 * @link     https://github.com/goletter/hyperf-mail
 * @contact  goletter@outlook.com
 * @license  https://github.com/goletter/hyperf-mail/blob/master/LICENSE
 */
namespace Goletter\Server;

use Goletter\Server\Commands\MakeModuleCommand;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [],
            'commands' => [
                MakeModuleCommand::class,
            ],
            'listeners' => [
            ],
            'publish' => [
                [
                    'id' => 'queue_serial',
                    'description' => 'Serial queue (pushSerial) concurrency config.',
                    'source' => __DIR__ . '/../publish/queue_serial.php',
                    'destination' => BASE_PATH . '/config/autoload/queue_serial.php',
                ],
            ],
        ];
    }
}