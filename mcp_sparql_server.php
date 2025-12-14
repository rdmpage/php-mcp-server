#!/usr/bin/env php
<?php
// mcp_sparql_server.php
// MCP stdio server in PHP 7 that wraps a SPARQL endpoint, hardened for Claude Desktop.


// ---- RUNTIME SAFETY / LOGGING -----------------------------------------

error_reporting(E_ALL);
ini_set('display_errors', '0');        // never echo PHP errors to stdout
ini_set('log_errors', '1');
ini_set('error_log', 'php://stderr');  // send errors to stderr (Claude log)

fwrite(STDERR, "[php-sparql-mcp] Starting server\n");

// ---- SHARED COMPONENTS ------------------------------------------------

// Load composer autoload (includes sparql_tools.php and citeproc-php)
require_once dirname(__FILE__) . '/vendor/autoload.php';

// Load MCP request handler (shared with HTTP server)
require_once dirname(__FILE__) . '/mcp_handler.php';

// ---- MCP FRAMING (Content-Length over stdio) --------------------------

// Track which protocol mode is being used
$GLOBALS['use_headers'] = false;

function readMessage()
{
	// Auto-detect protocol: headers (Content-Length) or line-delimited JSON
	while (true) {
		$line = fgets(STDIN);
		if ($line === false) {
			fwrite(STDERR, "[php-sparql-mcp] EOF or error reading line\n");
			return null;
		}

		$lineTrimmed = rtrim($line, "\r\n");

		if ($lineTrimmed === '') {
			// skip stray blank lines
			continue;
		}

		// Case 1: line-delimited JSON (no headers) - used by Claude
		$firstChar = ltrim($lineTrimmed);
		if ($firstChar !== '' && ($firstChar[0] === '{' || $firstChar[0] === '[')) {
			$GLOBALS['use_headers'] = false;
			fwrite(STDERR, "[php-sparql-mcp] Received JSON line (no headers): $lineTrimmed\n");

			$data = json_decode($lineTrimmed, true);
			if (json_last_error() !== JSON_ERROR_NONE) {
				fwrite(STDERR, "[php-sparql-mcp] JSON decode error: " . json_last_error_msg() . "\n");
				return null;
			}
			return $data;
		}

		// Case 2: header-based framing (Content-Length) - used by test client
		$GLOBALS['use_headers'] = true;
		$headers = [];
		$headersLine = $lineTrimmed;

		while (true) {
			if ($headersLine === '') {
				break;
			}

			$parts = explode(':', $headersLine, 2);
			if (count($parts) === 2) {
				$headers[strtolower(trim($parts[0]))] = trim($parts[1]);
			}

			$next = fgets(STDIN);
			if ($next === false) {
				fwrite(STDERR, "[php-sparql-mcp] EOF or error reading header\n");
				return null;
			}
			$headersLine = rtrim($next, "\r\n");
		}

		if (!isset($headers['content-length'])) {
			fwrite(STDERR, "[php-sparql-mcp] Missing Content-Length header\n");
			return null;
		}

		$length = (int)$headers['content-length'];
		if ($length <= 0) {
			fwrite(STDERR, "[php-sparql-mcp] Invalid Content-Length: $length\n");
			return null;
		}

		$body = '';
		$remaining = $length;

		while ($remaining > 0) {
			$chunk = fread(STDIN, $remaining);
			if ($chunk === false || $chunk === '') {
				fwrite(STDERR, "[php-sparql-mcp] Error or EOF while reading body\n");
				return null;
			}
			$body      .= $chunk;
			$remaining -= strlen($chunk);
		}

		fwrite(STDERR, "[php-sparql-mcp] Received body (with headers): $body\n");

		$data = json_decode($body, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			fwrite(STDERR, "[php-sparql-mcp] JSON decode error: " . json_last_error_msg() . "\n");
			return null;
		}

		return $data;
	}
}

function sendMessage(array $msg)
{
	$json = json_encode($msg, JSON_UNESCAPED_SLASHES);

	if ($GLOBALS['use_headers']) {
		// Send with Content-Length headers (for test client)
		$length = strlen($json);
		fwrite(STDOUT, "Content-Length: {$length}\r\n\r\n{$json}");
		fwrite(STDERR, "[php-sparql-mcp] Sent with headers: $json\n");
	} else {
		// Send as line-delimited JSON (for Claude)
		fwrite(STDOUT, $json . "\n");
		fwrite(STDERR, "[php-sparql-mcp] Sent line: $json\n");
	}

	fflush(STDOUT);
}

// ---- MAIN LOOP --------------------------------------------------------

fwrite(STDERR, "[php-sparql-mcp] Entering main loop\n");

while (!feof(STDIN)) {
    $request = readMessage();
    if ($request === null) {
        if (feof(STDIN)) {
            fwrite(STDERR, "[php-sparql-mcp] STDIN EOF, exiting\n");
            break;
        }
        // Invalid / partial message; keep listening
        continue;
    }
    
    // Need to do this otherwise Claude complains at startup
    // Notifications have no "id" → don't send a response
    if (!isset($request['id'])) {
        $method = isset($request['method']) ? $request['method'] : '(no method)';
        fwrite(STDERR, "[php-sparql-mcp] Received notification: $method\n");
        // Optionally handle notifications here if you want.
        continue;
    }    

    $response = handleRequest($request);
    sendMessage($response);
}

fwrite(STDERR, "[php-sparql-mcp] Server shutting down\n");