# PHP Model Context Protocol (MCP) server

Experiments with a Model Context Protocol (MCP) server in PHP. Original code developed using ChatGPT, MCP server run using Claude Desktop on a Mac. Debugging the code made extensive use of the logs `~/Library/Logs/Claude`.

```
{
  "mcpServers": {
    "php-sparql-mcp": {
      "command": "php",
      "args": [
        "/Users/rpage/Development/php-mcp-server/mcp_sparql_server.php"
      ],
      "env": {
        "SPARQL_ENDPOINT": "http://localhost:7878/query?union-default-graph"
      }
    }
  }
}
```

