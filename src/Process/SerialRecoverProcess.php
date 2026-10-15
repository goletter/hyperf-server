<?php

declare(strict_types=1);

namespace Goletter\Server\Process;

use Goletter\Server\Service\QueueService;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Coroutine\Coroutine;
use Hyperf\Process\AbstractProcess;
use Hyperf\Process\ProcessManager;
use Throwable;

/**
 * Periodically restarts lost serial queue runners (worker killed, push failed after enqueue, ...).
 */
class SerialRecoverProcess extends AbstractProcess
{
    public string $name = 'queue-serial-recover';

    public function isEnable($server): bool
    {
        return (bool) ($this->container->get(ConfigInterface::class)->get('queue_serial.recover.enable') ?? true);
    }

    public function handle(): void
    {
        $config = $this->container->get(ConfigInterface::class);
        $interval = max(1, (int) ($config->get('queue_serial.recover.interval') ?? 30));
        $idleSeconds = max(0, (int) ($config->get('queue_serial.recover.idle_seconds') ?? 60));
        $queueService = $this->container->get(QueueService::class);

        while (ProcessManager::isRunning()) {
            try {
                $stats = $queueService->recoverSerial(null, $idleSeconds);
                foreach ($stats as $queue => $item) {
                    if ($item['recovered'] > 0) {
                        $this->logger()?->warning(sprintf(
                            '[queue-serial] recovered %d lost runner(s) on queue "%s".',
                            $item['recovered'],
                            $queue
                        ));
                    }
                }
            } catch (Throwable $e) {
                $this->logger()?->error('[queue-serial] recover failed: ' . $e->getMessage());
            }

            Coroutine::sleep($interval);
        }
    }

    private function logger(): ?StdoutLoggerInterface
    {
        return $this->container->has(StdoutLoggerInterface::class)
            ? $this->container->get(StdoutLoggerInterface::class)
            : null;
    }
}
