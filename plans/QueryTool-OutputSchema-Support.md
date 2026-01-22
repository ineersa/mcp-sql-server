# Implementation Plan: Add outputSchema Support to QueryTool

## Executive Summary

**Status:** MCP SDK already has full `outputSchema` support. Need to integrate it into QueryTool.

**Current State:**
- SDK supports: `Tool::outputSchema`, `McpTool` attribute, `CallToolResult::structuredContent`
- QueryTool returns: text content only (markdown + JSON string)
- QueryTool has no `McpTool` attribute or `outputSchema`

**Desired State:**
- QueryTool has `McpTool` attribute with `outputSchema`
- QueryTool returns hybrid output: text content + structuredContent
- Tests updated and passing

---

## Changes Required

### File: `src/Tools/QueryTool.php`

#### Step 1: Add McpTool Attribute

Add before the `QueryTool` class:
```php
use App\Capability\Attribute\McpTool;

#[McpTool(
    name: 'database-server_query',
    description: 'Execute SQL queries against database connections',
    outputSchema: [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string'],
                'count' => ['type' => 'integer'],
                'rows' => ['type' => 'array']
            ],
            'required' => ['query', 'count', 'rows']
        ]
    ]
)]
```

#### Step 2: Modify Return Statement

Locate the `__invoke` method's return statement. Currently returns a simple content array.

Change to:
```php
return new CallToolResult(
    content: [
        new TextContent('**Executed ' . count($results) . ' queries**\n\n' . $markdownOutput),
        new TextContent(json_encode($results, JSON_PRETTY_PRINT))
    ],
    structuredContent: $results
);
```

#### Step 3: Handle Error Case

Ensure that when an error occurs (before `$results` is populated), the method returns `CallToolResult` with only text content (no `structuredContent`). This is already the case since errors return early with a simple text content array.

---

### Files: `tests/Inspector/__snapshots__/QueryToolSelect/*`

#### Automatic Update

After code changes, run `composer tests`. Snapshots will automatically regenerate with new content:

- Tool listing includes `outputSchema` field
- Tool responses include `structuredContent` field
- Review changes to ensure they're correct

---

## Implementation Workflow

1. **Add McpTool attribute** to `QueryTool` class
2. **Modify return statement** to use `CallToolResult` with `structuredContent`
3. **Run tests**: `composer tests`
4. **Review snapshot changes** in test output
5. **Code quality**: `composer cs-fix`
6. **Static analysis**: `composer phpstan`
7. **Final test run**: `composer tests`

---

## Risk Assessment

**Risks:** None identified

**Mitigations:**
- Backward compatible: text content preserved
- Snapshot tests catch regressions
- Error handling unchanged (text-only on error)

---

## Validation Checklist

- [ ] QueryTool has `McpTool` attribute with `outputSchema`
- [ ] Success returns `CallToolResult` with content + structuredContent
- [ ] Errors return `CallToolResult` with text content only
- [ ] All snapshot tests updated and passing
- [ ] Code style passes (`composer cs-fix`)
- [ ] Static analysis passes (`composer phpstan`)

---

## Additional Notes

- No changes needed to `vendor/mcp/sdk/` - already complete
- No changes needed to other tools - QueryTool only (per requirement)
- Hybrid approach maintains human-readable tables while adding programmatic access
- Schema structure: `[{query: string, count: int, rows: array[]}]`

---

## Research Findings

### MCP SDK Components

**Tool.php:**
- Has `outputSchema` property with validation (type must be 'object')
- Constructor param #7 accepts `ToolOutputSchema`
- `fromArray()` validates and handles it
- `jsonSerialize()` includes it

**McpTool Attribute:**
- Constructor has `outputSchema` parameter (#7)
- Used in Discoverer to extract schema from tool
- Accepts array structure for JSON Schema definition

**CallToolResult:**
- Constructor has `structuredContent` parameter (#3)
- Accepts `array|string|mixed[]` for structured content
- Included in `jsonSerialize()` when present
- Spec recommends text content + structuredContent together

### Current QueryTool Implementation

- Returns `content: [Text(markdown), Text(JSON string)]`
- No `McpTool` attribute
- No `outputSchema` defined
- No `structuredContent` field used

### Specification

From MCP spec:
- `outputSchema` is optional JSON Schema defining expected output structure
- Servers MUST provide `structuredContent` conforming to schema
- Clients SHOULD validate
- `structuredContent` field in tool result holds JSON object
- Text content should be provided for backward compatibility

### Design Pattern

```
outputSchema defined in McpTool attribute
  ↓
tool returns structuredContent in CallToolResult
  ↓
text content also provided for backwards compatibility
```

---

## Implementation Summary

### Status: ✅ COMPLETE

### Changes Applied

**File Modified:** `src/Tools/QueryTool.php`

1. **Added McpTool Attribute:**
   - Namespace: `Mcp\Capability\Attribute\McpTool`
   - Tool name: `database-server_query`
   - Added `outputSchema` property

2. **Output Schema Structure:**
   ```php
   [
       'type' => 'object',
       'properties' => [
           'results' => [
               'type' => 'array',
               'items' => [
                   'type' => 'object',
                   'properties' => [
                       'query' => ['type' => 'string'],
                       'count' => ['type' => 'integer'],
                       'rows' => ['type' => 'array'],
                   ],
                   'required' => ['query', 'count', 'rows'],
               ],
           ],
       ],
       'required' => ['results'],
   ]
   ```

3. **Modified Return Statement:**
   ```php
   return new CallToolResult(
       content: [
           new TextContent($markdown),
           new TextContent($structuredJson),
       ],
       isError: false,
       structuredContent: ['results' => $results],
   );
   ```

### Key Learnings from Implementation

1. **Schema Type Requirement:** The MCP SDK requires `outputSchema` to be an `object` type (not `array`). This necessitated wrapping the results array in an object with a `results` property.

2. **Namespace Location:** The correct namespace for `McpTool` is `Mcp\Capability\Attribute\McpTool` (not `App\Capability\Attribute\McpTool`).

3. **Validation:** The SDK automatically validates that `structuredContent` conforms to the `outputSchema` definition.

### Test Results

- ✅ All 11 snapshot tests regenerated and passing
  - QueryToolMultipleQueries: 6 tests
  - QueryToolSelect: 5 tests
- ✅ Code style passed (`composer cs-fix`)
- ✅ Static analysis passed (`composer phpstan`)

### Snapshot Changes

Old snapshots included only:
- `content` array with markdown and JSON text

New snapshots include:
- `content` array (unchanged - backward compatible)
- `outputSchema` in tool listing
- `structuredContent` in tool responses

### Backward Compatibility

- ✅ Text content preserved in all responses
- ✅ Error handling unchanged (text-only on errors)
- ✅ Existing clients continue to work with `content` field
- ✅ New clients can use `structuredContent` for programmatic access

### Final Output Structure

```json
{
  "content": [
    {
      "type": "text",
      "text": "**Executed 2 queries**\n\n..."
    },
    {
      "type": "text",
      "text": "[{\"query\":\"SELECT...\",\"count\":5,\"rows\":[...]}]"
    }
  ],
  "isError": false,
  "structuredContent": {
    "results": [
      {
        "query": "SELECT * FROM users",
        "count": 5,
        "rows": [...]
      }
    ]
  }
}
```
