<?php
/**
 * Unit tests for SPARQL query building and formatting functions
 *
 * To run these tests:
 *   1. Install dependencies: composer install
 *   2. Run tests: ./vendor/bin/phpunit
 */

use PHPUnit\Framework\TestCase;

// Load the functions we're testing
require_once __DIR__ . '/../sparql_tools.php';

class SparqlToolsTest extends TestCase
{
    // ========================================================================
    // QUERY BUILDER TESTS
    // These test that our query building functions generate correct SPARQL
    // ========================================================================

    /**
     * Test that build_authors_of_work_query() generates a valid SPARQL query
     */
    public function testBuildAuthorsOfWorkQuery()
    {
        $uri = 'https://doi.org/10.1234/test';
        $query = build_authors_of_work_query($uri);

        // Check the URI is properly embedded in the query
        $this->assertStringContainsString('<https://doi.org/10.1234/test>', $query);

        // Check it uses the schema.org vocabulary
        $this->assertStringContainsString('PREFIX schema: <http://schema.org/>', $query);

        // Check it queries for creators (not authors)
        $this->assertStringContainsString('schema:creator', $query);

        // Check it uses COALESCE for flexible name handling
        $this->assertStringContainsString('COALESCE', $query);

        // Check it handles both name formats
        $this->assertStringContainsString('schema:name', $query);
        $this->assertStringContainsString('schema:givenName', $query);
        $this->assertStringContainsString('schema:familyName', $query);
    }

    /**
     * Test that build_cites_query() generates correct SPARQL
     */
    public function testBuildCitesQuery()
    {
        $uri = 'https://doi.org/10.5555/example';
        $query = build_cites_query($uri);

        // Check the URI is in the query
        $this->assertStringContainsString('<https://doi.org/10.5555/example>', $query);

        // Check it looks for citations
        $this->assertStringContainsString(':citation', $query);

        // Check it groups results
        $this->assertStringContainsString('GROUP BY', $query);
    }

    /**
     * Test that build_list_types_query() generates correct SPARQL
     */
    public function testBuildListTypesQuery()
    {
        $query = build_list_types_query();

        // Check it uses RDF and RDFS prefixes
        $this->assertStringContainsString('PREFIX rdf:', $query);
        $this->assertStringContainsString('PREFIX rdfs:', $query);

        // Check it counts things
        $this->assertStringContainsString('COUNT(?thing)', $query);

        // Check it orders by count descending
        $this->assertStringContainsString('ORDER BY DESC(?count)', $query);
    }

    // ========================================================================
    // FORMATTER TESTS - SUCCESS CASES
    // These test that formatters correctly handle successful SPARQL results
    // ========================================================================

    /**
     * Test formatting authors result in text format
     */
    public function testFormatAuthorsResultText()
    {
        // Create a mock successful SPARQL response
        $mockResult = [
            'ok' => true,
            'status' => 200,
            'body' => json_encode([
                'results' => [
                    'bindings' => [
                        ['authorName' => ['value' => 'Jane Smith']],
                        ['authorName' => ['value' => 'John Doe']],
                        ['authorName' => ['value' => 'Alice Johnson']]
                    ]
                ]
            ])
        ];

        $output = format_authors_result($mockResult, 'text');

        // Check all authors appear in output
        $this->assertStringContainsString('Jane Smith', $output);
        $this->assertStringContainsString('John Doe', $output);
        $this->assertStringContainsString('Alice Johnson', $output);

        // Check it has a header
        $this->assertStringContainsString('Authors:', $output);

        // Check formatting uses bullet points
        $this->assertStringContainsString('- Jane Smith', $output);
    }

    /**
     * Test formatting authors result in JSON format
     */
    public function testFormatAuthorsResultJson()
    {
        $jsonBody = '{"results":{"bindings":[{"authorName":{"value":"Test Author"}}]}}';

        $mockResult = [
            'ok' => true,
            'status' => 200,
            'body' => $jsonBody
        ];

        $output = format_authors_result($mockResult, 'json');

        // JSON format should return the raw body unchanged
        $this->assertEquals($jsonBody, $output);
    }

    /**
     * Test formatting citations result
     */
    public function testFormatCitesResultText()
    {
        $mockResult = [
            'ok' => true,
            'status' => 200,
            'body' => json_encode([
                'results' => [
                    'bindings' => [
                        [
                            'citation' => ['value' => 'https://doi.org/10.1111/cited1'],
                            'title' => ['value' => 'First Citation']
                        ],
                        [
                            'citation' => ['value' => 'https://doi.org/10.2222/cited2'],
                            'title' => ['value' => 'Second Citation']
                        ]
                    ]
                ]
            ])
        ];

        $output = format_cites_result($mockResult, 'text');

        // Check titles and URIs appear
        $this->assertStringContainsString('First Citation', $output);
        $this->assertStringContainsString('https://doi.org/10.1111/cited1', $output);
        $this->assertStringContainsString('Second Citation', $output);

        // Check header
        $this->assertStringContainsString('Citations:', $output);
    }

    /**
     * Test formatting funders result
     */
    public function testFormatWorkFundersResultText()
    {
        $mockResult = [
            'ok' => true,
            'status' => 200,
            'body' => json_encode([
                'results' => [
                    'bindings' => [
                        [
                            'funder' => ['value' => 'https://doi.org/10.13039/100000001'],
                            'name' => ['value' => 'National Science Foundation']
                        ]
                    ]
                ]
            ])
        ];

        $output = format_work_funders_result($mockResult, 'text');

        $this->assertStringContainsString('National Science Foundation', $output);
        $this->assertStringContainsString('https://doi.org/10.13039/100000001', $output);
        $this->assertStringContainsString('Funders:', $output);
    }

    // ========================================================================
    // FORMATTER TESTS - ERROR CASES
    // These test that formatters correctly handle errors
    // ========================================================================

    /**
     * Test that formatters handle SPARQL errors properly
     */
    public function testFormatAuthorsResultError()
    {
        $mockResult = [
            'ok' => false,
            'status' => 500,
            'error' => 'Connection timeout'
        ];

        $output = format_authors_result($mockResult, 'text');

        // Check error message is present
        $this->assertStringContainsString('SPARQL error', $output);
        $this->assertStringContainsString('500', $output);
        $this->assertStringContainsString('Connection timeout', $output);
    }

    /**
     * Test error handling for different formatter
     */
    public function testFormatCitesResultError()
    {
        $mockResult = [
            'ok' => false,
            'status' => 404,
            'error' => 'Endpoint not found'
        ];

        $output = format_cites_result($mockResult, 'text');

        $this->assertStringContainsString('SPARQL error', $output);
        $this->assertStringContainsString('404', $output);
    }

    // ========================================================================
    // FORMATTER TESTS - EDGE CASES
    // These test unusual but valid inputs
    // ========================================================================

    /**
     * Test formatter with empty results
     */
    public function testFormatAuthorsResultEmpty()
    {
        $mockResult = [
            'ok' => true,
            'status' => 200,
            'body' => json_encode([
                'results' => [
                    'bindings' => []
                ]
            ])
        ];

        $output = format_authors_result($mockResult, 'text');

        // Should have a helpful message
        $this->assertStringContainsString('No authors found', $output);
    }

    /**
     * Test formatter with empty citations
     */
    public function testFormatCitesResultEmpty()
    {
        $mockResult = [
            'ok' => true,
            'status' => 200,
            'body' => json_encode([
                'results' => [
                    'bindings' => []
                ]
            ])
        ];

        $output = format_cites_result($mockResult, 'text');

        $this->assertStringContainsString('No citations found', $output);
    }

    /**
     * Test formatter with missing optional fields
     */
    public function testFormatCitesResultMissingTitle()
    {
        $mockResult = [
            'ok' => true,
            'status' => 200,
            'body' => json_encode([
                'results' => [
                    'bindings' => [
                        [
                            'citation' => ['value' => 'https://doi.org/10.1234/untitled']
                            // Note: no title field
                        ]
                    ]
                ]
            ])
        ];

        $output = format_cites_result($mockResult, 'text');

        // Should handle missing title gracefully
        $this->assertStringContainsString('Untitled', $output);
        $this->assertStringContainsString('https://doi.org/10.1234/untitled', $output);
    }

    /**
     * Test list types formatter
     */
    public function testFormatListTypesResultText()
    {
        $mockResult = [
            'ok' => true,
            'status' => 200,
            'body' => json_encode([
                'results' => [
                    'bindings' => [
                        [
                            'type' => ['value' => 'http://schema.org/ScholarlyArticle'],
                            'label' => ['value' => 'Scholarly Article'],
                            'count' => ['value' => '1500']
                        ],
                        [
                            'type' => ['value' => 'http://schema.org/Person'],
                            'label' => ['value' => 'Person'],
                            'count' => ['value' => '800']
                        ]
                    ]
                ]
            ])
        ];

        $output = format_list_types_result($mockResult, 'text');

        // Check both types appear with counts
        $this->assertStringContainsString('Scholarly Article', $output);
        $this->assertStringContainsString('1500 instances', $output);
        $this->assertStringContainsString('Person', $output);
        $this->assertStringContainsString('800 instances', $output);

        // Check header
        $this->assertStringContainsString('Types in knowledge graph:', $output);
    }

    /**
     * Test list types with missing labels (fallback to URI)
     */
    public function testFormatListTypesResultNoLabel()
    {
        $mockResult = [
            'ok' => true,
            'status' => 200,
            'body' => json_encode([
                'results' => [
                    'bindings' => [
                        [
                            'type' => ['value' => 'http://example.org/CustomType'],
                            // No label
                            'count' => ['value' => '42']
                        ]
                    ]
                ]
            ])
        ];

        $output = format_list_types_result($mockResult, 'text');

        // Should use URI when label is missing
        $this->assertStringContainsString('http://example.org/CustomType', $output);
        $this->assertStringContainsString('42 instances', $output);
    }

    // ========================================================================
    // FORMAT SWITCHING TEST
    // Test that format parameter works correctly
    // ========================================================================

    /**
     * Test that all formatters support both json and text formats
     */
    public function testFormatSwitching()
    {
        $jsonBody = '{"test":"data"}';
        $mockResult = [
            'ok' => true,
            'status' => 200,
            'body' => $jsonBody
        ];

        // Test json format returns raw body for various formatters
        $this->assertEquals($jsonBody, format_authors_result($mockResult, 'json'));
        $this->assertEquals($jsonBody, format_cites_result($mockResult, 'json'));
        $this->assertEquals($jsonBody, format_cited_by_result($mockResult, 'json'));
        $this->assertEquals($jsonBody, format_work_funders_result($mockResult, 'json'));
        $this->assertEquals($jsonBody, format_funded_works_result($mockResult, 'json'));
        $this->assertEquals($jsonBody, format_list_types_result($mockResult, 'json'));
    }
}
