<?php

namespace App\Jobs;

use App\Services\Ai\CopilotAutomationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessCopilotAutomation implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public readonly int $messageId,
        public readonly int $expectedAutomationVersion,
    ) {}

    public function handle(CopilotAutomationService $automation): void
    {
        $automation->handle($this->messageId, $this->expectedAutomationVersion);
    }
}
