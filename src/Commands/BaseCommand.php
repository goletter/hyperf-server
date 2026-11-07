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

use Hyperf\Command\Command as HyperfCommand;

/**
 * 生成任务 / 工具命令基类：统一打印执行上下文。
 */
abstract class BaseCommand extends HyperfCommand
{
    /** 打印命令名与起止时间，便于 crontab / 手工排障对照日志。 */
    protected function printContext(): void
    {
        $name = method_exists($this, 'getName') ? (string) $this->getName() : static::class;
        $this->line(sprintf('[%s] start %s', date('Y-m-d H:i:s'), $name));
    }
}
