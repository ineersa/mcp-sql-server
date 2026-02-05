# PII Redaction Strategy

This document outlines the strategy used by the `PIIAnalyzerService` to efficiently and safely redact PII from database query results.

## Overview: "One Big Text" Strategy

To maximize performance and minimize overhead, we employ a "One Big Text" strategy. Instead of processing rows individually or in small batches, we:

1.  **Concatenate** all rows into a single, massive text string.
2.  **Split** this string into safe, manageable chunks (to respect model context limits).
3.  **Infer** PII entities on all chunks in a single batch call to the GLiNER model.
4.  **Reconstruct** the rows from the redacted text.

This approach reduces the number of calls to the underlying model (and extension) to the absolute minimum (often just 1 call for thousands of rows), significantly reducing latency.

## Detailed Workflow

### 1. Stringification & Concatenation

All values in the result set are converted to distinct string representations:

- `null` -> `"NULL"`
- `true` -> `"true"`
- `false` -> `"false"`
- `DateTime` -> `"Y-m-d H:i:s"`
- Others -> Cast to string

Values are joined by a column separator (`<||SEP||>`), and rows are joined by a row separator (`<||ROW_SEP||>`).

### 2. Safe Chunking (`TextChunker`)

The massive string is split into chunks of approximately **1800 characters** (approx. 450 tokens) to stay well within the model's context window.

To prevent splitting a PII entity (like an email address) in half, we use a **smart lookback mechanism** (`App\Utils\TextChunker::splitTextSafely`):

- From the potential split point (1800 chars), we look back up to **100 characters**.
- We search for a "safe boundary" in this priority order:
    1.  **Row Separator** (`<||ROW_SEP||>`)
    2.  **Column Separator** (`<||SEP||>`)
    3.  **Whitespace** (`\n`, `\t`, ` `)
- Breaking at a separator ensures we never cut through a value.
- Breaking at whitespace is a fallback that is highly likely to be safe for typical PII (emails, names, phone numbers don't usually contain spaces in the middle).

### 3. Batch Inference

All text chunks are sent to `Guide\GlinerWrapper::predictBatch()` in a single call. This leverages the efficiency of the underlying Rust/ONNX implementation.

### 4. Redaction & Reconstruction

- Identified PII entities are replaced with `[REDACTED_<label>]` directly in the chunk strings.
- The redacted chunks are concatenated back into one full string.
- The string is `explode`d back into rows and columns using the known separators.

## Advantages

- **Performance**: Minimizes cross-boundary calls and creates optimal batch sizes for the model.
- **Safety**: "Safe Splitting" ensures PII is not missed due to arbitrary chunk boundaries.
- **Simplicity**: Handling everything as a string stream simplifies the core logic and avoids complex state management during iteration.

## Key Classes

- `App\Service\PIIAnalyzerService`: Orchestrates the flow.
- `App\Utils\TextChunker`: Handles the intelligent string splitting.
