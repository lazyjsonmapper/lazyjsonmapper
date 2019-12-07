<?php

require __DIR__.'/../vendor/autoload.php';

use LazyJsonMapper\LazyJsonMapper;

$section_json = <<<EOF
{
    "name": "Valery"
}
EOF;

class User extends LazyJsonMapper
{
    const JSON_PROPERTY_MAP = [
        'name' => 'string',
        'age'  => 'int',
    ];

    const JSON_REQUIRED_PROPERTIES = [
        'age'
   ];
}

/*
 * This demonstrates us constant JSON_REQUIRED_PROPERTIES. If we expect
 * property age but it wasn't in JSON data we throw exception about it
 */

$user = new User(json_decode($section_json, true));

try {
	$user->getAge();
} catch (\LazyJsonMapper\Exception\RequiredPropertyException $e) {
    printf($e->getMessage());
}

