<?php

namespace App\Contracts\Ai;

interface ConversationCopilotProviderInterface
{
    /** @param array<string, mixed> $context @return array<string, mixed> */
    public function analyze(array $context): array;

    public function name(): string;
}
