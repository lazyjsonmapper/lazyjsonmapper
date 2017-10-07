<?php

require __DIR__.'/../vendor/autoload.php';

use LazyJsonMapper\LazyJsonMapper;

/*
 * In these classes, there is no inheritance ("extends") relationship between
 * User/AdvancedUser and SomethingElse. But SomethingElse imports the whole map
 * from AdvancedUser (which in turn inherits (extends) the User map):
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

class SomethingElse extends LazyJsonMapper
{
    const JSON_PROPERTY_MAP = [
        AdvancedUser::class, // This is an "import class map"-command.
        'otherprop' => 'float[][]',
    ];
}

/*
 * This demonstrates that SomethingElse contains all fields from AdvancedUser
 * (which in turn inherited User's map), as well as having its own "otherprop".
 */

$somethingelse = new SomethingElse();
$somethingelse->printPropertyDescriptions();
