<?php
// mcp_handler.php
// Shared MCP request handler - handles JSON-RPC requests for both stdio and HTTP servers

require_once dirname(__FILE__) . '/sparql_tools.php';

// ---- MCP REQUEST HANDLER ----------------------------------------------

function handleRequest(array $request)
{
    $id     = isset($request['id']) ? $request['id'] : null;
    $method = isset($request['method']) ? $request['method'] : null;
    $params = isset($request['params']) ? $request['params'] : [];

    error_log("[php-sparql-mcp] Handling method: " . ($method ?? 'null'));

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
                        'list'      => true,
                        'read'      => true,
                        'subscribe' => false,
                    ],
                ],
            ];
            break;

		//--------------------------------------------------------------------------------
        case 'resources/list':
            $response['result'] = [
                'resources' => [
                    [
                        'uri'         => 'sparql://schema',
                        'name'        => 'Knowledge Graph Schema',
                        'description' => 'Description of the schema.org vocabulary used in the knowledge graph',
                        'mimeType'    => 'text/plain',
                    ],
                    [
                        'uri'         => 'sparql://examples',
                        'name'        => 'Example SPARQL Queries',
                        'description' => 'Common SPARQL query patterns for this knowledge graph',
                        'mimeType'    => 'text/plain',
                    ],
                ],
            ];
            break;

 		//--------------------------------------------------------------------------------
        case 'resources/read':
            $uri = $params['uri'] ?? null;

            switch ($uri) {
                case 'sparql://schema':
                    $content = <<<TEXT
# Knowledge Graph Schema

This knowledge graph uses schema.org vocabulary (http://schema.org/).

## URI Structure

- Works are identified by their URI (e.g., https://doi.org/10.1234/foo.bar)
- DOIs use the format: https://doi.org/[DOI]

## Main Entity Types

- **Works** (various types like schema:ScholarlyArticle, schema:Book, etc.)
  - Properties:
    - schema:name - Title of the work
    - schema:creator - Links to Person entities (creators/authors)
    - schema:datePublished - Publication date

- **schema:Person** - Authors and contributors
  - Properties:
    - schema:name - Full name of the person
    - schema:givenName - First name (when name is split)
    - schema:familyName - Last name (when name is split)
  - Note: A person may have either schema:name OR both givenName and familyName

## Common Prefixes

PREFIX schema: <http://schema.org/>

TEXT;

                    $response['result'] = [
                        'contents' => [
                            [
                                'uri'      => 'sparql://schema',
                                'mimeType' => 'text/plain',
                                'text'     => $content,
                            ],
                        ],
                    ];
                    break;

                case 'sparql://examples':
                    $content = <<<TEXT
# Example SPARQL Queries

PREFIX schema: <http://schema.org/>

## Find all properties of a work by URI

SELECT ?property ?value WHERE {
  <https://doi.org/10.1234/example> ?property ?value .
}

## List all creators/authors (handling different name formats)

SELECT
  ?author
  (COALESCE(
     ?nameStr,
     CONCAT(?givenStr, " ", ?familyStr),
     ?givenStr,
     ?familyStr
   ) AS ?authorName)
WHERE {
  SELECT
    ?author
    (SAMPLE(?name)       AS ?nameStr)
    (SAMPLE(?givenName)  AS ?givenStr)
    (SAMPLE(?familyName) AS ?familyStr)
  WHERE {
    ?author a schema:Person .
    OPTIONAL { ?author schema:name       ?name . }
    OPTIONAL { ?author schema:givenName  ?givenName . }
    OPTIONAL { ?author schema:familyName ?familyName . }
  }
  GROUP BY ?author
}

## Find works by a specific creator

SELECT ?work ?workName WHERE {
  ?work schema:name ?workName ;
        schema:creator ?author .
  ?author schema:name "John Doe" .
}

## Find creators of a work by URI

SELECT
  ?author
  (COALESCE(
     ?nameStr,
     CONCAT(?givenStr, " ", ?familyStr),
     ?givenStr,
     ?familyStr
   ) AS ?authorName)
WHERE {
  SELECT
    ?author
    (SAMPLE(?name)       AS ?nameStr)
    (SAMPLE(?givenName)  AS ?givenStr)
    (SAMPLE(?familyName) AS ?familyStr)
  WHERE {
    <https://doi.org/10.1234/example> schema:creator ?author .
    ?author a schema:Person .
    OPTIONAL { ?author schema:name       ?name . }
    OPTIONAL { ?author schema:givenName  ?givenName . }
    OPTIONAL { ?author schema:familyName ?familyName . }
  }
  GROUP BY ?author
}

TEXT;

                    $response['result'] = [
                        'contents' => [
                            [
                                'uri'      => 'sparql://examples',
                                'mimeType' => 'text/plain',
                                'text'     => $content,
                            ],
                        ],
                    ];
                    break;

                default:
                    $response['error'] = [
                        'code'    => -32002,
                        'message' => 'Unknown resource URI: ' . $uri,
                    ];
                    break;
            }
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
                        'name'        => 'authorsOfWork',
                        'description' => 'Given a work URI, find all authors/creators of the work.',
                        'inputSchema' => [
                            'type'       => 'object',
                            'properties' => [
                                'uri' => [
                                    'type'        => 'string',
                                    'description' => 'URI of the work, e.g. "https://doi.org/10.1234/foo.bar" or "https://example.org/work/123".',
                                ],
                            ],
                            'required' => ['uri'],
                        ],
                    ],
                    [
                        'name'        => 'relatedWorks',
                        'description' => 'Find works related to the given work using co-citation analysis.',
                        'inputSchema' => [
                            'type'       => 'object',
                            'properties' => [
                                'uri' => [
                                    'type'        => 'string',
                                    'description' => 'URI of the work, e.g. "https://doi.org/10.1234/foo.bar".',
                                ],
                            ],
                            'required' => ['uri'],
                        ],
                    ],
                    [
                        'name'        => 'cites',
                        'description' => 'Find all works and other resources cited by the given work.',
                        'inputSchema' => [
                            'type'       => 'object',
                            'properties' => [
                                'uri' => [
                                    'type'        => 'string',
                                    'description' => 'URI of the work, e.g. "https://doi.org/10.1234/foo.bar".',
                                ],
                            ],
                            'required' => ['uri'],
                        ],
                    ],
                    [
                        'name'        => 'citedBy',
                        'description' => 'Find all works that cite the given work.',
                        'inputSchema' => [
                            'type'       => 'object',
                            'properties' => [
                                'uri' => [
                                    'type'        => 'string',
                                    'description' => 'URI of the work, e.g. "https://doi.org/10.1234/foo.bar".',
                                ],
                            ],
                            'required' => ['uri'],
                        ],
                    ],
                    [
                        'name'        => 'workFunders',
                        'description' => 'Find organizations that funded a given work.',
                        'inputSchema' => [
                            'type'       => 'object',
                            'properties' => [
                                'uri' => [
                                    'type'        => 'string',
                                    'description' => 'URI of the work, e.g. "https://doi.org/10.1234/foo.bar".',
                                ],
                            ],
                            'required' => ['uri'],
                        ],
                    ],
                    [
                        'name'        => 'fundedWorks',
                        'description' => 'Find works and awards funded by a given organization.',
                        'inputSchema' => [
                            'type'       => 'object',
                            'properties' => [
                                'uri' => [
                                    'type'        => 'string',
                                    'description' => 'URI of the funder, e.g. "https://doi.org/10.13039/100000001".',
                                ],
                            ],
                            'required' => ['uri'],
                        ],
                    ],
                    [
                        'name'        => 'listTypes',
                        'description' => 'List all types of entities in the knowledge graph with counts.',
                        'inputSchema' => [
                            'type'       => 'object',
                            'properties' => new stdclass,
                        ],
                    ],
                    [
                        'name'        => 'listTypeProperties',
                        'description' => 'List literal properties (predicates with string values) for a given entity type, sampled from up to 1000 entities.',
                        'inputSchema' => [
                            'type'       => 'object',
                            'properties' => [
                                'uri' => [
                                    'type'        => 'string',
                                    'description' => 'URI of the entity type, e.g. "http://schema.org/ScholarlyArticle".',
                                ],
                            ],
                            'required' => ['uri'],
                        ],
                    ],
                    [
                        'name'        => 'listTypeLinks',
                        'description' => 'List entity links (neighborhood graph) for a given entity type, showing both outgoing and incoming links to other entities. Samples up to 1000 entities and groups by predicate, direction, and target type.',
                        'inputSchema' => [
                            'type'       => 'object',
                            'properties' => [
                                'uri' => [
                                    'type'        => 'string',
                                    'description' => 'URI of the entity type, e.g. "http://schema.org/ScholarlyArticle".',
                                ],
                            ],
                            'required' => ['uri'],
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
					$text     = format_sparql_result($result);

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

				case 'authorsOfWork':
					$uri = $args['uri'] ?? '';
					if (trim($uri) === '') {
						$response['error'] = [
							'code'    => -32602,
							'message' => 'Missing or empty "uri" argument for authorsOfWork.',
						];
						break;
					}

					$endpoint = get_sparql_endpoint();
					$query    = build_authors_of_work_query($uri);
					$result   = run_sparql_query($endpoint, $query, true);
					$text     = format_authors_result($result);

					$response['result'] = [
						'toolName' => 'authorsOfWork',
						'content'  => [
							[
								'type' => 'text',
								'text' => $text,
							],
						],
						'meta' => [
							'endpoint' => $endpoint,
							'status'   => $result['ok'] ? $result['status'] : null,
							'uri'      => $uri,
						],
					];
					break;

				case 'relatedWorks':
					$uri = $args['uri'] ?? '';
					if (trim($uri) === '') {
						$response['error'] = [
							'code'    => -32602,
							'message' => 'Missing or empty "uri" argument for relatedWorks.',
						];
						break;
					}

					$endpoint = get_sparql_endpoint();
					$query    = build_related_works_query($uri);
					$result   = run_sparql_query($endpoint, $query, true);
					$text     = format_related_works_result($result);

					$response['result'] = [
						'toolName' => 'relatedWorks',
						'content'  => [
							[
								'type' => 'text',
								'text' => $text,
							],
						],
						'meta' => [
							'endpoint' => $endpoint,
							'status'   => $result['ok'] ? $result['status'] : null,
							'uri'      => $uri,
						],
					];
					break;

				case 'cites':
					$uri = $args['uri'] ?? '';
					if (trim($uri) === '') {
						$response['error'] = [
							'code'    => -32602,
							'message' => 'Missing or empty "uri" argument for cites.',
						];
						break;
					}

					$endpoint = get_sparql_endpoint();
					$query    = build_cites_query($uri);
					$result   = run_sparql_query($endpoint, $query, true);
					$text     = format_cites_result($result);

					$response['result'] = [
						'toolName' => 'cites',
						'content'  => [
							[
								'type' => 'text',
								'text' => $text,
							],
						],
						'meta' => [
							'endpoint' => $endpoint,
							'status'   => $result['ok'] ? $result['status'] : null,
							'uri'      => $uri,
						],
					];
					break;

				case 'citedBy':
					$uri = $args['uri'] ?? '';
					if (trim($uri) === '') {
						$response['error'] = [
							'code'    => -32602,
							'message' => 'Missing or empty "uri" argument for citedBy.',
						];
						break;
					}

					$endpoint = get_sparql_endpoint();
					$query    = build_cited_by_query($uri);
					$result   = run_sparql_query($endpoint, $query, true);
					$text     = format_cited_by_result($result);

					$response['result'] = [
						'toolName' => 'citedBy',
						'content'  => [
							[
								'type' => 'text',
								'text' => $text,
							],
						],
						'meta' => [
							'endpoint' => $endpoint,
							'status'   => $result['ok'] ? $result['status'] : null,
							'uri'      => $uri,
						],
					];
					break;

				case 'workFunders':
					$uri = $args['uri'] ?? '';
					if (trim($uri) === '') {
						$response['error'] = [
							'code'    => -32602,
							'message' => 'Missing or empty "uri" argument for workFunders.',
						];
						break;
					}

					$endpoint = get_sparql_endpoint();
					$query    = build_work_funders_query($uri);
					$result   = run_sparql_query($endpoint, $query, true);
					$text     = format_work_funders_result($result);

					$response['result'] = [
						'toolName' => 'workFunders',
						'content'  => [
							[
								'type' => 'text',
								'text' => $text,
							],
						],
						'meta' => [
							'endpoint' => $endpoint,
							'status'   => $result['ok'] ? $result['status'] : null,
							'uri'      => $uri,
						],
					];
					break;

				case 'fundedWorks':
					$uri = $args['uri'] ?? '';
					if (trim($uri) === '') {
						$response['error'] = [
							'code'    => -32602,
							'message' => 'Missing or empty "uri" argument for fundedWorks.',
						];
						break;
					}

					$endpoint = get_sparql_endpoint();
					$query    = build_funded_works_query($uri);
					$result   = run_sparql_query($endpoint, $query, true);
					$text     = format_funded_works_result($result);

					$response['result'] = [
						'toolName' => 'fundedWorks',
						'content'  => [
							[
								'type' => 'text',
								'text' => $text,
							],
						],
						'meta' => [
							'endpoint' => $endpoint,
							'status'   => $result['ok'] ? $result['status'] : null,
							'uri'      => $uri,
						],
					];
					break;

				case 'listTypes':
					$endpoint = get_sparql_endpoint();
					$query    = build_list_types_query();
					$result   = run_sparql_query($endpoint, $query, true);
					$text     = format_list_types_result($result);

					$response['result'] = [
						'toolName' => 'listTypes',
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

				case 'listTypeProperties':
					$uri = $args['uri'] ?? '';
					if (trim($uri) === '') {
						$response['error'] = [
							'code'    => -32602,
							'message' => 'Missing or empty "uri" argument for listTypeProperties.',
						];
						break;
					}

					$endpoint = get_sparql_endpoint();
					$query    = build_list_type_properties_query($uri);
					$result   = run_sparql_query($endpoint, $query, true);
					$text     = format_list_type_properties_result($result);

					$response['result'] = [
						'toolName' => 'listTypeProperties',
						'content'  => [
							[
								'type' => 'text',
								'text' => $text,
							],
						],
						'meta' => [
							'endpoint' => $endpoint,
							'status'   => $result['ok'] ? $result['status'] : null,
							'uri'      => $uri,
						],
					];
					break;

				case 'listTypeLinks':
					$uri = $args['uri'] ?? '';
					if (trim($uri) === '') {
						$response['error'] = [
							'code'    => -32602,
							'message' => 'Missing or empty "uri" argument for listTypeLinks.',
						];
						break;
					}

					$endpoint = get_sparql_endpoint();
					$query    = build_list_type_links_query($uri);
					$result   = run_sparql_query($endpoint, $query, true);
					$text     = format_list_type_links_result($result);

					$response['result'] = [
						'toolName' => 'listTypeLinks',
						'content'  => [
							[
								'type' => 'text',
								'text' => $text,
							],
						],
						'meta' => [
							'endpoint' => $endpoint,
							'status'   => $result['ok'] ? $result['status'] : null,
							'uri'      => $uri,
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
