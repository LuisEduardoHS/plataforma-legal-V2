<?php

namespace App\Enums;

enum LegalResourceType: string
{
    case Guide = 'guide';
    case Document = 'document';
    case Template = 'template';
    case InstitutionalLink = 'institutional_link';
    case EducationalMaterial = 'educational_material';
}
