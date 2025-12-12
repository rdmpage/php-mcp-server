# PHP Model Context Protocol (MCP) server

Experiments with a Model Context Protocol (MCP) server in PHP. 

Original code developed using ChatGPT, the MCP server is run locally using Claude Desktop on a Mac. Debugging the code made extensive use of the logs `~/Library/Logs/Claude`. Subsequent development mostly using Claude Code, which has access to this repo and commits its changes to its own branch of the code (i.e., “vibe coding”). The initial code has been split across several files, unit tests added, as well as support for access over HTTP.

## Notes

### Claude MCP config file

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

### HTTP endpoint

The HTTP version can be launched using the PHP web server. Obviously, this is just for local experimentation.

```
php -S localhost:8000 mcp_http_server.php
```

