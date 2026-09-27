<?php

namespace App\Contracts;

use App\Data\OutgoingMessage;
use App\Data\ProviderMedia;
use App\Data\SendResult;

/**
 * Outbound port. The application only knows this contract; each provider
 * (Evolution, Z-API, WhatsMeow) supplies an implementation.
 */
interface WhatsAppProvider
{
    public function sendMessage(OutgoingMessage $message): SendResult;

    public function getMedia(string $providerMessageId, string $remoteJid, bool $fromMe): ProviderMedia;
}
