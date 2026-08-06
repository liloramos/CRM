<?php

namespace App\Champs\Enums;

enum ChampsLeadActivityType: string
{
    case Note = 'note';
    case StageChanged = 'stage_changed';
    case FavoriteAdded = 'favorite_added';
    case FavoriteRemoved = 'favorite_removed';
    case Contacted = 'contacted';
    case FollowUpScheduled = 'follow_up_scheduled';
    case Assigned = 'assigned';
    case Exported = 'exported';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * @return list<string>
     */
    public static function userCreatableValues(): array
    {
        return [
            self::Note->value,
            self::Contacted->value,
            self::Exported->value,
        ];
    }
}
