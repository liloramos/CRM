<?php

namespace App\Enums;

enum MenuComponentType: string
{
    case Base = 'base';
    case Hot = 'hot';
    case Salad = 'salad';
    case Meat = 'meat';
    case Extra = 'extra';
    case Addon = 'addon';
    case JuiceFlavor = 'juice_flavor';
}
