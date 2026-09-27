<?php

namespace App\Enums;

enum ProcessingStatus: string
{
    case Done = 'done';
    case Failed = 'failed';
    case Skipped = 'skipped';
}
