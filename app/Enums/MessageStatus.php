<?php

namespace App\Enums;

enum MessageStatus: string
{
    case Sent = 'sent';
    case Deleted = 'deleted';
}
