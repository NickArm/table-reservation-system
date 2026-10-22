<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The platform actions worth a permanent record (SPEC §13.2).
 *
 * A short list on purpose. An audit log that records everything is read by
 * nobody, and the question somebody actually brings to it is narrow: who
 * suspended this account, who gave them a free month, who was logged in as
 * them when that booking was cancelled.
 */
enum AuditAction: string
{
    case SubscriptionExtended = 'subscription_extended';
    case SubscriptionPlanChanged = 'subscription_plan_changed';
    case TenantSuspended = 'tenant_suspended';
    case TenantRestored = 'tenant_restored';
    case ImpersonationStarted = 'impersonation_started';
    case ImpersonationEnded = 'impersonation_ended';
    case PlanCreated = 'plan_created';
    case PlanUpdated = 'plan_updated';
    case PlanRetired = 'plan_retired';
    case LegalDocumentPublished = 'legal_document_published';
    case TermsAccepted = 'terms_accepted';

    public function label(): string
    {
        return match ($this) {
            self::SubscriptionExtended => 'Subscription extended',
            self::SubscriptionPlanChanged => 'Plan changed by support',
            self::TenantSuspended => 'Account suspended',
            self::TenantRestored => 'Account restored',
            self::ImpersonationStarted => 'Signed in as the venue',
            self::ImpersonationEnded => 'Stopped being the venue',
            self::PlanCreated => 'Plan created',
            self::PlanUpdated => 'Plan changed',
            self::PlanRetired => 'Plan retired',
            self::LegalDocumentPublished => 'Legal text published',
            self::TermsAccepted => 'Terms accepted',
        };
    }

    /**
     * Does this one need a written reason?
     *
     * Anything that changes what an account has paid for, or that puts
     * somebody inside an account that is not theirs. The rest are ordinary
     * administration and explain themselves.
     */
    public function needsAReason(): bool
    {
        return match ($this) {
            self::SubscriptionExtended,
            self::SubscriptionPlanChanged,
            self::TenantSuspended,
            self::ImpersonationStarted => true,
            default => false,
        };
    }
}
