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

use LazyJsonMapper\Exception\BadPropertyDefinitionException;
use ReflectionClass;
use ReflectionException;

/**
 * Describes the behavior of a LazyJsonMapper property.
 *
 * NOTE: The class validates all parameters, but provides public properties to
 * avoid needless function calls. It's therefore your responsibility to never
 * assign any bad values to the public properties after this object's creation!
 *
 * @copyright 2017 The LazyJsonMapper Project
 * @license http://www.apache.org/licenses/LICENSE-2.0
 * @author SteveJobzniak (https://github.com/SteveJobzniak)
 */
class PropertyDefinition
{
    /** @var bool Whether this property was defined or is the untyped default. */
    public $isUndefined;

    /**
     * Array-depth until we reach the typed values.
     *
     * Examples:
     *  0 = The property directly refers to an instance of "type".
     *  1 = The property is an array of ["type","type"].
     *  2 = The property is an array of arrays of [["type","type"],["type"]].
     *  ...
     *
     * @var int
     */
    public $arrayDepth;

    /** @var string Assigned value-type for the property. */
    public $propType;

    /** @var bool Whether the type is a class object or a built-in type. */
    public $isObjectType;

    /**
     * List of valid basic PHP types.
     *
     * NOTE: We don't include "array", "object" or "null". Because we handle
     * arrays separately (via arrayDepth), we handle objects via isObjectType,
     * and we never want to explicitly cast anything to NULL. We also avoid the
     * alternative names for some types, favoring their shortest names.
     *
     * @see http://php.net/settype
     *
     * @var string[]
     */
    private static $_basicTypes = ['bool', 'int', 'float', 'string'];

    /** @var PropertyDefinition|null An internal, undefined instance of this class. */
    private static $_undefinedInstance = null;

    /**
     * Constructor.
     *
     * @param string|null $definitionStr The string describing the property, or
     *                                   NULL to create a default "undefined" property
     *
     * @throws BadPropertyDefinitionException If the provided definition is invalid.
     */
    public function __construct(
        $definitionStr = null)
    {
        // Handle the creation of undefined properties.
        if ($definitionStr === null) {
            $this->isUndefined = true;
            $this->arrayDepth = 0;
            $this->propType = '';
            $this->isObjectType = false;

            return; // Skip the rest of the code.
        }

        if (!is_string($definitionStr)) {
            throw new BadPropertyDefinitionException('The property definition must be a string value.');
        }

        $this->isUndefined = false;

        // Set arrayDepth: Count well-formed array-brackets at end of type.
        // Example: "int[][][]" or "[][]" (yes, array of untyped is possible.)
        $this->arrayDepth = 0;
        if (preg_match('/(?:\[\])+$/', $definitionStr, $matches)) {
            // $matches[0] is the sequence of valid pairs, ie "[][][]".
            $this->arrayDepth = strlen($matches[0]) / 2;
            // Get rid of the pairs from the end of the definition.
            $definitionStr = substr($definitionStr, 0, -($this->arrayDepth * 2));
        }

        // Set propType: It's what remains of our definition string.
        // Example: "" or "int" or "\Foo\Bar"
        $this->propType = $definitionStr;

        // Determine whether the type refers to an object or a built-in type.
        $this->isObjectType = ($this->propType !== '' && $this->propType[0] === '\\');

        // Validate the type, to ensure that it's fully trustable when used.
        if ($this->isObjectType) {
            // Ensure that the target class actually exists (via autoloader).
            if (!class_exists($this->propType)) {
                throw new BadPropertyDefinitionException(sprintf('Class "%s" not found.', $this->propType));
            }

            // We'll use a reflector for analysis, to ensure the class is valid.
            try {
                // First clean up the case-insensitive class name to become the
                // EXACT name for the class. So we can trust "propType" in ===.
                // Example: "\fOO\bAr" to "Foo\Bar" (notice leading \ vanishes).
                $reflector = new ReflectionClass($this->propType);
                $this->propType = $reflector->getName();

                // The target class or its parents MUST inherit LazyJsonMapper,
                // so it implements the necessary behaviors and can be trusted
                // to accept our standardized constructor parameters.
                // NOTE: As you can see, we also allow users to map directly to
                // plain "LazyJsonMapper" objects. It's a very bad idea, since
                // they don't get any property definitions, and therefore their
                // object would be unreliable. But that's the user's choice.
                if ($this->propType !== LazyJsonMapper::class
                    && !$reflector->isSubClassOf(LazyJsonMapper::class)) {
                    throw new BadPropertyDefinitionException(sprintf('Class "%s" must inherit from LazyJsonMapper.', $this->propType));
                }
            } catch (ReflectionException $e) {
                throw new BadPropertyDefinitionException(sprintf('Reflection failed for class "%s". Reason: "%s".', $this->propType, $e->getMessage()));
            }
        } elseif ($this->propType !== '') {
            // Ensure that our basic non-empty type value is a real PHP type.
            // NOTE: This is intentionally cAsE-sensitive.
            if (!in_array($this->propType, self::$_basicTypes)) {
                throw new BadPropertyDefinitionException(sprintf('Invalid property type "%s".', $this->propType));
            }
        }
    }

    /**
     * Check if all values of this property matches another property object.
     *
     * @param PropertyDefinition $otherObject The object to compare with.
     *
     * @return bool
     */
    public function equals(
        PropertyDefinition $otherObject)
    {
        // The "==" operator checks for same class and matching property values.
        return $this == $otherObject;
    }

    /**
     * Get the property definition as its string representation.
     *
     * The string perfectly represents the property definition, and can
     * therefore even be used when constructing other object instances.
     *
     * @return string
     */
    public function asString()
    {
        return sprintf(
            '%s%s%s',
            $this->isObjectType ? '\\' : '',
            $this->propType,
            str_repeat('[]', $this->arrayDepth)
        );
    }

    /**
     * Get a shared, "undefined property" instance of this class.
     *
     * This function is great for memory purposes, since a single "undefined
     * property" object instance can be shared across all code, without needing
     * to allocate individual memory for any more instances of this class.
     *
     * WARNING: Because this class is optimized for LazyJsonMapper performance
     * (avoiding function call/stack overhead by having public properties), it's
     * extremely important that you do not modify ANY of the properties of this
     * object instance when you get it, or you'll break EVERY shared copy! You
     * are not supposed to manually edit any public property on this class
     * anyway, as the class description explains, but it's even more important
     * in this situation.
     *
     * @return PropertyDefinition
     */
    public static function getUndefinedInstance()
    {
        if (self::$_undefinedInstance === null) {
            self::$_undefinedInstance = new self();
        }

        return self::$_undefinedInstance;
    }

    /**
     * Get the property definition as its string representation.
     *
     * @return string
     */
    public function __toString()
    {
        return $this->asString();
    }
}
