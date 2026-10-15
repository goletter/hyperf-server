<?php

declare(strict_types=1);

namespace Goletter\Server\Commands;

use Goletter\Server\Service\QueueService;
use Hyperf\Command\Command as HyperfCommand;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Input\InputOption;

class ChainFailedCommand extends HyperfCommand
{
    public function __construct(protected ContainerInterface $container)
    {
        parent::__construct('queue:chain-failed');
    }

    public function configure()
    {
        parent::configure();
        $this->setDescription('List, retry or forget failed job chains.')
            ->addOption('queue', 'Q', InputOption::VALUE_OPTIONAL, 'Queue name, defaults to all async_queue pools')
            ->addOption('retry', null, InputOption::VALUE_OPTIONAL, 'Retry a failed chain from the failed step by chain ID')
            ->addOption('retry-all', null, InputOption::VALUE_NONE, 'Retry all failed chains')
            ->addOption('forget', null, InputOption::VALUE_OPTIONAL, 'Delete a failed chain by chain ID')
            ->addOption('limit', 'L', InputOption::VALUE_OPTIONAL, 'Max chains to list per queue', '100');
    }

    public function handle()
    {
        /** @var QueueService $queueService */
        $queueService = $this->container->get(QueueService::class);
        $queue = $this->input->getOption('queue');
        $queue = is_string($queue) && $queue !== '' ? $queue : null;

        $retry = (string) $this->input->getOption('retry');
        if ($retry !== '') {
            $queueService->retryChain($retry, $queue)
                ? $this->info("Chain {$retry} is retrying.")
                : $this->error("Failed chain {$retry} not found or already running.");
            return;
        }

        $forget = (string) $this->input->getOption('forget');
        if ($forget !== '') {
            $queueService->forgetFailedChain($forget, $queue)
                ? $this->info("Chain {$forget} deleted.")
                : $this->error("Failed chain {$forget} not found.");
            return;
        }

        $limit = max(1, (int) $this->input->getOption('limit'));

        if ($this->input->getOption('retry-all')) {
            $count = 0;
            foreach ($queueService->failedChains($queue, $limit) as $chain) {
                if ($queueService->retryChain($chain['id'], $chain['queue'])) {
                    ++$count;
                }
            }
            $this->info("Retried {$count} chain(s).");
            return;
        }

        $rows = array_map(static fn (array $chain) => [
            $chain['id'],
            $chain['key'] === $chain['id'] ? '-' : $chain['key'],
            $chain['queue'],
            $chain['steps'],
            $chain['failed_at'],
            $chain['exception'] . ': ' . mb_strimwidth($chain['error'], 0, 80, '...'),
        ], $queueService->failedChains($queue, $limit));

        $this->table(['id', 'key', 'queue', 'remaining steps', 'failed at', 'error'], $rows);
    }
}
