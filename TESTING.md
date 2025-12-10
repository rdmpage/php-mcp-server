# Testing Guide for SPARQL Tools

## ✅ What We've Created

A complete unit testing setup with **15 tests** covering:

### Files Created
- `composer.json` - Defines PHPUnit as a dependency
- `phpunit.xml` - PHPUnit configuration
- `tests/SparqlToolsTest.php` - 15 unit tests with 49 assertions
- `tests/README.md` - Testing documentation

### Test Coverage

**Query Builders (3 tests):**
- ✔ Build authors of work query
- ✔ Build cites query
- ✔ Build list types query

**Formatters - Success Cases (4 tests):**
- ✔ Format authors result text
- ✔ Format authors result json
- ✔ Format cites result text
- ✔ Format work funders result text

**Formatters - Error Cases (2 tests):**
- ✔ Format authors result error
- ✔ Format cites result error

**Formatters - Edge Cases (4 tests):**
- ✔ Format authors result empty
- ✔ Format cites result empty
- ✔ Format cites result missing title
- ✔ Format list types result text
- ✔ Format list types result no label

**Format Switching (1 test):**
- ✔ Format switching (tests all formatters support json/text)

## 🚀 Quick Start

1. **Install dependencies:**
   ```bash
   composer install
   ```

2. **Run all tests:**
   ```bash
   ./vendor/bin/phpunit
   ```

3. **Run with readable output:**
   ```bash
   ./vendor/bin/phpunit --testdox
   ```

## 📊 Current Test Results

```
Sparql Tools
 ✔ Build authors of work query
 ✔ Build cites query
 ✔ Build list types query
 ✔ Format authors result text
 ✔ Format authors result json
 ✔ Format cites result text
 ✔ Format work funders result text
 ✔ Format authors result error
 ✔ Format cites result error
 ✔ Format authors result empty
 ✔ Format cites result empty
 ✔ Format cites result missing title
 ✔ Format list types result text
 ✔ Format list types result no label
 ✔ Format switching

OK (15 tests, 49 assertions)
```

## 📝 How Tests Work

### 1. Query Builder Tests
These verify that SPARQL queries are correctly generated:

```php
public function testBuildAuthorsOfWorkQuery()
{
    $uri = 'https://doi.org/10.1234/test';
    $query = build_authors_of_work_query($uri);

    // Check the query contains what we expect
    $this->assertStringContainsString('<https://doi.org/10.1234/test>', $query);
    $this->assertStringContainsString('schema:creator', $query);
}
```

### 2. Formatter Tests with Mock Data
These create fake SPARQL responses and verify formatting:

```php
public function testFormatAuthorsResultText()
{
    // Create fake SPARQL result
    $mockResult = [
        'ok' => true,
        'status' => 200,
        'body' => json_encode([
            'results' => [
                'bindings' => [
                    ['authorName' => ['value' => 'Jane Smith']]
                ]
            ]
        ])
    ];

    $output = format_authors_result($mockResult, 'text');

    // Verify the output
    $this->assertStringContainsString('Jane Smith', $output);
}
```

### 3. Error Tests
Verify graceful error handling:

```php
public function testFormatAuthorsResultError()
{
    $mockResult = [
        'ok' => false,
        'status' => 500,
        'error' => 'Connection timeout'
    ];

    $output = format_authors_result($mockResult, 'text');

    $this->assertStringContainsString('SPARQL error', $output);
    $this->assertStringContainsString('500', $output);
}
```

## 🔍 Understanding Test Output

### When Tests Pass
```
✔ Build authors of work query [6.88 ms]
OK (15 tests, 49 assertions)
```
All green - everything works!

### When Tests Fail
If a test fails, you'll see:
```
F

Failed asserting that 'actual text' contains "expected text".
```

This tells you:
- Which test failed
- What was expected
- What was actually received

## ➕ Adding More Tests

You can expand the test suite by following these patterns:

### Test a new query builder:
```php
public function testBuildMyNewQuery()
{
    $query = build_my_new_query('input');
    $this->assertStringContainsString('expected keyword', $query);
}
```

### Test a new formatter:
```php
public function testFormatMyNewResult()
{
    $mockResult = [
        'ok' => true,
        'status' => 200,
        'body' => json_encode(['results' => ['bindings' => []]])
    ];

    $output = format_my_new_result($mockResult, 'text');
    $this->assertStringContainsString('expected output', $output);
}
```

## 🎯 What's NOT Tested Yet

These are more advanced and can be added later:

- ❌ `run_sparql_query()` - Requires HTTP mocking
- ❌ MCP protocol functions - Requires STDIN/STDOUT mocking
- ❌ Integration tests with real SPARQL endpoint

## 💡 Testing Best Practices

1. **Run tests frequently** - Run after any code change
2. **Test edge cases** - Empty results, missing fields, errors
3. **Use descriptive names** - `testFormatAuthorsResultEmpty` tells you what it does
4. **Keep tests simple** - One thing per test
5. **Use mock data** - Don't rely on external services

## 📚 Learn More

- [PHPUnit Documentation](https://phpunit.de/documentation.html)
- [Writing Testable PHP Code](https://phpunit.de/getting-started.html)

## 🏃 Common Commands

```bash
# Run all tests
./vendor/bin/phpunit

# Run with nice output
./vendor/bin/phpunit --testdox

# Run specific test
./vendor/bin/phpunit --filter testBuildAuthorsOfWorkQuery

# Run tests in a specific file
./vendor/bin/phpunit tests/SparqlToolsTest.php

# Show test coverage (requires xdebug)
./vendor/bin/phpunit --coverage-text
```
