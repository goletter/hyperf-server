<?php

declare(strict_types=1);

namespace Goletter\Server\Commands;

use Goletter\Server\Service\QueueService;
use Hyperf\Command\Command as HyperfCommand;
use Hyperf\Contract\ConfigInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Input\InputOption;

class SerialRecoverCommand extends HyperfCommand
{
    public function __construct(protected ContainerInterface $container)
    {
        parent::__construct('queue:serial-recover');
    }

    public function configure()
    {
        parent::configure();
        $this->setDescription('Restart lost runners of serial queues (pushSerial) and clean up idle keys.')
            ->addOption('queue', 'Q', InputOption::VALUE_OPTIONAL, 'Queue name, defaults to all async_queue pools');
    }

    public function handle()
    {
        $queue = $this->input->getOption('queue');
        $queue = is_string($queue) && $queue !== '' ? $queue : null;

        $idleSeconds = (int) ($this->container->get(ConfigInterface::class)->get('queue_serial.recover.idle_seconds') ?? 60);
        $stats = $this->container->get(QueueService::class)->recoverSerial($queue, $idleSeconds);

        $rows = [];
        foreach ($stats as $name => $item) {
            $rows[] = [$name, $item['checked'], $item['recovered'], $item['removed']];
        }
        $this->table(['queue', 'checked', 'recovered', 'removed'], $rows);
    }
}
