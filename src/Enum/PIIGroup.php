<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Groups for categorizing PII/PHI entity types.
 */
enum PIIGroup: string
{
    case PERSONAL = 'Personal';
    case CONTACT = 'Contact';
    case FINANCIAL = 'Financial';
    case GOVERNMENT = 'Government';
    case DIGITAL = 'Digital/Technical';
    case HEALTHCARE = 'Healthcare/PHI';
    case TEMPORAL = 'Temporal';
    case ORGANIZATION = 'Organization';

    public function getDescription(): string
    {
        return match ($this) {
            self::PERSONAL => 'Personal identity information (names, demographics)',
            self::CONTACT => 'Contact and location information',
            self::FINANCIAL => 'Financial and banking information',
            self::GOVERNMENT => 'Government-issued identifiers',
            self::DIGITAL => 'Digital and technical identifiers',
            self::HEALTHCARE => 'Protected Health Information (PHI)',
            self::TEMPORAL => 'Date and time information',
            self::ORGANIZATION => 'Organization and employment information',
        };
    }
}
