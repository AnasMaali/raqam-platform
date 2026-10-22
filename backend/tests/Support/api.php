<?php

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;
use Symfony\Component\Yaml\Yaml;

/** Helpers for the API tests: a request, and a check of an answer against the contract. */

/** Checks a response body against a schema of the contract (docs/api/openapi.yaml). */
function matchesContract(mixed $data, string $schema): void
{
    static $spec = null;
    $spec ??= json_decode(json_encode(Yaml::parseFile(base_path('../docs/api/openapi.yaml'))));

    $validator = new Validator;
    $validator->resolver()->registerRaw($spec, 'https://raqam.test/openapi');
    // The test decodes JSON into arrays, which turns an empty object into an empty list; put those back.
    $objects = function ($value) use (&$objects) {
        if (! is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $item) {
            $value[$key] = in_array($key, ['links', 'variants'], true) && $item === [] ? new stdClass : $objects($item);
        }

        return $value;
    };
    $result = $validator->validate(json_decode(json_encode($objects($data))), 'https://raqam.test/openapi#/components/schemas/'.$schema);

    expect($result->isValid())->toBeTrue($result->isValid() ? '' : json_encode((new ErrorFormatter)->format($result->error()), JSON_UNESCAPED_UNICODE));
}

function api(string $path = '', array $headers = [])
{
    return test()->withHeaders($headers)->getJson('/api/v1'.$path);
}

/** Checks an answer against the 200 schema of a contract path, given as written in the contract (e.g. /weather/{slug}). */
function validateAgainstPath(array $data, string $template): void
{
    static $spec = null;
    $spec ??= json_decode(json_encode(Yaml::parseFile(base_path('../docs/api/openapi.yaml'))));
    $schema = $spec->paths->{$template}->get->responses->{'200'}->content->{'application/json'}->schema;

    $validator = new Validator;
    $validator->resolver()->registerRaw($spec, 'https://raqam.test/openapi');
    $schema = json_decode(str_replace('"#/components', '"https://raqam.test/openapi#/components', json_encode($schema, JSON_UNESCAPED_SLASHES)));
    // The test decodes JSON into arrays, which turns an empty object into an empty list; put those back.
    $objects = function ($value) use (&$objects) {
        if (! is_array($value)) {
            return $value;
        }
        foreach ($value as $key => $item) {
            $value[$key] = in_array($key, ['links', 'variants'], true) && $item === [] ? new stdClass : $objects($item);
        }

        return $value;
    };
    $result = $validator->validate(json_decode(json_encode($objects($data))), $schema);

    expect($result->isValid())->toBeTrue($result->isValid() ? '' : json_encode((new ErrorFormatter)->format($result->error()), JSON_UNESCAPED_UNICODE));
}
