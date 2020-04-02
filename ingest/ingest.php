<?php

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/ingest_utils.php';

use Swaggest\JsonSchema\Schema;

// NOTE: requires KAFKA_HOSTNAME_PORT to be set in the environment in the form of <KAFKA_HOST>:<KAFKA_PORT>
// NOTE: requres KAFKA_POSTBACK_TOPIC to be set in the environment to specify the Kafka topic to use
// for publishing postbacks in the form of <HTTP_METHOD>,<HTTP_URL>
// For example: GET,http://the.callback.host.com/some/resource?q1=v1&q2=v

$startTime = microtime(true);

// TODO: error messages should really be returned in a response body as per rfc7807
if (strtoupper($_SERVER['REQUEST_METHOD']) != 'POST') {
    error_log("Invalid HTTP request method: " . $_SERVER['REQUEST_METHOD'], 0);
    http_response_code(405);
    exit();
}

if (!isContentType($httpContentTypes['json'])) {
    error_log('Invalid Content-Type. Expecting application/json, but got ' . $_SERVER['CONTENT_TYPE'], 0);
    http_response_code(405);
    exit();
}

$kafkaHostPort = getenv('KAFKA_HOSTNAME_PORT');
if ($kafkaHostPort == false) {
    error_log('No Kafka hostname:port specified in env var KAFKA_HOSTNAME_PORT', 0);
    http_response_code(500);
    exit();
}

$kafkaTopic = getenv('KAFKA_POSTBACK_TOPIC');
if ($kafkaTopic == false) {
    error_log('No Kafka topic specified in env var KAFKA_POSTBACK_TOPIC', 0);
    http_response_code(500);
    exit();
}

$jsonSchema = <<<'JSON'
{
    "required": [
        "data",
        "endpoint"
    ],
    "type": "object",
    "properties": {
        "endpoint": {
            "required": [
                "method",
                "url"
            ],
            "type": "object",
            "properties": {
                "method": {
                    "type": "string",
                    "example": "GET",
                    "enum": [
                        "GET",
                        "POST"
                    ]
                },
                "url": {
                    "type": "string",
                    "format": "url"
                }
            }
        },
        "data": {
            "type": "array",
            "items": {
                "$ref": "#/definitions/dataitem"
            }
        }
    },
    "definitions": {
        "dataitem": {
            "type": "object",
            "additionalProperties": {
                "type": "string"
            }
        }
    }
}
JSON;

$schema = Schema::import(json_decode($jsonSchema));

try {
    $jsonBody = loadInputJson();
} catch (ParseError $parseError) {
    error_log('Error parsing JSON for request body: ' . $parseError->getMessage(), 0);
    http_response_code(400);
    exit();
}

try {
    $schema->in($jsonBody);
} catch (Exception $e) {
    error_log('Error validating JSON schema with request body: ' . $e->getMessage(), 0);
    http_response_code(400);
    exit();
}

$postbackMethod = $jsonBody->endpoint->method;
$postbackRawUrl = $jsonBody->endpoint->url;
$postbackBuilder = new PostbackBuilder($postbackRawUrl, $postbackMethod, '{', '}', "/\{\w*\}/");

$postbacks = array();
foreach ($jsonBody->data as $postbackMap) {
    try {
        array_push($postbacks, $postbackBuilder->buildCsvPostback($postbackMap));
    } catch (Exception $e) {
        error_log('Error building postback URL: ' . $e->getMessage(), 0);
        http_response_code(400);
        exit();
    }
}

// TODO: consider signal handling
try {
    $conf = new RdKafka\Conf();
    $conf->set('metadata.broker.list', $kafkaHostPort);

    error_log('Kafka connect brokers: ' . $kafkaHostPort . ' Using topic: ' . $kafkaTopic, 0);
    $producer = new RdKafka\Producer($conf);
    $topic = $producer->newTopic($kafkaTopic);
    $result = 0;

    foreach ($postbacks as $postback) {
        error_log('Pushing postback URL to Kafka: ' . $postback, 0);
        $topic->produce(RD_KAFKA_PARTITION_UA, 0, $postback);
        $producer->poll(0);
    }

    for ($flushRetries = 0; $flushRetries < 3; $flushRetries++) {
        $result = $producer->flush(3000);
        if (RD_KAFKA_RESP_ERR_NO_ERROR === $result) {
            break;
        }
    }

    if (RD_KAFKA_RESP_ERR_NO_ERROR !== $result) {
        throw new RuntimeException('Unable to perform flush, messages might be lost!');
    }

} catch (Exception $e) {
    error_log("Error producing postback message(s) to Kafka: " . $e->getMessage(), 0);
    http_response_code(500);
    exit();
}

$totalTime = microtime(true) - $startTime;

error_log('Postback complete. ' . count($postbacks) . ' postback(s) in ' . $totalTime . ' seconds.');
