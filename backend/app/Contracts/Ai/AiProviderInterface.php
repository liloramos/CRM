<?php

namespace App\Contracts\Ai;

use App\Data\Ai\AiProviderStatus;
use App\Data\Ai\AiSuggestionContext;
use App\Data\Ai\AiSuggestionResult;
use App\Data\Ai\AutomationDispatchResult;

interface AiProviderInterface
{
    public function name(): string;

    public function isConfigured(): bool;

    public function connectionStatus(): AiProviderStatus;

    public function suggestReply(AiSuggestionContext $context): AiSuggestionResult;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function dispatchAutomationEvent(string $eventType, array $payload): AutomationDispatchResult;
}
