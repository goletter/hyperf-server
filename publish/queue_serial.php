<?php

declare(strict_types=1);

return [
    // 所有 key 合计最多同时跑多少个 SerialJob（0 = 不限制）。
    // 超出的 runner 进入 FIFO 等待队列，有名额释放时按顺序分配。
    'max_concurrent' => 32,

    // 排队等名额时，runner 保留该 key 执行权的时长（秒）。
    // 超时后由巡检重新拉起，并再次排队。
    'slot_wait_seconds' => 3600,

    // 名额租约（秒）。worker 崩溃未释放时自动过期；任务执行期间会续租。
    'slot_lease_seconds' => 600,

    // 单个 key 的 runner 租约（秒）。执行期间每 1/3 周期续租；
    // worker 被杀导致 runner 丢失时，最多等这么久后重新拉起。
    'runner_lease_seconds' => 300,

    // 单步最长执行时间（秒，0 = 不限制）。超时后停止续租，由巡检接手重跑。
    // 被接手的那一步可能仍在跑，步骤需要幂等。
    'max_run_seconds' => 3600,

    // 同一步因异常中断（worker 被杀 / 超过 max_run_seconds）达到该次数后，按失败处理。
    'max_lost_runs' => 3,

    'recover' => [
        // 随服务启动 queue-serial-recover 巡检进程。
        'enable' => true,
        // 两次巡检的间隔（秒）。
        'interval' => 30,
        // 等待队列为空、且超过该时长没有新任务时，从登记表中移除该 key。
        'idle_seconds' => 60,
    ],

    'chain' => [
        // 失败链保留时长，便于重试（秒，0 = 永久）。
        'failed_ttl_seconds' => 604800,
    ],
];
