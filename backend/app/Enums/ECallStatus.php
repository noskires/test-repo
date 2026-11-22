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
}
