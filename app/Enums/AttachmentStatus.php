<?php

namespace App\Enums;

enum AttachmentStatus: string
{
    case PendingUpload = 'pending_upload';
    case Available = 'available';
    case PendingDeletion = 'pending_deletion';
    case Deleted = 'deleted';
}
