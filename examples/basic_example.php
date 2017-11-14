<?php

require __DIR__.'/../vendor/autoload.php';

use LazyJsonMapper\LazyJsonMapper;

/*
 * Imagine the following JSON, which you want an object-oriented interface for:
 */

$section_json = <<<EOF
{
    "section_title": "Employees",
    "users": [
        {
            "name": "Edmund McMundensson",
            "details": {
                "age": 32,
                "hired_date": "2014-02-28"
            }
        },
        {
            "name": "Mabe Mabers",
            "details": {
                "age": 50,
                "hired_date": "2000-05-12"
            }
        }
    ]
}
EOF;

/*
 * Here are some PHP classes to represent that data structure as objects:
 */

class Section extends LazyJsonMapper
{
    const JSON_PROPERTY_MAP = [
        'section_title' => 'string',
        'users'         => 'User[]',
    ];
}

class User extends LazyJsonMapper
{
    const JSON_PROPERTY_MAP = [
        'name'    => 'string',
        'details' => 'UserDetails',
    ];
}

class UserDetails extends LazyJsonMapper
{
    const JSON_PROPERTY_MAP = [
        'age'        => 'int',
        'hired_date' => 'string',
    ];
}

/*
 * Now simply create the root object (a Section), and give it the section JSON:
 */

$section = new Section(json_decode($section_json, true));

/*
 * Here's how you would look at the available properties and their functions:
 */

$section->printPropertyDescriptions();

/*
 * Now let's access some data, via the functions shown by the previous command.
 */

printf("\n\nData Output Example:\n\nSection Title: %s\n", $section->getSectionTitle());
foreach ($section->getUsers() as $user) {
    // $user->printPropertyDescriptions(); // Uncomment to see User-functions.
    // $user->printJson(); // Uncomment to look at the JSON data for that user.
    printf(
        "- User: %s\n  Age: %s\n  Hired Date: %s\n",
        $user->getName(),
        $user->getDetails()->getAge(),
        $user->getDetails()->getHiredDate()
    );
}
echo "\n\n";

/*
 * Lastly, let's demonstrate looking at the actual internal JSON data:
 */

// var_dump($section->asJson()); // Uncomment to get a JSON data string instead.
// var_dump($section->getUsers()[0]->asJson()); // Property sub-object encoding.
// var_dump(json_encode($section->getUsers())); // Property non-object values
//                                              // solvable via `json_encode()`.
$section->printJson();

/*
 * There are a million other functions and features. Have fun exploring!
 * Simply read the main src/LazyJsonMapper.php file for all documentation!
 */
