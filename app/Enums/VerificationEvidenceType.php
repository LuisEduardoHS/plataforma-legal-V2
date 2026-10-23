<?php

namespace App\Enums;

enum VerificationEvidenceType: string
{
    case ProfessionalLicense = 'professional_license';
    case Certificate = 'certificate';
    case SupportingDocument = 'supporting_document';
}
