<?php

namespace App\Enums;

/**
 * What an approver did, as logged in an Approval Record (GLOSSARY "Approval Record"). Multi-level endorsements
 * use Approve / Return / Reject; the level shows in the record's status columns. Cancel is for issue 25.
 */
enum ApprovalAction: string
{
    case Approve = 'approve';
    case Return = 'return';
    case Reject = 'reject';
    case Cancel = 'cancel';
}
