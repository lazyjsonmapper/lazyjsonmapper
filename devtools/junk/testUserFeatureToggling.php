<?php
/*
 * Copyright 2017 The LazyJsonMapper Project
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

// This file tests the ability to disable virtual properties or functions.

require __DIR__.'/../../vendor/autoload.php';

use LazyJsonMapper\LazyJsonMapper;

class CoreMap extends LazyJsonMapper
{
    const JSON_PROPERTY_MAP = [
        'foo' => 'string',
        'bar' => 'string',
    ];
}

// --------------------------------------------------------

// Test class that disallows functions but allows properties.

class DisallowsFunctions extends CoreMap
{
    const ALLOW_VIRTUAL_FUNCTIONS = false;

    public function getBar()
    {
        return $this->_getProperty('bar');
    }
}

$jsonData = ['foo' => 'hello', 'bar' => 'world'];

$x = new DisallowsFunctions($jsonData);

echo str_repeat(PHP_EOL, 5);
$x->printPropertyDescriptions();
$x->printJson();

// works since we have overridden that function manually
printf("getBar(): \"%s\"\n", $x->getBar());

// works since we allow direct property access in the class above
$x->bar = 'changed via direct virtual property access';

// look at the new value
printf("getBar(): \"%s\"\n", $x->getBar());

// does not work since we have no setter "setBar()" in the class above
try {
    $x->setBar('xyzzy');
} catch (\Exception $e) {
    printf("setBar(): %s\n", $e->getMessage());
}

// try all function variations of the "foo" property. none should work.
try {
    $x->hasFoo();
} catch (\Exception $e) {
    printf("hasFoo(): %s\n", $e->getMessage());
}

try {
    $x->isFoo();
} catch (\Exception $e) {
    printf("isFoo(): %s\n", $e->getMessage());
}

try {
    $x->getFoo();
} catch (\Exception $e) {
    printf("getFoo(): %s\n", $e->getMessage());
}

try {
    $x->setFoo();
} catch (\Exception $e) {
    printf("setFoo(): %s\n", $e->getMessage());
}

try {
    $x->unsetFoo();
} catch (\Exception $e) {
    printf("unsetFoo(): %s\n", $e->getMessage());
}

// --------------------------------------------------------

// Test class that disallows properties but allows functions.

class DisallowsProperties extends CoreMap
{
    const ALLOW_VIRTUAL_PROPERTIES = false;
}

$jsonData = ['foo' => 'hello', 'bar' => 'world'];

$x = new DisallowsProperties($jsonData);

echo str_repeat(PHP_EOL, 5);
$x->printPropertyDescriptions();
$x->printJson();

// works since we allow functions
printf("getBar(): \"%s\"\n", $x->getBar());
$x->setBar('changed via virtual setBar() function acccess');

// look at the new value
printf("getBar(): \"%s\"\n", $x->getBar());

// try all property acccess variations of the "foo" property. none should work.
try {
    $test = $x->foo;
} catch (\Exception $e) {
    printf("__get() via x->foo: %s\n", $e->getMessage());
}

try {
    $x->foo[] = 'test'; // this __get()-trigger will fail too
} catch (\Exception $e) {
    printf("__get() via x->foo[]: %s\n", $e->getMessage());
}

try {
    $x->foo = 'xyz';
} catch (\Exception $e) {
    printf("__set() via x->foo = ...: %s\n", $e->getMessage());
}

try {
    isset($x->foo);
} catch (\Exception $e) {
    printf("__isset() via isset(x->foo): %s\n", $e->getMessage());
}

try {
    empty($x->foo);
} catch (\Exception $e) {
    printf("__isset() via empty(x->foo): %s\n", $e->getMessage());
}

try {
    unset($x->foo);
} catch (\Exception $e) {
    printf("__unset() via unset(x->foo): %s\n", $e->getMessage());
}

// --------------------------------------------------------

// Test class that disallows both.

class DisallowsBoth extends CoreMap
{
    const ALLOW_VIRTUAL_PROPERTIES = false;
    const ALLOW_VIRTUAL_FUNCTIONS = false;
}

$x = new DisallowsBoth($jsonData);

echo str_repeat(PHP_EOL, 5);
$x->printPropertyDescriptions();

try {
    $test = $x->foo;
} catch (\Exception $e) {
    printf("__get() via x->foo: %s\n", $e->getMessage());
}

try {
    $x->getFoo();
} catch (\Exception $e) {
    printf("getFoo(): %s\n", $e->getMessage());
}

// --------------------------------------------------------

// Test class that extends "DisallowsBoth" and re-allows both.

class ReallowsBoth extends DisallowsBoth
{
    const ALLOW_VIRTUAL_PROPERTIES = true;
    const ALLOW_VIRTUAL_FUNCTIONS = true;
}

$x = new ReallowsBoth($jsonData);

echo str_repeat(PHP_EOL, 5);
$x->printPropertyDescriptions();

printf("getFoo(): \"%s\"\n", $x->getFoo());
printf("x->bar: \"%s\"\n", $x->bar);
