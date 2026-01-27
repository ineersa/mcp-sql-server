#!/usr/bin/env python3
"""
GLiNER PII Detection Script

Communicates with PHP via NDJSON (line-delimited JSON) on stdin/stdout.
Loads NVIDIA's GLiNER-PII model and analyzes text data for PII/PHI entities.

Protocol:
- Request: {"action": "analyze", "table": "...", "columns": [...], "data": [[...]], "threshold": 0.9}
- Response: {"table": "...", "results": {"col": ["pii_type"]}, "samples": {"col": "sample_value"}}
- Shutdown: {"action": "shutdown"}

Usage:
    python3 gliner_pii.py
"""

import json
import sys
from typing import Any

# All PII labels supported by nvidia/gliner-pii model
ALL_LABELS = [
    # Personal
    "first_name", "last_name", "name", "date_of_birth", "age", "gender",
    "sexuality", "race_ethnicity", "religious_belief", "political_view",
    "occupation", "employment_status", "education_level",
    # Contact
    "email", "phone_number", "street_address", "city", "county", "state",
    "country", "coordinate", "zip_code", "po_box",
    # Financial
    "credit_debit_card", "cvv", "bank_routing_number", "account_number",
    "iban", "swift_bic", "pin", "ssn", "tax_id", "ein",
    # Government
    "passport_number", "driver_license", "license_plate", "national_id", "voter_id",
    # Digital/Technical
    "ipv4", "ipv6", "mac_address", "url", "user_name", "password",
    "device_identifier", "imei", "serial_number", "api_key", "secret_key",
    # Healthcare/PHI
    "medical_record_number", "health_plan_beneficiary_number", "blood_type",
    "biometric_identifier", "health_condition", "medication", "insurance_policy_number",
    # Temporal
    "date", "time", "date_time",
    # Organization
    "company_name", "employee_id", "customer_id", "certificate_license_number",
    "vehicle_identifier",
]


def load_model():
    """Load the GLiNER PII model."""
    try:
        from gliner import GLiNER
        model = GLiNER.from_pretrained("nvidia/gliner-pii")
        return model
    except ImportError:
        print(json.dumps({
            "error": "GLiNER library not installed. Run: pip install gliner torch"
        }), flush=True)
        sys.exit(1)
    except Exception as e:
        print(json.dumps({
            "error": f"Failed to load model: {str(e)}"
        }), flush=True)
        sys.exit(1)


def analyze_column(model, column_data: list[str], threshold: float) -> tuple[set[str], str | None]:
    """
    Analyze a column's data for PII entities.

    Returns:
        Tuple of (set of detected PII types, sample value that triggered detection)
    """
    detected_types: set[str] = set()
    sample_value: str | None = None

    # Combine all values into text blocks for analysis
    for value in column_data:
        if value is None or str(value).strip() == "":
            continue

        text = str(value)

        try:
            entities = model.predict_entities(text, ALL_LABELS, threshold=threshold)

            for entity in entities:
                label = entity.get("label", "")
                score = entity.get("score", 0)

                if score >= threshold and label in ALL_LABELS:
                    detected_types.add(label)
                    if sample_value is None:
                        sample_value = text[:50]  # Truncate for display

        except Exception:
            # Skip problematic values
            continue

    return detected_types, sample_value


def process_request(model, request: dict[str, Any]) -> dict[str, Any]:
    """Process an analyze request and return results."""
    table = request.get("table", "unknown")
    columns = request.get("columns", [])
    data = request.get("data", [])
    threshold = request.get("threshold", 0.9)

    results: dict[str, list[str]] = {}
    samples: dict[str, str] = {}

    # Build column data from rows
    num_columns = len(columns)
    column_values: dict[int, list[str]] = {i: [] for i in range(num_columns)}

    for row in data:
        for i, value in enumerate(row):
            if i < num_columns:
                column_values[i].append(str(value) if value is not None else "")

    # Analyze each column
    for i, column_name in enumerate(columns):
        values = column_values.get(i, [])
        if not values:
            continue

        detected, sample = analyze_column(model, values, threshold)

        if detected:
            results[column_name] = sorted(list(detected))
            if sample:
                samples[column_name] = sample

    return {
        "table": table,
        "results": results,
        "samples": samples
    }


def main():
    """Main entry point - reads NDJSON from stdin, writes responses to stdout."""
    # Send ready signal
    print(json.dumps({"status": "loading"}), flush=True)

    model = load_model()

    # Signal that model is loaded and ready
    print(json.dumps({"status": "ready"}), flush=True)

    for line in sys.stdin:
        line = line.strip()
        if not line:
            continue

        try:
            request = json.loads(line)
        except json.JSONDecodeError as e:
            print(json.dumps({"error": f"Invalid JSON: {str(e)}"}), flush=True)
            continue

        action = request.get("action", "")

        if action == "shutdown":
            print(json.dumps({"status": "shutdown"}), flush=True)
            break
        elif action == "analyze":
            try:
                response = process_request(model, request)
                print(json.dumps(response), flush=True)
            except Exception as e:
                print(json.dumps({
                    "error": f"Analysis failed: {str(e)}",
                    "table": request.get("table", "unknown")
                }), flush=True)
        else:
            print(json.dumps({"error": f"Unknown action: {action}"}), flush=True)


if __name__ == "__main__":
    main()
