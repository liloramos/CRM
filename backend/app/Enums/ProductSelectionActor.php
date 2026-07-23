<?php

namespace App\Enums;

enum ProductSelectionActor: string
{
    case System = 'system';
    case House = 'house';
    case Customer = 'customer';
}
