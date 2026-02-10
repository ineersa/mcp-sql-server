<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * PII/PHI entity types supported by GLiNER-PII model.
 *
 * Based on NVIDIA Nemotron-PII dataset with 55+ entity categories.
 *
 * @see https://huggingface.co/nvidia/gliner-PII
 * @see https://huggingface.co/datasets/nvidia/nemotron-pii
 */
enum PIILabel: string
{
    // Personal (13 types)
    case FIRST_NAME = 'first_name';
    case LAST_NAME = 'last_name';
    case NAME = 'name';
    case DATE_OF_BIRTH = 'date_of_birth';
    case AGE = 'age';
    case GENDER = 'gender';
    case SEXUALITY = 'sexuality';
    case RACE_ETHNICITY = 'race_ethnicity';
    case RELIGIOUS_BELIEF = 'religious_belief';
    case POLITICAL_VIEW = 'political_view';
    case OCCUPATION = 'occupation';
    case EMPLOYMENT_STATUS = 'employment_status';
    case EDUCATION_LEVEL = 'education_level';

    // Contact (10 types)
    case EMAIL = 'email';
    case PHONE_NUMBER = 'phone_number';
    case STREET_ADDRESS = 'street_address';
    case CITY = 'city';
    case COUNTY = 'county';
    case STATE = 'state';
    case COUNTRY = 'country';
    case COORDINATE = 'coordinate';
    case ZIP_CODE = 'zip_code';
    case PO_BOX = 'po_box';

    // Financial (10 types)
    case CREDIT_DEBIT_CARD = 'credit_debit_card';
    case CVV = 'cvv';
    case BANK_ROUTING_NUMBER = 'bank_routing_number';
    case ACCOUNT_NUMBER = 'account_number';
    case IBAN = 'iban';
    case SWIFT_BIC = 'swift_bic';
    case PIN = 'pin';
    case SSN = 'ssn';
    case TAX_ID = 'tax_id';
    case EIN = 'ein';

    // Government (5 types)
    case PASSPORT_NUMBER = 'passport_number';
    case DRIVER_LICENSE = 'driver_license';
    case LICENSE_PLATE = 'license_plate';
    case NATIONAL_ID = 'national_id';
    case VOTER_ID = 'voter_id';

    // Digital/Technical (11 types)
    case IPV4 = 'ipv4';
    case IPV6 = 'ipv6';
    case MAC_ADDRESS = 'mac_address';
    case URL = 'url';
    case USER_NAME = 'user_name';
    case PASSWORD = 'password';
    case DEVICE_IDENTIFIER = 'device_identifier';
    case IMEI = 'imei';
    case SERIAL_NUMBER = 'serial_number';
    case API_KEY = 'api_key';
    case SECRET_KEY = 'secret_key';

    // Healthcare/PHI (7 types)
    case MEDICAL_RECORD_NUMBER = 'medical_record_number';
    case HEALTH_PLAN_BENEFICIARY_NUMBER = 'health_plan_beneficiary_number';
    case BLOOD_TYPE = 'blood_type';
    case BIOMETRIC_IDENTIFIER = 'biometric_identifier';
    case HEALTH_CONDITION = 'health_condition';
    case MEDICATION = 'medication';
    case INSURANCE_POLICY_NUMBER = 'insurance_policy_number';

    // Temporal (3 types)
    case DATE = 'date';
    case TIME = 'time';
    case DATE_TIME = 'date_time';

    // Organization (5 types)
    case COMPANY_NAME = 'company_name';
    case EMPLOYEE_ID = 'employee_id';
    case CUSTOMER_ID = 'customer_id';
    case CERTIFICATE_LICENSE_NUMBER = 'certificate_license_number';
    case VEHICLE_IDENTIFIER = 'vehicle_identifier';

    public function getGroup(): PIIGroup
    {
        return match ($this) {
            // Personal
            self::FIRST_NAME,
            self::LAST_NAME,
            self::NAME,
            self::DATE_OF_BIRTH,
            self::AGE,
            self::GENDER,
            self::SEXUALITY,
            self::RACE_ETHNICITY,
            self::RELIGIOUS_BELIEF,
            self::POLITICAL_VIEW,
            self::OCCUPATION,
            self::EMPLOYMENT_STATUS,
            self::EDUCATION_LEVEL => PIIGroup::PERSONAL,

            // Contact
            self::EMAIL,
            self::PHONE_NUMBER,
            self::STREET_ADDRESS,
            self::CITY,
            self::COUNTY,
            self::STATE,
            self::COUNTRY,
            self::COORDINATE,
            self::ZIP_CODE,
            self::PO_BOX => PIIGroup::CONTACT,

            // Financial
            self::CREDIT_DEBIT_CARD,
            self::CVV,
            self::BANK_ROUTING_NUMBER,
            self::ACCOUNT_NUMBER,
            self::IBAN,
            self::SWIFT_BIC,
            self::PIN,
            self::SSN,
            self::TAX_ID,
            self::EIN => PIIGroup::FINANCIAL,

            // Government
            self::PASSPORT_NUMBER,
            self::DRIVER_LICENSE,
            self::LICENSE_PLATE,
            self::NATIONAL_ID,
            self::VOTER_ID => PIIGroup::GOVERNMENT,

            // Digital/Technical
            self::IPV4,
            self::IPV6,
            self::MAC_ADDRESS,
            self::URL,
            self::USER_NAME,
            self::PASSWORD,
            self::DEVICE_IDENTIFIER,
            self::IMEI,
            self::SERIAL_NUMBER,
            self::API_KEY,
            self::SECRET_KEY => PIIGroup::DIGITAL,

            // Healthcare/PHI
            self::MEDICAL_RECORD_NUMBER,
            self::HEALTH_PLAN_BENEFICIARY_NUMBER,
            self::BLOOD_TYPE,
            self::BIOMETRIC_IDENTIFIER,
            self::HEALTH_CONDITION,
            self::MEDICATION,
            self::INSURANCE_POLICY_NUMBER => PIIGroup::HEALTHCARE,

            // Temporal
            self::DATE,
            self::TIME,
            self::DATE_TIME => PIIGroup::TEMPORAL,

            // Organization
            self::COMPANY_NAME,
            self::EMPLOYEE_ID,
            self::CUSTOMER_ID,
            self::CERTIFICATE_LICENSE_NUMBER,
            self::VEHICLE_IDENTIFIER => PIIGroup::ORGANIZATION,
        };
    }

    /**
     * Get all labels as string values for passing to GLiNER.
     *
     * @return list<string>
     */
    public static function getAllValues(): array
    {
        return array_map(
            static fn (self $label): string => $label->value,
            self::cases()
        );
    }

    /**
     * Get labels grouped by their PIIGroup.
     *
     * @return array<string, list<string>>
     */
    public static function getGroupedLabels(): array
    {
        $grouped = [];

        foreach (PIIGroup::cases() as $group) {
            $grouped[$group->value] = [];
        }

        foreach (self::cases() as $label) {
            $grouped[$label->getGroup()->value][] = $label->value;
        }

        return $grouped;
    }

    /**
     * Try to create a PIILabel from a string value.
     */
    public static function tryFromValue(string $value): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->value === $value) {
                return $case;
            }
        }

        return null;
    }
}
