<?php
// sparql_tools.php
// SPARQL query building and formatting functions for bibliographic knowledge graph

use Seboettg\CiteProc\StyleSheet;
use Seboettg\CiteProc\CiteProc;

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

// ---- SPARQL WRAPPER ---------------------------------------------------

function run_sparql_query($endpoint, $query, $acceptJson = true)
{
    error_log("[php-sparql-mcp] Running SPARQL query against $endpoint");

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
        error_log("[php-sparql-mcp] cURL error: $error");
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
function format_sparql_result($result, $format = 'text')
{
    if (!$result['ok']) {
        return 'SPARQL error (HTTP ' . $result['status'] . '): ' . $result['error'];
    }

    $body = $result['body'];

    switch ($format) {
        case 'json':
            return $body;

        case 'text':
        default:
            $data = json_decode($body, true);
            if (json_last_error() === JSON_ERROR_NONE && $data !== null) {
                return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            }
            return $body;
    }
}

//----------------------------------------------------------------------------------------
// Get authors of a work by its URI (DOI, URL, etc.)
function build_authors_of_work_query($uri)
{
    $query = <<<SPARQL
PREFIX schema: <http://schema.org/>

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
    <$uri> schema:creator ?author .
    ?author a schema:Person .

    OPTIONAL { ?author schema:name       ?name . }
    OPTIONAL { ?author schema:givenName  ?givenName . }
    OPTIONAL { ?author schema:familyName ?familyName . }
  }
  GROUP BY ?author
}
SPARQL;

    return $query;
}

//----------------------------------------------------------------------------------------
function format_authors_result($result, $format = 'text')
{
    if (!$result['ok']) {
        return 'SPARQL error (HTTP ' . $result['status'] . '): ' . $result['error'];
    }

    $body = $result['body'];

    switch ($format) {
        case 'json':
            return $body;

        case 'text':
        default:
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
                return "No authors found for that work.";
            }

            $out = "Authors:\n";
            foreach ($names as $name) {
                $out .= "- " . $name . "\n";
            }

            return $out;
    }
}

//----------------------------------------------------------------------------------------
// Find related works using co-citation analysis
function build_related_works_query($uri)
{
    $query = <<<SPARQL
PREFIX rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
PREFIX schema: <http://schema.org/>

SELECT * WHERE
{
  {
    SELECT
      ?other_work
      (COUNT(DISTINCT ?citing_work) AS ?c)
      (SAMPLE(?title) AS ?title)
    WHERE {
      VALUES ?work { <$uri> }

      ?citing_work schema:citation ?work .
      ?citing_work schema:citation ?other_work .

      FILTER (?work != ?other_work)

      OPTIONAL {
        ?other_work schema:name ?title
      }
    }
    GROUP BY ?other_work
  }
  FILTER (?c >= 2)
}
ORDER BY DESC(?c)
LIMIT 10
SPARQL;

    return $query;
}

//----------------------------------------------------------------------------------------
function format_related_works_result($result, $format = 'text')
{
    if (!$result['ok']) {
        return 'SPARQL error (HTTP ' . $result['status'] . '): ' . $result['error'];
    }

    $body = $result['body'];

    switch ($format) {
        case 'json':
            return $body;

        case 'text':
        default:
            $data = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
                return $body;
            }

            if (!isset($data['results']['bindings'])) {
                return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            }

            $bindings = $data['results']['bindings'];
            $works = [];

            foreach ($bindings as $row) {
                $work = [];
                if (isset($row['other_work']['value'])) {
                    $work['uri'] = $row['other_work']['value'];
                }
                if (isset($row['c']['value'])) {
                    $work['count'] = $row['c']['value'];
                }
                if (isset($row['title']['value'])) {
                    $work['title'] = $row['title']['value'];
                }
                if (!empty($work)) {
                    $works[] = $work;
                }
            }

            if (empty($works)) {
                return "No related works found.";
            }

            $out = "Related works (by co-citation):\n\n";
            foreach ($works as $work) {
                $count = $work['count'] ?? '?';
                $title = $work['title'] ?? 'Untitled';
                $uri = $work['uri'] ?? '';
                $out .= "[$count co-citations] $title\n  $uri\n\n";
            }

            return $out;
    }
}

//----------------------------------------------------------------------------------------
// Find works cited by a given work
function build_cites_query($uri)
{
    $query = <<<SPARQL
PREFIX : <http://schema.org/>

SELECT ?citation (SAMPLE(?rawTitle) AS ?title)
WHERE
{
  <$uri> :citation ?citation .
  OPTIONAL
  {
     ?citation :name ?rawTitle .
  }
}
GROUP BY ?citation
SPARQL;

    return $query;
}

//----------------------------------------------------------------------------------------
function format_cites_result($result, $format = 'text')
{
    if (!$result['ok']) {
        return 'SPARQL error (HTTP ' . $result['status'] . '): ' . $result['error'];
    }

    $body = $result['body'];

    switch ($format) {
        case 'json':
            return $body;

        case 'text':
        default:
            $data = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
                return $body;
            }

            if (!isset($data['results']['bindings'])) {
                return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            }

            $bindings = $data['results']['bindings'];
            $citations = [];

            foreach ($bindings as $row) {
                $cite = [];
                if (isset($row['citation']['value'])) {
                    $cite['uri'] = $row['citation']['value'];
                }
                if (isset($row['title']['value'])) {
                    $cite['title'] = $row['title']['value'];
                }
                if (!empty($cite)) {
                    $citations[] = $cite;
                }
            }

            if (empty($citations)) {
                return "No citations found.";
            }

            $out = "Citations:\n\n";
            foreach ($citations as $cite) {
                $title = $cite['title'] ?? 'Untitled';
                $uri = $cite['uri'] ?? '';
                $out .= "$title\n  $uri\n\n";
            }

            return $out;
    }
}

//----------------------------------------------------------------------------------------
// Find works that cite a given work
function build_cited_by_query($uri)
{
    $query = <<<SPARQL
PREFIX : <http://schema.org/>

SELECT ?cites (SAMPLE(?rawTitle) AS ?title)
WHERE
{
  ?cites :citation <$uri> .
  OPTIONAL
  {
     ?cites :name ?rawTitle .
  }
}
GROUP BY ?cites
SPARQL;

    return $query;
}

//----------------------------------------------------------------------------------------
function format_cited_by_result($result, $format = 'text')
{
    if (!$result['ok']) {
        return 'SPARQL error (HTTP ' . $result['status'] . '): ' . $result['error'];
    }

    $body = $result['body'];

    switch ($format) {
        case 'json':
            return $body;

        case 'text':
        default:
            $data = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
                return $body;
            }

            if (!isset($data['results']['bindings'])) {
                return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            }

            $bindings = $data['results']['bindings'];
            $citing = [];

            foreach ($bindings as $row) {
                $cite = [];
                if (isset($row['cites']['value'])) {
                    $cite['uri'] = $row['cites']['value'];
                }
                if (isset($row['title']['value'])) {
                    $cite['title'] = $row['title']['value'];
                }
                if (!empty($cite)) {
                    $citing[] = $cite;
                }
            }

            if (empty($citing)) {
                return "No citing works found.";
            }

            $out = "Cited by:\n\n";
            foreach ($citing as $cite) {
                $title = $cite['title'] ?? 'Untitled';
                $uri = $cite['uri'] ?? '';
                $out .= "$title\n  $uri\n\n";
            }

            return $out;
    }
}

//----------------------------------------------------------------------------------------
// Find funders of a work
function build_work_funders_query($uri)
{
    $query = <<<SPARQL
PREFIX rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
PREFIX : <http://schema.org/>

SELECT ?funder (SAMPLE(?rawName) AS ?name)
WHERE
{
  VALUES ?work {<$uri> }
  {
  ?work :funder ?funder .
  }
  UNION
  {
    ?work :funding ?award .
    ?award :funder ?funder .
  }
  ?funder :name ?rawName .
}
GROUP BY ?funder
SPARQL;

    return $query;
}

//----------------------------------------------------------------------------------------
function format_work_funders_result($result, $format = 'text')
{
    if (!$result['ok']) {
        return 'SPARQL error (HTTP ' . $result['status'] . '): ' . $result['error'];
    }

    $body = $result['body'];

    switch ($format) {
        case 'json':
            return $body;

        case 'text':
        default:
            $data = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
                return $body;
            }

            if (!isset($data['results']['bindings'])) {
                return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            }

            $bindings = $data['results']['bindings'];
            $funders = [];

            foreach ($bindings as $row) {
                $funder = [];
                if (isset($row['funder']['value'])) {
                    $funder['uri'] = $row['funder']['value'];
                }
                if (isset($row['name']['value'])) {
                    $funder['name'] = $row['name']['value'];
                }
                if (!empty($funder)) {
                    $funders[] = $funder;
                }
            }

            if (empty($funders)) {
                return "No funders found.";
            }

            $out = "Funders:\n\n";
            foreach ($funders as $funder) {
                $name = $funder['name'] ?? 'Unnamed';
                $uri = $funder['uri'] ?? '';
                $out .= "$name\n  $uri\n\n";
            }

            return $out;
    }
}

//----------------------------------------------------------------------------------------
// Find works funded by a funder
function build_funded_works_query($uri)
{
    $query = <<<SPARQL
PREFIX : <http://schema.org/>

SELECT ?work ?award (SAMPLE(?rawName) AS ?name)
WHERE
{
  VALUES ?funder  {<$uri> }
  {
    ?work :funder ?funder .
  }
  UNION
  {
    ?work :funding ?funding .
    ?funding :identifier ?award .
    ?funding :funder ?funder .
  }
  ?work :name ?rawName .
}
GROUP BY ?work ?award
SPARQL;

    return $query;
}

//----------------------------------------------------------------------------------------
function format_funded_works_result($result, $format = 'text')
{
    if (!$result['ok']) {
        return 'SPARQL error (HTTP ' . $result['status'] . '): ' . $result['error'];
    }

    $body = $result['body'];

    switch ($format) {
        case 'json':
            return $body;

        case 'text':
        default:
            $data = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
                return $body;
            }

            if (!isset($data['results']['bindings'])) {
                return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            }

            $bindings = $data['results']['bindings'];
            $works = [];

            foreach ($bindings as $row) {
                $work = [];
                if (isset($row['work']['value'])) {
                    $work['uri'] = $row['work']['value'];
                }
                if (isset($row['award']['value'])) {
                    $work['award'] = $row['award']['value'];
                }
                if (isset($row['name']['value'])) {
                    $work['name'] = $row['name']['value'];
                }
                if (!empty($work)) {
                    $works[] = $work;
                }
            }

            if (empty($works)) {
                return "No funded works found.";
            }

            $out = "Funded works:\n\n";
            foreach ($works as $work) {
                $name = $work['name'] ?? 'Untitled';
                $uri = $work['uri'] ?? '';
                $award = isset($work['award']) ? " [Award: {$work['award']}]" : '';
                $out .= "$name$award\n  $uri\n\n";
            }

            return $out;
    }
}

//----------------------------------------------------------------------------------------
// List all types in the knowledge graph
function build_list_types_query()
{
    $query = <<<SPARQL
PREFIX rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
SELECT ?type ?label (COUNT(?thing) AS ?count) WHERE {
	?thing a ?type .
	OPTIONAL
    {
		?type rdfs:label ?label .
	}
}
GROUP BY ?type ?label
ORDER BY DESC(?count)
SPARQL;

    return $query;
}

//----------------------------------------------------------------------------------------
function format_list_types_result($result, $format = 'text')
{
    if (!$result['ok']) {
        return 'SPARQL error (HTTP ' . $result['status'] . '): ' . $result['error'];
    }

    $body = $result['body'];

    switch ($format) {
        case 'json':
            return $body;

        case 'text':
        default:
            $data = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
                return $body;
            }

            if (!isset($data['results']['bindings'])) {
                return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            }

            $bindings = $data['results']['bindings'];
            $types = [];

            foreach ($bindings as $row) {
                $type = [];
                if (isset($row['type']['value'])) {
                    $type['uri'] = $row['type']['value'];
                }
                if (isset($row['label']['value'])) {
                    $type['label'] = $row['label']['value'];
                }
                if (isset($row['count']['value'])) {
                    $type['count'] = $row['count']['value'];
                }
                if (!empty($type)) {
                    $types[] = $type;
                }
            }

            if (empty($types)) {
                return "No types found.";
            }

            $out = "Types in knowledge graph:\n\n";
            foreach ($types as $type) {
                $label = $type['label'] ?? '';
                $uri = $type['uri'] ?? '';
                $count = $type['count'] ?? '0';

                if ($label) {
                    $out .= "$label ($count instances)\n  $uri\n\n";
                } else {
                    $out .= "$uri ($count instances)\n\n";
                }
            }

            return $out;
    }
}

//----------------------------------------------------------------------------------------
// List literal predicates for a given entity type
function build_list_type_properties_query($uri)
{
    $query = <<<SPARQL
PREFIX rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#>

SELECT ?p (COUNT(?p) AS ?count) WHERE
{
  {
    SELECT ?s
    WHERE
    {
      ?s rdf:type <$uri> .
    }
    LIMIT 1000
  }

  ?s ?p ?o .
  FILTER isLiteral(?o)
}
GROUP BY ?p
SPARQL;

    return $query;
}

//----------------------------------------------------------------------------------------
function format_list_type_properties_result($result, $format = 'text')
{
    if (!$result['ok']) {
        return 'SPARQL error (HTTP ' . $result['status'] . '): ' . $result['error'];
    }

    $body = $result['body'];

    switch ($format) {
        case 'json':
            return $body;

        case 'text':
        default:
            $data = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
                return $body;
            }

            if (!isset($data['results']['bindings'])) {
                return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            }

            $bindings = $data['results']['bindings'];
            $properties = [];

            foreach ($bindings as $row) {
                $prop = [];
                if (isset($row['p']['value'])) {
                    $prop['uri'] = $row['p']['value'];
                }
                if (isset($row['count']['value'])) {
                    $prop['count'] = $row['count']['value'];
                }
                if (!empty($prop)) {
                    $properties[] = $prop;
                }
            }

            if (empty($properties)) {
                return "No literal properties found for this type.";
            }

            $out = "Literal properties:\n\n";
            foreach ($properties as $prop) {
                $uri = $prop['uri'] ?? '';
                $count = $prop['count'] ?? '0';
                $out .= "$uri ($count occurrences)\n";
            }

            return $out;
    }
}

//----------------------------------------------------------------------------------------
// List entity links (neighborhood) for a given entity type
function build_list_type_links_query($uri)
{
    $query = <<<SPARQL
PREFIX rdf:    <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
PREFIX schema: <http://schema.org/>

SELECT ?direction ?predicate ?nThings ?example (SAMPLE(?t) AS ?exampleType)
WHERE {
  {
    # Incoming: ?thing ?predicate ?dataset
    SELECT ?direction ?predicate ?nThings ?example WHERE {
      {
        SELECT ("incoming" AS ?direction)
               ?predicate
               (COUNT(DISTINCT ?thing) AS ?nThings)
               (SAMPLE(?thing) AS ?example)
        WHERE {
          ?centre a <$uri> .
          ?thing ?predicate ?centre .
          FILTER(isIRI(?thing))
          FILTER(?predicate != rdf:type)
        }
        GROUP BY ?predicate
      }
    }
  }
  UNION
  {
    # Outgoing: ?dataset ?predicate ?thing
    SELECT ?direction ?predicate ?nThings ?example WHERE {
      {
        SELECT ("outgoing" AS ?direction)
               ?predicate
               (COUNT(DISTINCT ?thing) AS ?nThings)
               (SAMPLE(?thing) AS ?example)
        WHERE {
          ?centre a <$uri>.
          ?centre ?predicate ?thing .
          FILTER(isIRI(?thing))
          FILTER(?predicate != rdf:type)
        }
        GROUP BY ?predicate
      }
    }
  }

  OPTIONAL { ?example a ?t }
}
GROUP BY ?direction ?predicate ?nThings ?example
ORDER BY ?direction DESC(?nThings)
SPARQL;

    return $query;
}

//----------------------------------------------------------------------------------------
function format_list_type_links_result($result, $format = 'text', $uri = '')
{
    if (!$result['ok']) {
        return 'SPARQL error (HTTP ' . $result['status'] . '): ' . $result['error'];
    }

    $body = $result['body'];

    switch ($format) {
        case 'json':
            return $body;

        case 'dot':
        case 'graphviz':
            $data = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
                return $body;
            }

            if (!isset($data['results']['bindings'])) {
                return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            }

            $bindings = $data['results']['bindings'];

            // Helper function to create node ID from URI
            $makeNodeId = function($uri) {
                if ($uri === '[no type]' || empty($uri)) {
                    return 'unknown';
                }
                // Use the last part of the URI as node ID
                $parts = explode('/', $uri);
                $id = end($parts);
                if (strpos($id, '#') !== false) {
                    $parts = explode('#', $id);
                    $id = end($parts);
                }
                return preg_replace('/[^a-zA-Z0-9_]/', '_', $id);
            };

            // Helper function to get label from URI
            $makeLabel = function($uri) {
                if ($uri === '[no type]' || empty($uri)) {
                    return 'Unknown';
                }
                $parts = explode('/', $uri);
                $label = end($parts);
                if (strpos($label, '#') !== false) {
                    $parts = explode('#', $label);
                    $label = end($parts);
                }
                return $label;
            };

            // Helper function to get edge label from predicate URI
            $makeEdgeLabel = function($uri) {
                $parts = explode('/', $uri);
                $label = end($parts);
                if (strpos($label, '#') !== false) {
                    $parts = explode('#', $label);
                    $label = end($parts);
                }
                return $label;
            };

            $dot = "digraph {\n";

            // Define the central node
            $centralId = $makeNodeId($uri);
            $centralLabel = $makeLabel($uri);
            $dot .= "  $centralId [label=\"$centralLabel\"];\n";

            $nodes = [];
            $edges = [];

            foreach ($bindings as $row) {
                if (!isset($row['predicate']['value']) || !isset($row['direction']['value'])) {
                    continue;
                }

                $predicate = $row['predicate']['value'];
                $direction = $row['direction']['value'];
                $exampleType = $row['exampleType']['value'] ?? '[no type]';

                $targetId = $makeNodeId($exampleType);
                $targetLabel = $makeLabel($exampleType);
                $edgeLabel = $makeEdgeLabel($predicate);

                // Add node if not already added
                if (!isset($nodes[$targetId])) {
                    $nodes[$targetId] = $targetLabel;
                }

                // Add edge
                if ($direction === 'outgoing') {
                    $edges[] = "  $centralId -> $targetId [label=\"$edgeLabel\"];";
                } else if ($direction === 'incoming') {
                    $edges[] = "  $targetId -> $centralId [label=\"$edgeLabel\"];";
                }
            }

            // Add all nodes
            foreach ($nodes as $id => $label) {
                $dot .= "  $id [label=\"$label\"];\n";
            }

            // Add all edges
            foreach ($edges as $edge) {
                $dot .= "$edge\n";
            }

            $dot .= "}\n";

            return $dot;

        case 'text':
        default:
            $data = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
                return $body;
            }

            if (!isset($data['results']['bindings'])) {
                return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            }

            $bindings = $data['results']['bindings'];
            $outgoing = [];
            $incoming = [];

            foreach ($bindings as $row) {
                $link = [];
                if (isset($row['predicate']['value'])) {
                    $link['predicate'] = $row['predicate']['value'];
                }
                if (isset($row['nThings']['value'])) {
                    $link['count'] = $row['nThings']['value'];
                }
                if (isset($row['example']['value'])) {
                    $link['example'] = $row['example']['value'];
                }
                if (isset($row['exampleType']['value'])) {
                    $link['exampleType'] = $row['exampleType']['value'];
                } else {
                    $link['exampleType'] = '[no type]';
                }

                if (!empty($link) && isset($row['direction']['value'])) {
                    $direction = $row['direction']['value'];
                    if ($direction === 'outgoing') {
                        $outgoing[] = $link;
                    } else if ($direction === 'incoming') {
                        $incoming[] = $link;
                    }
                }
            }

            if (empty($outgoing) && empty($incoming)) {
                return "No links found for this type.";
            }

            $out = '';

            if (!empty($outgoing)) {
                $out .= "Outgoing links:\n\n";
                foreach ($outgoing as $link) {
                    $predicate = $link['predicate'] ?? '';
                    $count = $link['count'] ?? '0';
                    $example = $link['example'] ?? '';
                    $exampleType = $link['exampleType'] ?? '[no type]';
                    $out .= "$predicate ($count distinct entities)\n";
                    $out .= "  Example: $example (type: $exampleType)\n";
                }
            }

            if (!empty($incoming)) {
                if (!empty($outgoing)) {
                    $out .= "\n";
                }
                $out .= "Incoming links:\n\n";
                foreach ($incoming as $link) {
                    $predicate = $link['predicate'] ?? '';
                    $count = $link['count'] ?? '0';
                    $example = $link['example'] ?? '';
                    $exampleType = $link['exampleType'] ?? '[no type]';
                    $out .= "$predicate ($count distinct entities)\n";
                    $out .= "  Example: $example (type: $exampleType)\n";
                }
            }

            return $out;
    }
}

//----------------------------------------------------------------------------------------
// Format citation for a work
function build_work_cite_query($uri)
{
	$query = <<<SPARQL
PREFIX : <http://schema.org/>
PREFIX rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
SELECT (SAMPLE(?csl) AS ?csl) WHERE {
  VALUES ?work { <$uri> }
  VALUES ?type { :CreativeWork :ScholarlyArticle }
  ?work a ?type  .
  ?work :description ?csl .
}
SPARQL;

    return $query;
}

//----------------------------------------------------------------------------------------
function format_work_cite_result($result, $format = 'apa')
{
    if (!$result['ok']) {
        return 'SPARQL error (HTTP ' . $result['status'] . '): ' . $result['error'];
    }

    $body = $result['body'];

    switch ($format) {
        case 'json':
            return $body;

        case 'apa':
        case 'bibtex':
        case 'citeproc':
        default:
            $data = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
                return $body;
            }

            if (!isset($data['results']['bindings'])) {
                return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            }

			// get CSL-JSON
            $bindings = $data['results']['bindings'];
            $csl = [];

            foreach ($bindings as $row) {
                $work = [];
                if (isset($row['csl']['value'])) {
                     $csl[] = json_decode($row['csl']['value']);
                }
            }

            if ($format == 'citeproc')
            {
            	$out = json_encode($csl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            else
            {
				$style_sheet = StyleSheet::loadStyleSheet($format);
				$citeProc = new CiteProc($style_sheet);
				$out = $citeProc->render($csl, "bibliography");
            }

            return $out;
    }
}

//----------------------------------------------------------------------------------------
// Helper function to normalize BIN URI
function normalize_bin_uri($uri)
{
    // If it's just a BIN ID like "BOLD:AAD8883", expand to full URI
    if (preg_match('/^BOLD:[A-Z0-9]+$/i', $uri)) {
        return 'https://portal.boldsystems.org/bin/' . $uri;
    }
    // Otherwise assume it's already a full URI
    return $uri;
}

//----------------------------------------------------------------------------------------
// List taxonomic identifications for a BIN
function build_bin_identifications_query($uri)
{
    $normalized_uri = normalize_bin_uri($uri);

	$query = <<<SPARQL
PREFIX dwc: <http://rs.tdwg.org/dwc/terms/>
PREFIX bin: <https://portal.boldsystems.org/bin/>
PREFIX : <http://schema.org/>

SELECT
  ?name
  ?rank
  (COUNT(?name) AS ?nameCount)
WHERE {
  ?barcode :isPartOf <$normalized_uri> .
  ?barcode dwc:taxonRank ?rank .
  ?barcode dwc:verbatimIdentification ?name .
}
GROUP BY ?rank ?name
SPARQL;

    return $query;
}

//----------------------------------------------------------------------------------------
function format_bin_identifications_result($result, $format = 'text')
{
    if (!$result['ok']) {
        return 'SPARQL error (HTTP ' . $result['status'] . '): ' . $result['error'];
    }

    $body = $result['body'];

    switch ($format) {
        case 'json':
            return $body;

        case 'text':
        default:
            $data = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
                return $body;
            }

            if (!isset($data['results']['bindings'])) {
                return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            }

            $bindings = $data['results']['bindings'];

            if (empty($bindings)) {
                return "No identifications found for this BIN.";
            }

            // Group by rank
            $by_rank = [];
            foreach ($bindings as $row) {
                $rank = $row['rank']['value'] ?? 'Unknown';
                $name = $row['name']['value'] ?? '';
                $count = $row['nameCount']['value'] ?? '0';

                if (!isset($by_rank[$rank])) {
                    $by_rank[$rank] = [];
                }
                $by_rank[$rank][] = [
                    'name' => $name,
                    'count' => $count
                ];
            }

            $out = "Taxonomic identifications:\n\n";

            foreach ($by_rank as $rank => $names) {
                $out .= "$rank:\n";
                foreach ($names as $item) {
                    $out .= "  {$item['name']} ({$item['count']} barcodes)\n";
                }
                $out .= "\n";
            }

            return $out;
    }
}

//----------------------------------------------------------------------------------------
// List publications that cite sequences in a BIN
function build_bin_sequence_citations_query($uri)
{
    $normalized_uri = normalize_bin_uri($uri);

	$query = <<<SPARQL
PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
PREFIX bin: <https://portal.boldsystems.org/bin/>
PREFIX : <http://schema.org/>

SELECT
  ?work
  (SAMPLE(?title) AS ?title)
  (SAMPLE(?csl) AS ?csl)
WHERE {
  VALUES ?bin { <$normalized_uri> }
  ?barcode :isPartOf ?bin .
  ?barcode :sameAs ?genbank .
  ?work :citation ?genbank .
  ?work :name ?title .
  OPTIONAL {
    ?work :description ?csl .
  }
}
GROUP BY ?work
SPARQL;

    return $query;
}

//----------------------------------------------------------------------------------------
function format_bin_sequence_citations_result($result, $format = 'text')
{
    if (!$result['ok']) {
        return 'SPARQL error (HTTP ' . $result['status'] . '): ' . $result['error'];
    }

    $body = $result['body'];

    switch ($format) {
        case 'json':
            return $body;

        case 'apa':
        case 'bibtex':
        case 'citeproc':
            // Use the existing citation formatting function
            $data = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
                return $body;
            }

            if (!isset($data['results']['bindings'])) {
                return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            }

            $bindings = $data['results']['bindings'];

            if (empty($bindings)) {
                return "No citations found for this BIN.";
            }

            // Collect all CSL-JSON records
            $csl = [];
            foreach ($bindings as $row) {
                if (isset($row['csl']['value'])) {
                    $csl[] = json_decode($row['csl']['value']);
                }
            }

            if (empty($csl)) {
                // Fallback to text list if no CSL data available
                $out = "Publications (CSL formatting not available):\n\n";
                foreach ($bindings as $row) {
                    $title = $row['title']['value'] ?? 'Untitled';
                    $work = $row['work']['value'] ?? '';
                    $out .= "- $title\n  $work\n\n";
                }
                return $out;
            }

            // Format using citeproc
            if ($format == 'citeproc') {
                return json_encode($csl, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            } else {
                $style_sheet = StyleSheet::loadStyleSheet($format);
                $citeProc = new CiteProc($style_sheet);
                return $citeProc->render($csl, "bibliography");
            }

        case 'text':
        default:
            $data = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
                return $body;
            }

            if (!isset($data['results']['bindings'])) {
                return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            }

            $bindings = $data['results']['bindings'];

            if (empty($bindings)) {
                return "No citations found for this BIN.";
            }

            $out = "Publications citing sequences in this BIN:\n\n";

            foreach ($bindings as $row) {
                $title = $row['title']['value'] ?? 'Untitled';
                $work = $row['work']['value'] ?? '';
                $out .= "- $title\n  $work\n\n";
            }

            return $out;
    }
}

//----------------------------------------------------------------------------------------
// List publications that cite BOLD datasets containing sequences from a BIN
function build_bin_dataset_citations_query($uri)
{
    $normalized_uri = normalize_bin_uri($uri);

	$query = <<<SPARQL
PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
PREFIX bin: <https://portal.boldsystems.org/bin/>
PREFIX : <http://schema.org/>

SELECT
  ?work
  (SAMPLE(?title) AS ?title)
  (SAMPLE(?csl) AS ?csl)
WHERE {
  VALUES ?bin { <$normalized_uri> }
  ?barcode :isPartOf ?bin .
  ?barcode :isPartOf ?dataset .
  ?dataset :sameAs|^:sameAs ?doi .
  ?work :citation ?doi .
  ?work :name ?title .
  OPTIONAL {
    ?work :description ?csl .
  }
}
GROUP BY ?work
SPARQL;

    return $query;
}

//----------------------------------------------------------------------------------------
function format_bin_dataset_citations_result($result, $format = 'text')
{
    if (!$result['ok']) {
        return 'SPARQL error (HTTP ' . $result['status'] . '): ' . $result['error'];
    }

    $body = $result['body'];

    switch ($format) {
        case 'json':
            return $body;

        case 'apa':
        case 'bibtex':
        case 'citeproc':
            // Use the existing citation formatting function
            $data = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
                return $body;
            }

            if (!isset($data['results']['bindings'])) {
                return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            }

            $bindings = $data['results']['bindings'];

            if (empty($bindings)) {
                return "No dataset citations found for this BIN.";
            }

            // Collect all CSL-JSON records
            $csl = [];
            foreach ($bindings as $row) {
                if (isset($row['csl']['value'])) {
                    $csl[] = json_decode($row['csl']['value']);
                }
            }

            if (empty($csl)) {
                // Fallback to text list if no CSL data available
                $out = "Publications (CSL formatting not available):\n\n";
                foreach ($bindings as $row) {
                    $title = $row['title']['value'] ?? 'Untitled';
                    $work = $row['work']['value'] ?? '';
                    $out .= "- $title\n  $work\n\n";
                }
                return $out;
            }

            // Format using citeproc
            if ($format == 'citeproc') {
                return json_encode($csl, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            } else {
                $style_sheet = StyleSheet::loadStyleSheet($format);
                $citeProc = new CiteProc($style_sheet);
                return $citeProc->render($csl, "bibliography");
            }

        case 'text':
        default:
            $data = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
                return $body;
            }

            if (!isset($data['results']['bindings'])) {
                return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            }

            $bindings = $data['results']['bindings'];

            if (empty($bindings)) {
                return "No dataset citations found for this BIN.";
            }

            $out = "Publications citing BOLD datasets containing sequences from this BIN:\n\n";

            foreach ($bindings as $row) {
                $title = $row['title']['value'] ?? 'Untitled';
                $work = $row['work']['value'] ?? '';
                $out .= "- $title\n  $work\n\n";
            }

            return $out;
    }
}

//----------------------------------------------------------------------------------------
// List all licenses in the knowledge graph with counts
function build_list_licenses_query()
{
	$query = <<<SPARQL
PREFIX : <http://schema.org/>
PREFIX rdf: <http://www.w3.org/1999/02/22-rdf-syntax-ns#>
PREFIX rdfs: <http://www.w3.org/2000/01/rdf-schema#>
SELECT (COUNT(?subject) AS ?count) ?license
WHERE
{
  ?subject :license ?license .
  FILTER(isIRI(?license) && !isBlank(?license))
}
GROUP BY ?license
ORDER BY DESC(?count)
SPARQL;

    return $query;
}

//----------------------------------------------------------------------------------------
function format_list_licenses_result($result, $format = 'text')
{
    if (!$result['ok']) {
        return 'SPARQL error (HTTP ' . $result['status'] . '): ' . $result['error'];
    }

    $body = $result['body'];

    switch ($format) {
        case 'json':
            return $body;

        case 'text':
        default:
            $data = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
                return $body;
            }

            if (!isset($data['results']['bindings'])) {
                return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            }

            $bindings = $data['results']['bindings'];

            if (empty($bindings)) {
                return "No licenses found.";
            }

            $out = "Licenses in knowledge graph:\n\n";

            foreach ($bindings as $row) {
                $license = $row['license']['value'] ?? 'Unknown';
                $count = $row['count']['value'] ?? '0';
                $out .= "$license ($count entities)\n";
            }

            return $out;
    }
}
