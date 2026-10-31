<?php

namespace App\Enums;

enum ClientChannelRole: string
{
    /** Internal conversation where the team registers progress by audio. */
    case Progress = 'progress';

    /** The client's own conversation/group, destination of the daily summary. */
    case ClientGroup = 'client_group';
}
