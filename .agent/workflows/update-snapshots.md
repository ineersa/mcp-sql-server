---
description: Update MCP Inspector snapshots by deleting them and running tests to regenerate
---

1. Delete existing snapshots for test you need
2. Run `rm -rf tests/Inspector/__snapshots__/*`
3. Regenerate snapshots by running tests (they will be marked as incomplete)
   // turbo
4. Run `composer test -- tests/Inspector/`
5. Check snapshots files, verify they don't contain errors
6. Verify and finalize snapshots by running tests again
   // turbo
7. Run `composer test -- tests/Inspector/`
