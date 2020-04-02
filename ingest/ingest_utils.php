<?php

$httpContentTypes = array(
    'json' => 'application/json',
);

/**
 * Verify the Content-Type header from $_SERVER is of a given type.
 *
 * @param string $expectedType The content type to check for.
 * @return boolean Returns true if the $expectedType is set, otherwise false.
 */
function isContentType($expectedType) {
    $contentType = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
    if (stripos($contentType, $expectedType) === false) {
        return false;
    }
    return true;
}

/**
 * Read and decode JSON from request body input.
 *
 * @return A JSON object decoded from request body input.
 */
function loadInputJson() {
    $bodyContent = file_get_contents('php://input');
    $jsonObj = json_decode($bodyContent);
    if (json_last_error()) {
        throw new ParseError('Malformed JSON body: ' . $bodyContent);
    }
    return $jsonObj;
}


class PostbackBuilder {
    protected $postbackRawUrl;
    protected $postbackMethod;
    protected $paramStartDelim;
    protected $paramEndDelim;
    protected $paramRegex;

    /**
     * PostbackBuilder constructor used for building postback URLs.
     *
     * @param $rawUrl The raw URL for the postback that included unsubstituted query params.
     * @param $httpMethod The HTTP method for the postback(s) built from this instance.
     * @param $paramStartDelim The start delimiter string for substitutions in the $rawUrl.
     * @param $paramEndDelim The end delimiter string for substitutions in the $rawUrl.
     * @param $paramRegex The regular expression to extract unsubstituted query params between
     *        the $paramStartDelim and $paramRegex.
     */
    public function __construct($rawUrl, $httpMethod, $paramStartDelim, $paramEndDelim, $paramRegex) {
        $this->postbackRawUrl = $rawUrl;
        $this->postbackMethod = strtoupper($httpMethod);
        $this->paramStartDelim = $paramStartDelim;
        $this->paramEndDelim = $paramEndDelim;
        $this->paramRegex = $paramRegex;
    }

    /**
     * Given a $paramMap of replacements, replace and build a postback formatted method,url.
     *
     * @param $paramMap The json_decode style object that provides parameter substitution key/values.
     * @return string The $rawUrl of this instance with all $paramRegex instances replaced with their values
     *                from $paramMap along with the $httpMethod. The format of this string is <HTTP_METHOD>,<URL>
     * @throws Exception If $paramRegex is found for which no property exists for it in $paramMap, an Exception is thrown.
     */
    public function buildCsvPostback($paramMap) {
        $postbackUrl = $this->postbackRawUrl;
        preg_match_all($this->paramRegex, $this->postbackRawUrl, $params);
        if (count((array)$paramMap)) {
            foreach ($params[0] as $rawParam) {
                $param = str_replace($this->paramStartDelim, '', $rawParam);
                $param = str_replace($this->paramEndDelim, '', $param);
                $urlParam = $paramMap->{$param};
                if ($urlParam == null) {
                    throw new Exception('Unknown param ' . $this->paramStartDelim .  $param . $this->paramEndDelim . ' in endpoint URL');
                }
                $postbackUrl = str_replace($rawParam, urlencode($urlParam), $postbackUrl);
            }
        }
        if (stripos($postbackUrl, $this->paramStartDelim) || stripos($postbackUrl, $this->paramEndDelim)) {
            throw new Exception('Missing parameters in URL: ' . $postbackUrl);
        }

        return $this->postbackMethod . ',' . $postbackUrl;
    }
}
