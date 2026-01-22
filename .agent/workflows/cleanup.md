---
description: Cleanup single-line "what" comments in src/ and tests/
---

1. Identify modified or uncommitted files in `src/` and `tests/` (unless "all files" is specified).
2. Review file contents for single-line comments.
3. Remove comments that only describe "what" the code is doing (redundant).
4. Keep comments that explain "why" (complex logic, edge cases).
5. Remove DocBlocks that duplicate PHP 8 type information.
6. Only keep comments that answer "why" and not "what".
