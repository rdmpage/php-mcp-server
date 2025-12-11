# HTTP Server Usage

The MCP server can run in two modes:

1. **Stdio mode** (for Claude Desktop, MCP clients) - `mcp_sparql_server.php`
2. **HTTP mode** (for web browsers, REST clients) - `mcp_http_server.php`

Both modes share the same request handler, so all features are available in both.

## Starting the HTTP Server

### Using PHP Built-in Server (Development)

```bash
php -S localhost:8000 mcp_http_server.php
```

The server will start on http://localhost:8000

### Using Apache/Nginx (Production)

Point your web server to `mcp_http_server.php` as the entry point.

## Testing the Server

### Get Server Info

```bash
curl http://localhost:8000
```

### Initialize

```bash
curl -X POST http://localhost:8000 \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}'
```

### List Tools

```bash
curl -X POST http://localhost:8000 \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}'
```

### Call a Tool

```bash
curl -X POST http://localhost:8000 \
  -H "Content-Type: application/json" \
  -d '{
    "jsonrpc":"2.0",
    "id":3,
    "method":"tools/call",
    "params":{
      "name":"authorsOfWork",
      "arguments":{
        "uri":"https://doi.org/10.1234/example"
      }
    }
  }'
```

### List Resources

```bash
curl -X POST http://localhost:8000 \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":4,"method":"resources/list","params":{}}'
```

### Read a Resource

```bash
curl -X POST http://localhost:8000 \
  -H "Content-Type: application/json" \
  -d '{
    "jsonrpc":"2.0",
    "id":5,
    "method":"resources/read",
    "params":{
      "uri":"sparql://schema"
    }
  }'
```

## Available Tools

All tools from the stdio server are available:

- **sparqlQuery** - Run arbitrary SPARQL query
- **authorsOfWork** - Find authors of a work by URI
- **relatedWorks** - Find related works via co-citation
- **cites** - Find citations from a work
- **citedBy** - Find works citing a target
- **workFunders** - Find funders of a work
- **fundedWorks** - Find works funded by an organization
- **listTypes** - List entity types in knowledge graph

## Configuration

Set the SPARQL endpoint via environment variable:

```bash
export SPARQL_ENDPOINT=https://your-endpoint.com/sparql
php -S localhost:8000 mcp_http_server.php
```

Or create an `env.php` file (in .gitignore):

```php
<?php
putenv('SPARQL_ENDPOINT=https://your-endpoint.com/sparql');
?>
```

## CORS

The server includes CORS headers for development:
- `Access-Control-Allow-Origin: *`
- `Access-Control-Allow-Methods: POST, GET, OPTIONS`

For production, you may want to restrict these in `mcp_http_server.php`.

## Architecture

The HTTP server reuses all the SPARQL logic:
- `sparql_tools.php` - SPARQL query builders and formatters (shared)
- `mcp_handler.php` - MCP request handler (shared)
- `mcp_http_server.php` - HTTP request/response handling
- `mcp_sparql_server.php` - Stdio request/response handling

This means adding a new tool updates both servers automatically!

## Error Handling

Invalid JSON returns HTTP 400:
```json
{
  "jsonrpc": "2.0",
  "id": null,
  "error": {
    "code": -32700,
    "message": "Parse error: Invalid JSON"
  }
}
```

Unknown methods return proper JSON-RPC error:
```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "error": {
    "code": -32601,
    "message": "Method not found: unknownMethod"
  }
}
```

## Logging

Requests and responses are logged to PHP error log (stderr):
```
[mcp-http] Received request: {"jsonrpc":"2.0",...}
[php-sparql-mcp] Handling method: initialize
[mcp-http] Sent response
```
