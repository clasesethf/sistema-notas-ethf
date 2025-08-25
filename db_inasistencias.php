<?php


function queryTursoDb(
    string $tursoUrl,
    string $authToken,
    string $sqlQuery,
    array $params = []
): array {
    $ch = curl_init();

    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $authToken,
    ];

    $payload = [
        'requests' => [
            [
                'type' => 'execute',
                'stmt' => [
                    'sql' => $sqlQuery,
                    'args' => array_map(function($param) {
                        return [
                            'type' => 'string', // Adjust type as necessary
                            'value' => $param
                        ];
                    }, $params)
                ]
            ],
        ],
    ];

    curl_setopt($ch, CURLOPT_URL, $tursoUrl.'/v2/pipeline');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        $error_msg = curl_error($ch);
        curl_close($ch);
        throw new Exception("cURL error: " . $error_msg);
    }

    curl_close($ch);

    $decodedResponse = json_decode($response, true);

    if ($httpCode !== 200) {
        $error_message = 'Unknown error';
        if (isset($decodedResponse['error'])) {
            $error_message = $decodedResponse['error'];
        }

        error_log(json_encode($decodedResponse));

        throw new Exception(
            "Turso API error (HTTP Code: {$httpCode}): " . $error_message
        );
    }

    if (isset($decodedResponse['results'][0]['response']['result']['rows'])) {
        return $decodedResponse['results'][0]['response']['result']['rows'];
    } else {
        throw new Exception(
            'Turso query error: ' .
                $decodedResponse
        );
    }

    return []; // Return empty array if no rows are found (e.g., for INSERT/UPDATE)
}

# EJEMPLO DE USO
$URL = 'https://inasistencias-ethf.aws-us-east-1.turso.io';
$TOKEN = 'eyJhbGciOiJFZERTQSIsInR5cCI6IkpXVCJ9.eyJhIjoicm8iLCJpYXQiOjE3NTI1ODc3ODYsImlkIjoiMzA0NWZjMzYtZWIzMi00NTU1LTkzODEtZWI3YTkwZDQ4YTVhIiwicmlkIjoiN2UzMTE2YzYtNWQ2MS00MGNhLTkzMmItNWMzNzc4ZGZmN2ZmIn0.sKgJ5MpKh8pK9AB62QtcHmM4KO41qQhuzqgQZ9PsUi43G38WqM9sGpz2Y8Wyu3WUzdPaJAosPMMx9JJSRsi8DA';
$selectQuery = 'SELECT * FROM inasistencias_ethf';
$params = [];
$users = queryTursoDb($URL, $TOKEN, $selectQuery, $params);

echo json_encode($users);