<?php

namespace App\Enums;

/**
 * open -> paid  (payment succeeded)
 * open -> void  (payment declined, nothing is owed)
 */
enum InvoiceStatus: string
{
    case Open = 'open';
    case Paid = 'paid';
    case Void = 'void';
}
