<?php

namespace App\Enums;

/**
 * How urgent an E-Call is.
 */
enum ECallPriority: string
{
    case Emergency = 'emergency';
    case High = 'high';
    case Normal = 'normal';
    case Low = 'low';
}
