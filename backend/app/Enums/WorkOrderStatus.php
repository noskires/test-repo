<?php

namespace App\Enums;

/**
 * Which workflow step a Work Order is on. Open is the editable draft (GLOSSARY "Open (Work Order)").
 * Later issues add submission, endorsement, implementation and billing statuses (19+).
 */
enum WorkOrderStatus: string
{
    case Open = 'open';
}
