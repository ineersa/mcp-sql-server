# PII Discovery Feature Walkthrough

This document provides a walkthrough of the PII Discovery feature implementation.

## Feature Overview

The `pii:discover` command scans database tables for Personally Identifiable Information (PII) and Protected Health Information (PHI) using NVIDIA's GLiNER-PII model.

## Files Created/Modified

### New Files

| File                                        | Description                                                                                                                      |
| ------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------- |
| `src/Enum/PIIGroup.php`                     | Enum with 8 PII categories (Personal, Contact, Financial, Government, Digital/Technical, Healthcare/PHI, Temporal, Organization) |
| `src/Enum/PIILabel.php`                     | Enum with 64 PII/PHI entity types, each mapped to a group                                                                        |
| `src/Service/PIIAnalyzerService.php`        | Service managing Python subprocess via NDJSON communication                                                                      |
| `src/Command/PIIDiscoveryCommand.php`       | CLI command with options for connection, tables, sample-size, confidence-threshold                                               |
| `scripts/gliner_pii.py`                     | Python script that loads GLiNER-PII model and processes data                                                                     |
| `scripts/requirements.txt`                  | Python dependencies (gliner, torch)                                                                                              |
| `Dockerfile.test`                           | Test image with PHP 8.4 + Python + GLiNER                                                                                        |
| `databases.docker.test.yaml`                | Docker-specific DB config using service names                                                                                    |
| `tests/Command/PIIDiscoveryCommandTest.php` | Integration tests with `@group pii` annotation                                                                                   |

### Modified Files

| File                                  | Changes                                                        |
| ------------------------------------- | -------------------------------------------------------------- |
| `docker-compose.test.yaml`            | Added `php-test` service                                       |
| `composer.json`                       | Added `tests-docker`, `tests-pii`, `docker-test-build` scripts |
| `tests/Fixtures/DatabaseFixtures.php` | Added `pii_samples` table with known PII data                  |

---

## Usage

### Basic Usage

```bash
# Scan all tables in a connection
php bin/console pii:discover --connection=production

# Scan specific tables only
php bin/console pii:discover -c production --tables=users,orders,customers

# Customize sample size and confidence threshold
php bin/console pii:discover -c production -s 100 --confidence-threshold=0.8
```

### View Available Options

```bash
php bin/console pii:discover --help
```

This displays:

- All command options
- Grouped list of all 64 PII/PHI entity types by category

---

## Running Tests

### Standard Tests (excludes PII tests)

```bash
# Run all tests except PII (no Python required)
vendor/bin/phpunit --exclude-group pii
```

### PII Integration Tests (requires Docker)

```bash
# Run all tests inside Docker container
composer tests-docker

# Run only PII-specific tests
composer tests-pii
```

---

## Architecture Details

### Communication Flow

1. **PHP Command** starts the Python subprocess
2. **Python script** loads the GLiNER-PII model (~2GB)
3. For each table:
    - PHP queries random sample rows from database
    - PHP sends data to Python via NDJSON (newline-delimited JSON)
    - Python analyzes each column using GLiNER
    - Python returns detected PII types with confidence scores
    - PHP collects results and displays progress
4. PHP outputs final YAML report

### NDJSON Protocol

**Request (PHP → Python):**

```json
{
  "action": "analyze",
  "table": "users",
  "columns": ["name", "email", "phone"],
  "data": [["John Smith", "john@example.com", "555-1234"], ...],
  "threshold": 0.9
}
```

**Response (Python → PHP):**

```json
{
    "table": "users",
    "results": {
        "name": ["first_name", "last_name"],
        "email": ["email"],
        "phone": ["phone_number"]
    },
    "samples": {
        "name": "John Smith",
        "email": "john@example.com"
    }
}
```

**Shutdown:**

```json
{ "action": "shutdown" }
```

---

## PII Entity Types

The system detects 64 entity types across 8 categories:

### Personal (13 types)

`first_name`, `last_name`, `name`, `date_of_birth`, `age`, `gender`, `sexuality`, `race_ethnicity`, `religious_belief`, `political_view`, `occupation`, `employment_status`, `education_level`

### Contact (10 types)

`email`, `phone_number`, `street_address`, `city`, `county`, `state`, `country`, `coordinate`, `zip_code`, `po_box`

### Financial (10 types)

`credit_debit_card`, `cvv`, `bank_routing_number`, `account_number`, `iban`, `swift_bic`, `pin`, `ssn`, `tax_id`, `ein`

### Government (5 types)

`passport_number`, `driver_license`, `license_plate`, `national_id`, `voter_id`

### Digital/Technical (11 types)

`ipv4`, `ipv6`, `mac_address`, `url`, `user_name`, `password`, `device_identifier`, `imei`, `serial_number`, `api_key`, `secret_key`

### Healthcare/PHI (7 types)

`medical_record_number`, `health_plan_beneficiary_number`, `blood_type`, `biometric_identifier`, `health_condition`, `medication`, `insurance_policy_number`

### Temporal (3 types)

`date`, `time`, `date_time`

### Organization (5 types)

`company_name`, `employee_id`, `customer_id`, `certificate_license_number`, `vehicle_identifier`

---

## Docker Test Environment

### Container Setup

The `php-test` container includes:

- PHP 8.4 with all database extensions (MySQL, PostgreSQL, SQLite, SQL Server)
- Python 3 with GLiNER and PyTorch (CPU version)
- Composer for PHP dependencies

### Building the Test Image

```bash
# Build the test image
composer docker-test-build

# Or manually
docker compose -f docker-compose.test.yaml build php-test
```

---

## Example Output

```yaml
PII Detection Results
Found potential PII in 5 columns across 2 tables:

users:
  email:
    - email
  name:
    - first_name
    - last_name
  phone:
    - phone_number

customers:
  ssn:
    - ssn
  credit_card:
    - credit_debit_card
```

---

## Troubleshooting

### Python Dependencies Not Found

```bash
# Install Python dependencies
pip install -r scripts/requirements.txt
```

### Model Download Slow

The first run downloads the GLiNER-PII model (~2GB).

### GPU vs CPU

The implementation uses CPU-only PyTorch to keep the Docker image size reasonable. For GPU support, modify `Dockerfile.test` to use the NVIDIA base image and install GPU-enabled PyTorch.
