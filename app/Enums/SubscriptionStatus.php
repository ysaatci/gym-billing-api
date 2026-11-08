<?php

namespace App\Enums;

/**
 * pending -> active  (payment succeeded)
 * pending -> failed  (payment declined)
 */
enum SubscriptionStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Failed = 'failed';
}
