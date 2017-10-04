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

namespace LazyJsonMapper\Magic;

use LazyJsonMapper\Exception\MagicTranslationException;

/**
 * Automatically translates a function name into equivalent property names.
 *
 * The function names will be calculated into both snake_case and camelCase
 * style properties, so that you can then look for their existence.
 *
 * Any problems with the casing of the initial word will be preserved, which
 * ensures that we will never accidentally treat "GetX()" the same as "getX()".
 * The initial word can be anything, such as "extract" or "parse" or "get".
 *
 * Translation Examples:
 *
 * - "getSome0XThing"       =>  type: "get"
 *                             snake: "some0_x_thing"
 *                             camel: "some0XThing"
 * - "hasSome0xThing"       =>  type: "has"
 *                             snake: "some0x_thing"
 *                             camel: "some0xThing"
 * - "GetSomeThing"         =>  type: "Get"
 *                             snake: "some_thing"
 *                             camel: "someThing"
 * - "Get_MessageList"      =>  type: "Get"
 *                             snake: "_message_list"
 *                             camel: "_messageList"
 * - "get__Foo_Bar__XBaz__" =>  type: "get"
 *                             snake: "__foo__bar___x_baz__"
 *                             camel: "__foo_Bar__XBaz__"
 * - "get___"               =>  type: "get"
 *                             snake: "___"
 *                             camel: "" (none, since there's nothing to camel)
 *
 * NOTE: The class validates all parameters, but provides public properties to
 * avoid needless function calls. It's therefore your responsibility to never
 * assign any bad values to the public properties after this object's creation!
 *
 * @copyright 2017 The LazyJsonMapper Project
 * @license http://www.apache.org/licenses/LICENSE-2.0
 * @author SteveJobzniak (https://github.com/SteveJobzniak)
 *
 * @see PropertyTranslation
 */
class FunctionTranslation
{
    /**
     * What type of function this is.
     *
     * For example "has", "get", "set" or "is".
     *
     * However, there is no validation to check the type. That's because it's
     * up to the caller to determine what to do. There are infinite variations
     * they might want for their function names, such as "exportSomeProperty",
     * and so on... So we simply pick the first word as the "function type".
     *
     * @var string
     */
    public $functionType;

    /**
     * The property name in underscore "snake_case" style.
     *
     * For example "some_example_property".
     *
     * @var string
     */
    public $snakePropName;

    /**
     * The property name in "camelCase" style.
     *
     * For example "someExampleProperty".
     *
     * NOTE: This is NULL if the property consists only of a single word.
     *
     * @var string|null
     */
    public $camelPropName;

    /**
     * Constructor.
     *
     * @param string $functionName The name of the function to translate,
     *                             such as "getSomeExampleProperty".
     *
     * @throws MagicTranslationException If the function name is unparseable.
     */
    public function __construct(
        $functionName)
    {
        if (!is_string($functionName) || $functionName === '') {
            throw new MagicTranslationException('The function name must be a non-empty string value.');
        }

        // Extract the components of the function name.
        $chunks = $this->_explodeCamelCase($functionName);
        $chunkCount = count($chunks);
        if ($chunks === false || $chunkCount < 2) {
            throw new MagicTranslationException(sprintf(
                'Invalid function name "%s".',
                $functionName
            ));
        }

        // Shift out the first chunk, containing function type (such as "get").
        $functionType = array_shift($chunks);
        $chunkCount--;

        // The property name chunks are already in perfect format for camelCase.
        // The first chunk starts with a lowercase letter, and all other chunks
        // start with an uppercase letter. So generate the camelCase property
        // name version first. But only if there are 2+ chunks. Otherwise NULL.
        // NOTE: Turns "i,Tunes,Item" into "iTunesItem", and "foo" into NULL.
        $camelPropName = $chunkCount >= 2 ? implode('', $chunks) : null;

        // Now make the second property name chunk and onwards into lowercase,
        // and then generate the all-lowercase "snake_case" property name.
        // NOTE: Turns "some,Property,Name" into "some_property_name".
        for ($i = 1; $i < $chunkCount; ++$i) {
            $chunks[$i] = lcfirst($chunks[$i]); // Only first letter can be UC.
        }
        $snakePropName = implode('_', $chunks);

        // We are done with the conversions.
        $this->functionType = $functionType;
        $this->snakePropName = $snakePropName;
        $this->camelPropName = $camelPropName;
    }

    /**
     * Explodes a function name string on camelcase boundaries.
     *
     * The function processes the first and second chunks, but leaves the rest
     * as-is with each having a leading uppercase letter. It's up to the caller
     * to further process them to camelCase and normal_case.
     *
     * Also note that it doesn't convert the 1st (function type prefix) chunk's
     * leading uppercase if that exists. Because we don't want to give false
     * positives by treating "GetX()" as "getX()".
     *
     * Lastly, note that any leading underscores after the function type prefix
     * but before the property name are moved to the 1st property name chunk.
     *
     * Examples:
     *
     * - "getSome0XThing"  => "get", "some0", "X", "Thing".
     * - "hasSome0xThing"  => "has", "some0x", "Thing".
     * - "GetSomeThing"    => "Get", "some", "Thing".
     * - "Get_MessageList" => "get", "_message", "List".
     *
     * @param string $inputString
     *
     * @return string[]|bool Array with parts if successful, otherwise FALSE.
     */
    protected function _explodeCamelCase(
        $inputString)
    {
        // Split the input into chunks on all camelcase boundaries.
        // NOTE: The input must be 2+ characters AND have at least one uppercase.
        // NOTE: Since all chunks are split on camelcase boundaries below, it
        // means that each chunk ONLY holds a SINGLE fragment which can ONLY
        // contain at most a SINGLE capital letter (the chunk's first letter).
        // NOTE: If this doesn't match anything, the input is returned as-is.
        $chunks = preg_split('/(?=[A-Z])/', $inputString, -1, PREG_SPLIT_NO_EMPTY);
        if ($chunks === false) {
            return false; // Only happens on regex engine failure, NOT mismatch!
        }

        // Commented out because we ALWAYS pass 1+ character strings in...
        // if ($chunkCount === 0) { // Means we had totally empty input.
        //     return false;
        // }

        // If there is a single chunk, it means that the input was either a
        // single character long, or that it didn't contain any uppercase
        // characters to split on, or that it only contained a single uppercase
        // character. In that situation we'll have to handle a special case:
        // [prefix][sequence of underscores]. Otherwise, we wouldn't be able to
        // access properties with names like "___".
        $chunkCount = count($chunks);
        if ($chunkCount === 1) {
            // Looks for 1+ non-underscore characters, followed by 1+
            // underscores. It does NOT match something like "get__m", because
            // that is NOT a valid function name that we would ever generate (we
            // would generate that one as "get__M").
            //
            // Valid examples: "get___" = "get", "___"
            // and: "get_" = "get", "_"
            // and: "g_" = "g", "_"
            // and: "Get_" = "Get", "_"
            if (preg_match('/^([^_]+)(_+)$/', $chunks[0], $matches)) {
                // Valid name...
                return [$matches[1], $matches[2]];
            } else {
                // Okay this is a truly invalid name such as "get___m" or "get".
                return false;
            }
        }

        // If there are at least 2 chunks, then we have a function name and its
        // individual property name fragments after that...
        if ($chunkCount >= 2) {
            // Lowercase the leading uppercase of 2nd ("property name") chunk,
            // since that one needs lowercase in both normal_case and camelCase.
            $chunks[1] = lcfirst($chunks[1]);

            // We also need to check if 1st chunk ("function type") ends with
            // trailing underscores, which means they want to access a property
            // with leading underscores, so move those to the start of the 2nd
            // ("property name") chunk instead.
            $oldLen = strlen($chunks[0]);
            $chunks[0] = rtrim($chunks[0], '_'); // "get_" => "get".
            $lenDiff = $oldLen - strlen($chunks[0]);
            if ($lenDiff > 0) {
                // Move all underscores to prop: "message" => "_message".
                $chunks[1] = str_repeat('_', $lenDiff).$chunks[1];
            }
        }

        return $chunks;
    }
}
