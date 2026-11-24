<?php

namespace App\Enums;

/**
 * What a PSS user may use (issue 46, ADR 0005). `Vendor` exactly when users.vendor_id is set; the User model keeps that
 * invariant. A `Pending` user can sign in but can't use PSS until a System Administrator classifies them.
 */
enum UserAccess: string
{
    /** An internal employee of the organisation. */
    case Internal = 'internal';

    /** Staff of the linked Vendor. */
    case Vendor = 'vendor';

    /** Waiting for a System Administrator to choose internal or a Vendor. */
    case Pending = 'pending';
}
