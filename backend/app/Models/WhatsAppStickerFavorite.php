<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppStickerFavorite extends Model
{
    protected $table = 'whatsapp_sticker_favorites';

    protected $fillable = ['company_id', 'content_hash', 'whatsapp_media_file_id'];

    public function mediaFile(): BelongsTo
    {
        return $this->belongsTo(WhatsAppMediaFile::class, 'whatsapp_media_file_id');
    }
}
