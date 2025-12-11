<?php
// mcp_http_server.php
// MCP server over HTTP - handles JSON-RPC requests via POST
// Can be used with PHP's built-in web server or any web server

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', 'php://stderr');

// Load shared MCP request handler
require_once __DIR__ . '/mcp_handler.php';

// ---- HTTP REQUEST HANDLING --------------------------------------------

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// Enable CORS for development
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight OPTIONS request
if ($method === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Handle POST requests (MCP JSON-RPC)
if ($method === 'POST') {
    $input = file_get_contents('php://input');
    $request = json_decode($input, true);

    if ($request === null) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode([
            'jsonrpc' => '2.0',
            'id' => null,
            'error' => [
                'code' => -32700,
                'message' => 'Parse error: Invalid JSON',
            ],
        ]);
        exit;
    }

    // Log the request
    error_log("[mcp-http] Received request: " . $input);

    // Handle the request using shared handler
    $response = handleRequest($request);

    // Send JSON response
    header('Content-Type: application/json');
    echo json_encode($response, JSON_UNESCAPED_SLASHES);

    error_log("[mcp-http] Sent response");
    exit;
}

// Handle GET requests (info page)
if ($method === 'GET') {
    header('Content-Type: text/plain');
    echo <<<TEXT
MCP SPARQL Server - HTTP Endpoint

This server implements the Model Context Protocol (MCP) over HTTP.

Usage:
  POST JSON-RPC requests to this endpoint.

Example:
  curl -X POST http://localhost:8000 \\
    -H "Content-Type: application/json" \\
    -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}'

Available methods:
  - initialize
  - tools/list
  - tools/call
  - resources/list
  - resources/read
  - ping

Available tools:
  - sparqlQuery - Run arbitrary SPARQL query
  - authorsOfWork - Find authors of a work by URI
  - relatedWorks - Find related works via co-citation
  - cites - Find citations from a work
  - citedBy - Find works citing a target
  - workFunders - Find funders of a work
  - fundedWorks - Find works funded by an organization
  - listTypes - List entity types in knowledge graph

Resources:
  - sparql://schema - Knowledge graph schema documentation
  - sparql://examples - Example SPARQL queries

Documentation: See README.md
TEXT;
    exit;
}

// Other methods not supported
http_response_code(405);
header('Allow: GET, POST, OPTIONS');
echo "Method Not Allowed\n";
