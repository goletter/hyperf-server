<?php

declare(strict_types=1);

return [
    // Max SerialJob handlers running at once across all keys (0 = unlimited).
    // Prevents FD / connection storms when many keys are active.
    'max_concurrent' => 32,

    // Milliseconds to wait before retrying when slots are full (converted to the queue driver's unit).
    'busy_delay_ms' => 1000,

    // Slot auto-expires if a worker crashes without release (seconds). Renewed while the job runs.
    'slot_lease_seconds' => 600,

    // Per-key runner lease (seconds). Renewed every 1/3 period while the job runs;
    // a runner lost to a killed worker is restarted after at most this long.
    'runner_lease_seconds' => 300,

    'recover' => [
        // Run the queue-serial-recover process alongside the server.
        'enable' => true,
        // Seconds between recover passes.
        'interval' => 30,
        // Keys with an empty waiting list and no push for this long are dropped from the registry.
        'idle_seconds' => 60,
    ],
];
