<?php

namespace App\Enums;

enum ProductSelectionMode: string
{
    case Fixed = 'fixed';
    case Single = 'single';
    case Multiple = 'multiple';
    case Addon = 'addon';
    case Variation = 'variation';
    case IncludedChoice = 'included_choice';
}
