# Unit Tests for SPARQL Tools

This directory contains unit tests for the SPARQL query building and formatting functions.

## Setup

1. Install dependencies (PHPUnit):
   ```bash
   composer install
   ```

## Running Tests

Run all tests:
```bash
./vendor/bin/phpunit
```

Run tests with verbose output:
```bash
./vendor/bin/phpunit --verbose
```

Run a specific test:
```bash
./vendor/bin/phpunit --filter testBuildAuthorsOfWorkQuery
```

## What's Being Tested

### Query Builders
- `build_authors_of_work_query()` - Generates SPARQL to find authors
- `build_cites_query()` - Generates SPARQL to find citations
- `build_list_types_query()` - Generates SPARQL to list entity types

### Result Formatters
- Success cases (valid SPARQL results)
- Error cases (SPARQL endpoint errors)
- Edge cases (empty results, missing fields)
- Format switching (json vs text output)

## Understanding Test Results

✓ Green/Pass = Test succeeded
✗ Red/Fail = Test failed (shows what was expected vs what was received)

## Adding New Tests

Follow the existing patterns:

1. **Testing a query builder**: Check that the generated SPARQL contains the right keywords
2. **Testing a formatter**: Create mock data and verify the output format
3. **Testing errors**: Pass error data and verify error messages appear

Example:
```php
public function testMyNewFunction()
{
    $result = my_new_function('input');
    $this->assertStringContainsString('expected', $result);
}
```
