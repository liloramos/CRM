<?php

namespace App\Enums;

enum MenuAvailabilityStatus: string
{
    case Available = 'available';
    case Unavailable = 'unavailable';
    case SoldOut = 'sold_out';
}
