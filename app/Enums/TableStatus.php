<?php

namespace App\Enums;

enum TableStatus: string
{
    case Available = 'available';
    case Maintenance = 'maintenance';
    case Inactive = 'inactive';
}
