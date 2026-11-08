<?php

namespace App\Enums;

/**
 * Two roles are enough here, so a plain enum instead of a permissions package (D9).
 */
enum UserRole: string
{
    case Member = 'member';
    case Admin = 'admin';
}
