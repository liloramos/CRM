<?php

namespace App\Champs\Enums;

enum ChampsLeadStage: string
{
    case New = 'new';
    case Reviewing = 'reviewing';
    case Interested = 'interested';
    case Contacted = 'contacted';
    case AwaitingResponse = 'awaiting_response';
    case MeetingScheduled = 'meeting_scheduled';
    case Client = 'client';
    case Lost = 'lost';
    case Discarded = 'discarded';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
