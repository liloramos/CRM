<?php

namespace App\Data\Ai;

final class CopilotAnalysis
{
    public const SCHEMA_VERSION = 1;

    /** @param array<string,mixed> $draftOrder @param list<array{code:string,message:string}> $warnings @param list<array{code:string,label:string}> $missingInformation @param array<string,mixed> $metadata @param list<string> $replyMessages */
    public function __construct(
        public readonly string $intent,
        public readonly float $confidence,
        public readonly string $summary,
        public readonly array $draftOrder,
        public readonly array $missingInformation,
        public readonly array $warnings,
        public readonly string $suggestedReply,
        public readonly bool $requiresHumanReview,
        public readonly array $metadata = [],
        public readonly array $replyMessages = [],
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['schema_version' => self::SCHEMA_VERSION, 'intent' => $this->intent, 'confidence' => $this->confidence, 'summary' => $this->summary, 'draft_order' => $this->draftOrder, 'missing_information' => $this->missingInformation, 'warnings' => $this->warnings, 'suggested_reply' => $this->suggestedReply, 'reply_messages' => $this->replyMessages, 'requires_human_review' => true, 'metadata' => $this->metadata];
    }
}
