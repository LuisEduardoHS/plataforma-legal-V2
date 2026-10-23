<?php

namespace App\Enums;

enum AttentionMode: string
{
    case InPerson = 'in_person';
    case Remote = 'remote';
    case Hybrid = 'hybrid';
}
