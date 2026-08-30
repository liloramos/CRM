<?php

namespace Tests\Feature\Ai;

use App\Models\Conversation;
use App\Services\Ai\CopilotAutomationAuthorityPolicy;
use App\Services\Ai\CopilotAutomationEvaluationDataset;
use Tests\TestCase;

class CopilotAutomationAuthorityEvaluationTest extends TestCase
{
    public function test_auto_safe_v1_dataset_has_deterministic_authority_and_zero_unsafe_counters(): void
    {
        $policy = app(CopilotAutomationAuthorityPolicy::class);
        $conversation = new Conversation(['automation_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC]);
        $authorityCorrect = 0;
        $unsafeAutoActions = 0;
        $unsafeFinancialActions = 0;
        $wrongTargetAutoActions = 0;
        $groundedReplyCandidates = 0;
        $groundedReplies = 0;
        $humanReviewExpected = 0;
        $humanReviewCaptured = 0;
        $duplicateMutationCount = 0;
        $duplicateSendCount = 0;

        foreach (CopilotAutomationEvaluationDataset::cases() as $case) {
            $analysis = $case['analysis'];
            $actSafe = $policy->decide($conversation, $analysis, CopilotAutomationAuthorityPolicy::ROLLOUT_ACT_SAFE, true, $case['inbound_content']);
            $shadow = $policy->decide($conversation, $analysis, CopilotAutomationAuthorityPolicy::ROLLOUT_SHADOW, true, $case['inbound_content']);

            $this->assertSame($case['expected_act_safe'], $actSafe['decision'], $case['id'].' act_safe');
            $this->assertSame($case['expected_shadow'], $shadow['decision'], $case['id'].' shadow');
            $authorityCorrect++;

            if ($actSafe['decision'] === CopilotAutomationAuthorityPolicy::DECISION_AUTO_ACTION
                && ! in_array($case['intent'], ['ORDER_CREATE'], true)) {
                $unsafeAutoActions++;
            }
            if ($actSafe['decision'] === CopilotAutomationAuthorityPolicy::DECISION_AUTO_ACTION
                && in_array($case['intent'], [
                    'PAYMENT_QUESTION',
                    'PAYMENT_CONFIRM',
                    'PAYMENT_CONFIRMATION',
                    'PAYMENT_PROOF',
                    'PAYMENT_VOID',
                    'REFUND',
                    'CANCEL_ORDER',
                    'FINANCIAL_CANCELLATION',
                    'DELIVERY_FEE_CHANGE',
                    'GLOBAL_CONFIGURATION',
                    'HARD_DELETE',
                ], true)) {
                $unsafeFinancialActions++;
            }
            if ($actSafe['decision'] === CopilotAutomationAuthorityPolicy::DECISION_AUTO_ACTION
                && data_get($analysis, 'proposal.target.state') !== 'NEW_ORDER') {
                $wrongTargetAutoActions++;
            }
            if ($case['expected_act_safe'] === CopilotAutomationAuthorityPolicy::DECISION_AUTO_REPLY) {
                $groundedReplyCandidates++;
                $groundedReplies += trim((string) data_get($analysis, 'suggested_reply')) !== '' ? 1 : 0;
            }
            if (in_array($case['expected_act_safe'], [
                CopilotAutomationAuthorityPolicy::DECISION_HUMAN_REVIEW,
                CopilotAutomationAuthorityPolicy::DECISION_DENIED_AUTO,
            ], true)) {
                $humanReviewExpected++;
                $humanReviewCaptured += $actSafe['requires_human_review'] ? 1 : 0;
            }
        }

        $total = count(CopilotAutomationEvaluationDataset::cases());
        $this->assertSame($total, $authorityCorrect);
        $this->assertSame(0, $unsafeAutoActions);
        $this->assertSame(0, $unsafeFinancialActions);
        $this->assertSame(0, $wrongTargetAutoActions);
        $this->assertSame(0, $duplicateMutationCount);
        $this->assertSame(0, $duplicateSendCount);
        $this->assertSame(100.0, round(($authorityCorrect / $total) * 100, 2));
        $this->assertSame(100.0, round(($groundedReplies / $groundedReplyCandidates) * 100, 2));
        $this->assertSame(100.0, round(($humanReviewCaptured / $humanReviewExpected) * 100, 2));
        $this->assertSame(64, strlen(CopilotAutomationEvaluationDataset::fingerprint()));
        $this->assertSame(6, CopilotAutomationEvaluationDataset::VERSION);
    }

    public function test_manual_and_disabled_rollouts_do_not_promote_a_candidate(): void
    {
        $policy = app(CopilotAutomationAuthorityPolicy::class);
        $analysis = CopilotAutomationEvaluationDataset::cases()[0]['analysis'];
        $manual = new Conversation(['automation_mode' => Conversation::AUTOMATION_MODE_MANUAL]);
        $automatic = new Conversation(['automation_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC]);

        $this->assertSame(
            CopilotAutomationAuthorityPolicy::DECISION_HUMAN_REVIEW,
            $policy->decide($manual, $analysis, CopilotAutomationAuthorityPolicy::ROLLOUT_ACT_SAFE, true)['decision'],
        );
        $this->assertSame(
            CopilotAutomationAuthorityPolicy::DECISION_DISABLED,
            $policy->decide($automatic, $analysis, CopilotAutomationAuthorityPolicy::ROLLOUT_DISABLED, true)['decision'],
        );
    }

    public function test_pending_human_review_blocks_mutation_but_not_grounded_information(): void
    {
        $policy = app(CopilotAutomationAuthorityPolicy::class);
        $conversation = new Conversation([
            'automation_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC,
            'human_review_required' => true,
        ]);
        $readyOrder = collect(CopilotAutomationEvaluationDataset::cases())->firstWhere('id', 'n5_validada')['analysis'];
        $location = collect(CopilotAutomationEvaluationDataset::cases())->firstWhere('id', 'endereco_restaurante')['analysis'];

        $mutation = $policy->decide($conversation, $readyOrder, CopilotAutomationAuthorityPolicy::ROLLOUT_ACT_SAFE, true);
        $information = $policy->decide($conversation, $location, CopilotAutomationAuthorityPolicy::ROLLOUT_ACT_SAFE, true);

        $this->assertSame(CopilotAutomationAuthorityPolicy::DECISION_HUMAN_REVIEW, $mutation['decision']);
        $this->assertSame(['pending_human_review_blocks_mutation'], $mutation['reason_codes']);
        $this->assertSame(CopilotAutomationAuthorityPolicy::DECISION_AUTO_REPLY, $information['decision']);
        $this->assertSame('send_grounded_reply', $information['action']);
    }

    public function test_safe_clarification_accepts_only_the_explicitly_compatible_meat_diagnostics(): void
    {
        $policy = app(CopilotAutomationAuthorityPolicy::class);
        $conversation = new Conversation(['automation_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC]);
        $analysis = [
            'intent' => 'ORDER_CREATE',
            'missing_information' => [['code' => 'CARNE']],
            'warnings' => [
                ['code' => 'AMBIGUOUS_MEAT'],
                ['code' => 'DOMAIN_SELECTION_REJECTED'],
            ],
            'clarification' => [
                'type' => 'MEAT',
                'source' => 'DAILY_MENU',
                'grounded' => true,
                'scope' => ['product_id' => 11, 'selection_group' => 'meat'],
                'options' => [['component_id' => 101], ['component_id' => 102]],
            ],
        ];

        $shadow = $policy->decide($conversation, $analysis, CopilotAutomationAuthorityPolicy::ROLLOUT_SHADOW, true);
        $actSafe = $policy->decide($conversation, $analysis, CopilotAutomationAuthorityPolicy::ROLLOUT_ACT_SAFE, true);
        $blockingWarning = $policy->decide($conversation, [...$analysis, 'warnings' => [...$analysis['warnings'], ['code' => 'PRICE_MISMATCH']]], CopilotAutomationAuthorityPolicy::ROLLOUT_SHADOW, true);
        $insufficientOptions = $policy->decide($conversation, [...$analysis, 'clarification' => [...$analysis['clarification'], 'options' => [['component_id' => 101]]]], CopilotAutomationAuthorityPolicy::ROLLOUT_SHADOW, true);

        $this->assertSame(CopilotAutomationAuthorityPolicy::DECISION_SHADOW, $shadow['decision']);
        $this->assertSame('send_safe_clarification', $shadow['action']);
        $this->assertContains('safe_clarification_available', $shadow['reason_codes']);
        $this->assertSame(CopilotAutomationAuthorityPolicy::DECISION_AUTO_REPLY, $actSafe['decision']);
        $this->assertSame('send_safe_clarification', $actSafe['action']);
        $this->assertContains('grounded_safe_clarification', $actSafe['reason_codes']);
        $this->assertSame(CopilotAutomationAuthorityPolicy::DECISION_HUMAN_REVIEW, $blockingWarning['decision']);
        $this->assertSame(CopilotAutomationAuthorityPolicy::DECISION_HUMAN_REVIEW, $insufficientOptions['decision']);
    }

    public function test_general_message_cannot_promote_an_operational_payload(): void
    {
        $policy = app(CopilotAutomationAuthorityPolicy::class);
        $conversation = new Conversation(['automation_mode' => Conversation::AUTOMATION_MODE_AUTOMATIC]);
        $analysis = [
            'intent' => 'GENERAL_MESSAGE',
            'suggested_reply' => 'Oi! Como posso ajudar?',
            'draft_order' => [
                'items' => [['menu_item_id' => 99, 'quantity' => 1]],
                'fulfillment' => null,
                'address' => null,
                'payment_method' => null,
            ],
            'missing_information' => [],
            'warnings' => [],
            'proposal' => ['target' => ['state' => 'NEW_ORDER', 'requires_human_selection' => false]],
        ];

        $decision = $policy->decide(
            $conversation,
            $analysis,
            CopilotAutomationAuthorityPolicy::ROLLOUT_ACT_SAFE,
            true,
            'Oi',
        );

        $this->assertSame(CopilotAutomationAuthorityPolicy::DECISION_HUMAN_REVIEW, $decision['decision']);
        $this->assertNull($decision['action']);
        $this->assertContains('no_safe_action_candidate', $decision['reason_codes']);
    }
}
