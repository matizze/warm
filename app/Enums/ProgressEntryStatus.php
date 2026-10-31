<?php

namespace App\Enums;

enum ProgressEntryStatus: string
{
    /** The entry is linked to a known client. */
    case Recorded = 'recorded';

    /** The audio had no identifiable client; kept for triage, never dropped. */
    case Pending = 'pending';
}
