<?php

namespace App\Jobs;

use App\Services\AutomaticQueueService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class HandleCallTimeout implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $ticketId) {}

    public function handle(AutomaticQueueService $automatic): void
    {
        $automatic->expire($this->ticketId);
    }
}
