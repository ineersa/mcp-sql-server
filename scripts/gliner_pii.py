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


def process_request(model, request: dict[str, Any]) -> dict[str, Any]:
    """Process an analyze request and return results using flattened batch processing."""
    table = request.get("table", "unknown")
    columns = request.get("columns", [])
    data = request.get("data", [])
    threshold = request.get("threshold", 0.9)

    results: dict[str, set[str]] = {}
    samples: dict[str, str] = {}

    # 1. Flatten all data: Collect all valid texts with their column association
    # List of tuples: (column_name, text_value)
    tasks: list[tuple[str, str]] = []

    num_columns = len(columns)

    # Extract values for each column and add to tasks (flat list)
    # We maintain column grouping implicitly by iterating columns outer logic if desired,
    # but here we iterate cells.

    # To keep context grouped by column (usually preferred for batching similar text),
    # we collect by column first.
    column_values: dict[int, list[str]] = {i: [] for i in range(num_columns)}

    for row in data:
        for i, value in enumerate(row):
            if i < num_columns:
                val_str = str(value) if value is not None else ""
                if val_str.strip():
                    column_values[i].append(val_str)

    # Build the task list ordered by column
    for i, column_name in enumerate(columns):
        for val in column_values.get(i, []):
            tasks.append((column_name, val))

    if not tasks:
        return {
            "table": table,
            "results": {},
            "samples": {}
        }

    # 2. Process in large batches
    BATCH_SIZE = 256

    for i in range(0, len(tasks), BATCH_SIZE):
        batch_tasks = tasks[i:i + BATCH_SIZE]
        batch_texts = [t[1] for t in batch_tasks]

        try:
            # Predict batch
            if hasattr(model, 'batch_predict_entities'):
                batch_predictions = model.batch_predict_entities(batch_texts, ALL_LABELS, threshold=threshold)
            else:
                batch_predictions = [model.predict_entities(t, ALL_LABELS, threshold=threshold) for t in batch_texts]

            # Process results
            for j, entities in enumerate(batch_predictions):
                column_name, text = batch_tasks[j]

                for entity in entities:
                    label = entity.get("label", "")
                    score = entity.get("score", 0)

                    if score >= threshold and label in ALL_LABELS:
                        if column_name not in results:
                            results[column_name] = set()

                        results[column_name].add(label)

                        # Save first sample detected
                        if column_name not in samples:
                            samples[column_name] = text[:50]

        except Exception as e:
            # Log error but continue with next batch
            sys.stderr.write(f"Batch error: {str(e)}\n")
            continue

    # 3. Format results as sorted lists
    final_results = {k: sorted(list(v)) for k, v in results.items()}

    return {
        "table": table,
        "results": final_results,
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
