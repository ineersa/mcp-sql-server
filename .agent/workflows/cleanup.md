---
description: Cleanup single-line "what" comments in src/ and tests/
---

# 🛡️ IMPORTANT: READ BEFORE PROCEEDING 🛡️

1. **STRICT LIMITATION**: You MUST **ONLY** touch uncommitted files in `src/` and `tests/`.
2. **RESTRICTION**: You **MUST NOT** modify files that are already committed to Git.
3. **PUNISHMENT**: YOU WILL GO TO PRISON IF YOU TOUCH OTHER FILES. This is a strict safety boundary.
4. **SCOPE**: This cleanup is exclusively for PHP files in `src/` and `tests/`.

## Workflow Steps

1. Identify **ONLY UNCOMMITTED** files (modified or untracked) in `src/` and `tests/`.
   Use `git status --short src/ tests/` or similar commands to find candidates.
2. Review file contents for single-line comments.
3. Remove comments that only describe "what" the code is doing (redundant).
4. Keep comments that explain "why" (complex logic, edge cases) and not "what".
5. Remove DocBlocks that duplicate PHP 8 type information (EXCEPT in `src/Tools/` namespace, where DocBlocks are used for MCP tool metadata).
   // turbo
6. Run verification suite: `composer cs-fix && composer phpstan && composer tests`
