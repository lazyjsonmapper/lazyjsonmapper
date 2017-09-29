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

use LazyJsonMapper\Exception\LazyJsonMapperException;

/**
 * Automatic variable type converter and validator.
 *
 * @copyright 2017 The LazyJsonMapper Project
 * @license http://www.apache.org/licenses/LICENSE-2.0
 * @author SteveJobzniak (https://github.com/SteveJobzniak)
 */
class ValueConverter
{
    /**
     * Convert an object data property to its assigned class or built-in PHP type.
     *
     * Performs automatic casting of basic PHP types, along with non-recursive
     * lazy-creation of class objects (the first time it encounters any
     * unconverted objects at the currently requested property's depth). The
     * non-recursion is intentional, for performance and memory purposes.
     * Objects within the created objects will remain as basic JSON-array data
     * until you actually access them, at which point they're lazy-created too.
     *
     * If any value is a literal NULL or no type-conversion is assigned, then it
     * will be accepted as-is instead of being validated/converted to another type.
     *
     * If the input is an array of values, all values in the array will be
     * processed recursively to the specified array-depth. However, note that we
     * don't ENFORCE that an array MUST be a certain depth. We'll just traverse
     * down to that depth, if it exists, and then ensure that all array-values
     * at THAT depth are of the correct type.
     *
     * @param mixed              &$value        The value to be converted. Will
     *                                          be passed as reference.
     * @param int                $remArrayDepth Remaining array-depth until we
     *                                          reach the typed values.
     * @param string             $propName      The name of the property. For
     *                                          exception messages.
     * @param PropertyDefinition $propDef       An object describing the property.
     *
     * @throws LazyJsonMapperException If the value can't be turned into its
     *                                 assigned class or built-in PHP type.
     */
    public static function convertValueFromInternal(
        &$value,
        $remArrayDepth,
        $propName,
        PropertyDefinition $propDef)
    {
        // Do nothing if the value is NULL or if no type conversion requested.
        if ($value === null || $propDef->propType === null) {
            return; // Skip the rest of the code.
        }

        // Handle arrays by recursively processing all layers down to the array-depth.
        // NOTE: If array depth remains, we only allow arrays (or NULLs above).
        if ($remArrayDepth > 0) {
            if (!is_array($value)) {
                throw new LazyJsonMapperException(sprintf(
                    'Unexpected non-array value for array-property "%s" at array-depth %d of %d.',
                    $propName, $propDef->arrayDepth - $remArrayDepth, $propDef->arrayDepth
                ));
            }

            // Subtract 1 from the remaining array-depth and process current layer.
            $newRemArrayDepth = $remArrayDepth - 1;
            foreach ($value as $k => &$v) { // IMPORTANT: By reference!
                self::convertValueFromInternal($v, $newRemArrayDepth, $propName, $propDef);
            }

            return; // Skip the rest of the code, since this value was an array.
        }

        // Now convert the provided individual value, as necessary...
        // NOTE: We validate and convert all values EVERY time, to protect
        // against things like being constructed with bad non-JSON input-arrays
        // with bad objects within it, and (even more importantly) to avoid
        // problems whenever the user modifies internal data by reference via
        // _getProperty() or __get() (particularly the latter; direct property
        // access makes it INCREDIBLY easy to directly modify internal data,
        // especially if they are arrays and the user does $x->items[] = 'foo').
        if (!$propDef->isObjectType) {
            // Basic PHP types are not allowed to have an array as their value.
            // NOTE: If arr, then the PropertyDefinition doesn't match the JSON!
            if (is_array($value)) {
                throw new LazyJsonMapperException(sprintf(
                    'Unexpected inner array value in non-array inner property for "%s", where we expect value type "%s".',
                    $propName, $propDef->propType
                ));
            }

            // Cast the value to the target built-in PHP type. We cannot cast objects.
            if (is_object($value) || !@settype($value, $propDef->propType)) {
                throw new LazyJsonMapperException(sprintf(
                    'Unable to cast inner value for property "%s" to built-in PHP type "%s".',
                    $propName, $propDef->propType
                ));
            }
        } else {
            // Only convert the value to object if it isn't already an object.
            if (!is_object($value)) {
                // Unconverted JSON objects MUST have an array as their inner
                // value, which contains their object data property list.
                if (!is_array($value)) {
                    throw new LazyJsonMapperException(sprintf(
                        'Unable to convert non-array inner value for property "%s" into class "%s".',
                        $propName, $propDef->propType
                    ));
                }

                // The encountered array MUST have string-keys, otherwise it
                // CANNOT be a JSON object. If the array has numerical keys
                // instead, it means that our array-depth is wrong and that
                // we're still looking at normal JSON ["foo"] arrays, instead
                // of associative key-value object {"foo":"bar"} property pairs.
                // NOTE: We only need to check the first key of the array,
                // because JSON data CANNOT mix associative and numerical keys.
                // In fact, if you try something like '{"a":{"a","foo":"bar"}}'
                // then PHP actually refuses to decode such invalid JSON.
                // NOTE: If first key is NULL, it means the array is empty. We
                // must allow empty arrays, since '{"obj":{}}' is valid JSON.
                reset($value); // Rewind array pointer to its first element.
                $firstArrKey = key($value); // Get key without moving pointer.
                if ($firstArrKey !== null && !is_string($firstArrKey)) {
                    throw new LazyJsonMapperException(sprintf(
                        'Unable to convert non-object-array inner value for property "%s" into class "%s".',
                        $propName, $propDef->propType
                    ));
                }

                // Convert the raw JSON array value to its assigned class object.
                try {
                    // Attempt creation and catch any construction issues.
                    // NOTE: This won't modify $value if construction fails.
                    $value = new $propDef->propType($value); // Constructs the classname in propType.
                } catch (\Exception $e) { // IMPORTANT: Catch ANY exception!
                    throw new LazyJsonMapperException(sprintf(
                        'Failed to create an instance of class "%s" for property "%s": "%s".',
                        $propDef->propType, $propName, $e->getMessage()
                    ));
                }
            }

            // Validate that the class matches the defined property class type.
            // NOTE: Since all PropertyDefinition types are validated to derive
            // from LazyJsonMapper, we don't need to verify "instanceof".
            if (!is_a($value, $propDef->propType)) { // Exact same class or a subclass of it.
                throw new LazyJsonMapperException(sprintf(
                    'Unexpected "%s" object in property "%s", but we expected an instance of "%s".',
                    get_class($value), $propName, $propDef->propType
                ));
            }
        }
    }

    /**
     * Process a new value for an object data property to ensure correctness.
     *
     * Verifies that the new value matches the correct type for the property and
     * does type-casting of built-in PHP types and verification of object types.
     *
     * If any value is a literal NULL or no type-conversion is assigned, then it
     * will be accepted as-is instead of being validated/converted to another type.
     *
     * If the input is an array of values, all values in the array will be
     * processed recursively to the specified array-depth. However, note that we
     * don't ENFORCE that an array MUST be a certain depth. We'll just traverse
     * down to that depth, if it exists, and then ensure that all array-values
     * at THAT depth are of the correct type.
     *
     * @param mixed              &$value        The value to be converted. Will
     *                                          be passed as reference.
     * @param int                $remArrayDepth Remaining array-depth until we
     *                                          reach the typed values.
     * @param string             $propName      The name of the property. For
     *                                          exception messages.
     * @param PropertyDefinition $propDef       An object describing the property.
     *
     * @throws LazyJsonMapperException If the value can't be turned into its
     *                                 assigned class or built-in PHP type.
     */
    public static function convertValueToInternal(
        &$value,
        $remArrayDepth,
        $propName,
        PropertyDefinition $propDef)
    {
        // Do nothing if the value is NULL or if no type conversion requested.
        if ($value === null || $propDef->propType === null) {
            return; // Skip the rest of the code.
        }

        // Handle arrays by recursively processing all layers down to the array-depth.
        // NOTE: If array depth remains, we only allow arrays (or NULLs above).
        if ($remArrayDepth > 0) {
            if (!is_array($value)) {
                throw new LazyJsonMapperException(sprintf(
                    'Unable to assign new non-array value for array-property "%s" at array-depth %d of %d.',
                    $propName, $propDef->arrayDepth - $remArrayDepth, $propDef->arrayDepth
                ));
            }

            // Subtract 1 from the remaining array-depth and process current layer.
            $newRemArrayDepth = $remArrayDepth - 1;
            foreach ($value as $k => &$v) { // IMPORTANT: By reference!
                self::convertValueToInternal($v, $newRemArrayDepth, $propName, $propDef);
            }

            return; // Skip the rest of the code, since this value was an array.
        }

        // No incoming value is allowed to be an array anymore at this depth...
        if (is_array($value)) {
            throw new LazyJsonMapperException(sprintf(
                'Unable to assign new inner array value for non-array inner property of "%s", which must be of type "%s".',
                $propName, $propDef->propType
            ));
        }

        // Now convert the provided individual value, as necessary...
        if (!$propDef->isObjectType) {
            // Cast the value to the target built-in PHP type. We cannot cast objects.
            if (is_object($value) || !@settype($value, $propDef->propType)) {
                throw new LazyJsonMapperException(sprintf(
                    'Unable to cast new inner value for property "%s" to built-in PHP type "%s".',
                    $propName, $propDef->propType
                ));
            }
        } else {
            // Check that the new value is an object and that it's an instance
            // of the exact required class (or at least a subclass of it).
            // NOTE: Since all PropertyDefinition types are validated to derive
            // from LazyJsonMapper, we don't need to check "instanceof".
            if (!is_object($value) || !is_a($value, $propDef->propType)) {
                throw new LazyJsonMapperException(sprintf(
                    'The new inner value for property "%s" must be an instance of class "%s".',
                    $propName, $propDef->propType
                ));
            }
        }
    }
}
