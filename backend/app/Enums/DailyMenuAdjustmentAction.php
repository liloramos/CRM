<?php

namespace App\Enums;

enum DailyMenuAdjustmentAction: string
{
    case Include = 'include';
    case Exclude = 'exclude';
}
