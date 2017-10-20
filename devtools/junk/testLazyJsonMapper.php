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

namespace Foo;

set_time_limit(0);
date_default_timezone_set('UTC');

require __DIR__.'/../../vendor/autoload.php';

use LazyJsonMapper\Exception\LazyJsonMapperException;
use LazyJsonMapper\Exception\LazyUserException;
use LazyJsonMapper\Export\PropertyDescription;
use LazyJsonMapper\LazyJsonMapper;
use LazyJsonMapper\Magic\FunctionTranslation;
use LazyJsonMapper\Magic\PropertyTranslation;
use LazyJsonMapper\Property\PropertyDefinition;
use LazyJsonMapper\Property\UndefinedProperty;

// TODO: This file was written EXTREMELY sloppily just to get all necessary
// tests during development of LazyJsonMapper... I barely even cared about
// grammar since this file was just meant to dump a bunch of tests in and not
// meant for public use. But it has so many extremely important tests that
// cover all kinds of common and unusual scenarios. Several of the tests may
// be outdated and test details that later changed during development. So
// everything below needs to be carefully revised.
//
// We REALLY SHOULD rewrite everything as clean and organized PHPUnit tests...
// if SOMEONE has the energy...
//
// PS: If anyone is using this library for commercial purposes, then a nice way
// to contribute back as a "thank you!" would be to manually go through
// everything below and rewrite relevant tests as beautiful PHPunit tests. :-)
// If someone wants to contribute, you can do the test-rewrite step by step and
// won't have to do everything all at once. ;-)
//
// - SteveJobzniak

$hasSeven = version_compare(PHP_VERSION, '7.0.0') >= 0;

$json = <<<EOF
{
    "just_a_string": "foo",
    "self_object": {
        "just_a_string": "foo2"
    },
    "camelCaseProp": 1234,
    "string_array": [
        "123",
        "b",
        "c"
    ],
    "self_array": [
        {
            "just_a_string": "foo2",
            "camelCaseProp": 456
        },
        {
            "just_a_string": "foo3",
            "self_object": {
                "just_a_string": "this is a deeply nested object"
            }
        },
        {
            "just_a_string": "foo4"
        }
    ]
}
EOF;

// Just some inheritance tests for the class-property cache builder.
class TestDeep extends LazyJsonMapper
{
    const JSON_PROPERTY_MAP = [
        'dep'                         => 'int',
        'just_a_string'               => 'float[]',
        'test_pure_lazymapper_object' => '\LazyJsonMapper\LazyJsonMapper',
        // test the shortcut to avoid having to write the whole path:
        'test_pure_lazymapper_object_shortcut' => 'LazyJsonMapper',
        'test_pure_lazymapper_object_shortarr' => 'LazyJsonMapper[][]',
        // test without strict case-sensitive checking: (MUST fail)
        // 'test_pure_lazymapper_object_shortcut' => 'lazyJsonMapper',
    ];
}
// var_dump(new TestDeep()); // look at the LazyJsonMapper shortcut success
class TestMid extends TestDeep
{
}

class Test extends TestMid
{
    const JSON_PROPERTY_MAP = [
        'just_a_string' => 'string',
        'camelCaseProp' => 'int',
        // full namespace path, with case-sensitivity typos on purpose (php
        // allows it, but LazyJsonMapper compiles this to the proper name
        // instead so that we have strict names internally):
        'self_object'   => '\foo\Test',
        'string_array'  => 'string[]',
        // relative notation instead of full "\namespace\path":
        // when relative mode is used, it looks in the defining class' own namespace.
        'self_array'    => 'Test[]',
    ];
}

$jsonData = json_decode($json, true, 512, JSON_BIGINT_AS_STRING);

var_dump($jsonData);

$x = new Test($jsonData, true);

// begin with basic tests...
var_dump($x);
$sub = $x->getSelfObject();
$sub->setJustAString('modifying nested object and propagating the change to root object $x');
var_dump($sub);
var_dump($x->getSelfObject());
var_dump($x);
$multi = $x->getSelfArray(); // resolves all objects in array, but avoids doing
                             // it recursively. sub-properties are lazy-converted
                             // when they are actually requested.
var_dump($multi); // array of objects, with no resolved sub-objects yet
var_dump($x); // now has array of objects
$deepsub = $multi[1]->getSelfObject(); // causes nested sub to be resolved
var_dump($multi);
var_dump($x);
$deepsub->setJustAString('wow, propagating change of very deep object!');
var_dump($multi);
var_dump($x);
var_dump($x->getCamelCaseProp());
var_dump($x->getJustAString());
var_dump($x->isJustAString());
var_dump($x->getJustAString());
var_dump($x);
var_dump($x->getSelfObject());
var_dump($x->getSelfObject()->getJustAString());
var_dump($x->self_object->just_a_string);
var_dump($x->getStringArray());
var_dump($x->getSelfArray());

try {
    echo $x->a_missing_property_not_in_data_or_def;
} catch (LazyJsonMapperException $e) {
    printf("Test missing property via property access Exception: %s\n", $e->getMessage());
}

try {
    $x->getAMissingPropertyNotInDataOrDef();
} catch (LazyJsonMapperException $e) {
    printf("Test missing property via magic getter Exception: %s\n", $e->getMessage());
}

$x = new Test($jsonData, true);
var_dump($x); // no data is resolved yet
// test deeply nested chain of getters and setters.
$x->getSelfArray()[1]->getSelfObject()->setJustAString('chained command for deep modification')->setCamelCaseProp(9944);
var_dump($x); // chain has been resolved and change has propagated
var_dump($x->getSelfArray()[1]->getSelfObject()->getCamelCaseProp()); // int(9944)

class SubClassOfTest extends Test
{
}
$foo = new SubClassOfTest(); // Test acceptance of subclasses of required class.
$x->setSelfObject($foo);
var_dump($x->getSelfObject());
var_dump($x->getSelfObject()->getJustAString());

try {
    $x->setSelfObject('x'); // trying to set non-object value for object property
} catch (LazyJsonMapperException $e) {
    printf("Test non-object assignment Exception: %s\n", $e->getMessage());
}

class Bleh
{
}

try {
    $x->setSelfObject(new Bleh()); // trying wrong class for property
} catch (LazyJsonMapperException $e) {
    printf("Test wrong object assignment Exception: %s\n", $e->getMessage());
}

$foo = new Test(['just_a_string' => 'example']);
$x->setSelfObject($foo);
var_dump($x->getSelfObject());
var_dump($x->getSelfObject()->getJustAString());
$x->printJson();
var_dump($x->just_a_string);
var_dump(isset($x->just_a_string));
unset($x->just_a_string);
var_dump($x->just_a_string);
var_dump(isset($x->just_a_string));
unset($x->self_array);
unset($x->camelCaseProp);
$x->printJson();

var_dump('---------------------');

// test creation of objects from empty object-arrays "{}" in JSON
class EmptyObjTest extends LazyJsonMapper
{
    const JSON_PROPERTY_MAP = [
        'self' => '\foo\EmptyObjTest',
    ];
}
$x = new EmptyObjTest(json_decode('{"self":null}', true)); // allow null object
var_dump($x->getSelf());
// NOTE: the empty-array test is because empty arrays are indistinguishable from
// objects when decoded from JSON. but if it had been an actual JSON array
// (which is always non-associative), then we detect that it's non-object data.
$x = new EmptyObjTest(json_decode('{"self":{}}', true)); // allow empty object
var_dump($x->getSelf());
$x = new EmptyObjTest(json_decode('{"self":[]}', true)); // allow empty array
var_dump($x->getSelf());
$x = new EmptyObjTest(json_decode('{"self":[1,2]}', true)); // forbid non-object
try {
    var_dump($x->getSelf());
} catch (\Exception $e) {
    printf("Test converting invalid regular JSON array to object Exception: %s\n", $e->getMessage());
}

var_dump('---------------------');

class TestUndefinedProps extends LazyJsonMapper
{
    const JSON_PROPERTY_MAP = [
        'self'      => '\foo\TestUndefinedProps',
        'selfArray' => '\foo\TestUndefinedProps[]',
        'foo_bar'   => 'int[][]',
        'property'  => 'string',
    ];
}

$json = <<<EOF
{
"self":{
  "yet_another_missing":"000"
},
"selfArray":[
  {
    "array_missing":"111"
  },
  {
    "array_missing":"222",
    "another_array_missing":"333"
  }
],
"property":"123",
"missing_property":"456",
"another_missing":"789"
}
EOF;

$data = json_decode($json, true, 512, JSON_BIGINT_AS_STRING);

try {
    // create with "require analysis" which will refuse to construct due to
    // undefined properties in the input data.
    // this tests the analysis requirement, to ensure that people's classes will
    // not construct if data is missing.
    $x = new TestUndefinedProps($data, true);
} catch (LazyJsonMapperException $e) {
    printf("TestUndefinedProps Exception: %s\n", $e->getMessage());
}

// now create the class without analysis enabled... which enables regular
// operation where the user can access the undefined properties too.
$y = new TestUndefinedProps($data, false);
var_dump($y); // look at the internal data and the compiled class map

// now verify what the exported property map says.
// the only defined properties are foo_bar, self, selfArray and property.
// the undefined ones are missing_property and another_missing.
$allowRelativeTypes = true;
$includeUndefined = true;
$descriptions = $y->exportPropertyDescriptions($allowRelativeTypes, $includeUndefined);
var_dump($descriptions);
foreach ($descriptions as $property) {
    printf("* Property: '%s'. Defined: %s\n", $property->name,
           $property->is_defined ? 'Yes!' : 'No.');
}

// Now just test the automatic printing function too...
$showFunctions = true;
$y->printPropertyDescriptions($showFunctions, $allowRelativeTypes, $includeUndefined);
$showFunctions = true;
$allowRelativeTypes = false;
$includeUndefined = false;
$y->printPropertyDescriptions($showFunctions, $allowRelativeTypes, $includeUndefined);
$showFunctions = false;
$y->printPropertyDescriptions($showFunctions, $allowRelativeTypes, $includeUndefined);

// And test it on the main class too:
$y = new Test();
$y->printPropertyDescriptions();
$y->printPropertyDescriptions(false, true); // without functions, with relative

var_dump('---------------------');

// Test the hasX() functions, which are useful when verifying that non-defined
// (not in class definition) fields exist in data before trying to read, to
// avoid causing any exceptions in the getter.
$x = new Test($data);
var_dump($x->hasReallyMissing()); // false, since it's not in class def or data.
var_dump($x->hasAnotherMissing()); // true, since it's in data (but not in class def)
var_dump($x->hasJustAString()); // true, since it's in class def (but not in data)
var_dump($x->getJustAString()); // null, since it's not in data (but is in class def)
try {
    $x->getReallyMissing(); // exception, since it's not in class def or data.
    // var_dump($x->really_missing); // also exception, "no such object property".
} catch (LazyJsonMapperException $e) {
    printf("Test getReallyMissing() Exception: %s\n", $e->getMessage());
}

try {
    $x->setReallyMissing('a'); // exception, since it's not in class def or data.
    // $x->really_missing = 'a'; // also exception, "no such object property".
} catch (LazyJsonMapperException $e) {
    printf("Test setReallyMissing() Exception: %s\n", $e->getMessage());
}
// intended usage by end-users when accessing undefined values:
if ($x->hasReallyMissing()) {
    // this won't run, since ReallyMissing didn't exist. but if it HAD existed
    // in the JSON data, this function call would now be safe without exceptions:
    var_dump($x->getReallyMissing());
} else {
    var_dump('not running getReallyMissing() since the property is missing');
}

var_dump('---------------------');

class TestNotSubClass extends LazyJsonMapper
{
    const JSON_PROPERTY_MAP = [
        'not_subclass'      => '\foo\NotSubClass',
    ];
}
class NotSubClass
{
} // Not instance of LazyJsonMapper

$json = <<<EOF
{
  "not_subclass":{}
}
EOF;

$data = json_decode($json, true, 512, JSON_BIGINT_AS_STRING);

try {
    $x = new TestNotSubClass($data, true);
    $x->getNotSubclass();
} catch (LazyJsonMapperException $e) {
    printf("TestNotSubClass Exception: %s\n", $e->getMessage());
}

var_dump('---------------------');

class TestMissingClass extends LazyJsonMapper
{
    const JSON_PROPERTY_MAP = [
        'a_missing_class'      => '\foo\Missing',
    ];
}

$json = <<<EOF
{
  "a_missing_class":{}
}
EOF;

$data = json_decode($json, true, 512, JSON_BIGINT_AS_STRING);

try {
    $x = new TestMissingClass($data, true);
} catch (LazyJsonMapperException $e) {
    printf("TestMissingClass Exception: %s\n", $e->getMessage());
}

var_dump('---------------------');

class TestMissingPropAndMissingClass extends LazyJsonMapper
{
    const JSON_PROPERTY_MAP = [
        'a_missing_class' => '\foo\Missing',
    ];
}

$json = <<<EOF
{
  "not_defined_property":123,
  "a_missing_class":{}
}
EOF;

$data = json_decode($json, true, 512, JSON_BIGINT_AS_STRING);

try {
    // will only show the error about missing class, because the class map is
    // compiled first and cannot resolve itself, so it never gets to the data
    // mapping/analysis stage. so the missing property is not mentioned.
    $x = new TestMissingPropAndMissingClass($data, true);
} catch (LazyJsonMapperException $e) {
    printf("TestMissingPropAndMissingClass Exception: %s\n", $e->getMessage());
}

var_dump('---------------------');

// this test checks two things:
// definitions that do not match the data.
// properties whose classes cannot be constructed (due to custom _init() fail).
class TestUnmappableAndFailConstructor extends LazyJsonMapper
{
    const JSON_PROPERTY_MAP = [
        'bad_definition' => 'int[][][]', // too deep arrays for the data
        // this one will not be able to construct during the getting of this property...
        'impossible_constructor' => '\foo\WithBadConstructor',
    ];
}

class WithBadConstructor extends LazyJsonMapper
{
    protected function _init()
    {
        // Uncomment this other exception to test the "Invalid exception thrown
        // by _init(). Must use LazyUserException." error when users throw the
        // wrong exception:
        // throw new \Exception('test');

        throw new \LazyJsonMapper\Exception\LazyUserException('Hello world! Thrown by a failing constructor.');
    }
}

$json = <<<EOF
{
  "impossible_constructor":{},
  "bad_definition":[1, 2, 3]
}
EOF;

$data = json_decode($json, true, 512, JSON_BIGINT_AS_STRING);

try {
    // this should throw and warn about the impossible constructor and the
    // unmappable property.
    $x = new TestUnmappableAndFailConstructor($data, true);
} catch (LazyJsonMapperException $e) {
    printf("TestUnmappableAndFailConstructor Exception: %s\n", $e->getMessage());
}

var_dump('---------------------');

class TestImpossibleSubPropertyCompile extends LazyJsonMapper
{
    const JSON_PROPERTY_MAP = [
        // this one links to a class that exists but isn't compiled yet and
        // therefore must be sub-compiled. but that particular one actually
        // failed its own compilation earlier (above) - because the
        // "TestMissingClass" class CANNOT be compiled... so OUR map compilation
        // HERE will succeed (it points at TestMissingClass which is a
        // LazyJsonMapper class which exists), but then WE will fail with a
        // sub-property class error when we try to ALSO compile OUR property's
        // uncompiled class ("TestMissingClass") at the end of its own
        // compilation process.
        'impossible_subcompilation' => '\foo\TestMissingClass',
    ];
}

try {
    $x = new TestImpossibleSubPropertyCompile();
} catch (\Exception $e) {
    printf("Test impossible sub-property class compilation: %s\n", $e->getMessage());
}

var_dump('---------------------');

// this test is very similar to the previous test, but it ensures that the
// validation works for deep hierarchies too, of classes with properties that
// refer to classes with properties that refer to classes that refer to
// uncompilable classes.
class TopFailChainClass extends LazyJsonMapper
{
    // successfully compiles since MiddleFailChainClass exists and is based on LazyJsonMapper
    const JSON_PROPERTY_MAP = [
        'middle_fail_chain_class' => '\Foo\MiddleFailChainClass',
    ];
}

class MiddleFailChainClass extends LazyJsonMapper
{
    // successfully compiles since DeepFailChainBadClass exists and is based on LazyJsonMapper
    const JSON_PROPERTY_MAP = [
        'deep_fail_chain_class' => '\Foo\DeepFailChainBadClass',
    ];
}

class DeepFailChainBadClass extends LazyJsonMapper
{
    // this map will fail to compile, which should stop the compilation of
    // whichever class began the compilation process that pointed at us...
    const JSON_PROPERTY_MAP = [
        'not_a_valid_class' => '/What/ever/...',
    ];
}

// try starting the compilation with each of the 3 classes:
// it doesn't matter which one we start with, since the compiler cache will
// notice the failures in them all and will auto-rollback their compilations.
// which means that the other classes won't incorrectly see anything in the
// cache. so each attempt to create any of these classes will be as if it was
// the first-ever call for compiling the classes in its hierarchy.

try {
    // fails immediately since this class map cannot be compiled
    $x = new DeepFailChainBadClass();
} catch (\Exception $e) {
    printf("Test compiling DeepFailChainBadClass Exception: %s\n", $e->getMessage());
}

try {
    // succeeds at compiling its own map, but then fails when trying to compile
    // the property classes (DeepFailChainBadClass) it found in the hierarchy.
    $x = new MiddleFailChainClass();
} catch (\Exception $e) {
    printf("Test compiling MiddleFailChainClass Exception: %s\n", $e->getMessage());
}

try {
    // succeeds at compiling its own map, then looks at its properties and
    // succeeds at compiling MiddleFailChainClass, and then looks at that one's
    // properties and fails at compiling the DeepFailChainBadClass it refers to.
    $x = new TopFailChainClass();
} catch (\Exception $e) {
    printf("Test compiling TopFailChainClass Exception: %s\n", $e->getMessage());
}

var_dump('---------------------');

class TestNullValue extends LazyJsonMapper
{
    const JSON_PROPERTY_MAP = [
        'this_is_null'      => '\foo\TestNullValue',
    ];
}

$json = <<<EOF
{
  "this_is_null":null
}
EOF;

$data = json_decode($json, true, 512, JSON_BIGINT_AS_STRING);

try {
    //tests the fact that all values must allow null both during construction
    //with analysis (the TRUE flag in constructor) and during getting.
    $x = new TestNullValue($data, true);
    var_dump($x->getThisIsNull());
} catch (LazyJsonMapperException $e) {
    printf("TestNullValue Exception: %s\n", $e->getMessage());
}

var_dump('---------------------');

class TestNoCastValue extends LazyJsonMapper
{
    const JSON_PROPERTY_MAP = [
        'no_cast1' => '',
        'no_cast2' => 'mixed', // same as ''
        'no_cast3' => '',
    ];
}

$json = <<<EOF
{
  "no_cast1":3.14,
  "no_cast2":1234,
  "no_cast3":true
}
EOF;

$data = json_decode($json, true, 512, JSON_BIGINT_AS_STRING);

try {
    $x = new TestNoCastValue($data, true);
    var_dump($x->getNoCast1());
    var_dump($x->getNoCast2());
    var_dump($x->getNoCast3());
    $x->setNoCast1('should succeed without type-forcing');
    var_dump($x->getNoCast1());
} catch (LazyJsonMapperException $e) {
    printf("TestNoCastValue Exception: %s\n", $e->getMessage());
}

var_dump('---------------------');

class TestDepth extends LazyJsonMapper
{
    const JSON_PROPERTY_MAP = [
        'array_of_arrays_of_arrays_of_int' => 'int[][][]',
    ];
}
$x = new TestDepth([]); // Init with no data.
try {
    $x->setArrayOfArraysOfArraysOfInt([[new Test()]]);
} catch (LazyJsonMapperException $e) {
    printf("Test non-array value at depth 2 of 3 Exception: %s\n", $e->getMessage());
}

try {
    $x->setArrayOfArraysOfArraysOfInt([[[new Test()]]]);
} catch (LazyJsonMapperException $e) {
    printf("Test invalid value at depth 3 of 3 Exception: %s\n", $e->getMessage());
}

try {
    $x->setArrayOfArraysOfArraysOfInt([[[[]]]]);
} catch (LazyJsonMapperException $e) {
    printf("Test invalid array-value at depth 3 of 3 Exception: %s\n", $e->getMessage());
}
$x->setArrayOfArraysOfArraysOfInt([[[1, '456', 100, 5.5]], [], null, [[20]]]);
var_dump($x); // 1, 456, 100, 5, 20

var_dump('---------------------');

// test working with raw properties not defined in the class property map.
class UndefinedPropertyAccess extends LazyJsonMapper
{
}
$json = '{"some_undefined_prop":null}';
$data = json_decode($json, true, 512, JSON_BIGINT_AS_STRING);

try {
    // if we run with VALIDATION (TRUE), this will throw an exception
    // since the data's "some_undefined_prop" is not in the class property map.
    // NOTE: We already did this test as TestUndefinedProps earlier...
    $x = new UndefinedPropertyAccess($data, true);
} catch (\Exception $e) {
    printf("Test creating class instance with validation detecting undefined properties Exception: %s\n", $e->getMessage());
}

$x = new UndefinedPropertyAccess($data);
var_dump($x);
var_dump($x->hasSomeUndefinedProp()); // true
var_dump($x->isSomeUndefinedProp()); // false (the null evaluates to false)
var_dump($x->getSomeUndefinedProp()); // null
$x->setSomeUndefinedProp(['no data validation since it is undefined']);
var_dump($x->isSomeUndefinedProp()); // true (the array evaluates to true)
var_dump($x->getSomeUndefinedProp()); // array with a string in it
$x->setSomeUndefinedProp('xyz');
var_dump($x->hasSomeUndefinedProp()); // true
var_dump($x->getSomeUndefinedProp()); // "xyz"
var_dump($x->isSomeUndefinedProp()); // true (the string evaluates to true)
$x->setSomeUndefinedProp(null);
var_dump($x->hasSomeUndefinedProp()); // true
var_dump($x->getSomeUndefinedProp()); // null

var_dump('---------------------');

// test of advanced multi-class inheritance:
// OurTree* is a set of classes inheriting (extending) each other.
// Unrelated* are two other classes extending each other.
// FarClass is a single class without any other parents except LazyJsonMapper.
//
// OurTreeThree compiles its own inherited hierarchy, which then imports
// UnrelatedTwo, which compiles its own hierarchy, which then finally imports
// FarClass. The result is a final, compiled map which includes all classes.
//
// (and as noted in the main source code, memory cost of inheritance is 0 since
// all classes inherit each other's PropertyDefinition objects; the only cost is
// the amount of RAM it takes for an array["key"] to link to the borrowed object)
class FarClass extends LazyJsonMapper
{
    const JSON_PROPERTY_MAP = [
        'farclass' => '\Foo\FarClass',
    ];
}
class UnrelatedOne extends LazyJsonMapper
{
    const JSON_PROPERTY_MAP = [
        FarClass::class,
        'unrelated_one' => 'int',
    ];
}
class UnrelatedTwo extends UnrelatedOne
{
    const JSON_PROPERTY_MAP = [
        'unrelated_two'     => 'float',
        'conflicting_prop1' => '\Foo\UnrelatedOne',
        'conflicting_prop2' => '\Foo\UnrelatedOne',
    ];
}
class OurTreeOne extends LazyJsonMapper
{
    const JSON_PROPERTY_MAP = [
        'ourtree_one' => 'string',
    ];
}
class OurTreeTwo extends OurTreeOne
{
    const JSON_PROPERTY_MAP = [
        'ourtree_two'       => 'int',
        'conflicting_prop1' => '\Foo\OurTreeThree', // will be overwritten
        UnrelatedTwo::class, // ... by this import
        'conflicting_prop2' => '\Foo\OurTreeThree', // will overwrite the import
    ];
}
class OurTreeThree extends OurTreeTwo
{
    const JSON_PROPERTY_MAP = [
        'ourtree_three' => 'bool[]',
    ];

    protected function _init()
    {
        echo "Hello world from the init function!\n";
    }
}

$x = new OurTreeThree();
var_dump($x);

var_dump('---------------------');

// LOTS OF TESTS OF DIRECT BY-REFERENCE ACCESS, BOTH INTERNALLY AND EXTERNALLY.
// INTERNALLY: &_getProperty(), EXTERNALLY: &__get()
class TestGetProperty extends LazyJsonMapper
{
    const JSON_PROPERTY_MAP = [
        'foo' => '\Foo\TestGetProperty[]',
        'bar' => 'int',
    ];

    protected function _init()
    {
        // always set "bar" to a good value after construction (this is just to
        // avoid code repetition during the testing... and to test the _init function)
        $this->_setProperty('bar', 1234);

        // uncommenting this will test the "must be LazyUserException" error:
        // throw new \Exception('x'); // is rejected and replaced with generic
        // throw new LazyUserException('What'); // okay, message propagates
    }

    public function runTest()
    {
        // just show the current internal data (nothing exists)
        var_dump($this); // foo is empty

        // test retrieving prop, but not saving missing NULL to _objectData
        var_dump($this->_getProperty('foo')); // NULL
        var_dump($this); // foo still empty

        // test saving reference to return-value, but not saving default NULL to _objectData
        $val = &$this->_getProperty('foo'); // missing important createMissingValue param
        $val = 'hi'; // does NOT modify the real property, modifies some temp var
        var_dump($this); // foo still empty

        // test saving reference to return-value, and creating + filling default
        // inner NULL value so that we can properly trust our references to always
        // return to real inner data. this is the correct way to save the return
        // by reference.
        $val = &$this->_getProperty('foo', true);
        var_dump($this); // foo now has a NULL value in its object data
        $val = 'hi, this worked because we are linked to real internal data!';
        var_dump($this); // overwritten internal value thanks to proper link

        // notice how we have set an invalid STRING value to the internal data?
        // the "foo" property was specified to need to be an array of object
        // instances (or NULL is ok too). well, we will detect that the next
        // time we try to retrieve that property!
        try {
            $this->_getProperty('foo');
        } catch (\Exception $e) {
            printf("Test _getProperty() with invalid data inserted by reference Exception: %s\n", $e->getMessage());
        }

        // let's try satisfying its array requirements (but still fail its type req)
        $val = ['string inside array now fits array req but not type req'];

        try {
            $this->_getProperty('foo');
        } catch (\Exception $e) {
            printf("Test _getProperty() with valid array but invalid type inserted by reference Exception: %s\n", $e->getMessage());
        }

        // now let's fix the property (set it back to NULL, or we could have
        // made an array of this class to satisfy the requirement).
        $val = null;
        var_dump($this); // foo is now NULL again

        // lastly, let's show that the value is always copy-on-write if the &
        // operator is omitted from the function call. because then PHP is told
        // to make $val into copy-on-write.
        unset($val); // important: break its current reference to avoid assigning to it
        $val = $this->_getProperty('foo');
        $val = 'not modified!';
        var_dump($this); // foo still NULL, since $val is not a reference

        // for completeness sake, also test the "bar" property which has a value
        // and therefore ignores the "createMissingValue"
        $bar = &$this->_getProperty('bar', true);
        var_dump($bar); // int(1234), since a value already existed
        var_dump($this); // bar still 1234
        $bar = 456;
        var_dump($this); // bar is now 456
    }
}

// run the internal $this->_getProperty call tests:
$x = new TestGetProperty();
$x->runTest();

// run the external __get() call tests used for "virtual property access":
$x = new TestGetProperty(); // reset the internal data
var_dump($x); // has no "foo" data, only "bar"
// accessing ->foo calls __get(), which creates "foo" since virtual prop access
// requires true internal data references, otherwise they would misbehave. so it
// creates the missing property and gives it the default NULL value.
var_dump($x->foo); // null
var_dump($x); // also has "foo" now
// accessing ->bar calls __get() which sees that it exists, and gives us its value.
var_dump($x->bar); // 1234
// trying to set varibles via equals causes __set() to run, which validates all data:
try {
    $x->bar = ['invalid']; // int is expected
} catch (\Exception $e) {
    printf("Test __set() with invalid value Exception: %s\n", $e->getMessage());
}
$x->bar = '932'; // this is okay, __set() sees it is valid and casts it to int
var_dump($x); // "bar" is now int(932)
// now let's do some evil special cases! we will steal a direct reference to the
// internal _objectData['bar'], and then modify it, thus bypassing all validation.
$evilRef = &$x->bar; // now holds reference to bar
$evilRef = ['invalid']; // works, since we're literally modifying internal data
var_dump($x); // "bar" now has an invalid value (an array with a string)
try {
    // luckily, every call to _getProperty() (which __get() uses) will validate
    // the data to ensure that its internal state is valid and fits the class map.
    var_dump($x->bar);
} catch (\Exception $e) {
    printf("Test detection of injected invalid data during next __get() Exception: %s\n", $e->getMessage());
}
$x->bar = 789; // call __set() and give it a new, valid value again.
var_dump($x->bar); // int(789), it is now fixed!
// lastly, let's play with direct access to internal arrays. anytime you access
// an array, it will call __get() to get the array, and then PHP resolves your
// [] brackets on the returned array. which means that we can modify arrays by
// reference automatically!
$x->foo = []; // runs __set(): create empty array for this "\Foo\TestGetProperty[]" property
var_dump($x->foo); // runs __get() which sees valid empty array and returns it
$x->foo[] = new TestGetProperty(); // okay data written by ref to "foo" array
var_dump($x->foo); // runs __get(), which sees valid array of 1 item of right type
$x->foo[] = 'invalid'; // this is allowed because __get() gets "foo" and then
                       // PHP just directly modifies the array...
var_dump($x); // the "foo" prop now has invalid data in it
// but luckily, anything that calls _getProperty() again, such as __get(), will
// cause validation of the data:
try {
    // var_dump($x->foo); // calls __get(), would also throw the error.
    $x->foo[] = 'x'; // calls __get() again to resolve "->foo" and throws error
} catch (\Exception $e) {
    printf("Test detection of invalid injected data via array access Exception: %s\n", $e->getMessage());
}
$x->foo = [new TestGetProperty(), new TestGetProperty()]; // run __set() to give okay array again, with 2 entries
var_dump($x->foo); // shows the array with 2 objects in it
$x->foo[0] = null; // runs __get(), gets "foo" by reference, and directly modifies element
var_dump($x->foo); // shows array with 1 NULL in it (this array is valid hence no error)
// we can also __get() the internal array, then loop over the
// values-by-reference, to directly modify them without any validation:
foreach ($x->foo as $k => &$fooVal) {
    $fooVal = 'invalid';
}
var_dump($x); // array with string "invalid".
try {
    // if this had been unset($x->foo) it would work, but we try to unset a
    // sub-element which means it actually calls __get() instead of __unset()
    unset($x->foo[0]); // calls __get(), sees that the data is now invalid
} catch (\Exception $e) {
    printf("Test detection of invalid injected data via array by-reference value loop Exception: %s\n", $e->getMessage());
}
var_dump($x); // two "invalid" remains
$x->foo = [null, new TestGetProperty()]; // let's make it a valid 2-element
var_dump($x->foo); // array of [null,obj];
unset($x->foo[0]); // runs __get() on "foo", then unsets the 0th element

// these tests were commented out after adding strict sequence valiation:
// var_dump($x->foo); // array of [obj];, with the 0 array key missing
// unset($x->foo[1]->bar); // runs__get() on "foo", gets array, finds 1st elem,
//                         // sees object, runs __unset() on that ones "bar"
// var_dump($x); // reveals that the inner object no longer has any "bar" value

// let's test accessing an object inside an array. and for fun add in regular getters
// NOTE: there is a subtle difference. getFoo() returns copy-on-write array, but
// any objects within it are of course objects and can be modified and will propagate.
$x->foo = [new TestGetProperty()];
var_dump($x->foo[0]->bar); // int(1234)
var_dump($x->foo[0]->getBar()); // int(1234)
var_dump($x->getFoo()[0]->getBar()); // int(1234)
var_dump($x->getFoo()[0]->bar); // int(1234)
$x->getFoo()[0]->setBar(10);
var_dump($x); // the 0th "foo" array element has a bar of int(10) now
$x->getFoo()[0] = 'xyz'; // this does nothing, since getFoo() is always copy-on-write.
var_dump($x); // still intact, statement above had no effect, which is as intended
// now let's modify the array by reference to avoid constant __get() calls...
$arr = &$x->foo;
$arr = [1, 2, 'f'=>'bar', [['very invalid data']]];
$arr[] = 'more invalid stuff...';
var_dump($x); // very invalid stuff...
try {
    var_dump($x->foo);
} catch (\Exception $e) {
    printf("Test __get() after lots of bad array edits by reference Exception: %s\n", $e->getMessage());
}
$arr = null;
var_dump($x->foo); // now it is fine again (NULL)
// let's call a normal array-command on the returned array-by-ref
$x->foo = []; // first make it into an array
array_push($x->foo, 'zzz'); // now __get() "foo" and then directly push (same as $x->foo[] = 'bar';)
var_dump($x); // we have directly added invalid data "zzz" into the array.
$x = null; // release the object...

var_dump('---------------------');

// Test PropertyDefinition equality:
$a = new PropertyDefinition('int[]');
$b = new PropertyDefinition('int');
$c = new PropertyDefinition('int[]');
var_dump($a->equals($b)); // false
var_dump($a->equals($c)); // true
var_dump($b->equals($a)); // false
var_dump($b->equals($c)); // false
var_dump($c->equals($a)); // true
var_dump($c->equals($b)); // false

var_dump('---------------------');

// Test inheriting from base-class and also importing from other-class
class OtherBase extends LazyJsonMapper
{
    const JSON_PROPERTY_MAP = [
        'otherbase' => 'string[]',
        // ImportMapTest::class, // triggers circular map error IF ImportMapTest
        //                       // itself refers to our class hierarchy.
    ];
}
class Other extends OtherBase
{
    const JSON_PROPERTY_MAP = [
        'identical_key' => 'float',
        'other'         => 'int',
    ];
}
class Base extends LazyJsonMapper
{
    const JSON_PROPERTY_MAP = [
        'base' => 'float',
    ];
}
class ImportMapTest extends Base
{
    const JSON_PROPERTY_MAP = [
        // Base::class, // self-hierarchy reference (not allowed)
        // ImportMapTest::class, // self-class reference (not allowed)
        C::class, // reference to deeper version of self (not allowed)
        Other::class, // okay, since it's another class.. but only ok if that
                      // other class doesn't have its circular reference enabled!
        'identical_key' => 'string', // should be string, since we add it after
                                 // importing Other. but the one in Other should
                                 // remain as its own one (float).
    ];
}
class C extends ImportMapTest
{
}

try {
    $x = new C(); // comment in/out various class references above to test
                  // various arrangements of bad circular references.
    var_dump($x); // if successful inheritance, print the
                  // _compiledPropertyMapLink so we can verify that all values
                  // are properly merged.
} catch (\Exception $e) {
    printf("Test resolved-shared-ancestor circular map Exception: %s\n", $e->getMessage());
}

class AA extends LazyJsonMapper
{
    const JSON_PROPERTY_MAP = [
        BB::class,
    ];
}
class BB extends LazyJsonMapper
{
    const JSON_PROPERTY_MAP = [
        AA::class,
    ];
}

try {
    $x = new AA();
} catch (\Exception $e) {
    printf("Test resolved-shared-ancestor circular map Exception: %s\n", $e->getMessage());
}

// ensure that the locks are empty after all the construction failures above
$x = new LazyJsonMapper();
$reflect = new \ReflectionProperty($x, '_propertyMapCache');
$reflect->setAccessible(true);
var_dump($reflect->getValue()->compilerLocks); // should be empty array

var_dump('---------------------');

// this was written to test PropertyDefinition re-use (RAM saving) when a class
// re-defines a property to the exact same settings that it already inherited.
// properties in LazyJsonMapper will keep their parent's/imported value if their
// new value is identical, thus avoiding needless creation of useless objects
// that just describe the exact same settings. it makes identical re-definitions
// into a zero-cost operation!
class HasFoo extends LazyJsonMapper
{
    const JSON_PROPERTY_MAP = [
        'foo' => 'string[]',
    ];
}
class RedefinesFoo extends HasFoo
{
    const JSON_PROPERTY_MAP = [
        // 'foo' => 'float', // tests non-identical settings (should USE NEW obj)
        'foo' => 'string[]', // tests identical settings (should KEEP parent
                             // obj). memory usage should be same as if 'foo'
                             // wasn't re-defined on THIS object at all.
        // 'foo' => 'string[][]', // tests non-identical settings (should USE NEW obj)
        'extra' => '\LazyJsonMapper\LazyJsonMapper',
    ];
}
$mem = memory_get_usage();
$x = new RedefinesFoo();
unset($x); // free the object itself, so we only keep the compiled map cache
printf("Memory increased by %d bytes.\n", memory_get_usage() - $mem);

var_dump('---------------------');

// test function overriding:
class OverridesFunction extends LazyJsonMapper
{
    const JSON_PROPERTY_MAP = [
        'foo' => 'string',
    ];

    public function getFoo()
    {
        $value = $this->_getProperty('foo');

        return 'Custom getter: '.var_export($value, true);
    }

    public function setFoo(
        $value)
    {
        $value = sprintf('Tried "%s" but we will write "%s" instead.', $value, md5(time()));
        $this->_setProperty('foo', $value);

        return $this;
    }
}

$x = new OverridesFunction();
var_dump($x->getFoo());
$x->setFoo('ignored');
var_dump($x->getFoo());

var_dump('---------------------');

// Test rejection of associative array keys in "array of" JSON definition, since
// those are illegal JSON.

class TestArrayKeyValidation extends LazyJsonMapper
{
    const JSON_PROPERTY_MAP = [
        'obj_arr' => '\Foo\TestArrayKeyValidation[]',
    ];
}

// normal sequence, okay:
$x = new TestArrayKeyValidation(['obj_arr' => [null, null, null, new TestArrayKeyValidation()]]);
var_dump($x->getObjArr());

// gap in sequence, not okay:
$x = new TestArrayKeyValidation(['obj_arr' => [1 => new TestArrayKeyValidation()]]);

try {
    var_dump($x->getObjArr());
} catch (\Exception $e) {
    printf("* Test numeric gap in typed 'array of' sequence: %s\n", $e->getMessage());
}

// This is ok because of a PHP quirk which converts '0' to 0 if used as array
// key in certain cases such as this one. The key here is literally int(0):
$x = new TestArrayKeyValidation(['obj_arr' => ['0' => new TestArrayKeyValidation()]]);
var_dump($x->getObjArr());

// string key in numerically indexed "array of", not okay:
$x = new TestArrayKeyValidation(['obj_arr' => ['not_allowed_to_have_key' => new TestArrayKeyValidation()]]);

try {
    var_dump($x->getObjArr());
} catch (\Exception $e) {
    printf("* Test illegal string-based key in typed 'array of' sequence: %s\n", $e->getMessage());
}

var_dump('---------------------');

// Test validation of mixed data (only allows NULL, int, float, string, bool, or
// numerically indexed arrays of any of those types). Untyped arrays always do
// array key validation.

class TestUntypedValidation extends LazyJsonMapper
{
    const JSON_PROPERTY_MAP = [
        'unt' => '', // behavior would be the same if this property is not
                     // defined in class map but then it would have to exist in
                     // the original input data, so I defined it here as untyped.
        'strict_depth' => 'mixed[][]', // enforces mixed non-array data at 2
                                       // levels deep within an array
    ];
}

$x = new TestUntypedValidation();

try {
    $x->setUnt(new \stdClass());
} catch (\Exception $e) {
    printf("* Test set-untyped rejection of object \stdClass: %s\n", $e->getMessage());
}

try {
    $x->setUnt([new \stdClass()]);
} catch (\Exception $e) {
    printf("* Test set-untyped rejection of object [\stdClass]: %s\n", $e->getMessage());
}

$fh = null;

try {
    $fh = fopen(__DIR__.'/../funcListData.serialized', 'r');

    try {
        $x->setUnt($fh);
    } catch (\Exception $e) {
        printf("* Test set-untyped rejection of Resource: %s\n", $e->getMessage());
    }

    try {
        $x->setUnt([$fh]);
    } catch (\Exception $e) {
        printf("* Test set-untyped rejection of [Resource]: %s\n", $e->getMessage());
    }
} finally {
    if (is_resource($fh)) {
        fclose($fh);
    }
}

// all other types are allowed in this untyped field:
$x->setUnt(null);
$x->setUnt(1);
$x->setUnt(1.5);
$x->setUnt('2');
$x->setUnt(true);
$x->setUnt([null, 1, [1.5], '2', true]);
var_dump($x->getUnt());

// we also allow associative keys in untyped fields (which will become JSON
// objects), since that allows people to access "objects" in json data (arrays)
// without needing to manually map the properties to actual LazyJsonMapper
// NOTE: mixing key types can create weird JSON objects. so if people want
// strict validation they need to use typed fields instead, as seen above.
$x->setUnt([null, 1, ['foo' => 1.5], '2', true]);
var_dump($x->getUnt());

// lastly, test the "mixed[]" strict_depth which specified untyped data but
// exactly 1 array level deep.
var_dump($x->getStrictDepth());
$x->setStrictDepth([[null, 1, null, '2', true], null, []]);
var_dump($x->getStrictDepth());

try {
    $x->setStrictDepth([[null, 1, null, '2', true], 'not_array', []]);
} catch (\Exception $e) {
    printf("* Test set-untyped rejection of non-array at array-depth: %s\n", $e->getMessage());
}

try {
    $x->setStrictDepth([[null, 1, null, '2', true], 'foo' => 'bar', []]);
} catch (\Exception $e) {
    printf("* Test set-untyped rejection of associative key in strict array depth: %s\n", $e->getMessage());
}

try {
    $x->setStrictDepth([[null, 1, null, '2', true], [['too_deep']], []]);
} catch (\Exception $e) {
    printf("* Test set-untyped rejection of array deeper than max depth: %s\n", $e->getMessage());
}
var_dump($x->getStrictDepth());
$x->setStrictDepth([]); // okay, since we never reach maxdepth
var_dump($x->getStrictDepth()); //accepted
$x->setStrictDepth(null); // null is always okay
var_dump($x->getStrictDepth()); //accepted
try {
    $x->setStrictDepth('foo'); // rejected since the value is not at specified depth
} catch (\Exception $e) {
    printf("* Test set-untyped rejection of value at not enough depth: %s\n", $e->getMessage());
}

var_dump('---------------------');

// Test FunctionCase name translations into property names:

$x = new FunctionTranslation('ExportProp');
var_dump($x);

try {
    // Invalid single-word lowercase FuncCase name.
    $x = new FunctionTranslation('somelowercase');
} catch (\Exception $e) {
    printf("Test invalid single-word lowercase FuncCase name 'somelowercase' Exception: %s\n", $e->getMessage());
}
$x = new FunctionTranslation('_MessageList');
var_dump($x);
$x = new FunctionTranslation('Nocamelcase'); // Single uppercase = no camel.
var_dump($x);
$x = new FunctionTranslation('WithCamelCase'); // Multiple Ucwords = camel.
var_dump($x);

var_dump('---------------------');

// Test property name translations into FunctionCase, and back...
// They must be 100% identical in both directions!

// Test function names to property names, and then ensure that both snake_case
// and camelCase variants translate back to the same function name via PropertyTranslation.
$funcList = [
    'getSome0XThing',
    'getSome0xThing',
    'getSomeThing',
    'get_Messages',
    'get__MessageList',
    'get0m__AnUn0x',
    'get__Foo_Bar__XBaz__',
    'get__Foo_Bar_',
    'get___M',
    'get_M',
    'get_0',
    'get_',
    'get___',
    'get123',
    'get123prop',
    'get123Prop',
];
foreach ($funcList as $f) {
    echo "---\n";
    list($functionType, $funcCase) = FunctionTranslation::splitFunctionName($f);

    $x = new FunctionTranslation($funcCase);
    printf("* Function: '%s'\n-     Type: '%s'\n- FuncCase: '%s'\n   > snake: '%s',\n   > camel: '%s'\n", $f, $functionType, $funcCase, $x->snakePropName, $x->camelPropName);

    $y = new PropertyTranslation($x->snakePropName);
    $getter = 'get'.$y->propFuncCase;
    printf("* Property: '%s' (snake)\n    > func: '%s' (%s)\n", $x->snakePropName, $getter, $getter === $f ? 'ok' : 'fail');
    if ($x->camelPropName === null) {
        echo "* Property: No Camel Property, skipping...\n";
    } else {
        $y = new PropertyTranslation($x->camelPropName);
        $getter = 'get'.$y->propFuncCase;
        printf("* Property: '%s' (camel)\n    > func: '%s' (%s)\n", $x->camelPropName, $getter, $getter === $f ? 'ok' : 'fail');
    }
    echo "---\n";
}

var_dump('---------------------');

// test the special operator translator
$result = 'A + and - and * and / and finally % symbol... And some ++--**//%% close ones...';
var_dump($result);
$result = \LazyJsonMapper\Magic\SpecialOperators::encodeOperators($result);
var_dump($result);
$result = \LazyJsonMapper\Magic\SpecialOperators::decodeOperators($result);
var_dump($result);

class OperatorTest extends LazyJsonMapper
{
    const JSON_PROPERTY_MAP = [
        'en+US'                 => 'string',
        'en-US'                 => 'string',
        'en/US'                 => 'string',
        'en%US'                 => 'string',
        'en*US'                 => 'string',
        'with;semicolon_and@at' => 'string',
    ];
}

$optest = new OperatorTest([
    'en+US'                 => 'plus',
    'en-US'                 => 'minus',
    'en/US'                 => 'divide',
    'en%US'                 => 'modulo',
    'en*US'                 => 'multiply',
    'with;semicolon_and@at' => 'complex characters here!',
]);

$optest->printPropertyDescriptions();
var_dump($optest->getEn_x2B_US()); // plus
var_dump($optest->getEn_x2D_US()); // minus
var_dump($optest->getEn_x2F_US()); // divide
var_dump($optest->getEn_x25_US()); // modulo
var_dump($optest->getEn_x2A_US()); // multiply
var_dump($optest->getWith_x3B_semicolonAnd_x40_at());

var_dump('---------------------');

// Test the property description system (the parameters are so strict that there
// isn't really anything to test, apart from the relative property param...)
$ownerClassName = get_class(new Test());
$desc = new PropertyDescription(
    $ownerClassName,
    'the_property',
    new PropertyDefinition('\Foo\Test[][]'),
    false // do not allow relative paths
);
var_dump($desc);

$desc = new PropertyDescription(
    $ownerClassName,
    'the_property',
    new PropertyDefinition('\Foo\Test[][]'),
    true // allow relative paths
);
var_dump($desc);

// and now test the is_defined detection of UndefinedProperty:

$desc = new PropertyDescription(
    $ownerClassName,
    'the_property',
    \LazyJsonMapper\Property\UndefinedProperty::getInstance(),
    false // do not allow relative paths
);
var_dump($desc);

$desc = new PropertyDescription(
    $ownerClassName,
    'the_property',
    \LazyJsonMapper\Property\UndefinedProperty::getInstance(),
    true // allow relative paths
);
var_dump($desc);

var_dump('---------------------');

// calculate the memsize of a propertydefinition under various circumstances

// first... force autoloading to find each class if not already loaded, to avoid
// messing up the measurement.
$x = new PropertyDefinition();
$x = new LazyJsonMapper();

unset($x);
$mem = memory_get_usage();
$x = new PropertyDefinition('\LazyJsonMapper\LazyJsonMapper');
printf("Memory size of a PropertyDefinition object referring to '\\LazyJsonMapper\\LazyJsonMapper': %d bytes.\n", memory_get_usage() - $mem);

unset($x);
$mem = memory_get_usage();
$x = new PropertyDefinition();
printf("Memory size of a PropertyDefinition object referring to NULL ('mixed'/untyped): %d bytes.\n", memory_get_usage() - $mem);

unset($x);
$mem = memory_get_usage();
$x = new PropertyDefinition('int');
printf("Memory size of a PropertyDefinition object referring to 'int': %d bytes.\n", memory_get_usage() - $mem);

unset($x);
$mem = memory_get_usage();
$x = new PropertyDefinition('int[]');
printf("Memory size of a PropertyDefinition object referring to 'int[]': %d bytes.\n", memory_get_usage() - $mem);

unset($x);
$mem = memory_get_usage();
$x = new PropertyDefinition('float[][][]');
printf("Memory size of a PropertyDefinition object referring to 'float[][][]': %d bytes.\n", memory_get_usage() - $mem);

var_dump('---------------------');

// Test detection of undefined properties:
$undef = UndefinedProperty::getInstance();
$def = new PropertyDefinition();
var_dump($undef instanceof UndefinedProperty); // true
var_dump($def instanceof UndefinedProperty); // false

var_dump('---------------------');

// the following test analyzes memory usage of FunctionTranslation objects vs
// storing the cache values as a regular array (without objects). since objects
// are specialized arrays in PHP, with lower memory needs, we see some pretty
// great space savings by using objects.
//
// these tests will determine the memory needs for the runtime function call
// translation cache. I'd say normally people use around 100 different
// properties. this code generates very realistic function names that mimic
// real-world data.

// if true, re-use pre-built list of 10000 function names and ignore the other
// params below. that's useful because building the list of names is slow on PHP5.
// and especially because it guarantees comparable runs between PHP binaries.
$usePrebuiltFuncList = true;

// how many function names to generate. large sample sizes = accurate averages.
// this only happens if "useprebuilt" is disabled.
// $funcCacheCount = 10000; // recommended
$funcCacheCount = 100; // fast for testing but gives inaccurate memory averages

/*
 * Here are results for PHP7 and PHP5 with 10000x entries to really demonstrate
 * the correct averages by having a large enough sample size.
 *
 * PHP7: Array of 10000x FunctionTranslation objects: 2485704 bytes total, ~248.6 bytes per entry.
 * PHP7: Array of 10000x numerically indexed arrays: 5394584 bytes total, ~539.5 bytes per entry.
 * PHP5: Array of 10000x FunctionTranslation objects: 5104024 bytes total, ~510.4 bytes per entry.
 * PHP5: Array of 10000x numerically indexed arrays: 6640864 bytes total, ~664.4 bytes per entry.
 *
 * Those numbers include the object AND the overhead of their associatively
 * named string key in the parent (cache) array. The array key is the FuncCase
 * portion of the name (meaning it lacks the functionType prefix like "get" or
 * "unset").
 *
 * The actual LazyJsonMapper project uses FunctionTranslation objects, and
 * normal users can be expected to need around 100 cache entries to cover every
 * property they use in their project.
 *
 * That's ~25kb of RAM on PHP7 or ~51kb on PHP5. ;-)
 */

function randWords(
    array $allWords)
{
    global $allWords;

    // pick 1-3 words
    $keys = array_rand($allWords, mt_rand(2, 4));
    array_shift($keys);

    // ensure that they are all lowercase, with an uppercase first letter
    $words = [];
    foreach ($keys as $k) {
        $w = ucfirst(preg_replace('/[^a-z]+/', 'x', strtolower($allWords[$k])));
        // The wordlist has many insanely long words...
        // Limit the length to 2-5 chars per word (JSON programmers are terse)
        $w = substr($w, 0, mt_rand(2, 5));
        $words[] = $w;
    }

    return $words;
}
function randFunctionName(
    array $allWords)
{ // Generates a valid "realistic" function name
    // Commented out because we no longer use the function type as part of the
    // parsing of FuncCase names. So we don't need it in the test-data.
    // $functionType = ['has', 'get', 'set', 'is', 'unset'][mt_rand(0, 4)];
    // return $functionType.implode(randWords($allWords));

    return implode(randWords($allWords));
}
function buildFuncList(
    array $allWords,
    $count = 500)
{ // Generates a list of unique functions.
    if (count($allWords) < 100) {
        die('Not enough words...');
    }
    $funcList = [];
    while (count($funcList) < $count) {
        $funcList[randFunctionName($allWords)] = true;
    }

    return array_keys($funcList);
}

if (!$usePrebuiltFuncList) {
    if (!is_file('/usr/share/dict/words')) {
        die('Dictionary file missing.');
    }
    $allWords = @file('/usr/share/dict/words');

    // build a list of $funcCacheCount amount functions that we'll put in a lookup cache
    echo "- creating a list of {$funcCacheCount} random function names...\n";
    $funcList = buildFuncList($allWords, $funcCacheCount);

    // debug/list generation:
    // var_dump($funcList); // uncomment to see quality of name generation
    // file_put_contents(__DIR__.'/../funcListData.serialized', serialize($funcList));

    echo "- function list built... running cache test...\n";
} else {
    if (!is_file(__DIR__.'/../funcListData.serialized')) {
        die('No serialized function list.');
    }
    $funcList = unserialize(file_get_contents(__DIR__.'/../funcListData.serialized'));
}

// force autoloading of the class to prevent counting the class itself in mem
$x = new FunctionTranslation('Example');

// try storing them as FunctionTranslation objects
$holder = [];
foreach ($funcList as $funcCase) {
    $newFuncCase = $funcCase.'x'; // Avoid variable re-use of incoming string.
    $holder[$newFuncCase] = new FunctionTranslation($newFuncCase);
    unset($newFuncCase);
}
// var_dump($holder); // warning: don't uncomment while testing; increases mem
$mem = memory_get_usage();
unset($holder);
$totalmem = $mem - memory_get_usage();
$indivmem = $totalmem / count($funcList); // includes the parent array overhead
printf("PHP%d: Array of %dx FunctionTranslation objects: %d bytes total, ~%.1f bytes per entry.\n", $hasSeven ? 7 : 5, count($funcList), $totalmem, $indivmem);

// try storing them as a regular non-associative array
$holder = [];
foreach ($funcList as $funcCase) {
    $newFuncCase = $funcCase.'y'; // Avoid variable re-use of incoming string.
    $translation = new FunctionTranslation($newFuncCase);
    $y = [
        // paranoid about PHP re-using the object's value, so let's tweak all:
        substr($translation->snakePropName, 0, -1).'y',
        $translation->camelPropName === null ? null : substr($translation->camelPropName, 0, -1).'y',
    ];
    $holder[$newFuncCase] = $y;
    unset($translation);
    unset($newFuncCase);
    unset($y);
}
// var_dump($holder); // warning: don't uncomment while testing; increases mem
$mem = memory_get_usage();
unset($holder);
$totalmem = $mem - memory_get_usage();
$indivmem = $totalmem / count($funcList); // includes the parent array overhead
printf("PHP%d: Array of %dx numerically indexed arrays: %d bytes total, ~%.1f bytes per entry.\n", $hasSeven ? 7 : 5, count($funcList), $totalmem, $indivmem);

var_dump('---------------------');

// test cache clearing and the memory usage of each cache from this test-file.
$mem = memory_get_usage();
$lookupCount = LazyJsonMapper::clearGlobalMagicLookupCache();
printf("Saved %d bytes by clearing the magic function lookup cache, which contained %d function name translations.\n", $mem - memory_get_usage(), $lookupCount);

$mem = memory_get_usage();
$classCount = LazyJsonMapper::clearGlobalPropertyMapCache();
printf("Saved %d bytes by clearing %d compiled class maps. But not all may have been freed from memory by PHP yet, if any class instance variables are still in scope.\n", $mem - memory_get_usage(), $classCount);

var_dump('---------------------');

// perform lots of tests of the array converter:

// assign the normal json data array, but do not recursively validate (convert)
// it since we want a mix of converted and unconverted data during this test...
$x = new Test($jsonData);

//
// the magic: asArray() CLONES the internal data, then recursively validates all
// of it and then converts it back to a plain array. the result is therefore
// fully validated/type-converted as a side-effect of the conversion process.
//
// it does not touch the contents of the original object:
//
$x->getSelfObject(); // force self_object to evaluate and parse
var_dump($x); // look at raw data... nothing is parsed except self_object

$asArray = $x->asArray();

// look at the original object... still nothing is parsed except self_object,
// which remains obj, with the exact same instance number. this verifies that
// asArray did not manipulate/destroy data in our object.
var_dump($x);

// validate the asArray result for correctness:
// $asArray[] = 'x'; // uncomment this to trigger a mismatch below
// var_dump($asArray); // look at asarray contents
printf("The asArray() result matches original input array? %s\n",
       // NOTE: Array === operator checks all keys, keytypes, key order, values,
       // valuetypes and counts recursively. If true, arrays contain IDENTICAL.
       ($asArray === $jsonData ? 'YES!' : 'No...'));

// try tweaking the input data so that the class definition no longer matches:
$jsonData['self_array'] = [$jsonData['self_array']]; // wrap in extra array depth
$x = new Test($jsonData);

try {
    $asArray = $x->asArray();
} catch (\Exception $e) {
    printf("Trying asArray() with data that mismatches class map Exception: %s\n", $e->getMessage());
}
$jsonData['self_array'] = $jsonData['self_array'][0]; // fix data again

// try undefined/untyped (missing) field with acceptable basic non-object data:
// acceptable basic data is: "int, float, string, bool, NULL" (and arrays of those).
$jsonData['untyped_field_with_non_object'] = '123456foo';
$x = new Test($jsonData);
$asArray = $x->asArray();
printf("As array with untyped/undefined missing but ok data: %s\n",
       ($asArray === $jsonData ? 'YES!' : 'No...'));

// try undefined/untyped (missing) field with a LazyJsonMapper object. this will
// NOT be okay because untyped fields only allow basic PHP types mentioned above.
// NOTE: This can NEVER happen via real json_decode() data. It is a test against
// user's custom data arrays with bad values...
$jsonData['untyped_field_with_lazy_object'] = new LazyJsonMapper(['inner_val' => '123foo']);

try {
    $x = new Test($jsonData, true); // true = run with validation
} catch (\Exception $e) {
    printf("Test construction with validation enabled, and having an illegal value (object) in an undefined property Exception: %s\n", $e->getMessage());
}
// try with non-LazyJsonMapper object too in a different property (will fail too
// since ALL OBJECTS are forbidden in undefined/untyped properties):
$jsonData['untyped_field_with_bad_object'] = new \stdClass();
$x = new Test($jsonData); // now construct it WITHOUT validation, so the illegal
                          // value is undetected...
try {
    $asArray = $x->asArray();
} catch (\Exception $e) {
    // should warn about BOTH the lazy and the "bad" object:
    printf("Test asArray() on previously unvalidated object containing illegal values in data array Exception: %s\n", $e->getMessage());
}

try {
    $x->getUntypedFieldWithBadObject();
} catch (\Exception $e) {
    // should warn about BOTH the lazy and the "bad" object:
    printf("Test getUntypedFieldWithBadObject() on previously unvalidated object with illegal value in that field Exception: %s\n", $e->getMessage());
}

// now remove the fake data value again to restore the original jsonData...
unset($jsonData['untyped_field_with_lazy_object']);
unset($jsonData['untyped_field_with_bad_object']);

$x = new Test($jsonData);
$asArray = $x->asArray(); // works again since all bad data is gone!
var_dump($asArray);

// now try type-conversion to ensure that the type-map is followed:
class ForceType extends LazyJsonMapper
{
    const JSON_PROPERTY_MAP = [
        'arr' => 'float[]',
    ];
}
$x = new ForceType(['arr' => ['1', '232', '94.2', 123.42]]);
var_dump($x->asArray()); // all are floats, exactly as the class-map requested
var_dump($x); // and as usual... internal _objectData remains untouched.

// try non-integer arguments
try {
    $x->asJson(false);
} catch (\Exception $e) {
    printf("Test asJson() with non-int arg1: %s\n", $e->getMessage());
}

try {
    $x->asJson(0, false);
} catch (\Exception $e) {
    printf("Test asJson() with non-int arg2: %s\n", $e->getMessage());
}

// try requesting a json data depth that is way too low for the data:
try {
    $x->asJson(0, 1);
} catch (\Exception $e) {
    printf("Test asJson() with too low depth parameter Exception: %s\n", $e->getMessage());
}

// and for fun...
var_dump($x->asArray());
var_dump($x->asJson());
var_dump($x->asJson(JSON_PRETTY_PRINT));
$x->printJson();

var_dump('---------------------');

$x = new Test($jsonData);

// ensure that "convert object to string" works and properly outputs JSON...
echo '['.$x."]\n\n";
echo $x;
echo PHP_EOL;

// and test invalid data being output as <Message> with <> brackets as intended:
$bad = new Test(['self_object' => 1]);
echo PHP_EOL.'Test of clearly bad input data error handling as message string (since __toString cannot throw): '.$bad.PHP_EOL;

var_dump('---------------------');

// try unsetting properties from the internal JSON data tree:
$x = new Test($jsonData);
$x->printJson();
$x->unsetSelfArray() // NOTE: This tests the "chained unsetter" feature too.
    ->unsetCamelCaseProp()
    ->setSelfObject(new Test(['just_a_string' => '123 new object!'])); // Tests chained setter together with unsetters.
$x->printJson();
$x->unsetSelfObject();
$x->printJson();

// now try reading a property and then unsetting and reading it again:
var_dump($x->getStringArray());
$x->unsetStringArray();
var_dump($x->getStringArray());
$x->printJson();

// also try using the direct unset() on the remaining values
unset($x->just_a_string);
unset($x->untyped_field_with_non_object);
$x->printJson();

var_dump('---------------------');

// Let's do some serialization tests:

// First, run a recursive analysis to force all unparsed properties to evaluate
// into creating inner LazyJsonMapper objects.
$x = new Test($jsonData);
$x->exportClassAnalysis();
var_dump($x); // tree of objects

// Now test the secret, internal "tight packing" serialization method which
// returns the internal data as a plain array instead of as a serialized string:
$secretArr = $x->serialize($x);
var_dump($secretArr);

// Now serialize the object into an actual, serialized string. The same way an
// end-user would do it.
// NOTE: This resolves all nested objects and serializes the root object with a
// single serialized, plain array within it.
$str = serialize($x);
var_dump($str); // no nested serialized objects

// test the ability to fake "unserialize" into re-constructing objects from any
// serialized array. NOTE: this is just for testing proper re-building /
// unserialization in a different way. users should never do this. it's dumb.
// they should just create their "new TheClass([...])" instead.
$fakeunserialize = new Test();
var_dump($fakeunserialize); // empty _objectData
$fakeunserialize->unserialize(serialize(['my_data' => 'hehe']));
var_dump($fakeunserialize); // objectdata now has my_data

// test exception when calling the function directly with bad params
try {
    $fakeunserialize->unserialize();
    $fakeunserialize->unserialize(null);
} catch (\Exception $e) {
    printf("Test unserialize manual call with bad params Exception: %s\n", $e->getMessage());
}

// lastly, let's test real unserialization as a new object instance.
// this creates a brand new object with the data array, and has no links to the
// original object (except using the same shared, compiled classmap since we are
// still in the same runtime and have a shared classmap cache entry available).
$new = unserialize($str);
// verify that all _objectData is there in the new object, and that unlike the
// original object (which had exportClassAnalysis() to create inner objects),
// this unserialized copy just has a plain data array:
var_dump($new);
// get a random property to cause it to convert it to its destination format:
$new->getSelfArray();
var_dump($new); // self_array is now an array of actual objects

var_dump('---------------------');

// test asArray/asStdClass which are aliases to exportObjectDataCopy
var_dump($x->exportObjectDataCopy('array'));
var_dump($x->asArray());
// var_dump($x->exportObjectDataCopy('Array')); // test invalid type
var_dump($x->exportObjectDataCopy('stdClass'));
var_dump($x->asStdClass());

var_dump('---------------------');

// test new data assignment at a later time via assignObjectData():
$foo = new Test(['just_a_string' => '123']);
$foo->printPropertyDescriptions();
$foo->printJson();
$foo->assignObjectData(['camelCaseProp' => 999, 'string_array' => ['a', 'b', 'c']]);
$foo->printJson();

var_dump('---------------------');

class TestJSONArrayKeys extends LazyJsonMapper
{
    const JSON_PROPERTY_MAP = [
        'array_of_strings' => 'string[]',
    ];
}

// first, test the fact that objects must always be output as {} notation.
$test = new TestJSONArrayKeys();
var_dump($test->asJson());
$test->printJson(); // {}
$test->setArrayOfStrings(['a', 'b']);
var_dump($test->asJson());
$test->printJson(); // {"array_of_strings":["a","b"]}
$test = new TestJSONArrayKeys(['a', 'b']);
var_dump($test->asJson());
$test->printJson(); // {"0":"a","1":"b"}

// now do a test of the fact that properties defined as "array of" only allow
// sequential, numerical keys.
$test->setArrayOfStrings(['a', 'b']);
$test->setArrayOfStrings(['0' => 'a', 'b']); // works because PHP converts "0" to int(0)
$test->setArrayOfStrings([0 => 'a', 1 => 'b']); // correct order
try {
    $test->setArrayOfStrings([1 => 'a', 0 => 'b']); // bad order
} catch (\Exception $e) {
    printf("Test wrong order array keys, Exception: %s\n", $e->getMessage());
}

try {
    $test->setArrayOfStrings([4 => 'a', 5 => 'b']); // not starting at 0
} catch (\Exception $e) {
    printf("Test array keys not starting at 0, Exception: %s\n", $e->getMessage());
}

try {
    $test->setArrayOfStrings(['a', 'b', 'foo' => 'b']); // string-key
} catch (\Exception $e) {
    printf("Test non-numeric array key in numeric array, Exception: %s\n", $e->getMessage());
}

var_dump('---------------------');

// test that our forced-object-notation {} JSON output works in all cases (even
// when strictly numeric keys or empty arrays).
// the correct, intended format is: The outer container (the object) is always
// {}, but any inner arrays in properties are [].

$foo = new Test(); // no internal array assigned, so uses empty default array
var_dump($foo->asJson());
$foo->printJson();
$foo = new Test([1, [11, 22, 33], 3]);
var_dump($foo->asJson());
$foo->printJson();
$foo = new Test([0=>1, 1=>[11, 22, 33], 2=>3]);
var_dump($foo->asJson());
$foo->printJson();
$foo = new Test([0=>1, '1'=>[11, 22, 33], 2=>3]);
var_dump($foo->asJson());
$foo->printJson();
$foo = new Test([0=>1, 2=>3, 1=>[11, 22, 33]]);
var_dump($foo->asJson());
$foo->printJson();
$foo = new Test([1, [11, 22, 33], 3, 'x'=>1]);
var_dump($foo->asJson());
$foo->printJson();
$foo = new Test(['x'=>1, 1, [11, 22, 33], 3]);
var_dump($foo->asJson());
$foo->printJson();

var_dump('---------------------');

// now end with some nice data dumping tests on the final, large data object...
// and let's use the newly unserialized object instance for fun..
var_dump($new->asJson());
var_dump($new->asJson(JSON_PRETTY_PRINT)); // manually controlling JSON output options
$new->printPropertyDescriptions();
echo str_repeat(PHP_EOL, 5);
$new->printJson(false); // automatic printing but without pretty-print enabled
echo str_repeat(PHP_EOL, 5);
$new->getSelfObject()->printJson(); // printing a sub-object (only safe if obj is non-NULL)
echo str_repeat(PHP_EOL, 5);
// $new->getSelfArray()->printJson(); // would not work on PHP arrays, obviously
$new->getSelfArray()[0]->printJson(); // works, since that array entry is an obj
echo str_repeat(PHP_EOL, 5);
$new->printJson(); // <-- Debug heaven! ;-)
