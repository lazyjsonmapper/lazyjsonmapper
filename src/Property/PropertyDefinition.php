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

namespace LazyJsonMapper\Property;

use LazyJsonMapper\Exception\BadPropertyDefinitionException;
use LazyJsonMapper\LazyJsonMapper;
use ReflectionClass;
use ReflectionException;

/**
 * Describes the behavior of a LazyJsonMapper property.
 *
 * `NOTE:` The class validates all parameters, but provides public properties to
 * avoid needless function calls. It's therefore your responsibility to never
 * assign any bad values to the public properties after this object's creation!
 *
 * @copyright 2017 The LazyJsonMapper Project
 * @license http://www.apache.org/licenses/LICENSE-2.0
 * @author SteveJobzniak (https://github.com/SteveJobzniak)
 */
class PropertyDefinition
{
    /**
     * Array-depth until we reach the typed values.
     *
     * Examples:
     *
     * - `0` = Property directly refers to an instance of `"type"`.
     * - `1` = Property is an array of `["type","type"]`.
     * - `2` = Property is an array of arrays of `[["type","type"],["type"]]`.
     * - and so on...
     *
     * @var int
     */
    public $arrayDepth;

    /**
     * Assigned value-type for the property.
     *
     * In case of basic PHP types, it is a string value such as `int`.
     *
     * It uses `NULL` to represent `mixed` or `""` (mixed shorthand) to make it
     * easy & fast to check for untyped properties via a `=== null` comparison.
     *
     * In case of classes, this is the normalized `NameSpace\Class` path, but
     * WITHOUT any initial leading `\` ("look from the global namespace")
     * backslash. That's PHP's preferred notation for class paths in all of its
     * various "get name" functions. However, that's very unsafe for actual
     * object creation, since PHP would first try resolving to a relative
     * object. Therefore, use `getStrictClassPath()` for actual creation and
     * for all strict comparisons where any kind of namespace resolution will
     * be involved, in functions such as `is_a()` or `is_subclass_of()`, etc!
     *
     * @var string|null
     *
     * @see PropertyDefinition::getStrictClassPath()
     */
    public $propType;

    /**
     * Whether the type is a class object or a built-in type.
     *
     * Tip: If this value is `TRUE` then you can always trust `$propType` to be
     * a string representing the normalized `NameSpace\Class` path to the target
     * class.
     *
     * @var bool
     */
    public $isObjectType;

    /**
     * List of valid basic PHP types.
     *
     * `NOTE:` We don't include `array`, `object` or `null`. Because we handle
     * arrays separately (via `$arrayDepth`), and objects via `$isObjectType`,
     * and we never want to explicitly cast anything to `NULL`. We also avoid
     * PHP's alternative names for some types, favoring their shortest names.
     *
     * @see http://php.net/settype
     *
     * @var string[]
     */
    const BASIC_TYPES = ['bool', 'int', 'float', 'string'];

    /**
     * Constructor.
     *
     * @param string|null $definitionStr A PHPdoc-style string describing the
     *                                   property, or `NULL` to create a default
     *                                   "untyped" property. Note that if the
     *                                   type is set to the exact keyword
     *                                   `LazyJsonMapper`, we will select the
     *                                   core class path without you needing to
     *                                   write the full, global
     *                                   `\LazyJsonMapper\LazyJsonMapper` path
     *                                   yourself. This shortcut also works with
     *                                   "array of" `LazyJsonMapper[][]` syntax.
     * @param string|null $baseNamespace Namespace to use for resolving relative
     *                                   class paths. It CANNOT start or end
     *                                   with a backslash. (Use `__NAMESPACE__`
     *                                   format). If no namespace is provided,
     *                                   all classes are assumed to be relative
     *                                   to the global namespace (`\`).
     *
     * @throws BadPropertyDefinitionException If the provided definition is invalid.
     */
    public function __construct(
        $definitionStr = null,
        $baseNamespace = '')
    {
        // Handle the creation of untyped properties.
        if ($definitionStr === null) {
            $this->arrayDepth = 0;
            $this->propType = null;
            $this->isObjectType = false;

            return; // Skip the rest of the code.
        }

        if (!is_string($definitionStr)) {
            throw new BadPropertyDefinitionException(
                'The property definition must be a string value.'
            );
        }

        // Clean up and validate any provided base namespace or make it global.
        // IMPORTANT NOTE: Any provided classnames "relative to a certain
        // namespace" will NOT know about any "use"-statements in the files
        // where those classes are defined. Even Reflection cannot detect "use".
        if (is_string($baseNamespace)) { // Custom namespace.
            if (strlen($baseNamespace) > 0
                && ($baseNamespace[0] === '\\' || substr($baseNamespace, -1) === '\\')) {
                throw new BadPropertyDefinitionException(sprintf(
                    'Invalid namespace "%s". The namespace is not allowed to start or end with a backslash. Use __NAMESPACE__ format.',
                    $baseNamespace
                ));
            }
        } else {
            $baseNamespace = ''; // Global namespace.
        }

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
        // Example: "" or "mixed" or "int" or "\Foo\Bar" or "Bar"
        $this->propType = $definitionStr;

        // Always store "" or "mixed" as NULL, to make it easy to check.
        if ($this->propType === '' || $this->propType === 'mixed') {
            $this->propType = null;
        }

        // If there's no type, or if it refers to a basic type, then we're done.
        // This ensures that our basic non-empty type value is a real PHP type.
        // NOTE: This is intentionally cAsE-sensitive.
        // NOTE: The basic types are reserved names in PHP, so there's no risk
        // they refer to classes, since PHP doesn't allow such class names.
        if ($this->propType === null || in_array($this->propType, self::BASIC_TYPES)) {
            $this->isObjectType = false;

            return; // Skip the rest of the code.
        }

        // They are trying to refer to a class (or they've mistyped a basic
        // type). Validate the target to ensure that it's fully trustable
        // to be a reachable LazyJsonMapper-based class.
        $this->isObjectType = true;

        // Check if they've used the special shortcut for the core class.
        if ($this->propType === 'LazyJsonMapper') {
            $this->propType = '\\'.LazyJsonMapper::class;
        }

        // Begin by copying whatever remaining type value they've provided...
        $classPath = $this->propType;

        // First check if they want the global namespace instead.
        if ($classPath[0] === '\\') {
            // Their class refers to a global path.
            $baseNamespace = ''; // Set global namespace as base.
            $classPath = substr($classPath, 1); // Strip leading "\".
        }

        // Construct the full class-path from their base namespace and class.
        // NOTE: The leading backslash is super important to ensure that PHP
        // actually looks in the target namespace instead of a sub-namespace
        // of its current namespace.
        $fullClassPath = sprintf(
            '\\%s%s%s',
            $baseNamespace,
            $baseNamespace !== '' ? '\\' : '',
            $classPath
        );

        // Ensure that the target class actually exists (via autoloader).
        if (!class_exists($fullClassPath)) {
            throw new BadPropertyDefinitionException(sprintf(
                'Class "%s" not found.',
                $fullClassPath
            ));
        }

        // We'll use a reflector for analysis, to ensure the class is valid
        // for use as a target type. It must be based on LazyJsonMapper.
        try {
            // First clean up the case-insensitive class name to become the
            // EXACT name for the class. So we can trust "propType" in ===.
            // Example: "\fOO\bAr" to "Foo\Bar". (Without any leading "\".)
            // NOTE: getName() gets the "NameSpace\Class" without leading "\",
            // which is PHP's preferred notation. And that is exactly how we
            // will store the final path internally, so that we can always
            // trust comparisons of these typenames vs full paths to other
            // class names retrieved via other methods such as get_class().
            // It does however mean that propType is NOT the right value for
            // actually constructing the class safely.
            $reflector = new ReflectionClass($fullClassPath);
            $fullClassPath = $reflector->getName();

            // The target class or its parents MUST inherit LazyJsonMapper,
            // so that it implements the necessary behaviors and can be
            // trusted to accept our standardized constructor parameters.
            // NOTE: As you can see, we also allow users to map directly to
            // plain "LazyJsonMapper" objects. It's a very bad idea, since
            // they don't get any property definitions, and therefore their
            // object would be unreliable. But that's the user's choice.
            if ($fullClassPath !== LazyJsonMapper::class
                && !$reflector->isSubClassOf('\\'.LazyJsonMapper::class)) {
                throw new BadPropertyDefinitionException(sprintf(
                    'Class "\\%s" must inherit from LazyJsonMapper.',
                    $fullClassPath
                ));
            }

            // Alright, the class path has been fully resolved, validated
            // to be a LazyJsonMapper, and normalized into its correct name.
            // ... Rock on! ;-)
            $this->propType = $fullClassPath;
        } catch (ReflectionException $e) {
            throw new BadPropertyDefinitionException(sprintf(
                'Reflection failed for class "%s". Reason: "%s".',
                $fullClassPath, $e->getMessage()
            ));
        }
    }

    /**
     * Get the strict, global path to the target class.
     *
     * Always use this function when creating objects or in any other way using
     * the "property type" class path as argument for PHP's class checking
     * functions. The strict path that it provides ensures that PHP will find
     * the global path instead of resolving to a local object.
     *
     * @return string|null Strict path if this is an object, otherwise `NULL`.
     */
    public function getStrictClassPath()
    {
        return $this->isObjectType ? '\\'.$this->propType : null;
    }

    /**
     * Check if all values of this property match another property object.
     *
     * @param PropertyDefinition $otherObject The object to compare with.
     *
     * @return bool `TRUE` if all property values are identical, otherwise `FALSE`.
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
            $this->propType !== null ? $this->propType : 'mixed',
            str_repeat('[]', $this->arrayDepth)
        );
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
