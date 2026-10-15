<?php

declare(strict_types=1);

return [
    // Max SerialJob handlers running at once across all keys (0 = unlimited).
    // Runners beyond the limit wait in a FIFO slot queue and get a slot as soon as one frees up.
    'max_concurrent' => 32,

    // How long a runner keeps its key while waiting in the slot queue (seconds).
    // After that, recover restarts the key and it queues again.
    'slot_wait_seconds' => 3600,

    // Slot auto-expires if a worker crashes without release (seconds). Renewed while the job runs.
    'slot_lease_seconds' => 600,

    // Per-key runner lease (seconds). Renewed every 1/3 period while the job runs;
    // a runner lost to a killed worker is restarted after at most this long.
    'runner_lease_seconds' => 300,

    // Max time one step may run before its lease stops being renewed and recover takes over (seconds, 0 = unlimited).
    // The taken-over step may still be running, so steps should be idempotent.
    'max_run_seconds' => 3600,

    // A step that ends abnormally (worker killed / exceeded max_run_seconds) this many times is treated as failed.
    'max_lost_runs' => 3,

    'recover' => [
        // Run the queue-serial-recover process alongside the server.
        'enable' => true,
        // Seconds between recover passes.
        'interval' => 30,
        // Keys with an empty waiting list and no push for this long are dropped from the registry.
        'idle_seconds' => 60,
    ],

    'chain' => [
        // How long failed chains are kept for retry (seconds, 0 = forever).
        'failed_ttl_seconds' => 604800,
    ],
];
