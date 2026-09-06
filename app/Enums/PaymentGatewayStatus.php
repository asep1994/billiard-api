<?php

namespace App\Enums;

enum PaymentGatewayStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Failed = 'failed';
    case Expired = 'expired';
}
