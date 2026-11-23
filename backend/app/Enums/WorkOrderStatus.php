<?php

namespace App\Enums;

/**
 * Which workflow step a Work Order is on. Open is the editable draft (GLOSSARY "Open (Work Order)"). Submitting
 * starts the 2-level endorsement (issue 19). The Site Vendor's completion declaration moves For Implementation to
 * Implemented (issue 20). The EIC's validation moves Implemented to For Billing Endorsement LVL1 (issue 21); issue 23
 * adds the further billing levels and Closed. endorsementLevel() covers the pre-implementation endorsement only.
 */
enum WorkOrderStatus: string
{
    case Open = 'open';
    case ForLvl1Endorsement = 'for_lvl1_endorsement';
    case ForLvl2Endorsement = 'for_lvl2_endorsement';
    case ForImplementation = 'for_implementation';
    case Implemented = 'implemented';
    case ForBillingEndorsementLvl1 = 'for_billing_endorsement_lvl1';
    case Rejected = 'rejected';

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
