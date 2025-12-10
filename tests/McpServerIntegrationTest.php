<?php
/**
 * Integration tests for MCP protocol server
 *
 * These tests actually launch the MCP server and communicate with it
 * using the MCP protocol over stdio with Content-Length framing.
 *
 * To run: ./vendor/bin/phpunit tests/McpServerIntegrationTest.php
 */

use PHPUnit\Framework\TestCase;

class McpServerIntegrationTest extends TestCase
{
    private $process;
    private $pipes;

    /**
     * Start the MCP server before each test
     */
    protected function setUp(): void
    {
        $serverPath = __DIR__ . '/../mcp_sparql_server.php';
        $command = 'php ' . escapeshellarg($serverPath);

        $descriptorspec = [
            0 => ['pipe', 'r'], // stdin  - we write
            1 => ['pipe', 'w'], // stdout - we read
            2 => ['pipe', 'w'], // stderr - we read
        ];

        $this->process = proc_open($command, $descriptorspec, $this->pipes);

        if (!is_resource($this->process)) {
            $this->fail('Failed to launch MCP server process');
        }

        // Give server a moment to start
        usleep(100000); // 100ms
    }

    /**
     * Clean up the server process after each test
     */
    protected function tearDown(): void
    {
        if (is_resource($this->process)) {
            // Close stdin so server knows to exit
            if (isset($this->pipes[0]) && is_resource($this->pipes[0])) {
                fclose($this->pipes[0]);
            }

            // Terminate process
            proc_terminate($this->process);

            // Close remaining pipes
            if (isset($this->pipes[1]) && is_resource($this->pipes[1])) {
                fclose($this->pipes[1]);
            }
            if (isset($this->pipes[2]) && is_resource($this->pipes[2])) {
                fclose($this->pipes[2]);
            }

            // Wait for process to finish
            proc_close($this->process);
        }
    }

    /**
     * Send a JSON-RPC message using Content-Length framing
     */
    private function sendMessage(array $msg): void
    {
        $json = json_encode($msg, JSON_UNESCAPED_SLASHES);
        $len = strlen($json);
        $out = "Content-Length: $len\r\n\r\n" . $json;

        fwrite($this->pipes[0], $out);
        fflush($this->pipes[0]);
    }

    /**
     * Read one MCP response message using Content-Length framing
     */
    private function readMessage(): ?array
    {
        // Read headers
        $headers = [];
        while (true) {
            $line = fgets($this->pipes[1]);
            if ($line === false) {
                return null;
            }
            $trim = rtrim($line, "\r\n");
            if ($trim === '') {
                break; // end of headers
            }
            $parts = explode(':', $trim, 2);
            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
        }

        if (!isset($headers['content-length'])) {
            return null;
        }

        $len = (int)$headers['content-length'];
        if ($len <= 0) {
            return null;
        }

        // Read JSON body
        $body = '';
        $remaining = $len;
        while ($remaining > 0) {
            $chunk = fread($this->pipes[1], $remaining);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $body .= $chunk;
            $remaining -= strlen($chunk);
        }

        return json_decode($body, true);
    }

    // ========================================================================
    // PROTOCOL TESTS
    // ========================================================================

    /**
     * Test the initialize method returns proper server info
     */
    public function testInitialize()
    {
        $this->sendMessage([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => []
        ]);

        $response = $this->readMessage();

        // Basic JSON-RPC structure
        $this->assertIsArray($response);
        $this->assertEquals('2.0', $response['jsonrpc']);
        $this->assertEquals(1, $response['id']);
        $this->assertArrayHasKey('result', $response);

        // MCP initialize response structure
        $result = $response['result'];
        $this->assertArrayHasKey('protocolVersion', $result);
        $this->assertArrayHasKey('serverInfo', $result);
        $this->assertArrayHasKey('capabilities', $result);

        // Server info
        $this->assertEquals('php-sparql-mcp', $result['serverInfo']['name']);
        $this->assertArrayHasKey('version', $result['serverInfo']);

        // Capabilities
        $this->assertTrue($result['capabilities']['tools']['list']);
        $this->assertTrue($result['capabilities']['tools']['call']);
        $this->assertTrue($result['capabilities']['resources']['list']);
        $this->assertTrue($result['capabilities']['resources']['read']);
    }

    /**
     * Test the tools/list method returns available tools
     */
    public function testToolsList()
    {
        // Initialize first
        $this->sendMessage([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => []
        ]);
        $this->readMessage();

        // Now request tools list
        $this->sendMessage([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
            'params' => []
        ]);

        $response = $this->readMessage();

        $this->assertIsArray($response);
        $this->assertEquals('2.0', $response['jsonrpc']);
        $this->assertEquals(2, $response['id']);
        $this->assertArrayHasKey('result', $response);

        // Check tools array exists
        $result = $response['result'];
        $this->assertArrayHasKey('tools', $result);
        $this->assertIsArray($result['tools']);
        $this->assertNotEmpty($result['tools']);

        // Check for expected tools
        $toolNames = array_column($result['tools'], 'name');
        $this->assertContains('sparqlQuery', $toolNames);
        $this->assertContains('authorsOfWork', $toolNames);
        $this->assertContains('cites', $toolNames);
        $this->assertContains('citedBy', $toolNames);
        $this->assertContains('workFunders', $toolNames);
        $this->assertContains('fundedWorks', $toolNames);
        $this->assertContains('listTypes', $toolNames);

        // Validate tool structure
        foreach ($result['tools'] as $tool) {
            $this->assertArrayHasKey('name', $tool);
            $this->assertArrayHasKey('description', $tool);
            $this->assertArrayHasKey('inputSchema', $tool);
            $this->assertIsString($tool['name']);
            $this->assertIsString($tool['description']);
            $this->assertIsArray($tool['inputSchema']);
        }
    }

    /**
     * Test the resources/list method returns available resources
     */
    public function testResourcesList()
    {
        $this->sendMessage([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => []
        ]);
        $this->readMessage();

        $this->sendMessage([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'resources/list',
            'params' => []
        ]);

        $response = $this->readMessage();

        $this->assertIsArray($response);
        $this->assertEquals(2, $response['id']);
        $this->assertArrayHasKey('result', $response);

        // Check resources array
        $result = $response['result'];
        $this->assertArrayHasKey('resources', $result);
        $this->assertIsArray($result['resources']);

        // Check for expected resources
        $resourceUris = array_column($result['resources'], 'uri');
        $this->assertContains('sparql://schema', $resourceUris);
        $this->assertContains('sparql://examples', $resourceUris);

        // Validate resource structure
        foreach ($result['resources'] as $resource) {
            $this->assertArrayHasKey('uri', $resource);
            $this->assertArrayHasKey('name', $resource);
            $this->assertArrayHasKey('description', $resource);
        }
    }

    /**
     * Test reading a resource
     */
    public function testResourcesRead()
    {
        $this->sendMessage([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => []
        ]);
        $this->readMessage();

        $this->sendMessage([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'resources/read',
            'params' => [
                'uri' => 'sparql://schema'
            ]
        ]);

        $response = $this->readMessage();

        $this->assertIsArray($response);
        $this->assertEquals(2, $response['id']);
        $this->assertArrayHasKey('result', $response);

        // Check contents
        $result = $response['result'];
        $this->assertArrayHasKey('contents', $result);
        $this->assertIsArray($result['contents']);
        $this->assertNotEmpty($result['contents']);

        // Check first content item
        $content = $result['contents'][0];
        $this->assertArrayHasKey('uri', $content);
        $this->assertArrayHasKey('mimeType', $content);
        $this->assertArrayHasKey('text', $content);
        $this->assertEquals('sparql://schema', $content['uri']);
        $this->assertStringContainsString('schema.org', $content['text']);
    }

    /**
     * Test that unknown methods return proper error
     */
    public function testUnknownMethod()
    {
        $this->sendMessage([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'unknownMethod',
            'params' => []
        ]);

        $response = $this->readMessage();

        $this->assertIsArray($response);
        $this->assertEquals(1, $response['id']);
        $this->assertArrayHasKey('error', $response);

        // Check error structure
        $error = $response['error'];
        $this->assertArrayHasKey('code', $error);
        $this->assertArrayHasKey('message', $error);
        $this->assertEquals(-32601, $error['code']); // Method not found
    }

    /**
     * Test handling notifications (messages without id)
     */
    public function testNotificationHandling()
    {
        // Send a notification (no id)
        $this->sendMessage([
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
            'params' => []
        ]);

        // Send a regular request after
        $this->sendMessage([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => []
        ]);

        // Should get response to regular request (not notification)
        $response = $this->readMessage();

        $this->assertIsArray($response);
        $this->assertEquals(1, $response['id']);
        $this->assertArrayHasKey('result', $response);
    }

    /**
     * Test ping method
     */
    public function testPing()
    {
        $this->sendMessage([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'ping',
            'params' => []
        ]);

        $response = $this->readMessage();

        $this->assertIsArray($response);
        $this->assertEquals(1, $response['id']);
        $this->assertArrayHasKey('result', $response);
        $this->assertEquals(['ok' => true], $response['result']);
    }

    /**
     * Test that multiple sequential requests work correctly
     */
    public function testMultipleSequentialRequests()
    {
        // Request 1: Initialize
        $this->sendMessage([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => []
        ]);
        $response1 = $this->readMessage();
        $this->assertEquals(1, $response1['id']);

        // Request 2: Tools list
        $this->sendMessage([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
            'params' => []
        ]);
        $response2 = $this->readMessage();
        $this->assertEquals(2, $response2['id']);

        // Request 3: Resources list
        $this->sendMessage([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'resources/list',
            'params' => []
        ]);
        $response3 = $this->readMessage();
        $this->assertEquals(3, $response3['id']);

        // Request 4: Ping
        $this->sendMessage([
            'jsonrpc' => '2.0',
            'id' => 4,
            'method' => 'ping',
            'params' => []
        ]);
        $response4 = $this->readMessage();
        $this->assertEquals(4, $response4['id']);

        // All should have results
        $this->assertArrayHasKey('result', $response1);
        $this->assertArrayHasKey('result', $response2);
        $this->assertArrayHasKey('result', $response3);
        $this->assertArrayHasKey('result', $response4);
    }
}
