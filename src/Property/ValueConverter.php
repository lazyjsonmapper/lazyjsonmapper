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
    const CONVERT_FROM_INTERNAL = 1;
    const CONVERT_TO_INTERNAL = 2;

    /**
     * Validate and convert an incoming or outgoing object data property.
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
     * Conversion behavior depends on the data direction:
     *
     * Incoming (CONVERT_TO_INTERNAL):
     *
     *  Processes a new value for an object data property to ensure correctness.
     *
     *  Verifies that the new value matches the correct type for the property and
     *  does type-casting of built-in PHP types and verification of object types.
     *
     * Outgoing (CONVERT_FROM_INTERNAL):
     *
     *  Converts an object data property to its assigned class or built-in PHP type.
     *
     *  Performs automatic casting of basic PHP types, along with non-recursive
     *  lazy-creation of class objects (the first time it encounters any
     *  unconverted objects at the currently requested property's depth). The
     *  non-recursion is intentional, for performance and memory purposes.
     *  Objects within the created objects will remain as basic JSON-array data
     *  until you actually access them, at which point they're lazy-created too.
     *
     * @param int                $direction     One of the CONVERT_* constants.
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
    public static function convert(
        $direction,
        &$value,
        $remArrayDepth,
        $propName,
        PropertyDefinition $propDef)
    {
        // Do nothing if this particular value is NULL.
        if ($value === null) {
            return; // Skip the rest of the code.
        }

        // Handle "arrays of [type]" by recursively processing all layers down
        // until the array-depth, and verifying all keys and values.
        // NOTE: If array depth remains, we ONLY allow arrays (or NULLs above),
        // and all of the arrays until the depth MUST be numerically indexed in
        // a sequential order starting from element 0, without any gaps.
        // Otherwise the item is invalid JSON, since json_encode() treats gaps
        // in numeric arrays as objects "{"5":99}" instead of arrays "[99]".
        // NOTE: We even support "arrays of mixed", and in that case will verify
        // that the mixed data is at the expected depth and has key integrity.
        // So specifying "mixed[]" requires data like "[1,null,true]", whereas
        // specifying "mixed" avoids doing any depth validation.
        if ($remArrayDepth > 0) {
            if (!is_array($value)) {
                if ($direction === self::CONVERT_FROM_INTERNAL) {
                    throw new LazyJsonMapperException(sprintf(
                        'Unexpected non-array value for array-property "%s" at array-depth %d of %d.',
                        $propName, $propDef->arrayDepth - $remArrayDepth, $propDef->arrayDepth
                    ));
                } else {
                    throw new LazyJsonMapperException(sprintf(
                        'Unable to assign new non-array value for array-property "%s" at array-depth %d of %d.',
                        $propName, $propDef->arrayDepth - $remArrayDepth, $propDef->arrayDepth
                    ));
                }
            }

            // Subtract 1 from the remaining array-depth and process current layer.
            $newRemArrayDepth = $remArrayDepth - 1;
            $nextValidKey = 0; // Must start at 0.
            foreach ($value as $k => &$v) { // IMPORTANT: By reference!
                // OPTIMIZATION: We MUST only allow sequential int keys, but we
                // avoid the is_int() call by using an int counter instead:
                if ($k !== $nextValidKey++) { // ++ post increment...
                    // We're in an "array of"-typed JSON property structure, but
                    // encountered either an associative key or a gap in the
                    // normal numeric key sequence. The JSON data is invalid!
                    // Display the appropriate error.
                    if (is_int($k)) {
                        // It is numeric, so there was a gap...
                        throw new LazyJsonMapperException(sprintf(
                            'Unexpected out-of-sequence numeric array key (expected: %s, found: %s) for array-property "%s" at array-depth %d of %d.',
                            $nextValidKey - 1, $k, $propName,
                            $propDef->arrayDepth - $remArrayDepth, $propDef->arrayDepth
                        ));
                    } else {
                        // Invalid associative key in a numeric array.
                        throw new LazyJsonMapperException(sprintf(
                            'Unexpected non-numeric array key ("%s") for array-property "%s" at array-depth %d of %d.',
                            $k, $propName, $propDef->arrayDepth - $remArrayDepth, $propDef->arrayDepth
                        ));
                    }
                }

                // The key was valid, so convert() any sub-values within this
                // value. Its next depth is either 0 (values) or 1+ (more arrays).
                if ($v !== null) { // OPTIMIZATION: Avoid useless call if null.
                    if ($direction === self::CONVERT_FROM_INTERNAL) {
                        self::convert($direction, $v, $newRemArrayDepth, $propName, $propDef);
                    } else {
                        self::convert($direction, $v, $newRemArrayDepth, $propName, $propDef);
                    }
                }
            }

            // Skip rest of the code, since array depth remained in this call.
            return;
        } // End of "remaining array depth" handler.

        // Alright, we now know that we're at the "data depth". However, the
        // property itself may be untyped ("mixed"). Handle that first.
        if ($propDef->propType === null) {
            // This is a non-NULL but "untyped" property, which means that we
            // only accept three things: NULL, Scalars (int, float, string,
            // bool) and arrays of those. We will NOT allow any Objects or
            // external Resources. And arrays at this level will only be
            // allowed if the "mixed" type didn't specify any depth.

            // We've already checked NULL earlier. Check the most common data
            // types now. Almost all untyped values will be one of these,
            // meaning even untyped properties are blazingly fast to process.
            if (is_scalar($value)) { // int, float, string, bool
                return; // Scalar accepted. Skip the rest of the code.
            }

            // Alright... then it's either an array, Object or Resource.
            try {
                if (is_array($value)) {
                    // Forbid arrays at this "value-level" if this was a
                    // "mixed[]" notation untyped property, since the "[]"
                    // specifies the maximum mixed data depth in that case.
                    // TODO: We do not have any PropertyDefinition notation
                    // for specifying "mixed non-array property". Perhaps add
                    // that feature someday, maybe "mixed[-]", since "mixed[]"
                    // is already taken by "1-level deep mixed" and "mixed" is
                    // already taken by "do not check depth of this mixed data".
                    // It would be nice to be able to say that a mixed property
                    // can contain any basic type but not arrays.
                    // A simple implementation would be that arrayDepth "-1"
                    // for mixed denotes "do not check array depth" ("mixed")
                    // and "0" denotes "check array depth" ("mixed[-]").
                    // Either way, the syntax also needs to be valid PHPdoc so
                    // that the automatic property signatures are valid.
                    if ($propDef->arrayDepth > 0) {
                        // This "mixed" type specifies a max-depth, which means
                        // that we've reached it. We cannot allow more arrays.
                        throw new LazyJsonMapperException(sprintf(
                            // Let's try to be REALLY clear so the user understands...
                            // Since I anticipate lots of untyped user properties.
                            '%s non-array inner untyped property of "%s". This untyped property specifies a maximum array depth of %d.',
                            ($direction === self::CONVERT_FROM_INTERNAL
                             ? 'Unexpected inner array value in'
                             : 'Unable to assign new inner array value for'),
                            $propName, $propDef->arrayDepth
                        ));
                    }

                    // This mixed property has no max depth. Just verify the
                    // contents recursively to ensure it has no invalid data.
                    array_walk_recursive($value, function ($v) {
                        // NOTE: Mixed properties without max-depth can be
                        // either JSON objects or JSON arrays, and we don't know
                        // which, so we cannot verify their array key-type. If
                        // people want validation of keys, they should set a max
                        // depth for their mixed property OR switch to typed.
                        if ($v !== null && !is_scalar($v)) {
                            // Found bad (non-NULL, non-scalar) inner value.
                            throw new LazyJsonMapperException('bad_inner_type');
                        }
                    });
                } else {
                    // Their value is an Object or Resource.
                    throw new LazyJsonMapperException('bad_inner_type');
                }
            } catch (LazyJsonMapperException $e) {
                // Automatically select appropriate exception message.
                if ($e->getMessage() !== 'bad_inner_type') {
                    throw $e; // Re-throw since it already had a message.
                }

                throw new LazyJsonMapperException(sprintf(
                    // Let's try to be REALLY clear so the user understands...
                    // Since I anticipate lots of untyped user properties.
                    '%s untyped property "%s". Untyped properties can only contain NULL or scalar values (int, float, string, bool), or arrays holding any mixture of those types.',
                    ($direction === self::CONVERT_FROM_INTERNAL
                     ? 'Unexpected value in'
                     : 'Unable to assign invalid new value for'),
                    $propName
                ));
            }

            // If we've come this far, their untyped property contained a valid
            // array with only NULL/scalars (or nothing at all) inside. Done!
            return; // Skip the rest of the code.
        }

        // Alright... we know that we're at the "data depth" and that $value
        // refers to a single non-NULL, strongly typed value...
        if ($direction === self::CONVERT_TO_INTERNAL) {
            // No incoming value is allowed to be array anymore at this depth.
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
        } else { // CONVERT_FROM_INTERNAL
            // Now convert the individual internal value, as necessary...
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
        } // End of CONVERT_FROM_INTERNAL.
    }
}
