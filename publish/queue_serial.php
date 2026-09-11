<?php

declare(strict_types=1);

return [
    // Max SerialJob handlers running at once across all keys (0 = unlimited).
    // Prevents FD / connection storms when many keys are active.
    'max_concurrent' => 32,

    // Seconds (or ms on ms pool) to wait before retrying when slots are full.
    'busy_delay' => 1,

    // Slot auto-expires if a worker crashes without release (seconds).
    'slot_lease_seconds' => 600,
];
