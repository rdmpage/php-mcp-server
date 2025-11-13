#!/usr/bin/env php
<?php
// mcp_server.php
// Minimal MCP-compatible JSON-RPC server over stdio (PHP 7)

function readMessage()
{
    $headers = [];
    // Read headers
    while (true) {
        $line = fgets(STDIN);
        if ($line === false) {
            return null; // EOF
        }

        $line = rtrim($line, "\r\n");

        if ($line === '') {
            // Empty line: end of headers
            break;
        }

        $parts = explode(':', $line, 2);
        if (count($parts) === 2) {
            $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
        }
    }

    if (!isset($headers['content-length'])) {
        return null;
    }

    $length = (int)$headers['content-length'];
    if ($length <= 0) {
        return null;
    }

    $body = '';
    $remaining = $length;

    while ($remaining > 0) {
        $chunk = fread(STDIN, $remaining);
        if ($chunk === false || $chunk === '') {
            return null;
        }
        $body      .= $chunk;
        $remaining -= strlen($chunk);
    }

    $data = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return null;
    }

    return $data;
}

function sendMessage(array $msg)
{
    $json   = json_encode($msg, JSON_UNESCAPED_SLASHES);
    $length = strlen($json);

    // Content-Length framing as per MCP/LSP style
    $headers  = "Content-Length: {$length}\r\n";
    $headers .= "\r\n";

    fwrite(STDOUT, $headers . $json);
    fflush(STDOUT);
}

// Simple dispatch loop
while (!feof(STDIN)) {
    $request = readMessage();
    if ($request === null) {
        if (feof(STDIN)) {
            break;
        }
        continue;
    }

    $id     = isset($request['id']) ? $request['id'] : null;
    $method = isset($request['method']) ? $request['method'] : null;
    $params = isset($request['params']) ? $request['params'] : [];

    // Base response skeleton
    $response = [
        'jsonrpc' => '2.0',
        'id'      => $id,
    ];

    switch ($method) {
        case 'initialize':
            // Minimal MCP-compatible initialize response
            $response['result'] = [
                'protocolVersion' => '2024-11-05', // adjust to spec version if needed
                'serverInfo' => [
                    'name'    => 'php-mcp-demo',
                    'version' => '0.0.1',
                ],
                'capabilities' => [
                    // Advertise simple echo tool under "tools"
                    'tools' => [
                        'list' => true,
                        'call' => true,
                    ],
                    // You can leave resources empty for now
                    'resources' => [
                        'list'   => false,
                        'read'   => false,
                        'subscribe' => false,
                    ],
                    // Optional: logMessages, prompts, etc.
                ],
            ];
            break;

        case 'tools/list':
            // Return a single "echo" tool with one string input
            $response['result'] = [
                'tools' => [
                    [
                        'name'        => 'echo',
                        'description' => 'Echo back the provided text.',
                        'inputSchema' => [
                            'type'       => 'object',
                            'properties' => [
                                'text' => [
                                    'type'        => 'string',
                                    'description' => 'Text to echo back',
                                ],
                            ],
                            'required' => ['text'],
                        ],
                    ],
                ],
            ];
            break;

        case 'tools/call':
            // Expect: params.toolName, params.arguments
            $toolName = isset($params['toolName']) ? $params['toolName'] : null;
            $args     = isset($params['arguments']) ? $params['arguments'] : [];

            if ($toolName === 'echo') {
                $text = isset($args['text']) ? $args['text'] : '';
                $response['result'] = [
                    'toolName' => 'echo',
                    'content'  => [
                        [
                            'type' => 'text',
                            'text' => 'Echo: ' . $text,
                        ],
                    ],
                ];
            } else {
                $response['error'] = [
                    'code'    => -32601,
                    'message' => 'Unknown tool: ' . $toolName,
                ];
            }
            break;

        case 'ping':
            $response['result'] = ['ok' => true];
            break;

        default:
            $response['error'] = [
                'code'    => -32601,
                'message' => 'Method not found: ' . $method,
            ];
            break;
    }

    sendMessage($response);
}
