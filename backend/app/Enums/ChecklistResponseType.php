<?php

namespace App\Enums;

/**
 * How a Checklist Item is answered.
 */
enum ChecklistResponseType: string
{
    case PassFail = 'pass_fail';
    case GoodDefective = 'good_defective';
    case YesNo = 'yes_no';
    case Numeric = 'numeric';
    case Text = 'text';
    case Photo = 'photo';
}
