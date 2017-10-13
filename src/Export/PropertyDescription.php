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

namespace LazyJsonMapper\Export;

use LazyJsonMapper\Exception\LazyJsonMapperException;
use LazyJsonMapper\LazyJsonMapper;
use LazyJsonMapper\Magic\PropertyTranslation;
use LazyJsonMapper\Property\PropertyDefinition;
use LazyJsonMapper\Property\UndefinedProperty;
use LazyJsonMapper\Utilities;

/**
 * Provides a human-readable description of a compiled PropertyDefinition.
 *
 * `NOTE:` The class validates all parameters, but provides public properties to
 * avoid needless function calls. It's therefore your responsibility to never
 * assign any bad values to the public properties after this object's creation!
 *
 * @copyright 2017 The LazyJsonMapper Project
 * @license http://www.apache.org/licenses/LICENSE-2.0
 * @author SteveJobzniak (https://github.com/SteveJobzniak)
 *
 * @see LazyJsonMapper::exportPropertyDescriptions()
 */
class PropertyDescription
{
    /**
     * The strict, global path to the class which owns this property.
     *
     * Examples: `\MyNamespace\MyClass` or `\MyGlobalClass`.
     *
     * @var string
     */
    public $owner;

    /**
     * Whether this property is defined in the class property map.
     *
     * Properties are only reliable when defined within the class map. Because
     * undefined properties will be inaccessible as soon as they are missing
     * from the data, whereas class properties are always accessible.
     *
     * Is `TRUE` if the property is defined in the class map, otherwise `FALSE`
     * if this property only exists within the current class instance's data.
     *
     * @var bool
     */
    public $is_defined;

    /**
     * The JSON data name of the property.
     *
     * Examples: `some_property`.
     *
     * @var string
     */
    public $name;

    /**
     * The property type in PHPdoc format.
     *
     * Examples: `int[]`, `mixed`, `mixed[][]`, `\Foo\Bar[]`, `\Baz`.
     *
     * @var string
     */
    public $type;

    /**
     * Whether the type is a basic PHP type.
     *
     * Basic types are `mixed`, `int`, `float`, `string` or `bool`.
     *
     * Note that `mixed` is simply an alias for "allow any of the basic types,
     * as well as arrays of those basic types" (although it's worth noting that
     * `mixed[]` for example would enforce a depth of 1 with no deeper arrays).
     *
     * Is `TRUE` if basic type, or `FALSE` if object type.
     *
     * @var bool
     */
    public $is_basic_type;

    /**
     * Whether the type is a relative class path (relative to owner class).
     *
     * Examples: Imagine that the `$owner` is `\Foo\Bar`, and the `$type` is
     * `\Foo\Xyz`. In that case, the type is not a relative path. However, if
     * the type had been `Xyz`, it would be marked as relative here.
     *
     * This is always `FALSE` for basic types. And is only `TRUE` for object
     * types if the path is actually relative. That's only possible when the
     * target class type lives within the same namespace as the owner.
     *
     * @var bool
     */
    public $is_relative_type_path;

    /**
     * The signature of the hasX()-function.
     *
     * This function checks whether the property exists.
     *
     * @var string
     */
    public $function_has;

    /**
     * The signature of the isX()-function.
     *
     * This function checks whether the property exists and evaluates to `TRUE`.
     *
     * @var string
     */
    public $function_is;

    /**
     * The signature of the getX()-function.
     *
     * This function gets the value of the property.
     *
     * @var string
     */
    public $function_get;

    /**
     * The signature of the setX()-function.
     *
     * This function sets the value of the property.
     *
     * @var string
     */
    public $function_set;

    /**
     * The signature of the unsetX()-function.
     *
     * This function erases the property value from the internal object data.
     * You can still use all other functions on the property afterwards.
     *
     * @var string
     */
    public $function_unset;

    /**
     * The bare "FuncCase" translation of the property name.
     *
     * Might be helpful for very advanced users.
     *
     * @var string
     */
    public $func_case;

    /**
     * Constructor.
     *
     * @param string             $ownerClassName        The full path of the
     *                                                  class that owns this
     *                                                  property, but without
     *                                                  any leading `\` global
     *                                                  prefix. To save time, we
     *                                                  assume the caller has
     *                                                  already verified that it
     *                                                  is a valid
     *                                                  `LazyJsonMapper` class.
     * @param string             $propName              The JSON property name.
     * @param PropertyDefinition $propDef               Compiled definition of
     *                                                  the property.
     * @param bool               $allowRelativeTypePath If `TRUE`, object types
     *                                                  will use relative paths
     *                                                  (compared to the owner
     *                                                  class), when possible.
     *                                                  It's only possible when
     *                                                  the target class type
     *                                                  lives within the same
     *                                                  namespace as the owner.
     *
     * @throws LazyJsonMapperException If there are any problems with the input.
     */
    public function __construct(
        $ownerClassName,
        $propName,
        PropertyDefinition $propDef,
        $allowRelativeTypePath = false)
    {
        if (!is_string($ownerClassName) || $ownerClassName === '') {
            throw new LazyJsonMapperException('The owner class name must be a non-empty string value.');
        }
        if (!is_string($propName) || $propName === '') {
            throw new LazyJsonMapperException('The property name must be a non-empty string value.');
        }
        if (!is_bool($allowRelativeTypePath)) {
            throw new LazyJsonMapperException('The allowRelativeTypePath argument must be a boolean.');
        }

        // Determine whether this property is defined in the class property map.
        $isDefined = !($propDef instanceof UndefinedProperty);

        // Generate the strict, global path to the owning class.
        $strictOwnerClassPath = Utilities::createStrictClassPath($ownerClassName);

        // Determine the final type to use, in either absolute or relative form.
        $finalType = $propDef->asString();
        $isRelativeTypePath = false;
        if ($allowRelativeTypePath && $propDef->isObjectType) {
            $relativeType = Utilities::createRelativeClassPath(
                Utilities::splitStrictClassPath($strictOwnerClassPath),
                // NOTE: This is safe because the asString() value is always a
                // strict class path with optional [] suffixes (which will not
                // interfere with the splitting process).
                Utilities::splitStrictClassPath($finalType)
            );
            if ($finalType !== $relativeType) {
                $isRelativeTypePath = true;
                $finalType = $relativeType;
            }
        }

        // Perform the translation from the property name to its FunctionCase.
        $translation = new PropertyTranslation($propName); // Throws.

        // Now just store all of the user-friendly descriptions.
        $this->owner = $strictOwnerClassPath;
        $this->is_defined = $isDefined;
        $this->name = $propName;
        $this->type = $finalType;
        $this->is_basic_type = !$propDef->isObjectType;
        $this->is_relative_type_path = $isRelativeTypePath;
        $this->function_has = sprintf('bool has%s()', $translation->propFuncCase);
        $this->function_is = sprintf('bool is%s()', $translation->propFuncCase);
        $this->function_get = sprintf('%s get%s()', $finalType, $translation->propFuncCase);
        $this->function_set = sprintf('$this set%s(%s $value)', $translation->propFuncCase, $finalType);
        $this->function_unset = sprintf('$this unset%s()', $translation->propFuncCase);
        $this->func_case = $translation->propFuncCase;
    }
}
