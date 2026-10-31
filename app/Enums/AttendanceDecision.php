<?php

namespace App\Enums;

/**
 * Outcome of an attendance attempt for one customer question.
 *
 * `Answered` means the knowledge was deemed sufficient and a reply was
 * produced (sent, or only logged when running in dry-run). `NoKnowledge` is
 * the guardrail outcome: the flow declined to answer instead of inventing —
 * the handoff to a person is a separate flow (H5). `Error` records a failure
 * while generating or sending, so nothing is answered and the case is visible.
 */
enum AttendanceDecision: string
{
    case Answered = 'answered';
    case NoKnowledge = 'no_knowledge';
    case Error = 'error';
}
