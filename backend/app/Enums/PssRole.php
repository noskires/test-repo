<?php

namespace App\Enums;

/**
 * The only roles PSS stores (ADR 0004). Every other PRD role is derived: Requestor is anyone, the EIC comes from Site
 * settings, Vendor comes from users.vendor_id, and approvers come from the Immediate Head chain.
 */
enum PssRole: string
{
    /** Holds every `manage-*` gate, including granting roles. */
    case SystemAdministrator = 'system-administrator';

    /** Reads Reports and Exports (issue 29). */
    case ManagementViewer = 'management-viewer';
}
