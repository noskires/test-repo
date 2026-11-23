<?php

namespace App\Enums;

/**
 * Which workflow step an E-Call is on (PRD v2 §7). Filing sets ForVendorAssessment; later slices move it on.
 */
enum ECallStatus: string
{
    case ForVendorAssessment = 'for_vendor_assessment';
    case ForEicReview = 'for_eic_review';
    case Approved = 'approved';
    case Closed = 'closed';
    case Returned = 'returned';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    /**
     * Whether the Site Vendor may submit an FSR and BOQ revision in this status.
     */
    public function acceptsProposal(): bool
    {
        return $this === self::ForVendorAssessment || $this === self::Returned;
    }

    /**
     * Whether the Requestor or the current Site EIC may cancel the E-Call: until the EIC approves the FSR and BOQ
     * (issue 25).
     */
    public function isCancellable(): bool
    {
        return in_array($this, [self::ForVendorAssessment, self::ForEicReview, self::Returned], true);
    }
}
