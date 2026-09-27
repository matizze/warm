<?php

namespace App\Enums;

enum MessageType: string
{
    case Text = 'text';
    case Image = 'image';
    case Audio = 'audio';
    case Video = 'video';
    case Document = 'document';
    case Sticker = 'sticker';
    case Location = 'location';
    case Contact = 'contact';

    /**
     * Types whose payload is a downloadable media file.
     */
    public function isMedia(): bool
    {
        return in_array($this, [self::Image, self::Audio, self::Video, self::Document, self::Sticker], true);
    }
}
