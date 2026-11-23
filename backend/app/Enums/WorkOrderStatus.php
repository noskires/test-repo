<?php

namespace App\Enums;

/**
 * Which workflow step a Work Order is on. Open is the editable draft (GLOSSARY "Open (Work Order)"). Submitting
 * starts the 2-level endorsement (issue 19). The Site Vendor's completion declaration moves For Implementation to
 * Implemented (issue 20). The EIC's validation moves Implemented to For Billing Endorsement LVL1 (issue 21); billing
 * endorsement climbs LVL1..LVL4 as the Billing Amount requires, and the last required approval Closes the Work Order
 * (issue 23). endorsementLevel() covers the pre-implementation endorsement, billingLevel() the billing endorsement.
 * Cancelled (issue 25) is final, like Rejected.
 */
enum WorkOrderStatus: string
{
    case Open = 'open';
    case ForLvl1Endorsement = 'for_lvl1_endorsement';
    case ForLvl2Endorsement = 'for_lvl2_endorsement';
    case ForImplementation = 'for_implementation';
    case Implemented = 'implemented';
    case ForBillingEndorsementLvl1 = 'for_billing_endorsement_lvl1';
    case ForBillingEndorsementLvl2 = 'for_billing_endorsement_lvl2';
    case ForBillingEndorsementLvl3 = 'for_billing_endorsement_lvl3';
    case ForBillingEndorsementLvl4 = 'for_billing_endorsement_lvl4';
    case Closed = 'closed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    /**
     * Whether the creator or the current Site EIC may cancel the Work Order: only before implementation is
     * authorised (issue 25). After that, the only way to end it is Reject.
     */
    public function isCancellable(): bool
    {
        return in_array($this, [self::Open, self::ForLvl1Endorsement, self::ForLvl2Endorsement], true);
    }

    /**
     * The billing endorsement level that must act now (1-4), or null when no billing endorsement is pending.
     */
    public function billingLevel(): ?int
    {
        return match ($this) {
            self::ForBillingEndorsementLvl1 => 1,
            self::ForBillingEndorsementLvl2 => 2,
            self::ForBillingEndorsementLvl3 => 3,
            self::ForBillingEndorsementLvl4 => 4,
            default => null,
        };
    }

    public static function forBillingLevel(int $level): self
    {
        return match ($level) {
            1 => self::ForBillingEndorsementLvl1,
            2 => self::ForBillingEndorsementLvl2,
            3 => self::ForBillingEndorsementLvl3,
            4 => self::ForBillingEndorsementLvl4,
        };
    }

    /**
     * The Immediate Head level that must act now, or null when no endorsement is pending.
     */
    public function endorsementLevel(): ?int
    {
        return match ($this) {
            self::ForLvl1Endorsement => 1,
            self::ForLvl2Endorsement => 2,
            default => null,
        };
    }

    /**
     * Where an endorsement action at this status leads: Approve moves one level up (level 2 authorises
     * implementation), Return goes back exactly one step (level 1 back to the creator's Open draft), Reject is final.
     */
    public function afterEndorsement(ApprovalAction $action): self
    {
        return match ([$this, $action]) {
            [self::ForLvl1Endorsement, ApprovalAction::Approve] => self::ForLvl2Endorsement,
            [self::ForLvl2Endorsement, ApprovalAction::Approve] => self::ForImplementation,
            [self::ForLvl1Endorsement, ApprovalAction::Return] => self::Open,
            [self::ForLvl2Endorsement, ApprovalAction::Return] => self::ForLvl1Endorsement,
            [self::ForLvl1Endorsement, ApprovalAction::Reject], [self::ForLvl2Endorsement, ApprovalAction::Reject] => self::Rejected,
        };
    }
}
