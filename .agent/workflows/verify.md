---
description: Run full verification suite (lint, analyze, test)
---
1. Format code using PHP CS Fixer.
// turbo
2. `composer cs-fix`
3. Perform static analysis using PHPStan.
// turbo
4. `composer phpstan`
5. Execute the test suite using PHPUnit.
// turbo
6. `composer tests`
