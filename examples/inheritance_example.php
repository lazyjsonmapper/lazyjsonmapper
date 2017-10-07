<?php

require __DIR__.'/../vendor/autoload.php';

use LazyJsonMapper\LazyJsonMapper;

/*
 * Example JSON data:
 */

$advanceduser_json = <<<EOF
{
    "name": "A User",
    "age": 40,
    "advanced": "Advanced field..."
}
EOF;

/*
 * Here are classes where AdvancedUser extends from User and inherits its map:
 */

class User extends LazyJsonMapper
{
    const JSON_PROPERTY_MAP = [
        'name' => 'string',
        'age'  => 'int',
    ];
}

class AdvancedUser extends User
{
    const JSON_PROPERTY_MAP = [
        'advanced' => 'string',
    ];
}

/*
 * This demonstrates that AdvancedUser contains all fields from User.
 */

$advanceduser = new AdvancedUser(json_decode($advanceduser_json, true));

$advanceduser->printPropertyDescriptions();
printf(
    "\n\nName: %s\nAge: %s\nAdvanced: %s\n",
    $advanceduser->getName(),
    $advanceduser->getAge(),
    $advanceduser->getAdvanced()
);
$advanceduser->printJson();
