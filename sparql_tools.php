<?php
// sparql_tools.php
// SPARQL query building and formatting functions for bibliographic knowledge graph

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
          ?dataset a <$uri> .
          ?thing ?predicate ?dataset .
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
          ?dataset a <$uri>.
          ?dataset ?predicate ?thing .
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
function format_list_type_links_result($result, $format = 'text')
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
