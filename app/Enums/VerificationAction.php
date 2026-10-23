<?php

namespace App\Enums;

enum VerificationAction: string
{
    case Created = 'created';
    case EvidenceAdded = 'evidence_added';
    case EvidenceRemoved = 'evidence_removed';
    case Submitted = 'submitted';
    case ReviewOpened = 'review_opened';
    case EvidenceRead = 'evidence_read';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Revoked = 'revoked';
    case Invalidated = 'invalidated';
}
