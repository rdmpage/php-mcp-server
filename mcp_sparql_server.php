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

// ---- CONFIG -----------------------------------------------------------

// Environment----------------------------------------------------------------------------
// In development this is a PHP file that is in .gitignore, when deployed these parameters
// will be set on the server
if (file_exists(dirname(__FILE__) . '/env.php'))
{
	include 'env.php';
}

function get_sparql_endpoint()
{
    $endpoint = getenv('SPARQL_ENDPOINT');
    if ($endpoint === false || $endpoint === '') {
        // Fallback, but you probably want to set SPARQL_ENDPOINT in Claude config
        $endpoint = 'https://example.org/sparql';
    }
    return $endpoint;
}

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

// ---- SPARQL WRAPPER ---------------------------------------------------

function run_sparql_query($endpoint, $query, $acceptJson = true)
{
    fwrite(STDERR, "[php-sparql-mcp] Running SPARQL query against $endpoint\n");

    $ch = curl_init();

    $postFields = http_build_query([
        'query' => $query,
    ], '', '&');

    curl_setopt($ch, CURLOPT_URL, $endpoint);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $headers = [];
    if ($acceptJson) {
        $headers[] = 'Accept: application/sparql-results+json, application/json;q=0.9, */*;q=0.1';
    } else {
        $headers[] = 'Accept: text/turtle, application/n-triples;q=0.9, application/rdf+xml;q=0.8, */*;q=0.1';
    }
    $headers[] = 'Content-Type: application/x-www-form-urlencoded; charset=UTF-8';

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20); // avoid hanging forever

    $responseBody = curl_exec($ch);
    $errno        = curl_errno($ch);
    $error        = curl_error($ch);
    $status       = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($errno) {
        fwrite(STDERR, "[php-sparql-mcp] cURL error: $error\n");
        return [
            'ok'     => false,
            'error'  => 'cURL error: ' . $error,
            'status' => $status,
        ];
    }

    return [
        'ok'     => true,
        'status' => $status,
        'body'   => $responseBody,
        'isJson' => $acceptJson,
    ];
}

//----------------------------------------------------------------------------------------
function format_sparql_result_as_text(array $result)
{
    if (!$result['ok']) {
        return 'SPARQL error (HTTP ' . $result['status'] . '): ' . $result['error'];
    }

    $body = $result['body'];

    $data = json_decode($body, true);
    if (json_last_error() === JSON_ERROR_NONE && $data !== null) {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    return $body;
}

//----------------------------------------------------------------------------------------
// Optional: higher-level DOI helper (only if you’re using the authorsByDoi pattern)
// (You can comment this out if not using it)
function build_authors_by_doi_query($doi)
{
    $doiLiteral = str_replace('"', '\"', $doi);

    $query = <<<SPARQL
PREFIX schema: <https://schema.org/>

SELECT DISTINCT ?authorName WHERE {
  ?article a schema:ScholarlyArticle ;
           schema:identifier ?doi ;
           schema:author ?author .

  ?author a schema:Person ;
          schema:name ?authorName .

  FILTER( LCASE(STR(?doi)) = LCASE("$doiLiteral") )
}
ORDER BY ?authorName
SPARQL;

    return $query;
}

//----------------------------------------------------------------------------------------
function format_authors_result_as_text(array $result)
{
    if (!$result['ok']) {
        return 'SPARQL error (HTTP ' . $result['status'] . '): ' . $result['error'];
    }

    $body = $result['body'];
    $data = json_decode($body, true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        return $body;
    }

    if (!isset($data['results']['bindings'])) {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    $bindings = $data['results']['bindings'];
    $names = [];

    foreach ($bindings as $row) {
        if (isset($row['authorName']['value'])) {
            $names[] = $row['authorName']['value'];
        }
    }

    if (empty($names)) {
        return "No authors found for that DOI.";
    }

    $out = "Authors:\n";
    foreach ($names as $name) {
        $out .= "- " . $name . "\n";
    }

    return $out;
}

// ---- MCP REQUEST HANDLER ----------------------------------------------

function handleRequest(array $request)
{
    $id     = isset($request['id']) ? $request['id'] : null;
    $method = isset($request['method']) ? $request['method'] : null;
    $params = isset($request['params']) ? $request['params'] : [];

    fwrite(STDERR, "[php-sparql-mcp] Handling method: " . ($method ?? 'null') . "\n");

    $response = [
        'jsonrpc' => '2.0',
        'id'      => $id,
    ];

    switch ($method) {
    
		//--------------------------------------------------------------------------------
        case 'initialize':
            // Mirror client's protocolVersion if provided
            $clientProtocol = isset($params['protocolVersion'])
                ? $params['protocolVersion']
                : '2025-06-18';

            $response['result'] = [
                'protocolVersion' => $clientProtocol,
                'serverInfo' => [
                    'name'    => 'php-sparql-mcp',
                    'version' => '0.1.1',
                ],
                'capabilities' => [
                    'tools' => [
                        'list' => true,
                        'call' => true,
                    ],
                    'resources' => [
                        'list'      => true,   // we implement resources/list
                        'read'      => false,
                        'subscribe' => false,
                    ],
                ],
            ];
            break;
            
		//--------------------------------------------------------------------------------
        case 'resources/list':
            // For now we expose no resources; return an empty list.
            $response['result'] = [
                'resources' => [],
            ];
            break;    
            
 		//--------------------------------------------------------------------------------
        case 'resources/read':
            // We don't actually implement any resources yet.
            $response['error'] = [
                'code'    => -32001,
                'message' => 'No resources are implemented by this server.',
            ];
            break;                    

		//--------------------------------------------------------------------------------
        case 'tools/list':
            $response['result'] = [
                'tools' => [
                    [
                        'name'        => 'sparqlQuery',
                        'description' => 'Run an arbitrary SPARQL query against the configured endpoint.',
                        'inputSchema' => [
                            'type'       => 'object',
                            'properties' => [
                                'query' => [
                                    'type'        => 'string',
                                    'description' => 'SPARQL query string.',
                                ],
                                'jsonPreferred' => [
                                    'type'        => 'boolean',
                                    'description' => 'If true, request SPARQL JSON results (default true).',
                                ],
                            ],
                            'required' => ['query'],
                        ],
                    ],
                    [
                        'name'        => 'authorsByDoi',
                        'description' => 'Given a DOI, find all authors of the corresponding schema.org ScholarlyArticle.',
                        'inputSchema' => [
                            'type'       => 'object',
                            'properties' => [
                                'doi' => [
                                    'type'        => 'string',
                                    'description' => 'DOI of the paper, e.g. "10.1234/foo.bar".',
                                ],
                            ],
                            'required' => ['doi'],
                        ],
                    ],
                ],
            ];
            break;

		//--------------------------------------------------------------------------------
        case 'tools/call':
			$toolName = $params['name'] ?? null;
            $args     = $params['arguments'] ?? [];

            switch ($toolName)
            {
            	// Generic SPARQL query
				case 'sparqlQuery':
					$query = isset($args['query']) ? $args['query'] : '';
					if (trim($query) === '') {
						$response['error'] = [
							'code'    => -32602,
							'message' => 'Missing or empty "query" argument for sparqlQuery.',
						];
						break;
					}
	
					$jsonPreferred = true;
					if (isset($args['jsonPreferred'])) {
						$jsonPreferred = (bool)$args['jsonPreferred'];
					}
	
					$endpoint = get_sparql_endpoint();
					$result   = run_sparql_query($endpoint, $query, $jsonPreferred);
					$text     = format_sparql_result_as_text($result);
	
					$response['result'] = [
						'toolName' => 'sparqlQuery',
						'content'  => [
							[
								'type' => 'text',
								'text' => $text,
							],
						],
						'meta' => [
							'endpoint' => $endpoint,
							'status'   => $result['ok'] ? $result['status'] : null,
						],
					];
					break;
					
				case 'authorsByDoi':
					$doi = isset($args['doi']) ? $args['doi'] : '';
					if (trim($doi) === '') {
						$response['error'] = [
							'code'    => -32602,
							'message' => 'Missing or empty \"doi\" argument for authorsByDoi.',
						];
						break;
					}
	
					$endpoint = get_sparql_endpoint();
					$query    = build_authors_by_doi_query($doi);
					$result   = run_sparql_query($endpoint, $query, true);
					$text     = format_authors_result_as_text($result);
	
					$response['result'] = [
						'toolName' => 'authorsByDoi',
						'content'  => [
							[
								'type' => 'text',
								'text' => $text,
							],
						],
						'meta' => [
							'endpoint' => $endpoint,
							'status'   => $result['ok'] ? $result['status'] : null,
							'doi'      => $doi,
						],
					];
					break;
					
				default:
					$response['error'] = [
						'code'    => -32601,
						'message' => 'Unknown tool: ' . $toolName,
					];
					break;
            }
            break;

		//--------------------------------------------------------------------------------
        case 'ping':
            $response['result'] = ['ok' => true];
            break;

 		//--------------------------------------------------------------------------------
       default:
            $response['error'] = [
                'code'    => -32601,
                'message' => 'Method not found: ' . $method,
            ];
            break;
    }

    return $response;
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
