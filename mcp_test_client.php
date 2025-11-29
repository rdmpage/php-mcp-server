<?php
/**
 * mcp_test_client.php
 *
 * Tiny PHP 7 MCP client to test an MCP server that communicates
 * over stdio using Content-Length framing (same protocol Claude uses).
 *
 * Usage:
 *   php mcp_test_client.php "/usr/bin/php" "/path/to/mcp_server.php" [extra args...]
 *
 * Example:
 *   php mcp_test_client.php php /Users/rpage/Development/php-mcp-test-o/mcp_sparql_server.php
 */

// --- CONFIG / ARGS -------------------------------------------------------

if ($argc < 3) {
    fwrite(STDERR, "Usage: php mcp_test_client.php <command> <script> [args...]\n");
    exit(1);
}

$command = $argv[1];
$args    = array_slice($argv, 2);

// Build command line as a string (PHP 7's proc_open expects a string)
$cmdline = escapeshellarg($command);
if (!empty($args)) {
    $escapedArgs = array_map('escapeshellarg', $args);
    $cmdline .= ' ' . implode(' ', $escapedArgs);
}

fwrite(STDERR, "Launching MCP server: $cmdline\n");

// 'r' = child reads → parent writes
// 'w' = child writes → parent reads
$descriptorspec = [
    0 => ['pipe', 'r'], // stdin  of child (we write to $pipes[0])
    1 => ['pipe', 'w'], // stdout of child (we read from $pipes[1])
    2 => ['pipe', 'w'], // stderr of child (we read from $pipes[2])
];

$process = proc_open($cmdline, $descriptorspec, $pipes);

if (!is_resource($process)) {
    fwrite(STDERR, "Failed to launch MCP server process.\n");
    exit(1);
}

// --- Helper Functions ----------------------------------------------------

/**
 * Send a JSON-RPC message using Content-Length framing.
 */
function send_mcp_message($pipes, array $msg)
{
    $json = json_encode($msg, JSON_UNESCAPED_SLASHES);
    $len  = strlen($json);

    $out  = "Content-Length: $len\r\n\r\n" . $json;

    fwrite($pipes[0], $out);
    fflush($pipes[0]);

    echo ">>> Sent:\n$out\n\n";
}

/**
 * Read one MCP response message using Content-Length framing.
 */
function read_mcp_message($pipes)
{
    // Read headers
    $headers = [];
    while (true) {
        $line = fgets($pipes[1]);
        if ($line === false) {
            echo "<<< EOF or error while reading headers\n";
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
        echo "<<< Invalid response (missing Content-Length)\n";
        return null;
    }

    $len = (int)$headers['content-length'];
    if ($len <= 0) {
        echo "<<< Invalid Content-Length value: $len\n";
        return null;
    }

    // Read JSON body
    $body = '';
    $remaining = $len;
    while ($remaining > 0) {
        $chunk = fread($pipes[1], $remaining);
        if ($chunk === false || $chunk === '') {
            echo "<<< Error or EOF while reading body\n";
            break;
        }
        $body .= $chunk;
        $remaining -= strlen($chunk);
    }

    echo "<<< Received raw body:\n$body\n\n";

    $data = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo "<<< JSON decode error: " . json_last_error_msg() . "\n";
        return null;
    }

    echo "<<< Decoded JSON:\n";
    var_dump($data);
    echo "\n";

    return $data;
}

// --- Test Sequence -------------------------------------------------------

// 1) initialize
send_mcp_message($pipes, [
    'jsonrpc' => '2.0',
    'id'      => 1,
    'method'  => 'initialize',
    'params'  => [],
]);
read_mcp_message($pipes);

// 2) tools/list
send_mcp_message($pipes, [
    'jsonrpc' => '2.0',
    'id'      => 2,
    'method'  => 'tools/list',
    'params'  => [],
]);
read_mcp_message($pipes);

// 3) Example tools/call for sparqlQuery (if your server exposes it)
send_mcp_message($pipes, [
    'jsonrpc' => '2.0',
    'id'      => 3,
    'method'  => 'tools/call',
    'params'  => [
        'toolName'  => 'sparqlQuery',
        'arguments' => [
            'query' => 'SELECT * WHERE { ?s ?p ?o } LIMIT 3',
        ],
    ],
]);
read_mcp_message($pipes);

// --- Cleanup: important order so we don't hang ---------------------------

// Close child's stdin so it knows there is no more input
fclose($pipes[0]);

// Ask PHP to terminate the child process
proc_terminate($process);

// Now it's safe to drain stderr (process should be exiting)
$stderr = stream_get_contents($pipes[2]);
if ($stderr !== '') {
    fwrite(STDERR, "=== Server STDERR ===\n$stderr\n======================\n");
}

// Close remaining pipes
fclose($pipes[1]);
fclose($pipes[2]);

// Finally, reap the process
proc_close($process);

echo "Done.\n";
