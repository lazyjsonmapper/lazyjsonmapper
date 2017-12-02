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

namespace LazyJsonMapper;

use JsonSerializable;
use LazyJsonMapper\Exception\LazyJsonMapperException;
use LazyJsonMapper\Exception\LazySerializationException;
use LazyJsonMapper\Exception\LazyUserException;
use LazyJsonMapper\Exception\LazyUserOptionException;
use LazyJsonMapper\Exception\MagicTranslationException;
use LazyJsonMapper\Export\ClassAnalysis;
use LazyJsonMapper\Export\PropertyDescription;
use LazyJsonMapper\Magic\FunctionTranslation;
use LazyJsonMapper\Property\PropertyDefinition;
use LazyJsonMapper\Property\PropertyMapCache;
use LazyJsonMapper\Property\PropertyMapCompiler;
use LazyJsonMapper\Property\UndefinedProperty;
use LazyJsonMapper\Property\ValueConverter;
use Serializable;
use stdClass;

/**
 * Advanced, intelligent & automatic object-oriented JSON containers for PHP.
 *
 * Implements a highly efficient, automatic, object-oriented and lightweight
 * (memory-wise) JSON data container. It provides intelligent data conversion
 * and parsing, to give you a nice, reliable interface to your JSON data,
 * without having to worry about doing any of the tedious parsing yourself.
 *
 * Features:
 *
 *  - Provides a completely object-oriented interface to all of your JSON data.
 *
 *  - Automatically maps complex, nested JSON data structures onto real PHP
 *    objects, with total support for nested objects and multi-level arrays.
 *
 *  - Extremely optimized for very high performance and very low memory usage.
 *    Much lower than other PHP JSON mappers that people have used in the past.
 *
 *    For example, normal PHP objects with manually defined `$properties`, which
 *    is what's used by _other_ JSON mappers, will consume memory for every
 *    property even if that property wasn't in the JSON data (is a `NULL`). Our
 *    system on the other hand takes up ZERO bytes of RAM for any properties
 *    that don't exist in the current object's JSON data!
 *
 *  - Automatically provides "direct virtual properties", which lets you
 *    interact with the JSON data as if it were regular object properties,
 *    such as `echo $item->some_value` and `$item->some_value = 'foo'`.
 *
 *    The virtual properties can be disabled via an option.
 *
 *  - Automatically provides object-oriented "virtual functions", which let you
 *    interact with the data in a fully object-oriented way via functions such
 *    as `$item->getSomeValue()` and `$item->setSomeValue('foo')`. We support a
 *    large range of different functions for manipulating the JSON data, and you
 *    can see a list of all available function names for all of your properties
 *    by simply running `$item->printPropertyDescriptions()`.
 *
 *    The virtual functions can be disabled via an option.
 *
 *  - Includes the `LazyDoctor` tool, which _automatically_ documents all of
 *    your `LazyJsonMapper`-based classes so that their virtual properties and
 *    functions become _fully_ visible to your IDE and to various intelligent
 *    code analysis tools. It also performs class diagnostics by compiling all
 *    of your class property maps, which means that you can be 100% sure that
 *    all of your maps are valid (compilable) if this tool runs successfully.
 *
 *  - We provide a complete, internal API which your subclasses can use to
 *    interact with the data inside of the JSON container. This allows you to
 *    easily override the automatic functions or create additional functions
 *    for your objects. To override core functions, just define a function with
 *    the exact same name on your object and make it do whatever you want to.
 *
 *    Here are some examples of function overriding:
 *
 *      ```php
 *      public function getFoo()
 *      {
 *          // try to read property, and handle a special "current_time" value.
 *          $value = $this->_getProperty('foo');
 *          if ($value === 'current_time') { return time(); }
 *          return $value;
 *      }
 *      public function setFoo(
 *          $value)
 *      {
 *          // if they try to set value to "md5", use a special value instead.
 *          if ($value === 'md5') { $value = md5(time()); }
 *          return $this->_setProperty('foo', $value);
 *      }
 *      ```
 *
 *  - All mapping/data conversion is done "lazily", on a per-property basis.
 *    When you access a property, that specific property is mapped/converted to
 *    the proper type as defined by your class property map. No time or memory
 *    is wasted converting properties that you never touch.
 *
 *  - Strong type-system. The class property map controls the exact types and
 *    array depths. You can fully trust that the data you get/set will match
 *    your specifications. Invalid data that mismatches the spec is impossible.
 *
 *  - Advanced settings system. Everything is easily configured via PHP class
 *    constants, which means that your class-settings are stateless (there's no
 *    need for any special "settings/mapper object" to keep track of settings),
 *    and that all settings are immutable constants (which means that they are
 *    reliable and can never mutate at runtime, so that you can fully trust that
 *    classes will always behave as-defined in their code).
 *
 *    If you want to override multiple core settings identically for all of your
 *    classes, then simply create a subclass of `LazyJsonMapper` and configure
 *    all of your settings on that, and then derive all of your other classes
 *    from your re-configured subclass!
 *
 *  - The world's most advanced mapper definition system. Your class property
 *    maps are defined in an easy PHPdoc-style format, and support multilevel
 *    arrays (such as `int[][]` for "an array of arrays of ints"), relative
 *    types (so you can map properties to classes/objects that are relative to
 *    the namespace of the class property map), parent inheritance (all of your
 *    parent `extends`-hierarchy's maps will be included in your final property
 *    map) and even multiple inheritance (you can literally "import" an infinite
 *    number of other maps into your class, which don't come from your own
 *    parent `extends`-hierarchy).
 *
 *  - Inheriting properties from parent classes or importing properties from
 *    other classes is a zero-cost operation thanks to how efficient our
 *    property map compiler is. So feel free to import everything you need.
 *    You can even use this system to create importable classes that just hold
 *    "collections" of shared properties, which you import into other classes.
 *
 *  - The class property maps are compiled a single time per-class at runtime,
 *    the first time a class is used. The compilation process fully verifies
 *    and compiles all property definitions, all parent maps, all inherited
 *    maps, and all maps of all classes you link properties to.
 *
 *    If there are any compilation problems due to a badly written map anywhere
 *    in your hierarchy, you will be shown the exact problem in great detail.
 *
 *    In case of success, the compiled and verified maps are all stored in an
 *    incredibly memory-efficient format in a global cache which is shared by
 *    your whole PHP runtime, which means that anything in your code or in any
 *    other libraries which accesses the same classes will all share the cached
 *    compilations of those classes, for maximum memory efficiency.
 *
 *  - You are also able to access JSON properties that haven't been defined in
 *    the class property map. In that case, they are treated as undefined and
 *    untyped (`mixed`) and there won't be any automatic type-conversion of such
 *    properties, but it can still be handy in a pinch.
 *
 *  - There are lots of data export/output options for your object's JSON data,
 *    to get it back out of the object again: As a multi-level array, as nested
 *    stdClass objects, or as a JSON string representation of your object.
 *
 *  - We include a whole assortment of incredibly advanced debugging features:
 *
 *    You can run the constructor with `$requireAnalysis` to ensure that all
 *    of your JSON data is successfully mapped according to your class property
 *    map, and that you haven't missed defining any properties that exist in the
 *    data. In case of any problems, the analysis message will give you a full
 *    list of all problems encountered in your entire JSON data hierarchy.
 *
 *    For your class property maps themselves, you can run functions such as
 *    `printPropertyDescriptions()` to see a complete list of all properties and
 *    how they are defined. This helps debug your class inheritance and imports
 *    to visually see what your final class map looks like, and it also helps
 *    users see all available properties and all of their virtual functions.
 *
 *    And for the JSON data, you can use functions such as `printJson()` to get
 *    a beautiful view of all internal JSON data, which is incredibly helpful
 *    when you (or your users) need to figure out what's available inside the
 *    current object instance's data storage.
 *
 *  - A fine-grained and logical exception-system which ensures that you can
 *    always trust the behavior of your objects and can catch problems easily.
 *    And everything we throw is _always_ based on `LazyJsonMapperException`,
 *    which means that you can simply catch that single "root" exception
 *    whenever you don't care about fine-grained differentiation.
 *
 *  - Clean and modular code ensures stability and future extensibility.
 *
 *  - Deep code documentation explains everything you could ever wonder about.
 *
 *  - Lastly, we implement super-efficient object serialization. Everything is
 *    stored in a tightly packed format which minimizes data size when you need
 *    to transfer your objects between runtimes.
 *
 * @copyright 2017 The LazyJsonMapper Project
 * @license http://www.apache.org/licenses/LICENSE-2.0
 * @author SteveJobzniak (https://github.com/SteveJobzniak)
 */
class LazyJsonMapper implements Serializable, JsonSerializable
{
    /**
     * Whether "direct virtual properties" access is enabled.
     *
     * This constant can be overridden in your subclasses to toggle the option.
     *
     * It is recommended that all of your brand new projects create a subclass
     * of `LazyJsonMapper` which disables this option, and then you simply
     * derive all of your other classes from that subclass so that nothing in
     * your project has direct virtual property access enabled.
     *
     * That's because there are many quirks with how PHP implements virtual
     * properties, which can lead to performance slowdowns and lack of data
     * validation if you use direct access in CERTAIN scenarios (without writing
     * proper code). And if users are going to be interacting with YOUR library,
     * then you SHOULD disable virtual properties so that your users don't fail
     * to implement their proper usage. PHP's virtual property quirks are all
     * described in the documentation for `__get()`. Please read it and decide
     * whether you want to allow "direct virtual properties".
     *
     * The only reason why this feature is enabled by default is that it helps
     * people easily migrate from legacy projects.
     *
     * @var bool
     *
     * @see LazyJsonMapper::__get() More details about virtual properties.
     */
    const ALLOW_VIRTUAL_PROPERTIES = true;

    /**
     * Whether "virtual functions" access is enabled.
     *
     * This constant can be overridden in your subclasses to toggle the option.
     *
     * It's the recommended access method. It always ensures complete data
     * validation and the highest performance. However, some people may want to
     * disable it in favor of manually defining your object's functions instead.
     * (This library provides a complete internal API for manipulating the
     * object's JSON data from your own custom class-functions.)
     *
     * @var bool
     *
     * @see LazyJsonMapper::__call() More details about virtual functions.
     */
    const ALLOW_VIRTUAL_FUNCTIONS = true;

    /**
     * Whether we should cache all magic virtual function translations.
     *
     * This constant can be overridden in your subclasses to toggle caching.
     *
     * The cache stores all of the current runtime's encountered magic/virtual
     * function name translations, such as `SomeProperty` (via `getSomeProperty`
     * and `setSomeProperty`, etc) and the fact that it refers to a JSON
     * property that will be named either `some_property` (if snake style)
     * or `someProperty` (if camel style).
     *
     * Entries are added to the cache one-by-one every time you call a function
     * on an uncached property name for the first time. The next time you call a
     * function which accesses the same property name, then there's no need to
     * analyze the function name again (to figure out what JSON data property it
     * refers to), since the translation is already cached. It is cached on a
     * per-property name basis. So a SINGLE cached translation of `SomeProperty`
     * will be shared by ALL function calls related to that property (such as
     * all of the core functions; `hasSomeProperty`, `isSomeProperty`,
     * `getSomeProperty`, `setSomeProperty`, and `unsetSomeProperty`).
     *
     * At the cost of a very tiny bit of RAM, this caching greatly speeds up
     * magic function calls so that they only take about 16% as long as they
     * would without the cache. However, they're still very fast even without a
     * cache, and you may be accessing so many different properties that you'd
     * prefer to avoid a cache for memory reasons (if you prefer lower memory
     * needs over pure speed). The choice is yours.
     *
     * To calculate the memory needs of your own project's virtual function
     * cache, you simply need to know that an average cache entry uses ~248.6
     * bytes of RAM on PHP 7 and ~510.4 bytes on PHP 5. Note that the size per
     * name translation is small enough to be measured in BYTES (NOT kilobytes)!
     *
     * Those averages were calculated from a huge sample-size of 10000 function
     * names, and are very accurate for real-world JSON data.
     *
     * To calculate your own cache memory size, think about how many different
     * JSON properties you'll need to access in your project. Most normal
     * projects would probably only access at most 100 different property names
     * (such as `getComments`, `setTime`, etc), since you most likely won't
     * access every property in the JSON data. So only count the properties
     * you'll access. And also remember that identical property names used
     * across different classes, and all the different functions for accessing
     * the same property, will still only require a SINGLE global cache entry
     * per property name.
     *
     * The storage needs for 100 different property name translations would be
     * as follows. As you can see, the cache is very memory-efficient:
     *
     * - PHP 7: 100x ~248.6 bytes = 24 860 bytes (~24.9 kilobytes).
     * - PHP 5: 100x ~510.4 bytes = 51 040 bytes (~51.1 kilobytes).
     *
     * (If you're still using PHP 5, you should really consider upgrading to
     * PHP 7 with its better memory efficiency and its much faster engine!)
     *
     * It is highly recommended to use caching, and it is enabled by default
     * unless you override the constant in your class, as follows:
     *
     *   ```php
     *   class MyUncachedLazyJsonMapper extends LazyJsonMapper
     *   {
     *       const USE_MAGIC_LOOKUP_CACHE = false;
     *   }
     *   ```
     *
     * With the above code, `MyUncachedLazyJsonMapper` (and anything extending
     * from it) would run without using the magic function translation cache,
     * which means that all magic "virtual function" calls on instances of that
     * class would have to redo their property translations every time.
     *
     * @var bool
     */
    const USE_MAGIC_LOOKUP_CACHE = true;

    /**
     * Tells us how to map JSON properties to internal PHP types or objects.
     *
     * This constant can be overridden in your subclasses, to add custom JSON
     * mapping definitions to your class. It is recommended to always do so.
     *
     * The value must be an array of key-value pairs, where the key is the name
     * of a JSON property and the value is a string with the definition for that
     * specific property in PHPdoc-style. We will then perform automatic, strict
     * lazy-conversion of the value to the target type whenever you access that
     * property. (However, be aware that we always allow `NULL` values in any
     * property, and will never enforce/convert those to the target type.)
     *
     * The following built-in types are supported: `bool`, `int`, `float`,
     * `string`. The JSON data will be type-converted to match that type.
     *
     * Note that if the type is set to `mixed` or an empty string `""` instead,
     * then the property will allow any of the built-in types (`bool`, `int`,
     * `float`, `string`, `NULL` (as always) and multi-level arrays of any of
     * those types). There simply won't be any "forced" type-conversion to any
     * specific basic type for that property since you haven't defined any
     * strict type.
     *
     * Setting up untyped properties is still useful even if you don't know its
     * type, since defining the property in the class map ensures that you can
     * always get/set/access/use that property even if it's unavailable in the
     * current object instance's internal JSON data.
     *
     * You can also map values to objects. In the case of objects (classes), the
     * classes MUST inherit from `LazyJsonMapper` so they support all necessary
     * mapping features. To assign a class to a property, you can either write a
     * FULL (global) class path starting with a leading backslash `\`, such as
     * `\Foo\Bar\Baz`. Alternatively, you can use a RELATIVE path related to the
     * namespace of the CURRENT MAP'S class, such as `Bar\Baz` (if your current
     * class is `\Foo\Whatever`, then it'd be interpreted as `\Foo\Bar\Baz`).
     * Anything that DOESN'T start with a leading backslash is interpreted as a
     * relative class path! Just be aware that your class `use`-statements are
     * completely ignored since PHP has no mechanism to let us detect those.
     *
     * It's also possible to map properties to the core `LazyJsonMapper` object
     * if you simply want an object-oriented container for its data without
     * writing a custom class. You can do that by defining the property type as
     * either `\LazyJsonMapper\LazyJsonMapper`, or as `LazyJsonMapper` (which is
     * a special shortcut that ALWAYS resolves to the core class). But it's
     * always best to write a proper class, so that its properties are reliable.
     *
     * Lastly, you can map "arrays of TYPE" as well. Simply add one or more `[]`
     * brackets to the end of the type. For example, `int[]` means "array of
     * ints", and `\Foo[][][]` means "array of arrays of arrays of `\Foo`
     * objects". It may be easier to understand those mentally if you read them
     * backwards and say the words "array of" every time you see a `[]` bracket.
     * (Note: You can also use the array notation with `mixed[][]`, which would
     * then strictly define that the value MUST be mixed data at an exact depth
     * of 2 arrays, and that no further arrays are allowed deeper than that.)
     *
     * The assigned types and array-depths are STRICTLY validated. That's an
     * integral part of the `LazyJsonMapper` container, since it guarantees your
     * class property map interface will be strictly followed, and that you can
     * fully TRUST the data you're interacting with. If your map says that the
     * array data is at depth 8 and consists of `YourObject` objects, then we'll
     * make sure the data is indeed at depth 8 and their value type is correct!
     *
     * That goes for arrays too. If you define an `int[]` array of ints, then
     * we'll ensure that the array has sequential numeric keys starting at 0 and
     * going up without any gaps, exactly as a JSON array is supposed to be. And
     * if you define an object `YourObject`, then we'll ensure that the input
     * JSON data consists of an array with associative keys there (which is used
     * as the object properties), exactly as a JSON object is supposed to be.
     *
     * In other words, you can TOTALLY trust your data if you've assigned types!
     *
     * Example property map:
     *
     * ```php
     * const JSON_PROPERTY_MAP = [
     *     'some_string'              => 'string',
     *     'an_object'                => '\YourProject\YourObject',
     *     'array_of_numbers'         => 'int[]',
     *     'array_of_objects'         => 'RelativeObject[]',
     *     'untyped_value'            => '', // shorthand for 'mixed' below:
     *     'another_untyped_value'    => 'mixed', // allows multilevel arrays
     *     'deep_arr_of_arrs_of_int'  => 'int[][]',
     *     'array_of_mixed'           => 'mixed[]', // enforces 1-level depth
     *     'array_of_generic_objects' => 'LazyJsonMapper[]',
     * ];
     * ```
     *
     * Also note that we automatically inherit all of the class maps from all
     * parents higher up in your object-inheritance chain. In case of a property
     * name clash, the deepest child class definition of it takes precedence.
     *
     * It is also worth knowing that we support a special "multiple inheritance"
     * instruction which allows you to "import the map" from one or more other
     * classes. The imported maps will be merged onto your current class, as if
     * the entire hierarchy of properties from the target class (including the
     * target class' inherited parents and own imports) had been "pasted inside"
     * your class' property map at that point. And you don't have to worry
     * about carefully writing your inheritance relationships, because we have
     * full protection against circular references (when two objects try to
     * import each other) and will safely detect that issue at runtime whenever
     * we try to compile the maps of either of those bad classes with their
     * badly written imports.
     *
     * The instruction for importing other maps is very simple: You just have to
     * add an unkeyed array element with a reference to the other class. The
     * other class must simply refer to the relative or full path of the class,
     * followed by `::class`.
     *
     * Example of importing one or more maps from other classes:
     *
     * ```php
     * const JSON_PROPERTY_MAP = [
     *     'my_own_prop'                     => 'string',
     *     OtherClass::class,                // relative class path
     *     'redefined_prop'                  => float,
     *     \OtherNamespace\SomeClass::class, // full class path
     * ];
     * ```
     *
     * The imports are resolved in the exact order they're listed in the array.
     * Any property name clashes will always choose the version from the latest
     * statement in the list. So in this example, our class would first inherit
     * all of its own parent (`extends`) class maps. Then it would add/overwrite
     * `my_own_prop`. Then it imports everything from `OtherClass` (and its
     * parents/imports). Then it adds/overwrites `redefined_prop` (useful if you
     * want to re-define some property that was inherited from the other class).
     * And lastly, it imports everything from `\OtherNamespace\SomeClass` (and
     * its parents/imports). As long as there are no circular references,
     * everything will compile successfully and you will end up with a very
     * advanced final map! And any other class which later inherits from
     * (extends) or imports YOUR class will inherit the same advanced map from
     * you! This is REAL "multiple inheritance"! ;-)
     *
     * Also note that "relative class path" properties are properly inherited as
     * pointing to whatever was relative to the original inherited/imported
     * class where the property was defined. You don't have to worry about that.
     *
     * The only thing you should keep in mind is that we ONLY import the maps.
     * We do NOT import any functions from the imported classes (such as its
     * overridden functions), since there's no way for us to affect how PHP
     * resolves function calls to "copy" functions from one class to another. If
     * you need their functions, then you should simply `extends` from the class
     * to get true PHP inheritance instead of only importing its map. Or you
     * could just simply put your functions in PHP Traits and Interfaces so
     * that they can be re-used by various classes without needing inheritance!
     *
     * Lastly, here's an important general note about imports and inheritance:
     * You DON'T have to worry about "increased memory usage" when importing or
     * inheriting tons of other classes. The runtime "map compiler" is extremely
     * efficient and re-uses the already-compiled properties inherited from its
     * parents/imports, meaning that property inheritance is a ZERO-COST memory
     * operation! In fact, re-definitions are also zero-cost as long as your
     * class re-defines a property to the exact same type that it had already
     * inherited/imported. Such as `Base: foo:string, Child: foo:string`. In
     * that case, we detect that the definitions are identical and just re-use
     * the compiled property from `Base` instead. It's only when a class adds
     * NEW/MODIFIED properties that memory usage increases a little bit! In
     * other words, inheritance/imports are a very good thing, and are always a
     * better idea than manually writing similar lists of properties in various
     * unrelated (not extending each other) classes. In fact, if you have a
     * situation where many classes need similar properties, then it's a GREAT
     * idea to create special re-usable "property collection" classes and then
     * simply importing THOSE into ALL of the different classes that need those
     * sets of properties! You can even use that technique to get around PHP's
     * `use`-statement limitations (simply place a "property collection" class
     * container in the namespace that had all of your `use`-classes, and define
     * its properties via easy, relative paths there, and then simply make your
     * other classes import THAT container to get all of its properties).
     *
     * As you can see, the mapping and inheritance system is extremely powerful!
     *
     * Note that the property maps are analyzed and compiled at runtime, the
     * first time we encounter each class. After compilation, the compiled maps
     * become immutable (cannot be modified). So there's no point trying to
     * modify this variable at runtime. That's also partially the reason why
     * this is defined through a constant, since they prevent you from modifying
     * the array at runtime and you believing that it would update your map. The
     * compiled maps are immutable at runtime for performance & safety reasons!
     *
     * Have fun!
     *
     * @var array
     *
     * @see LazyJsonMapper::printPropertyDescriptions() Easily looking at and
     *                                                  debugging your final
     *                                                  class property map.
     * @see LazyJsonMapper::printJson()                 Looking at the JSON data
     *                                                  contents of the current
     *                                                  object instance.
     * @see LazyJsonMapper::exportClassAnalysis()       Looking for problems
     *                                                  with your class map.
     *                                                  However, the same test
     *                                                  can also be achieved
     *                                                  via `$requireAnalysis`
     *                                                  as a constructor flag.
     */
    const JSON_PROPERTY_MAP = [];

    /**
     * Magic virtual function lookup cache.
     *
     * Globally shared across all instances of `LazyJsonMapper` classes. That
     * saves tons of memory, since multiple different components/libraries in
     * your project can use any functions they need, such as `getComments()`,
     * and then all OTHER code that uses a `comments` property would share that
     * cached translation too, which ensures maximum memory efficiency!
     *
     * It is private to prevent subclasses from modifying it.
     *
     * @var array
     *
     * @see LazyJsonMapper::clearGlobalMagicLookupCache()
     */
    private static $_magicLookupCache = [];

    /**
     * Storage container for compiled class property maps.
     *
     * Class maps are built at runtime the FIRST time we encounter each class.
     * This cache is necessary so that we instantly have fully-validated maps
     * without needing to constantly re-build/validate the class property maps.
     *
     * Compilation only happens the first time that we encounter the class
     * during the current runtime (unless you manually decide to clear the
     * cache at any point). And the compiled map format is extremely optimized
     * and very memory-efficient (for example, subclasses and inherited classes
     * all share the same compiled PropertyDefinition objects, and those objects
     * themselves are extremely optimized). So you don't have to worry about
     * memory usage at all.
     *
     * You should think about this exactly like PHP's own class loader/compiler.
     * Whenever you request a class for the first time, PHP reads its .php file
     * and parses and compiles its code and then keeps that class in memory for
     * instant re-use. That's exactly like what this "property map cache" does.
     * It compiles the maps the first time the class is used, and then it just
     * stays in memory for instant re-use. So again, don't worry about it! :-)
     *
     * Globally shared across all instances of `LazyJsonMapper` classes. Which
     * ensures that classes are truly only compiled ONCE during PHP's runtime,
     * even when multiple parts of your project or other libraries use the same
     * classes. And it also means that there's no need for you to manually
     * maintain any kind of personal "cache-storage class instance". The global
     * storage takes care of that for you effortlessly!
     *
     * It is private to prevent subclasses from modifying it.
     *
     * @var PropertyMapCache
     *
     * @see LazyJsonMapper::clearGlobalPropertyMapCache()
     */
    private static $_propertyMapCache;

    /**
     * Direct reference to the current object's property definition cache.
     *
     * This is a reference. It isn't a copy. So the memory usage of each class
     * instance stays identical since the same cache is shared among them all.
     * Therefore, you don't have to worry about seeing this in `var_dump()`.
     *
     * It is private to prevent subclasses from modifying it.
     *
     * @var array
     *
     * @see LazyJsonMapper::printPropertyDescriptions() Easily looking at and
     *                                                  debugging your final
     *                                                  class property map.
     */
    private $_compiledPropertyMapLink;

    /**
     * Container for this specific object instance's JSON data.
     *
     * Due to the lazy-conversion of objects, some parts of the tree can be
     * objects and others can be JSON sub-arrays that have not yet been turned
     * into objects. That system ensures maximum memory and CPU efficiency.
     *
     * This container is private to prevent subclasses from modifying it. Use
     * the protected functions to indirectly check/access/modify values instead.
     *
     * @var array
     */
    private $_objectData;

    /**
     * Constructor.
     *
     * You CANNOT override this constructor. That's because the constructor and
     * its arguments and exact algorithm are too important to allow subclasses
     * to risk breaking it (especially since you must then perfectly maintain an
     * identical constructor argument list and types of thrown exceptions, and
     * would always have to remember to call our constructor first, and so on).
     *
     * Furthermore, trying to have custom constructors on "JSON data container
     * objects" MAKES NO SENSE AT ALL, since your JSON data objects can be
     * created recursively and automatically from nested JSON object data. So
     * there's no way you could run your object's custom constructors then!
     *
     * Instead, there is a separate `_init()` function which you can override in
     * your subclasses, for safe custom initialization. And if you NEED to use
     * some external variables in certain functions in your JSON data classes,
     * then simply add those variables as arguments to those class-functions!
     *
     * Also note that there are many reasons why the JSON data must be provided
     * as an array instead of as an object. Most importantly, the memory usage
     * is much lower if you decode to an array (because a \stdClass requires as
     * much RAM as an array WITH object overhead ON TOP of that), and also
     * because the processing we do is more efficient when we have an array.
     *
     * Lastly, this seems like a prominent enough place to mention something
     * that's VERY important if you are handling JSON data on 32-bit systems:
     * PHP running as a 32-bit process does NOT support 64-bit JSON integers
     * natively. It will cast them to floats instead, which may lose precision,
     * and fails completely if you then cast those numbers to strings, since you
     * will get float notation or `INT_MAX` capping depending on what you are
     * doing with the data (ie `printf('%d')` would give `INT_MAX`, and `%s`
     * would give scientific notation like `8.472E+22`). All of those situations
     * can be avoided by simply telling PHP to decode big numbers to strings:
     *
     *   ```php
     *   json_decode($json, true, 512, JSON_BIGINT_AS_STRING);
     *   ```
     *
     * If you are ever going to handle 64-bit integers on 32-bit systems, then
     * you will NEED to do the above, as well as ensuring that all of your class
     * `JSON_PROPERTY_MAP` definitions never use any `int` field type (since
     * that would convert the safe strings back into truncated 32-bit integers).
     * Instead, you should define those fields as `string`-type in your map.
     *
     * @param array $objectData      Decoded JSON data as an array (NOT object).
     * @param bool  $requireAnalysis Whether to throw an exception if any of the
     *                               raw JSON properties aren't defined in the
     *                               class property map (are missing), or if any
     *                               of the encountered property-classes are bad
     *                               (fail to construct with the JSON data;
     *                               usually only possible due to a custom
     *                               `_init()` or when its raw JSON data value
     *                               wasn't actually an object at all), or any
     *                               problems with array-depth or type coercion.
     *                               This option is very useful for debugging
     *                               when creating/updating your custom classes.
     *                               But BEWARE that it causes the WHOLE
     *                               `$objectData` tree to be recursively parsed
     *                               and analyzed, which is really TERRIBLE for
     *                               performance. So DON'T use this permanently!
     *
     * @throws LazyJsonMapperException If the class hierarchy contains any
     *                                 invalid JSON property map/definition
     *                                 which prevents successful class map
     *                                 compilation, or if JSON data analysis
     *                                 requested and any of the map's property
     *                                 definitions are bad/missing. Also if a
     *                                 custom class `_init()` threw any kind of
     *                                 exception.
     *
     * @see LazyJsonMapper::_init()
     * @see LazyJsonMapper::assignObjectData()
     */
    final public function __construct(
        array $objectData = [],
        $requireAnalysis = false)
    {
        // Create the global property map cache object if not yet initialized.
        if (self::$_propertyMapCache === null) {
            self::$_propertyMapCache = new PropertyMapCache();
        }

        // Compile this class property map if not yet built and cached.
        // NOTE: Validates all definitions the first time and throws if invalid
        // definitions, invalid map, or if there are any circular map imports.
        // NOTE: This aborts compilation, automatically rolls back the failed
        // compilation attempt, AND throws - at the FIRST-discovered problem
        // during map compilation! It will only show the single, specific
        // problem which caused compilation to fail. So it won't inundate the
        // user's screen with all individual error message in case there are
        // more problems. If their class maps have multiple problems that
        // prevent compilation, they'll have to see and fix them one by one.
        // But the user will only have to fix their maps once, since compilation
        // issues are a core problem with their map and aren't a runtime issue!
        $thisClassName = get_class($this);
        if (!isset(self::$_propertyMapCache->classMaps[$thisClassName])) {
            PropertyMapCompiler::compileClassPropertyMap( // Throws.
                self::$_propertyMapCache,
                $thisClassName
            );
        }

        // Now link this class instance directly to its own property-cache, via
        // direct REFERENCE for high performance (to avoid map array lookups).
        // The fact that it's a link also avoids the risk of copy-on-write.
        $this->_compiledPropertyMapLink = &self::$_propertyMapCache->classMaps[$thisClassName];

        // Assign the JSON data, run optional analysis, and then _init().
        $this->assignObjectData($objectData, $requireAnalysis); // Throws.
    }

    /**
     * Assign a new internal JSON data array for this object.
     *
     * This is used by the constructor for assigning the initial internal data
     * state, but can also be very useful for users who want to manually replace
     * the contents of their object at a later time.
     *
     * For example, it might suit your project design better to first construct
     * an empty object, and *then* pass it to some other function which actually
     * fills it with the JSON data. This function allows you to achieve that.
     *
     * The entire internal data storage will be replaced with the new data.
     *
     * @param array $objectData      Decoded JSON data as an array (NOT object).
     * @param bool  $requireAnalysis Whether to analyze the JSON data and throw
     *                               if there are problems with mapping. See
     *                               `__construct()` for more details.
     *
     * @throws LazyJsonMapperException If JSON data analysis requested and any
     *                                 of the map's property definitions are
     *                                 bad/missing. Also if a custom class
     *                                 `_init()` threw any kind of exception.
     *
     * @see LazyJsonMapper::__construct()
     * @see LazyJsonMapper::_init()
     */
    final public function assignObjectData(
        array $objectData = [],
        $requireAnalysis = false)
    {
        // Save the provided JSON data array.
        $this->_objectData = $objectData;

        // Recursively look for missing/bad JSON properties, if scan requested.
        // NOTE: "Bad" in this case includes things like fatal mismatches
        // between the definition of a property and the actual JSON data for it.
        // NOTE: This analysis includes ALL problems with the class and its
        // entire hierarchy (recursively), which means that it can produce
        // really long error messages. It will warn about ALL missing properties
        // that exist in the data but are not defined in the class, and it will
        // warn about ALL bad properties that existed in the data but cannot be
        // mapped in the way that the class map claims.
        if ($requireAnalysis) {
            $analysis = $this->exportClassAnalysis(); // Never throws.
            if ($analysis->hasProblems()) {
                // Since there were problems, throw with all combined summaries.
                throw new LazyJsonMapperException(
                    $analysis->generateNiceSummariesAsString()
                );
            }
        }

        // Call the custom initializer, where the subclass can do its own init.
        // NOTE: This is necessary for safely encapsulating the subclass' code.
        try {
            $this->_init();
        } catch (LazyUserException $e) {
            throw $e; // Re-throw user-error as is, since it's a proper exception.
        } catch (\Exception $e) { // IMPORTANT: Catch ANY other exception!
            // Ensure that they didn't throw something dumb from their code.
            // We'll even swallow the message, to truly discourage misuse.
            throw new LazyUserException(
                'Invalid exception thrown by _init(). Must use LazyUserException.'
            );
        }
    }

    /**
     * Initializer for custom subclass construction / data updates.
     *
     * This is where you can perform your custom subclass initialization, since
     * you are unable to override the main constructor.
     *
     * We automatically run this function at the end of the normal constructor,
     * as well as every time that you manually use `assignObjectData()` to
     * replace the object's data. (When new data is assigned, you should treat
     * yourself as a new object, which is why `_init()` will run again.)
     *
     * `WARNING:` Please RESIST the urge to touch ANY of the internal JSON data
     * during this initialization. All data will always be automatically
     * validated during actual retrieval and setting, so you can always trust
     * that the final types WILL match your class property map definitions.
     *
     * Remember that any extra work you do in `_init()` will run EVERY time an
     * instance of your object is created, even when those objects live within a
     * main object. So if your function is heavy, it will slow down operation of
     * the class; and your parsing of the properties would be counter-productive
     * against the goal of "lazy parsing" of JSON data on a when-accessed basis!
     *
     * Instead, it's preferable to just override specific property getter and
     * setter functions, to make your class return different default values if a
     * certain internal data field is missing an expected value. But in general,
     * your ultimate USER CODE should be responsible for most of THAT checking,
     * by simply looking for `NULL` (denoting a missing value in the JSON data
     * container), and then deciding what to do with that in your final project.
     *
     * Lastly, there are a few rules that MUST be followed by your `_init()`:
     *
     *  1. As explained above, but worth repeating again: Your `LazyJsonMapper`
     *  classes are supposed to be LIGHT-weight "JSON data containers". So
     *  please DO NOT treat this as a "standard class constructor". The more
     *  work you do in `_init()`, the slower your object creation is. And since
     *  your object is a JSON container which is automatically created from
     *  data, you can expect LOTS of instances of your class to be created
     *  during normal runtime! That's also why your `_init()` function does not
     *  get any parameters! That is to discourage misuse as a normal class.
     *
     *  Also remember that you may not even need `_init()`, since you can simply
     *  give your properties default values instead of using `_init()`, such as
     *  by writing `public $myproperty = true;`. All instances of that object
     *  would then start with that value set to `TRUE` by default.
     *
     *  2. You can ONLY throw `LazyUserException`. All other exceptions will be
     *  blocked and turned into a generic `LazyUserException`. This is done to
     *  ensure that your custom init function cannot break the contract that the
     *  `LazyJsonMapper` constructor guarantees in its listed exceptions.
     *
     *  (That's also the reason why all built-in functions except `_init()` are
     *  marked `final`: So that subclasses cannot break the `LazyJsonMapper`
     *  API/interface contract. If something IS a `LazyJsonMapper`, it MUST
     *  behave as a `LazyJsonMapper`, and the `final` functions guarantee that!)
     *
     *  3. If your class extends from `LazyJsonMapper`, then there's no need to
     *  call `parent::_init()` (since our core `_init()` does nothing). But if
     *  you extend from ANY OTHER CLASS, you MUST call your parent's init BEFORE
     *  doing any work, just to guarantee that your WHOLE parent-class hierarchy
     *  is fully initialized first.
     *
     *  4. Understand and accept the fact that your personal class properties
     *  will NOT be serialized if you serialize an object. Only the JSON data
     *  array will be kept. The `_init()` function will be called again when you
     *  unserialize the object data, as if your object had just been created for
     *  the first time. This is done to save space and to discourage misuse
     *  of your `LazyJsonMapper` containers.
     *
     *  Remember that your classes are supposed to be lightweight JSON data
     *  containers, which give you a strongly typed, automatic, object-oriented
     *  interface to your data. Classes "with advanced constructors and tons of
     *  properties" are NOT suitable as JSON containers and belong ELSEWHERE in
     *  the rest of your project!
     *
     * @throws LazyUserException If there is any fatal error which prevents
     *                           initialization. This stops object construction
     *                           if this is the initial construction. However,
     *                           it doesn't affect data-assignment if you throw
     *                           during a later `assignObjectData()` call.
     *
     * @see LazyJsonMapper::assignObjectData()
     */
    protected function _init()
    {
        // This standard _init does nothing by default...

        // Always call your parent's init FIRST, to avoid breaking your class
        // hierarchy's necessary initializers. But that can be skipped if your
        // direct parent is LazyJsonMapper, since we do nothing in our _init().

        // parent::_init();

        // After your parent hierarchy is initialized, you're welcome to perform
        // YOUR OWN class initialization, such as setting up state variables...

        // $this->someFlag = true;

        // However, that kind of usage is highly discouraged, since your objects
        // will lose their state again when serialized and then unserialized.
        // Remember: Your class is meant to be a "light-weight JSON container",
        // and NOT some ultra-advanced normal class. Always think about that!
    }

    /**
     * Export human-readable descriptions of class/object instance properties.
     *
     * The defined (class property map) properties are always included. You can
     * optionally choose to also include undefined properties that only exist in
     * the current object instance's JSON data, but which aren't in the class.
     * Such properties are dangerous, since they only exist in the current data.
     *
     * Furthermore, you can choose whether any class-types for properties should
     * use absolute/global paths (ie `\Foo\Bar\Baz`), or whether they should use
     * paths relative to the class they are owned by (ie `Baz`) when possible.
     * And that's ONLY possible whenever the target type lives within the same
     * namespace as the class. Any properties from other namespaces will still
     * use absolute paths.
     *
     * Note that if relative types are used, they are ALWAYS relative to the
     * class that the current object IS AN INSTANCE OF. This is true even when
     * those properties were inherited/imported from another class!
     *
     * Relative mode is mostly meant for class-documentation, to be placed
     * inside each of your class files. That way, the relative paths will be
     * perfectly understood by your IDE. The absolute, non-relative format is
     * preferable in other, more general "runtime usage" since it is totally
     * clear about exactly which final object each class path refers to.
     *
     * @param bool $allowRelativeTypes If `TRUE`, object types will use relative
     *                                 paths (compared to this class) whenever
     *                                 possible.
     * @param bool $includeUndefined   Whether to also include properties that
     *                                 only exist in the current object
     *                                 instance's JSON data, but aren't defined
     *                                 in the actual class property map.
     *
     * @throws LazyJsonMapperException If any properties cannot be described.
     *                                 But that should never be able to happen.
     *
     * @return PropertyDescription[] Associative array of property descriptions,
     *                               sorted by property name in case-insensitive
     *                               natural order.
     *
     * @see LazyJsonMapper::printPropertyDescriptions()
     */
    final public function exportPropertyDescriptions(
        $allowRelativeTypes = false,
        $includeUndefined = false)
    {
        if (!is_bool($allowRelativeTypes) || !is_bool($includeUndefined)) {
            throw new LazyJsonMapperException('The function arguments must be booleans.');
        }

        // First include all of the defined properties for the current class.
        $descriptions = [];
        $ownerClassName = get_class($this);
        foreach ($this->_compiledPropertyMapLink as $propName => $propDef) {
            $descriptions[$propName] = new PropertyDescription( // Throws.
                $ownerClassName,
                $propName,
                $propDef,
                $allowRelativeTypes
            );
        }

        // Also include all undefined, JSON-only data properties if desired.
        if ($includeUndefined) {
            $undefinedProperty = UndefinedProperty::getInstance();
            foreach ($this->_objectData as $propName => $v) {
                if (!isset($descriptions[$propName])) {
                    $descriptions[$propName] = new PropertyDescription( // Throws.
                        $ownerClassName,
                        $propName,
                        $undefinedProperty,
                        $allowRelativeTypes
                    );
                }
            }
        }

        // Sort the descriptions by the case-insensitive name of each property.
        ksort($descriptions, SORT_NATURAL | SORT_FLAG_CASE); // Natural order.

        return $descriptions;
    }

    /**
     * Print human-readable descriptions of class/object instance properties.
     *
     * This helper is provided as a quick and easy debug feature, and helps you
     * look at your compiled class property maps, or to quickly look up how a
     * certain property needs to be accessed in its function-name form.
     *
     * Please read the description of `exportPropertyDescriptions()` if you want
     * more information about the various options.
     *
     * @param bool $showFunctions      Whether to show the list of functions for
     *                                 each property. Which is very helpful, but
     *                                 also very long. So you may want to
     *                                 disable this option.
     * @param bool $allowRelativeTypes If `TRUE`, object types will use relative
     *                                 paths (compared to this class) whenever
     *                                 possible.
     * @param bool $includeUndefined   Whether to also include properties that
     *                                 only exist in the current object
     *                                 instance's JSON data, but aren't defined
     *                                 in the actual class property map.
     *
     * @throws LazyJsonMapperException If any properties cannot be described.
     *                                 But that should never be able to happen.
     *
     * @see LazyJsonMapper::exportPropertyDescriptions()
     * @see LazyJsonMapper::printJson()
     */
    final public function printPropertyDescriptions(
        $showFunctions = true,
        $allowRelativeTypes = false,
        $includeUndefined = false)
    {
        if (!is_bool($showFunctions) || !is_bool($allowRelativeTypes) || !is_bool($includeUndefined)) {
            throw new LazyJsonMapperException('The function arguments must be booleans.');
        }

        // Generate the descriptions.
        $descriptions = $this->exportPropertyDescriptions( // Throws.
            $allowRelativeTypes,
            $includeUndefined
        );

        // Create some bars for output formatting.
        $equals_bar = str_repeat('=', 60);
        $dash_bar = str_repeat('-', 60);

        // Header.
        printf(
            '%s%s> Class:    "%s"%s  Supports: [%s] Virtual Functions [%s] Virtual Properties%s%s%s  Show Functions: %s.%s  Allow Relative Types: %s.%s  Include Undefined Properties: %s.%s%s%s',
            $equals_bar,
            PHP_EOL,
            Utilities::createStrictClassPath(get_class($this)),
            PHP_EOL,
            static::ALLOW_VIRTUAL_FUNCTIONS ? 'X' : ' ',
            static::ALLOW_VIRTUAL_PROPERTIES ? 'X' : ' ',
            PHP_EOL,
            $dash_bar,
            PHP_EOL,
            $showFunctions ? 'Yes' : 'No',
            PHP_EOL,
            $allowRelativeTypes ? 'Yes' : 'No',
            PHP_EOL,
            $includeUndefined ? 'Yes' : 'No',
            PHP_EOL,
            $equals_bar,
            PHP_EOL
        );

        // Properties.
        $lastPropertyNum = count($descriptions);
        $padNumDigitsTo = strlen($lastPropertyNum);
        if ($padNumDigitsTo < 2) {
            $padNumDigitsTo = 2; // Minimum 2-digit padding: "09".
        }
        $alignPadding = 4 + (2 * $padNumDigitsTo); // "  #/" plus the digits.
        $thisPropertyNum = 0;
        foreach ($descriptions as $property) {
            $thisPropertyNum++;

            // Output core information about the property.
            printf(
                '  #%s/%s: "%s"%s%s%s: "%s"%s%s',
                str_pad($thisPropertyNum, $padNumDigitsTo, '0', STR_PAD_LEFT),
                str_pad($lastPropertyNum, $padNumDigitsTo, '0', STR_PAD_LEFT),
                $property->name,
                !$property->is_defined ? ' (Not in class property map!)' : '',
                PHP_EOL,
                str_pad('* Type', $alignPadding, ' ', STR_PAD_LEFT),
                $property->type,
                $property->is_basic_type ? ' (Basic PHP type)' : '',
                PHP_EOL
            );

            // Optionally output the function list as well.
            if ($showFunctions) {
                foreach (['has', 'is', 'get', 'set', 'unset'] as $function) {
                    printf(
                        '%s: %s%s',
                        str_pad($function, $alignPadding, ' ', STR_PAD_LEFT),
                        $property->{"function_{$function}"},
                        PHP_EOL
                    );
                }
            }

            // Dividers between properties.
            if ($thisPropertyNum !== $lastPropertyNum) {
                echo $dash_bar.PHP_EOL;
            }
        }

        // Handle empty property lists.
        if (empty($descriptions)) {
            echo '- No properties.'.PHP_EOL;
        }

        // Footer.
        echo $equals_bar.PHP_EOL;
    }

    /**
     * Get a processed copy of this object instance's internal data contents.
     *
     * It is recommended that you save the result if you intend to re-use it
     * multiple times, since each call to this function will need to perform
     * copying and data conversion from our internal object representation.
     *
     * Note that the conversion process will recursively validate and convert
     * all properties in the entire internal data hierarchy. You can trust that
     * the returned result will be perfectly accurate and follow your class map
     * property type-rules. This does however mean that the data may not be the
     * same as the input array you gave to `__construct()`. For example, if your
     * input contained an array `["1", "2"]` and your type definition map says
     * that you want `int` for that property, then you'd get `[1, 2]` as output.
     *
     * The conversion is always done on a temporary COPY of the internal data,
     * which means that you're welcome to run this function as much as you want
     * without causing your object to grow from resolving all its sub-objects.
     *
     * It also means the returned copy belongs to YOU, and that you're able to
     * do ANYTHING with it, without risk of affecting any of OUR internal data.
     *
     * `WARNING:` If you intend to use the result to `json_encode()` a new JSON
     * object then please DON'T do that. Look at `asJson()` instead, which gives
     * you full control over all JSON output parameters and properly handles the
     * conversion in all scenarios, without you needing to do any manual work.
     *
     * Also look at the separate `asStdClass()` and `asArray()` functions, for
     * handy shortcuts instead of manually having to call this longer function.
     *
     * @param string $objectRepresentation What container to use to represent
     *                                     `LazyJsonMapper` objects. Can be
     *                                     either `array` (useful if you require
     *                                     that the result is compatible with
     *                                     `__construct()`), or `stdClass` (the
     *                                     best choice if you want to be able to
     *                                     identify subobjects via
     *                                     `is_object()`).
     *
     * @throws LazyJsonMapperException If there are any conversion problems.
     *
     * @return stdClass|array A processed copy of the internal data, using the
     *                        desired container type to represent all objects.
     *
     * @see LazyJsonMapper::asStdClass()
     * @see LazyJsonMapper::asArray()
     * @see LazyJsonMapper::asJson()
     * @see LazyJsonMapper::printJson()
     */
    final public function exportObjectDataCopy(
        $objectRepresentation = 'array')
    {
        if (!in_array($objectRepresentation, ['stdClass', 'array'], true)) {
            throw new LazyJsonMapperException(sprintf(
                'Invalid object representation type "%s". Must be either "stdClass" or "array".',
                $objectRepresentation
            ));
        }

        // Make a shallow copy (copy-on-write) so that we will avoid affecting
        // our actual internal object data during the parsing below. Otherwise
        // we would turn all of OUR internal, still-unconverted sub-object
        // arrays into real objects on our REAL instance, and permanently cause
        // our object instance's memory usage to go up after an export call.
        $copy = clone $this;

        // Perform a NON-RECURSIVE analysis of the copy's top-level (own)
        // properties. This will CONVERT all of their values to the proper type,
        // and perform further sub-object creation (convert sub-object arrays
        // into real objects), etc. It also ensures no illegal values exist.
        // NOTE: For performance, we DON'T want recursive analysis, since we'll
        // soon be asking any sub-objects to analyze themselves one-by-one too!
        $analysis = $copy->exportClassAnalysis(false); // Never throws.

        // Abort if there are any BAD definitions (conversion failure due to
        // mismatches between defined class map and the actual data).
        if (!empty($analysis->bad_definitions)) { // Ignore missing_definitions
            $problemSummaries = $analysis->generateNiceSummaries();

            throw new LazyJsonMapperException(sprintf(
                'Unable to convert data to %s: %s',
                $objectRepresentation,
                $problemSummaries['bad_definitions'] // Tells them exact error.
            ));
        }

        // Now recursively process every other sub-object within the copy's data
        // via exportObjectDataCopy(), thus forcing all nested sub-objects
        // (which are still DIRECT REFERENCES to OUR "$this" original versions
        // of the objects, since objects are always by reference) to copy and
        // validate/convert THEMSELVES and their data and then return it
        // within the type of container we need. For a perfect final result.
        // NOTE: We iterate by &$value reference, but that WON'T affect the
        // objects they point to. It's a reference to the "_objectData" entry.
        array_walk_recursive($copy->_objectData, function (&$value, $key) use ($objectRepresentation) {
            // Only process objects. Everything else is already perfect (either
            // converted to the target-type if mapped or is valid "mixed" data).
            if (is_object($value)) {
                // Verify that the object is an instance of LazyJsonMapper.
                // NOTE: It SHOULDN'T be able to be anything else, since the
                // object analysis above has already verified that any "mixed"
                // (non-LazyJsonMapper) properties only contain basic PHP types.
                // But this check is a cheap safeguard against FUTURE bugs.
                if (!$value instanceof self) {
                    throw new LazyJsonMapperException(sprintf(
                        'Unable to convert data to %s: Unexpected "%s" object in property/key "%s", but we expected an instance of a LazyJsonMapper object.',
                        $objectRepresentation,
                        Utilities::createStrictClassPath(get_class($value)),
                        $key
                    ));
                }

                // Now just ask the sub-object to give us a copy of ITS verified
                // + converted data wrapped in the desired container type.
                // NOTE: This will be resolved recursively as-necessary for all
                // objects in the entire data tree.
                $value = $value->exportObjectDataCopy($objectRepresentation); // Throws.
            }
        });

        // Convert the outer data-holder to the object-representation type.
        if ($objectRepresentation === 'stdClass') {
            // When they want a stdClass, we MUST create it ourselves and assign
            // all of the internal data key-value pairs on it, with string-keys.
            $outputContainer = new stdClass();
            foreach ($copy->_objectData as $k => $v) {
                $outputContainer->{(string) $k} = $v;
            }
        } else { // 'array'
            // For an array-representation, we'll simply steal the copy's array.
            $outputContainer = $copy->_objectData;
        }

        // Voila, we now have their desired container, with cleaned-up and fully
        // validated copies of all of our internal data. And we haven't affected
        // any of our real $this data at all. Clones rock!
        // NOTE: And since $copy goes out of scope now, there will be no other
        // references to any of the data inside the container. It is therefore
        // fully encapsulated, standalone data which is safe to manipulate.
        return $outputContainer;
    }

    /**
     * Get a processed copy of this object instance's data wrapped in stdClass.
     *
     * This function is just a shortcut/alias for `exportObjectDataCopy()`.
     * Please read its documentation to understand the full implications and
     * explanation for the behavior of this function.
     *
     * All objects in the returned value will be represented as `stdClass`
     * objects, which is important if you need to be able to identify nested
     * internal sub-objects via `is_object()`.
     *
     * @throws LazyJsonMapperException If there are any conversion problems.
     *
     * @return stdClass A processed copy of the internal data, with all objects
     *                  represented as stdClass.
     *
     * @see LazyJsonMapper::exportObjectDataCopy()
     * @see LazyJsonMapper::asArray()
     * @see LazyJsonMapper::asJson()
     * @see LazyJsonMapper::printJson()
     */
    final public function asStdClass()
    {
        return $this->exportObjectDataCopy('stdClass'); // Throws.
    }

    /**
     * Get a processed copy of this object instance's data as an array.
     *
     * This function is just a shortcut/alias for `exportObjectDataCopy()`.
     * Please read its documentation to understand the full implications and
     * explanation for the behavior of this function.
     *
     * All objects in the returned value will be represented as nested arrays,
     * which is good if you require that the result is compatible with
     * `__construct()` again.
     *
     * @throws LazyJsonMapperException If there are any conversion problems.
     *
     * @return array A processed copy of the internal data, with all objects
     *               represented as arrays.
     *
     * @see LazyJsonMapper::exportObjectDataCopy()
     * @see LazyJsonMapper::asStdClass()
     * @see LazyJsonMapper::asJson()
     * @see LazyJsonMapper::printJson()
     */
    final public function asArray()
    {
        return $this->exportObjectDataCopy('array'); // Throws.
    }

    /**
     * Get a processed representation of this object instance's data as JSON.
     *
     * This helper gives you a JSON string representation of the object's
     * internal data, and provides the necessary interface to fully control
     * the JSON encoding process.
     *
     * It handles all steps for you and wraps everything in nice exceptions,
     * and will even clearly explain any `json_encode()` errors in plain English
     * (although the default settings will never cause any encoding failures).
     * And most importantly, this function also guarantees that your data is
     * ALWAYS properly encoded as a valid JSON object `{}` (rather than risking
     * getting the result as a JSON array such as `[]` or `["a","b","c"]`).
     * We accept all of the same parameters as the `json_encode()` function!
     *
     * Note that we only encode properties that exist in the actual internal
     * data. Anything that merely exists in the class property map is omitted.
     *
     * `WARNING:` It is worth saving the output of this function if you intend
     * to use the result multiple times, since each call to this function will
     * internally use `exportObjectDataCopy()`, which performs quite intensive
     * work to recursively validate and convert values while creating the final
     * representation of the object's internal JSON data. Please read the
     * description of `exportObjectDataCopy()` for more information.
     *
     * @param int $options Bitmask to control `json_encode()` behavior.
     * @param int $depth   Maximum JSON depth. Encoding fails if set too low.
     *                     Can almost always safely be left at `512` (default).
     *
     * @throws LazyJsonMapperException If there are any conversion problems.
     *
     * @return string
     *
     * @see http://php.net/json_encode
     * @see LazyJsonMapper::exportObjectDataCopy()
     * @see LazyJsonMapper::asStdClass()
     * @see LazyJsonMapper::asArray()
     * @see LazyJsonMapper::printJson()
     * @see LazyJsonMapper::jsonSerialize()        The native json_encode()
     *                                             serializer instead.
     */
    final public function asJson(
        $options = 0,
        $depth = 512)
    {
        if (!is_int($options) || !is_int($depth)) {
            throw new LazyJsonMapperException('Invalid non-integer function argument.');
        }

        // Create a fully-validated, fully-converted final object-tree.
        // NOTE: See `jsonSerialize()` for details about why we MUST do this.
        $objectData = $this->exportObjectDataCopy('stdClass'); // Throws.

        // Gracefully handle JSON encoding and validation.
        $jsonString = @json_encode($objectData, $options, $depth);
        if ($jsonString === false) {
            throw new LazyJsonMapperException(sprintf(
                'Failed to encode JSON string (error %d: "%s").',
                json_last_error(), json_last_error_msg()
            ));
        }

        return $jsonString;
    }

    /**
     * Print a processed representation of this object instance's data as JSON.
     *
     * This helper is provided as a quick and easy debug feature, instead of
     * having to manually write something like `var_dump($item->asJson())`.
     *
     * And it's also a much better alternative than PHP's common but totally
     * unreadable `var_dump($item->asArray())` or `var_dump($item)` techniques.
     *
     * You can even take advantage of chained operations, if you are 100% sure
     * that NONE of the properties along the way are `NULL`. An example of doing
     * that would be `$container->getItems()[0]->getUser()->printJson()`.
     * However, such code is ONLY recommended for quick debug tests, but not
     * for actual production use, since you should always be handling any `NULL`
     * properties along the way (unless you enjoy random errors whenever a
     * particular property along the chain happens to be missing from the
     * object's internal JSON data value storage).
     *
     * Please read the description of `asJson()` if you want more information
     * about how the internal JSON conversion process works.
     *
     * @param bool $prettyPrint Use whitespace to nicely format the data.
     *                          Defaults to `TRUE` since we assume that you want
     *                          human readable output while debug-printing.
     * @param int  $depth       Maximum JSON depth. Encoding fails if set too
     *                          low. Can almost always safely be left at `512`
     *                          (default).
     *
     * @throws LazyJsonMapperException If there are any conversion problems.
     *
     * @see LazyJsonMapper::exportObjectDataCopy()
     * @see LazyJsonMapper::asStdClass()
     * @see LazyJsonMapper::asArray()
     * @see LazyJsonMapper::asJson()
     * @see LazyJsonMapper::printPropertyDescriptions()
     */
    final public function printJson(
        $prettyPrint = true,
        $depth = 512)
    {
        // NOTE: These options are important. For display purposes, we don't
        // want escaped slashes or `\uXXXX` hex versions of UTF-8 characters.
        $options = ($prettyPrint ? JSON_PRETTY_PRINT : 0) | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        $json = $this->asJson($options, $depth); // Throws.
        if ($prettyPrint && PHP_EOL !== "\n") {
            // PHP's JSON pretty-printing uses "\n" line endings, which must be
            // translated for proper display if that isn't the system's style.
            $json = str_replace("\n", PHP_EOL, $json);
        }
        echo $json.PHP_EOL;
    }

    /**
     * Serialize this object to a value that's supported by json_encode().
     *
     * You are not supposed to call this directly. It is _automatically_ called
     * by PHP whenever you attempt to `json_encode()` a `LazyJsonMapper` object
     * or any data structures (such as arrays) which contain such objects. This
     * function is then called and provides a proper "object representation"
     * which can be natively encoded by PHP.
     *
     * Having this helper ensures that you can _easily_ encode very advanced
     * structures (such as a regular PHP array which contains several nested
     * `LazyJsonMapper`-based objects), _without_ needing to manually fiddle
     * around with `asJson()` on every individual object within your array.
     *
     * You are instead able to simply `json_encode($mainObj->getSubArray())`,
     * which will properly encode every array-element in that array, regardless
     * of whether they're pure PHP types or nested `LazyJsonMapper` objects.
     *
     * Note that we only export properties that exist in the actual internal
     * data. Anything that merely exists in the class property map is omitted.
     *
     * `WARNING:` It is worth saving the output of `json_encode()` if you intend
     * to use the result multiple times, since each call to this function will
     * internally use `exportObjectDataCopy()`, which performs quite intensive
     * work to recursively validate and convert values while creating the final
     * representation of the object's internal JSON data. Please read the
     * description of `exportObjectDataCopy()` for more information.
     *
     * `WARNING:` In _most_ cases, you should be using `asJson()` (instead of
     * `json_encode()`), since it's _far more_ convenient and completely wraps
     * PHP's JSON encoding problems as exceptions. If you truly want to manually
     * `json_encode()` the data, you'll still get complete data conversion and
     * validation, but you _won't_ get any _automatic exceptions_ if PHP fails
     * to encode the JSON, which means that _you'll_ have to manually check if
     * the final value was successfully encoded by PHP. Just be aware of that!
     *
     * @throws LazyJsonMapperException If there are any conversion problems.
     *
     * @return stdClass A processed copy of the internal data, with all objects
     *                  represented as stdClass. Usable by `json_encode()`.
     *
     * @see http://php.net/json_encode
     * @see LazyJsonMapper::exportObjectDataCopy()
     * @see LazyJsonMapper::asJson()
     * @see LazyJsonMapper::printJson()
     */
    final public function jsonSerialize()
    {
        // Create a fully-validated, fully-converted final object-tree.
        // NOTE: It is VERY important that we export the data-copy with objects
        // represented as stdClass objects. Otherwise `json_encode()` will be
        // unable to understand which parts are JSON objects (whose keys must
        // always be encoded as `{}` or `{"0":"a","1":"b"}`) and which parts are
        // JSON arrays (whose keys must always be encoded as `[]` or
        // `["a","b"]`).
        // IMPORTANT NOTE: Any "untyped" objects from the original JSON data
        // input ("mixed"/undefined data NOT mapped to `LazyJsonMapper` classes)
        // will be encoded using PHP's own auto-detection for arrays, which
        // GUESSES based on the keys of the array. It'll be guessing perfectly
        // in almost 100% of all cases and will encode even those "untyped"
        // arrays as JSON objects again, EXCEPT if an object from the original
        // data used SEQUENTIAL numerical keys STARTING AT 0, such as:
        // `json_encode(json_decode('{"0":"a","1":"b"}', true))` which will
        // result in `["a","b"]`. But that's incredibly rare (if ever), since it
        // requires weird objects with numerical keys that START AT 0 and then
        // sequentially go up from there WITHOUT ANY GAPS or ANY NON-NUMERIC
        // keys. That should NEVER exist in any user's real JSON data, and it
        // doesn't warrant internally representing all JSON object data as the
        // incredibly inefficient stdClass type instead. If users REALLY want to
        // perfectly encode such data as objects when they export as JSON, EVEN
        // in the insanely-rare/impossible situation where their objects use
        // sequential numeric keys, then they should at the very least map them
        // to "LazyJsonMapper", which will ensure that they'll be preserved as
        // objects in the final JSON output too.
        return $this->exportObjectDataCopy('stdClass'); // Throws.
    }

    /**
     * Handles automatic string conversion when the object is used as a string.
     *
     * Internally runs `asJson()`.
     *
     * `WARNING:` It's **dangerous** to rely on the automatic string conversion,
     * since PHP doesn't allow this handler to throw any error/exceptions (if we
     * do, PHP would die with a Fatal Error). So we cannot notify about errors.
     *
     * Therefore, any warnings and exceptions will silently be placed directly
     * in the string output instead, enclosed in `<>` brackets to guarantee that
     * they won't be interpreted as valid JSON.
     *
     * Smart programmers will instead call `$item->asJson()` manually. It is
     * only a few more characters, and it guarantees that you can catch
     * conversion errors and react to them appropriately.
     *
     * There is only ONE scenario where `__toString()` is reliable: That's if
     * your JSON data was constructed with the `$requireAnalysis` constructor
     * argument, which recursively ensures that all data type-conversions and
     * all nested sub-object constructions are successful before your object is
     * allowed to be created. Furthermore, you must not have manipulated
     * anything via "direct property access by reference" AFTER that (as
     * explained in `__get()`), so that you're sure that all of your internal
     * data is truly valid.
     *
     * But that is all moot anyway, because always using the debug-only option
     * `$requireAnalysis` defeats the purpose of memory-efficient lazy-loading
     * and slows down the creation of all of your class instances. Don't do it.
     *
     * In short: `__toString()` is here as a nice bonus for completeness sake,
     * but you should never use it in production. Just use `asJson()` instead!
     *
     * @var string
     *
     * @see LazyJsonMapper::asJson()
     */
    final public function __toString()
    {
        try {
            return $this->asJson(); // Throws.
        } catch (\Exception $e) { // IMPORTANT: Catch ANY exception!
            // __toString() is not allowed to throw, so give generic info in a
            // way that's definitely going to be invalid JSON data, so that the
            // user will notice the problem if they use this method to manually
            // generate JSON data, such as if they're doing '["i":'.$item.']'.
            // NOTE: "<Exception Message>" was chosen since it's illegal JSON.
            return sprintf('<%s>', $e->getMessage());
        }
    }

    /**
     * Analyze the entire object and check for undefined or bad JSON properties.
     *
     * This lets you analyze the object data & map to look for the following:
     *
     *  1. Undefined JSON properties that need to be defined in your classes
     *  so that their existence becomes permanent and therefore safer to use
     *  (since defined properties are always retrievable even if they don't
     *  exist in the current object instance's data). And actually defining
     *  the properties is also the ONLY way that you can enforce a specific
     *  data-type, since undefined properties default to untyped (`mixed`).
     *
     *  2. Bad class map definitions that don't match the actual JSON data.
     *  It does this by checking all of the encountered classes within the JSON
     *  data to ensure that they construct successfully (which they ALWAYS
     *  will, unless the user has overridden `_init()` and throws from it), as
     *  well as verifying that all basic PHP type coercions work, and that the
     *  data is formatted as-described in the definition (such as having the
     *  correct array-depth, or data defined as objects actually being objects).
     *
     * The scan is performed by looking at EVERY item in the object's internal
     * JSON data array, and checking for a corresponding class map definition
     * (if nothing exists, it's treated as "missing from the class map"), and
     * then attempting conversion to its specified type (if that fails, it's
     * treated as "bad class map definition"). All undefined values (which lack
     * any class map definition) are ALSO validated as if they were `mixed`,
     * to ensure that NOTHING can contain illegal values.
     *
     * If a recursive scan is requested, then all sub-objects (`LazyJsonMapper`
     * data containers) will ALSO recursively perform the same analysis on their
     * own internal data, so that the whole JSON data tree is recursively
     * verified. Their analysis results will be merged with our return value.
     *
     * Note that calling this function will cause the WHOLE object tree to be
     * constructed and ALL of its mapped properties to be converted/parsed to
     * verify the class map, which takes some time and permanently increases the
     * object's memory usage (since conversion causes any internal sub-objects
     * to be stored as actual objects instead of merely as plain sub-arrays)!
     *
     * Therefore, you should ONLY use this function when necessary: When you
     * need advanced and powerful debugging of your class maps!
     *
     * @param bool $recursiveScan Whether to also verify missing/bad properties
     *                            in sub-objects in the analysis. Recommended.
     *
     * @return ClassAnalysis An object describing the problems with this class.
     */
    final public function exportClassAnalysis(
        $recursiveScan = true)
    {
        $result = new ClassAnalysis();

        // All problems with OUR class will get filed under our class name.
        $definitionSource = get_class($this);

        // Ensure that all object-data properties exist in our class definition,
        // and that their values can be converted as described in the class map.
        foreach ($this->_objectData as $propName => $value) {
            // Check if property exists in the class map and get its definition.
            // NOTE: Normally, this function can throw, but not with the way we
            // are calling it, because we KNOW that the property exists in at
            // least the object data, so we DON'T have to catch any errors!
            $propDef = $this->_getPropertyDefinition($propName);

            // Regardless of whether it's defined or not, we MUST now "get" it
            // to validate/convert the property's contents, to ensure it's safe.
            try {
                // Trying to "get" the property forces complete validation of
                // the internal property data and non-recursively creates all
                // sub-objects (if any). In case of any errors, this call will
                // throw an exception with an error description.
                // NOTE: Calling this getter is EXTREMELY important even for
                // UNDEFINED (untyped) properties, because it ensures that no
                // illegal data values (such as Resources or non-LazyJsonMapper
                // objects) exist within the data even in undefined properties.
                // NOTE: In the case of arrays, it validates the whole
                // arrayDepth and ensures that the array is well-formed and
                // contains the exact type at the exact depth specified (but it
                // accepts NULL values anywhere in the chain of arrays, and it
                // accepts empty arrays at non-max depth). It also verifies
                // type-coercion to built-in PHP types. And in the case of
                // objects, it verifies that they are successfully constructed.
                $value = $this->_getProperty($propName); // Throws.

                // Recursively check all internal objects to make sure they also
                // have all properties from their own raw JSON data.
                // NOTE: Nothing in this sub-block throws any exceptions, since
                // everything was validated by _getProperty(). And the deeper
                // exportClassAnalysis() calls don't throw anything either.
                if ($recursiveScan && $value !== null && $propDef->isObjectType) {
                    // NOTE: We don't have to validate array-depth/type
                    // correctness, since _getProperty() already did that for
                    // us. Nothing will be isObjectType unless it's really a
                    // LazyJsonMapper class. Also, since the $value is not NULL
                    // (see above) and it's an "array of [type]" property, then
                    // we KNOW the $value is_array(), since it validated as OK.
                    if ($propDef->arrayDepth > 0) { // Array of objects.
                        array_walk_recursive($value, function (&$obj) use (&$result) {
                            if (is_object($obj)) { // Could be inner NULL too.
                                $result->mergeAnalysis($obj->exportClassAnalysis());
                            }
                        });
                    } else { // Non-"array of" object property.
                        $result->mergeAnalysis($value->exportClassAnalysis());
                    }
                }
            } catch (LazyJsonMapperException $e) {
                // Unable to get the value of this property... which usually
                // means that the property cannot be type-coerced as requested,
                // or that the JSON data doesn't match the definition (such as
                // having a deeper JSON array than what the definition says), or
                // that invalid data was encountered (such as encountering an
                // internal non-Lazy object/resource instead of the expected
                // data), or that the property's class could not be constructed
                // (which may really only happen due to a user's custom _init()
                // function failing, since our own classmap's compilation has
                // already taken care of successfully verifying the classmap
                // compilation of ALL classes referred to by our ENTIRE property
                // hierarchy). All of those are user-caused errors...

                // We'll save the exception details message as-is...
                // TODO: This also means that undefined properties containing
                // weird data (objects or resources) would throw above and get
                // logged as "bad definition" despite NOT having any definition.
                // We may want to add a check here for UndefinedProperty and
                // prepend something to the exception message in that case, to
                // tell the user that their UNDEFINED property contained the bad
                // data. But seriously, they can NEVER have such invalid data
                // inside of a real json_decode() input array, so nobody sane is
                // going to be warned of "bad definition" for their undefined
                // properties! And we can't just log it as "missing" since we
                // NEED all "bad data" to be logged as bad_definitions, since
                // that's what our various data validators look at to detect
                // SERIOUS data errors! Besides, their missing definition WILL
                // also get logged under "missing_definitions" in the next step.
                $result->addProblem(
                    $definitionSource,
                    'bad_definitions',
                    $e->getMessage()
                );
            }

            // Now check if we lacked a user-definition for this JSON property.
            if ($propDef instanceof UndefinedProperty) {
                // NOTE: We need the (string) casting in case of int-keys. Which
                // can happen if the user gives us a manually created, non-JSON
                // constructor array containing non-associative integer keys.
                $result->addProblem(
                    $definitionSource,
                    'missing_definitions',
                    (string) $propName
                );
            }
        }

        // Nicely sort the problems and remove any duplicates.
        $result->sortProblemLists();

        return $result;
    }

    /**
     * Check if a property definition exists.
     *
     * These are properties defined in the JSON property map for the class.
     *
     * `NOTE:` This is the STRICTEST function, which checks if a property is
     * defined in the class. It rejects properties that only exist in the data.
     *
     * @param string $propName The property name.
     *
     * @return bool
     */
    final protected function _hasPropertyDefinition(
        $propName)
    {
        return isset($this->_compiledPropertyMapLink[$propName]);
    }

    /**
     * Check if a property definition or an object instance data value exists.
     *
     * These are properties that are either defined in the JSON property map
     * for the class OR that exist in the object instance's data.
     *
     * `NOTE:` This is the RECOMMENDED function for checking if a property is
     * valid, since properties ARE VALID if they exist in the class definition
     * OR in the object instance's data.
     *
     * @param string $propName The property name.
     *
     * @return bool
     */
    final protected function _hasPropertyDefinitionOrData(
        $propName)
    {
        return isset($this->_compiledPropertyMapLink[$propName])
            || array_key_exists($propName, $this->_objectData);
    }

    /**
     * Check if an object instance data value exists.
     *
     * These are properties that currently exist in the object instance's data.
     *
     * `NOTE:` This function ISN'T RECOMMENDED unless you know what you're doing.
     * Because any property that exists in the class definition is valid as
     * well, and can be retrieved even if it isn't in the current object data.
     * So `_hasPropertyDefinitionOrData()` is recommended instead, if your goal
     * is to figure out if a property name is valid. Alternatively, you can use
     * `_hasPropertyDefinition()` if you want to be even stricter by requiring
     * that the property is defined in the class map.
     *
     * @param string $propName The property name.
     *
     * @return bool
     *
     * @see LazyJsonMapper::_hasPropertyDefinition()
     * @see LazyJsonMapper::_hasPropertyDefinitionOrData()
     */
    final protected function _hasPropertyData(
        $propName)
    {
        return array_key_exists($propName, $this->_objectData);
    }

    /**
     * Get the property definition for an object data property.
     *
     * If the property doesn't exist in the class definition but exists in the
     * object instance's data, then it will be treated as an undefined & untyped
     * property. This ensures that the user can access undefined properties too!
     *
     * However, you should always strive to keep your classes up-to-date so that
     * all properties are defined, otherwise they'll be totally inaccessible
     * (instead of returning `NULL`) anytime they're missing from the JSON data.
     *
     * @param string $propName       The property name.
     * @param bool   $allowUndefined Whether to allow default definitions when
     *                               the property exists in the data but not in
     *                               our actual class property map.
     *
     * @throws LazyJsonMapperException If property isn't defined in the class
     *                                 map and also doesn't exist in the object
     *                                 instance's data. Note that if undefined
     *                                 definitions are disallowed, we will ONLY
     *                                 check in the class definition map.
     *
     * @return PropertyDefinition An object describing the property.
     */
    final protected function _getPropertyDefinition(
        $propName,
        $allowUndefined = true)
    {
        if (isset($this->_compiledPropertyMapLink[$propName])) {
            // Custom class property definition exists, so use that. Properties
            // defined in the class map are always valid even if not in data!
            return $this->_compiledPropertyMapLink[$propName];
        } elseif ($allowUndefined && array_key_exists($propName, $this->_objectData)) {
            // No property definition exists, but the property exists in the
            // object instance's data, so treat it as undefined & untyped.
            return UndefinedProperty::getInstance();
        } else {
            // There is no definition & no object instance data (or disallowed)!
            throw new LazyJsonMapperException(sprintf(
                'No such object property "%s".',
                $propName
            ));
        }
    }

    /**
     * Get the value of an object data property.
     *
     * This function automatically reads the object instance's data and converts
     * the value to the correct type on-the-fly. If that part of the data-array
     * is an object, it will be lazy-created the first time it's requested.
     *
     * `NOTE:` If the object instance's internal data doesn't contain a value, but
     * it's listed in the class property definition, then it will be treated as
     * missing and `NULL` will automatically be returned instead. However, if
     * it's missing from the class definition AND the object instance's data,
     * then it's treated as an invalid property and an exception is thrown.
     *
     * Because of the fact that the default return value for internally-missing
     * but "class-valid" properties is `NULL`, it means that you cannot discern
     * whether the `NULL` came from actual JSON data or from the default return
     * value. However, if that distinction matters to you, you should simply
     * call `_hasPropertyData()` before retrieving the value.
     *
     * `IMPORTANT:` This function performs return-by-reference, which was done
     * in order to allow certain ADVANCED programming tricks by the caller. If
     * you save our return value by reference, you'll then have a direct link to
     * the internal data for that property and can write directly to it
     * (including writing invalid data if you want to, since we cannot verify
     * what you do with your reference). However, you A) don't have to worry
     * about invalid data, because it will all get validated at the next
     * `_getProperty()` call again, and B) you have to explicitly bind the
     * returned value by reference to actually risk affecting the internal
     * data, as explained below.
     *
     * Method 1 (recommended for almost 100% of all usages, safest):
     *
     *  ```php
     *  $val = $this->_getProperty('foo'); // Copy-on-write assignment to $val.
     *  $val = 'bar'; // Does NOT modify the internal data "foo" property.
     *  ```
     *
     * Method 2 (dangerous, and this example even contains an intentional bug):
     *
     *  ```php
     *  $val = &$this->_getProperty('foo'); // Reference assignment to $val.
     *  $val = 'bar'; // Direct link, thus modifies the internal "foo" property.
     *  ```
     *
     * `SERIOUS WARNING:` If you use Method 2, do NOT use the code above. It was
     * just explained that way to demonstrate a SERIOUS MISTAKE. If you assign
     * by reference, you obviously intend to link directly to internal data. But
     * the code above fails if no internal data for that property exists yet. In
     * that case, the code above will write to a temporary `NULL` variable which
     * is not linked at all to the object. Instead, you MUST ALWAYS use
     * `$createMissingValue = true` if you want TRUSTABLE data references.
     *
     * Method 2 (the CORRECT way of writing it):
     *
     *  ```php
     *  $val = &$this->_getProperty('foo', true);
     *  $val = 'bar';
     *  ```
     *
     * That FIXED code will create the property and put `NULL` in it if it's
     * missing from the internal data, and then returns the reference to the
     * created internal data entry. It's the ONLY way to ensure REAL references.
     *
     * Note that there is ZERO PERFORMANCE BENEFIT of assigning our return-value
     * by reference. ONLY use references if you have an ADVANCED reason for it!
     * Internally, PHP treats both assignment types identically until the moment
     * you try to write to/modify the contents of the variable. So if you are
     * only reading, which is most of the time, then there's no reason to use a
     * reference. And never forget that modifying via direct reference lets you
     * write invalid data (which won't be detected until a `_getProperty()`
     * call attempts to read that bad data again), as mentioned earlier.
     *
     * There is one final warning: If YOU have a "return-by-reference" function,
     * then you will AUTOMATICALLY return a direct link to the inner data if you
     * use us directly in your return statement. Here's an example of that:
     *
     *  ```php
     *  public function &myFunction() { // <-- Note the "&" return-by-ref here!
     *      // returns reference to real internal data OR temp var (badly coded)
     *      return $this->_getProperty('foo'); // <-- VERY DANGEROUS BUG!
     *
     *      // returns reference to real internal data (correctly coded)
     *      return $this->_getProperty('foo', true); // <-- CORRECT!
     *
     *      // you can also intentionally break the link to the internal data,
     *      // by putting it in a copy-on-write variable and returning THAT:
     *      $copyOnWrite = $this->_getProperty('foo'); // copy-on-write (no ref)
     *      return $copyOnWrite; // ref to $copyOnWrite but not to real internal
     *  }
     *  ```
     *
     * @param string $propName           The property name.
     * @param bool   $createMissingValue If `TRUE` then we will create missing
     *                                   internal object data entries (for
     *                                   class-defined properties) and store a
     *                                   `NULL` in them. This will "pollute" the
     *                                   internal data with default `NULL`s for
     *                                   every missing property that you attempt
     *                                   to retrieve, but it's totally NECESSARY
     *                                   if you intend to use the return-value
     *                                   by-reference, since we MUST store the
     *                                   value internally when you want the
     *                                   returned reference to link to our
     *                                   actual object's internal data storage!
     *
     * @throws LazyJsonMapperException If the value can't be turned into its
     *                                 assigned class or built-in PHP type, or
     *                                 if the property doesn't exist in either
     *                                 the class property definition or the
     *                                 object instance's data.
     *
     * @return mixed The value as the correct type, or `NULL` if it's either a
     *               literal `NULL` in the data or if no value currently existed
     *               in the internal data storage at all. Note that this
     *               function returns the value by-reference.
     */
    final protected function &_getProperty(
        $propName,
        $createMissingValue = false)
    {
        // Check if the property exists in class/data and get its definition.
        $propDef = $this->_getPropertyDefinition($propName); // Throws.

        // Assign the appropriate reference to the "$value" variable.
        if (array_key_exists($propName, $this->_objectData)) {
            // The value exists in the data, so refer directly to it.
            $value = &$this->_objectData[$propName]; // IMPORTANT: By reference!
        } elseif ($createMissingValue) {
            // No value exists in data yet, AND the caller wants us to create
            // a default NULL value for the data property in that situation.
            // NOTE: This is IMPORTANT so that the returned "default NULL"
            // by-reference value will refer to the correct internal data
            // array entry instead of to an unrelated, temporary variable.
            // NOTE: The downside to this is that we'll fill the object's
            // internal data storage with a bunch of default "NULL" values,
            // which increases memory needs and messes with the "to JSON"
            // output functions later. But it's unavoidable. We obviously
            // CANNOT return a real reference to an internal data entry
            // unless we CREATE the entry. So we MUST unfortunately do it!
            // NOTE: This "pollution" is only a problem if the caller intends
            // to use the property by reference, which isn't the case for
            // default __call() (the virtual functions), but IS necessary
            // in the default __get() (direct property access by reference).
            $this->_objectData[$propName] = null;

            // Now just link to the data array entry we just added.
            $value = &$this->_objectData[$propName]; // IMPORTANT: By reference!
            return $value; // OPTIMIZATION: Skip convert() for missing data.
        } else {
            // No value exists in data yet, but the caller didn't want us to set
            // the missing value. So we'll simply use a default NULL variable.
            // NOTE: If the caller tries to write to this one by reference,
            // they'll just modify this temporary variable instead of the
            // internal data. To link to real they MUST $createMissingValue.
            // NOTE: We MUST return a variable, since "return null" is illegal.
            $value = null; // Copy-on-write. Temporary variable to be returned.
            return $value; // OPTIMIZATION: Skip convert() for missing data.
        }

        // Map the value to the appropriate type and validate it.
        ValueConverter::convert( // Throws.
            ValueConverter::CONVERT_FROM_INTERNAL,
            $value, $propDef->arrayDepth, $propName, $propDef
        );

        // Whatever $value points at will now be returned by-reference.
        return $value; // By-reference due to &function signature.
    }

    /**
     * Check if an object data property exists and its value evaluates to TRUE.
     *
     * @param string $propName The property name.
     *
     * @return bool
     */
    final protected function _isProperty(
        $propName)
    {
        // Object instance's data has the property and it evaluates to true?
        return array_key_exists($propName, $this->_objectData)
            && (bool) $this->_objectData[$propName];
    }

    /**
     * Set an object data property to a new value.
     *
     * @param string $propName The property name.
     * @param mixed  $value    The new value for the property. `NULL` is always
     *                         allowed as the new value, regardless of type.
     *
     * @throws LazyJsonMapperException If the new value isn't legal for that
     *                                 property, or if the property doesn't
     *                                 exist in either the class property
     *                                 definition or the object instance's data.
     *
     * @return $this The current object instance, to allow chaining setters.
     */
    final protected function _setProperty(
        $propName,
        $value)
    {
        // Check if the property exists in class/data and get its definition.
        $propDef = $this->_getPropertyDefinition($propName); // Throws.

        // Map the value to the appropriate type and validate it.
        ValueConverter::convert( // Throws.
            ValueConverter::CONVERT_TO_INTERNAL,
            $value, $propDef->arrayDepth, $propName, $propDef
        );

        // Assign the new value for the property.
        $this->_objectData[$propName] = $value;

        return $this;
    }

    /**
     * Erase the internal value of an object data property.
     *
     * This is useful for things like erasing certain parts of the JSON data
     * tree before you're going to output the object as JSON. You can still
     * continue to access and modify an "erased" property after unsetting it,
     * but ONLY if that property is defined in your class property map.
     *
     * The current value is simply removed from the internal object data array,
     * as if the object had been constructed without that part of the array.
     *
     * Also note that we act like the real unset() function, meaning that we
     * don't care whether that property key exists in the object instance's
     * data array. We'll simply unset it if it exists. Or otherwise gracefully
     * do absolutely nothing.
     *
     * @param string $propName The property name.
     *
     * @return $this The current object instance, to allow chaining unsetters.
     */
    final protected function _unsetProperty(
        $propName)
    {
        unset($this->_objectData[$propName]);

        return $this;
    }

    /**
     * __CALL is invoked when attempting to access missing functions.
     *
     * This magic handler auto-maps "virtual function" has-ers, is-ers, getters,
     * setters and unsetters for all of the object's JSON data properties.
     *
     * - `bool hasX()` checks if "x" exists in the class definition and/or the
     *   object instance data. The `has`-functions are the only ones that never
     *   throw even if the property is invalid. This function is totally useless
     *   if you've defined the property in the class map, and will always return
     *   `TRUE` in that case. Its ONLY purpose is to allow you to look for
     *   UNDEFINED properties that may/may not exist in the current data, before
     *   you decide to call any of the other "throwy" functions on that
     *   property. In other words, it's used for working with UNMAPPED
     *   (undefined) properties!
     * - `bool isX()` checks if "x" exists in the object instance's data and its
     *   current value evaluates to `TRUE`.
     * - `mixed getX()` retrieves the value of "x". Uses copy-on-write (not a
     *   reference).
     * - `$this setX(mixed $value)` sets the value of "x" to `$value`. The
     *   setters can be chained.
     * - `$this unsetX()` erases the internal value from the object instance's
     *   data. The unsetters can be chained.
     *
     * @param string $functionName Name of the function being called.
     * @param array  $arguments    Array of arguments passed to the function.
     *
     * @throws LazyUserOptionException If virtual functions are disabled.
     * @throws LazyJsonMapperException If the function type or property name is
     *                                 invalid, or if there's any problem with
     *                                 the conversion to/from the object
     *                                 instance's internal data. As well as if
     *                                 the setter doesn't get exactly 1 arg.
     *
     * @return mixed The return value depends on which function is used.
     *
     * @see http://php.net/manual/en/language.oop5.magic.php
     * @see LazyJsonMapper::_hasPropertyDefinitionOrData()
     * @see LazyJsonMapper::_isProperty()
     * @see LazyJsonMapper::_getProperty()
     * @see LazyJsonMapper::_setProperty()
     * @see LazyJsonMapper::_unsetProperty()
     */
    final public function __call(
        $functionName,
        $arguments)
    {
        if (!static::ALLOW_VIRTUAL_FUNCTIONS) {
            throw new LazyUserOptionException(
                $this,
                LazyUserOptionException::ERR_VIRTUAL_FUNCTIONS_DISABLED
            );
        }

        // Split the function name into its function-type and FuncCase parts.
        list($functionType, $funcCase) = FunctionTranslation::splitFunctionName(
            $functionName
        );

        // If the function didn't follow the [lower prefix][other] format, such
        // as "getSomething", then this was an invalid function (type = NULL).
        if ($functionType === null) {
            throw new LazyJsonMapperException(sprintf(
                'Unknown function "%s".',
                $functionName
            ));
        }

        // Resolve the FuncCase component into its equivalent property names.
        if (static::USE_MAGIC_LOOKUP_CACHE && isset(self::$_magicLookupCache[$funcCase])) {
            // Read the previously processed result from the lookup cache.
            $translation = self::$_magicLookupCache[$funcCase];
        } else {
            // Attempt to parse the newly called function name.
            try {
                $translation = new FunctionTranslation($funcCase); // Throws.
            } catch (MagicTranslationException $e) {
                throw new LazyJsonMapperException(sprintf(
                    'Unknown function "%s".', $functionName
                ));
            }

            // Store the processed result in the global lookup cache, if caching
            // is allowed by the current class instance.
            // NOTE: We'll store it even if the property doesn't exist on this
            // particular object, because the user may be querying undefined
            // data that WILL exist, and we don't want to waste time re-parsing.
            if (static::USE_MAGIC_LOOKUP_CACHE) {
                self::$_magicLookupCache[$funcCase] = $translation;
            }
        }

        // Check for the existence of the "snake_case" property variant first,
        // and if that fails then look for a "camelCase" property instead.
        // NOTE: Checks both the class definition & the object instance's data.
        if ($this->_hasPropertyDefinitionOrData($translation->snakePropName)) {
            $propName = $translation->snakePropName; // We found a snake prop!
        } elseif ($translation->camelPropName !== null
                  && $this->_hasPropertyDefinitionOrData($translation->camelPropName)) {
            $propName = $translation->camelPropName; // We found camel instead.
        } else {
            // This object doesn't have the requested property! If this is a
            // hasX() call, simply return false. In all other cases, throw!
            if ($functionType === 'has') {
                return false; // We don't have the property.
            } else {
                throw new LazyJsonMapperException(sprintf(
                    'Unknown function "%s".',
                    $functionName
                ));
            }
        }

        // Return the kind of response expected by their desired function.
        switch ($functionType) {
        case 'has':
            return true; // If we've come this far, we have the property.
            break;
        case 'is':
            return $this->_isProperty($propName);
            break;
        case 'get':
            // NOTE: This will NOT return a reference, since __call() itself
            // does not support return-by-reference. We return a copy-on-write.
            return $this->_getProperty($propName); // Throws.
            break;
        case 'set':
            // They must provide exactly 1 argument for a setter call.
            if (count($arguments) !== 1) {
                // We know property exists; get its def from class or "undef".
                $propDef = $this->_getPropertyDefinition($propName); // Throws.

                throw new LazyJsonMapperException(sprintf(
                    'Property setter requires exactly 1 argument: "%s(%s $value)".',
                    $functionName, $propDef->asString()
                ));
            }

            // Returns $this so that the user can chain the setters.
            return $this->_setProperty($propName, $arguments[0]); // Throws.
            break;
        case 'unset':
            // NOTE: Normal PHP unset() calls would have a VOID return type. But
            // ours actually returns $this so that the user can chain them.
            return $this->_unsetProperty($propName);
            break;
        default:
            // Unknown function type prefix...
            throw new LazyJsonMapperException(sprintf(
                'Unknown function "%s".',
                $functionName
            ));
        }
    }

    /**
     * __GET is invoked when reading data from inaccessible properties.
     *
     * This magic handler takes care of "virtual property" access to the
     * object's JSON data properties.
     *
     * `WARNING:` Note that the `__get()` "virtual property" handling creates
     * `NULL` values in any missing (but valid in class-map) properties that you
     * try to access! That is NECESSARY because PHP EXPECTS the `__get()` return
     * value to be a REFERENCE to real internal data, so we MUST create a value
     * if no value exists, so that we can link PHP to a true reference to the
     * internal data. Obviously we can't link to something that doesn't exist,
     * which is why we MUST create `NULL` values. Unfortunately that means that
     * "virtual property access" will lead to increased memory usage and worse
     * JSON output (due to all the added values) if you want to export the
     * internal data as JSON later.
     *
     * The recommended access method, `$obj->getFoo()` ("virtual functions")
     * doesn't have that problem. It ONLY happens when you decide to use
     * `$obj->foo` for "direct virtual property" access.
     *
     * There are several other quirks with "direct virtual properties", due to
     * how PHP works. If you don't write your code carefully, you may cause
     * serious issues with performance or unexpected results.
     *
     * All of the important quirks are as follows:
     *
     *  1. The aforementioned necessary `NULL` value insertion increases memory
     *  usage and may lead to unexpected problems if you convert back to JSON,
     *  such as if the server does not expect those values to exist with `NULL`.
     *
     *  2. Anytime you use the array `[]` access operator on the outer property
     *  name, PHP will trigger a `__get()` call, which of course does its whole
     *  data-validation again. The following would therefore be EXTREMELY SLOW,
     *  and possibly even DANGEROUS (since you're manipulating the array while
     *  looping over its keys while constantly triggering `__get()` re-parsing):
     *
     *    ```php
     *    foreach ($obj->something as $k => $v) {
     *        // Every assignment here will do __get('something') which parses
     *        // the array over and over again before each element is modified:
     *        $obj->something[$k] = 'foo';
     *    }
     *    ```
     *
     *  The proper way to solve THAT is to either save a reference to
     *  `$obj->something` as follows:
     *
     *    ```php
     *    $ref = &$obj->something; // Calls __get('something') a single time.
     *    foreach ($ref as $k => $v) {
     *        $ref[$k] = 'foo'; // Changes array directly via stored reference.
     *    }
     *    ```
     *
     *  Or to loop through the input values as follows:
     *
     *    ```php
     *    foreach ($obj->something as &$v) { // Note the "&" symbol.
     *        $v = 'foo'; // Changes array value directly via reference.
     *    }
     *    ```
     *
     *  3. Anytime you use a reference, there will be NO DATA VALIDATION of the
     *  new value you are inputting. It will not be checked again UNTIL the next
     *  internal `_getProperty()` call for that property (ie. by the next
     *  `__get()`) so you may therefore unintentionally insert bad data that
     *  doesn't match the class definition map:
     *
     *    ```php
     *    // Saving a reference to internal data and then changing that variable
     *    // will directly edit the value without letting us validate it:
     *
     *    $ref = &$obj->some_string; // Make "$ref" into a reference to data.
     *    $ref = new InvalidObject(); // Writes a bad value directly to memory.
     *    var_dump($obj->some_string); // Throws error because of bad data.
     *
     *    // The same is true about array access. In this case, PHP does
     *    // __get('some_int_array') and finds an array, and then it directly
     *    // manipulates that array's memory without letting us validate it:
     *
     *    $obj->some_int_array[] = new InvalidObject();
     *    $obj->some_int_array[15] = new InvalidObject();
     *    var_dump($obj->some_int_array); // Throws error because of bad data.
     *    ```
     *
     *  4. You can always trust that `__set()` assignments WILL be validated.
     *  But a `__set()` ONLY happens when you assign with the equals operator
     *  (`=`) to a pure property name WITHOUT any array access (`[]`) operators.
     *
     *    ```php
     *    $obj->some_property = '123'; // Calls __set() and validates new value.
     *    $obj->an_array[] = '123' // Calls __get() and won't validate changes.
     *    ```
     *
     * These quirks of "virtual direct property access" are quite easy to deal
     * with when you know about them, since almost all of them are about array
     * access, and the rest are about intentional misuse by binding directly to
     * references. Just avoid making those mistakes.
     *
     * However, in general, you should REALLY be using the "virtual function"
     * access method instead, which allows you to do great things such as
     * overriding certain class property-functions (ie `getSomething()`) with
     * your own custom behaviors, so that you can do extra validation, get/set
     * value transformations, and other fantastic things!
     *
     * It is possible (and recommended) to disable virtual properties via the
     * `ALLOW_VIRTUAL_PROPERTIES` class constant. The feature is only enabled by
     * default because it helps people easily migrate from legacy projects.
     *
     * @param string $propName The property name.
     *
     * @throws LazyUserOptionException If virtual properties are disabled.
     * @throws LazyJsonMapperException If the value can't be turned into its
     *                                 assigned class or built-in PHP type, or
     *                                 if the property doesn't exist in either
     *                                 the class property definition or the
     *                                 object instance's data.
     *
     * @return mixed The value as the correct type, or `NULL` if it's either a
     *               literal `NULL` in the data or if no value currently existed
     *               in the internal data storage at all. Note that this
     *               function returns the value by-reference.
     *
     * @see LazyJsonMapper::_getProperty()
     * @see LazyJsonMapper::__set()
     */
    final public function &__get(
        $propName)
    {
        if (!static::ALLOW_VIRTUAL_PROPERTIES) {
            throw new LazyUserOptionException(
                $this,
                LazyUserOptionException::ERR_VIRTUAL_PROPERTIES_DISABLED
            );
        }

        // This does the usual validation/parsing of the value to ensure that it
        // is a valid property. It then creates the property with a default NULL
        // if it doesn't exist, and finally returns a direct reference to the
        // internal data entry. That's NECESSARY to allow the user treat the
        // "virtual property" like a real property, so that they can do things
        // like array-modification, or binding to it by reference, and so on.
        // NOTE: Because _getProperty() AND __get() are "return-by-reference"
        // functions, this return-value is automatically a propagated reference.
        return $this->_getProperty($propName, true); // Throws.
    }

    /**
     * __SET is invoked when writing data to inaccessible properties.
     *
     * @param string $propName The property name.
     * @param mixed  $value    The new value for the property. `NULL` is always
     *                         allowed as the new value, regardless of type.
     *
     * @throws LazyUserOptionException If virtual properties are disabled.
     * @throws LazyJsonMapperException If the new value isn't legal for that
     *                                 property, or if the property doesn't
     *                                 exist in either the class property
     *                                 definition or the object instance's data.
     *
     * @see LazyJsonMapper::_setProperty()
     * @see LazyJsonMapper::__get()
     */
    final public function __set(
        $propName,
        $value)
    {
        if (!static::ALLOW_VIRTUAL_PROPERTIES) {
            throw new LazyUserOptionException(
                $this,
                LazyUserOptionException::ERR_VIRTUAL_PROPERTIES_DISABLED
            );
        }

        // NOTE: PHP ignores the return value of __set().
        $this->_setProperty($propName, $value); // Throws.
    }

    /**
     * __ISSET is invoked by calling isset() or empty() on inaccessible properties.
     *
     * `NOTE:` When the user calls `empty()`, PHP first calls `__isset()`, and if
     * that's true it calls `__get()` and ensures the value is really non-empty.
     *
     * @param string $propName The property name.
     *
     * @throws LazyUserOptionException If virtual properties are disabled.
     *
     * @return bool `TRUE` if the property exists in the object instance's data
     *              and is non-`NULL`.
     */
    final public function __isset(
        $propName)
    {
        if (!static::ALLOW_VIRTUAL_PROPERTIES) {
            throw new LazyUserOptionException(
                $this,
                LazyUserOptionException::ERR_VIRTUAL_PROPERTIES_DISABLED
            );
        }

        return isset($this->_objectData[$propName]);
    }

    /**
     * __UNSET is invoked by calling unset() on inaccessible properties.
     *
     * @param string $propName The property name.
     *
     * @throws LazyUserOptionException If virtual properties are disabled.
     *
     * @see LazyJsonMapper::_unsetProperty()
     */
    final public function __unset(
        $propName)
    {
        if (!static::ALLOW_VIRTUAL_PROPERTIES) {
            throw new LazyUserOptionException(
                $this,
                LazyUserOptionException::ERR_VIRTUAL_PROPERTIES_DISABLED
            );
        }

        // NOTE: PHP ignores the return value of __unset().
        $this->_unsetProperty($propName);
    }

    /**
     * Called during serialization of the object.
     *
     * You are not supposed to call this directly. Instead, use PHP's global
     * `serialize()` call:
     *
     * ```php
     * $savedStr = serialize($obj);
     * ```
     *
     * This serializer is thin and efficient. It simply recursively packs all
     * nested, internal `LazyJsonMapper` objects as a single, plain data array,
     * which guarantees the lowest possible serialized data size and the fastest
     * possible unserialization later (since there will only be a SINGLE parent
     * object that needs to wake up, rather than a whole tree).
     *
     * There is no data conversion/validation of properties (ie to make them
     * match their "class property map"), since serialization is intended to
     * quickly save the internal contents of an instance to let you restore it
     * later, regardless of what was in your internal data property storage.
     *
     * Also note that only the internal JSON data is serialized. We will not
     * serialize any subclass `$properties`, since that would be a misuse of how
     * your `LazyJsonMapper` subclasses are supposed to be designed. If they
     * need custom properties, then you can handle those in `_init()` as usual.
     *
     * Lastly, you should know that calling `serialize()` will not disrupt any
     * internal data of the current object instance that you're serializing.
     * You can therefore continue to work with the object afterwards, or even
     * `serialize()` the same instance multiple times.
     *
     * @throws LazySerializationException If the internal data array cannot be
     *                                    serialized. But this problem can
     *                                    literally NEVER happen unless YOU have
     *                                    intentionally put totally-invalid,
     *                                    non-serializable sub-objects within
     *                                    your data array AND those objects in
     *                                    turn throw exceptions when trying to
     *                                    `serialize()`. That's NEVER going to
     *                                    happen with real `json_decode()` data;
     *                                    so if you constructed our object with
     *                                    real JSON data then you never have to
     *                                    look for `serialize()` exceptions.
     *
     * @return string The object's internal data as a string representation.
     *                Note that serialization produces strings containing binary
     *                data which cannot be handled as text. It is intended for
     *                storage in binary format (ie a `BLOB` database field).
     */
    final public function serialize()
    {
        // Tell all of our LJM-properties to pack themselves as plain arrays.
        // NOTE: We don't do any value-conversion or validation of properties,
        // since that's not our job. The user wants to SERIALIZE our data, so
        // translation of array entries to "class-map types" doesn't matter.
        $objectData = $this->_objectData; // Copy-on-write.
        array_walk_recursive($objectData, function (&$value) {
            if (is_object($value) && $value instanceof self) {
                // This call will recursively detect and take care of nested
                // objects and return ALL of their data as plain sub-arrays.
                $value = $value->serialize($value); // Throws.
            }
        });

        // If this is not the root object of a nested hierarchy, return raw arr.
        // NOTE: This efficiently packs all inner, nested LazyJsonMapper objects
        // by ensuring that their data joins the main root-level array again.
        $args = func_get_args(); // Check secret argument.
        $isRootObject = !isset($args[0]) || $args[0] !== $this;
        if (!$isRootObject) {
            return $objectData; // Secret, undocumented array return value.
        }

        // This is the root object that was asked to serialize itself, so finish
        // the process by serializing the data and validating its success.
        $serialized = null;

        try {
            // NOTE: This will ALWAYS succeed if the JSON data array is pure.
            $serialized = serialize($objectData); // Throws.
        } catch (\Exception $e) { // IMPORTANT: Catch ANY exception!
            // This can literally ONLY happen if the user has given us (and now
            // wants to serialize) non-JSON data containing other objects that
            // attempt (and fail) serialization and throw an exception instead.
            throw new LazySerializationException(sprintf(
                'Unexpected exception encountered while serializing a sub-object. Error: %s',
                $e->getMessage()
            ));
        }

        if (!is_string($serialized)) {
            // Anything other than a string means that serialize() failed.
            // NOTE: This should NEVER be able to happen!
            throw new LazySerializationException(
                'The object data could not be serialized.'
            );
        }

        // The data is fine. Now just return the string.
        return $serialized;
    }

    /**
     * Called during unserialization of the object.
     *
     * You are not supposed to call this directly. Instead, use PHP's global
     * `unserialize()` call:
     *
     * ```php
     * $restoredObj = unserialize($savedStr);
     * ```
     *
     * This unserializer is thin and efficient. It simply unpacks the serialized
     * raw data array and uses it as the new object's data, without validating
     * any of the actual values within the serialized data array. It's intended
     * to get the new object into the same JSON data state as the serialized
     * object, which is why we don't re-analyze the data after unserialization.
     *
     * Note that the unserialization will call the constructor, with a single
     * argument (your unserialized JSON data array). Everything that the default
     * constructor performs will happen during unserialization, EXACTLY as if
     * you had created a brand new object and given it the data array directly.
     *
     * You can therefore fully trust your unserialized objects as much as you
     * already trust your manually created ones! And you can even store them
     * somewhere and then unserialize them at any time in the future, during a
     * completely separate PHP process, and even years from now as long as your
     * project still contains the specific (sub)-class that you originally
     * serialized. Have fun!
     *
     * @param string $serialized The string representation of the object.
     *
     * @throws LazySerializationException If the raw, serialized data cannot be
     *                                    unserialized at all.
     * @throws LazyJsonMapperException    If there are any problems creating the
     *                                    class that you are unserializing. See
     *                                    the regular constructor for error
     *                                    reasons, but disregard all of its data
     *                                    validation reasons, since unserialized
     *                                    JSON data is not validated (analyzed)
     *                                    when it reaches the constructor. The
     *                                    most likely reasons why this exception
     *                                    would be thrown during `unserialize()`
     *                                    is that your class property map is
     *                                    invalid and could not be compiled, and
     *                                    thus the object couldn't be recreated,
     *                                    OR that a custom `_init()` threw.
     *
     * @see LazyJsonMapper::__construct()
     */
    final public function unserialize(
        $serialized = null)
    {
        $objectData = null;

        try {
            // Attempt to unpack the serialized data. Do not @suppress any
            // syntax errors, since the user needs to know if they've provided
            // a bad serialized string (or even a non-string value).
            // NOTE: If the original object only contained perfect JSON data,
            // then there are no sub-objects. But if any sub-objects existed
            // within the data, this will recursively unserialize those too.
            $objectData = unserialize($serialized); // Throws.
        } catch (\Exception $e) { // IMPORTANT: Catch ANY exception!
            // This can literally ONLY happen if the user had given us (and then
            // serialized) non-JSON data containing other objects that attempt
            // (and fail) unserialization now and throw an exception instead.
            throw new LazySerializationException(sprintf(
                'Unexpected exception encountered while unserializing a sub-object. Error: %s',
                $e->getMessage()
            ));
        }

        if (!is_array($objectData)) {
            // Anything other than an array means that $serialized was invalid.
            throw new LazySerializationException(
                'The serialized object data that you provided could not be unserialized.'
            );
        }

        // The data is fine. Now just construct this new object instance.
        // NOTE: Important since ctor builds/links its necessary property map.
        // NOTE: The unserialized object (or its data) has no links to the
        // originally serialized object anymore, apart from the fact that (just
        // like ANY two identical classes) they would both be linked to the
        // same compiled class property map ("_compiledPropertyMapLink").
        $this->__construct($objectData); // Throws.
    }

    /**
     * Advanced function: Clear the global "magic function lookup cache".
     *
     * This command is NOT RECOMMENDED unless you know exactly what you are
     * doing and WHY you are doing it. This clears the globally shared cache
     * of magic function-name to property-name translations.
     *
     * It is always safe to clear that cache, since any future magic function
     * calls (even to existing object instances) will simply re-calculate those
     * translations and put them back in the global cache again.
     *
     * However, it's not recommended to clear the cache if you're sure that
     * you'll constantly be calling the same functions over and over again.
     * It's obviously faster to keep cached translations for instant lookups!
     *
     * @return int How many unique function names were cached before clearing.
     */
    final public static function clearGlobalMagicLookupCache()
    {
        $lookupCount = count(self::$_magicLookupCache);
        self::$_magicLookupCache = [];

        return $lookupCount;
    }

    /**
     * Advanced function: Clear the global "compiled property map cache".
     *
     * This command is NOT RECOMMENDED unless you know exactly what you are
     * doing and WHY you are doing it. This clears the globally shared cache of
     * compiled property maps, which may be useful if you've created tons of
     * different class objects from lots of different classes, and you no longer
     * have any of those objects in memory and will never create any of them
     * again. In that case, clearing the cache would get rid of the memory
     * consumed by the compiled maps from each class.
     *
     * All currently existing object instances will continue to work, since they
     * retain their own personal links to their own compiled property maps.
     * Therefore, the memory you free by calling this function may not be the
     * full amount (until ALL of the object instances of all classes are freed).
     * PHP will only be able to free their unused parent classes, and their
     * unused subclasses, and any other unused classes in general. But PHP will
     * retain memory for the SPECIFIC, per-class maps for all living objects.
     *
     * Calling this function is totally harmless, since all future class
     * instance constructors will simply re-compile their whole map hierarchies
     * again from scratch, and all class maps will be re-built IDENTICALLY the
     * next time since all of their map definitions themselves are immutable
     * class constants and can NEVER be modified at runtime (not even via PHP
     * 7.1's advanced `ReflectionClassConstant` class).
     *
     * However, because of the fact that all existing object instances retain
     * private links to their own maps from the previous compilation, it means
     * that you may actually end up using MORE memory after clearing the global
     * cache, IF you still have a LOT of different living object instances AND
     * also begin to create new instances of various classes again (and thus
     * begin re-compiling new, global instances of each of their "cleared" class
     * property maps).
     *
     * In short: Do NOT call this function unless you know why you are doing it!
     *
     * @return int How many unique classes were cached before clearing.
     */
    final public static function clearGlobalPropertyMapCache()
    {
        $classCount = count(self::$_propertyMapCache->classMaps);
        self::$_propertyMapCache->clearCache();

        return $classCount;
    }
}
