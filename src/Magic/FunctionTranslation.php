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
 * Automatically translates a FunctionCase name into equivalent property names.
 *
 * The function names will be calculated into both `snake_case` and `camelCase`
 * style properties, so that you can then look for their existence.
 *
 * ---------------------------------------------------------------------
 *
 * `NOTE`: We support `snake_case` and `camelCase` property styles. We do NOT
 * support any other styles or any `badly_mixed_Styles`. If you cannot simply
 * rename your badly named properties to valid names, then you can still access
 * them via the internal LazyJsonMapper API instead of this magic translation!
 *
 * ---------------------------------------------------------------------
 *
 * `WARNING:`
 * We do NOT support property names in "HumpBack" notation. It's intentional,
 * since HumpBack style is extremely rare, and we save processing by not
 * supporting it. See `PropertyTranslation`'s class docs for more information.
 *
 * ---------------------------------------------------------------------
 *
 * Translation Examples (INPUT LISTED FIRST, then its output as snake & camel):
 *
 * - `__Foo_Bar__XBaz__` => `__foo__bar___x_baz__` (snake)
 *                          `__foo_Bar__XBaz__` (camel)
 * - `0m__AnUn0x`        => `0m___an_un0x` (snake) & `0m__AnUn0x` (camel)
 * - `Some0XThing`       => `some0_x_thing` (snake) & `some0XThing` (camel)
 * - `Some0xThing`       => `some0x_thing` (snake) & `some0xThing` (camel)
 * - `SomeThing`         => `some_thing` (snake) & `someThing` (camel)
 * - `Something`         => `something` (snake) & `NULL` (camel)
 * - `___`               => `___` (snake) & `NULL` (camel)
 * - `_0`                => `_0` (snake) & `NULL` (camel)
 * - `_Messages`         => `_messages` (snake) & `NULL` (camel)
 * - `__MessageList`     => `__message_list` (snake) & `__messageList` (camel)
 * - `123`               => `123` (snake) & `NULL` (camel)
 * - `123prop`           => `123prop` (snake) & `NULL` (camel)
 * - `123Prop`           => `123_prop` (snake) & `123Prop` (camel)
 *
 * ---------------------------------------------------------------------
 *
 * `NOTE:` The class validates all parameters, but provides public properties to
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
     * The property name in underscore "snake_case" style.
     *
     * For example `some_example_property`.
     *
     * @var string
     */
    public $snakePropName;

    /**
     * The property name in "camelCase" style.
     *
     * For example `someExampleProperty`.
     *
     * `NOTE:` This is `NULL` if the property consists only of a single word.
     *
     * @var string|null
     */
    public $camelPropName;

    /**
     * Constructor.
     *
     * @param string $funcCase The "property" portion of the function name to
     *                         translate, in "FunctionCase" style, such as
     *                         `SomeExampleProperty`.
     *
     * @throws MagicTranslationException If the `$funcCase` name is unparseable.
     *
     * @see FunctionTranslation::splitFunctionName() A helper to split your
     *                                               function names into the
     *                                               necessary parts.
     */
    public function __construct(
        $funcCase)
    {
        if (!is_string($funcCase) || $funcCase === '') {
            throw new MagicTranslationException('The function name must be a non-empty string value.');
        }

        // Convert the FuncCase name to its snake and camel properties.
        $result = $this->_funcCaseToProperties($funcCase);
        if ($result === false) {
            throw new MagicTranslationException(sprintf(
                'The provided input value "%s" is not a valid FunctionCase name.',
                $funcCase
            ));
        }

        // We are done with the conversions.
        $this->snakePropName = $result['snake'];
        $this->camelPropName = $result['camel'];
    }

    /**
     * Split a function name into its function-type and FuncCase components.
     *
     * This helper function takes care of splitting a full function name, such
     * as `getSomeVariable`, into `get` (its function type) and `SomeVariable`
     * (the valid input format for the FunctionTranslation constructor).
     *
     * Call it from your own code before constructing your `FunctionTranslation`
     * objects. Don't worry about the extra function call for this splitter.
     * It can perform its job 2.5 million times per second on a 2010 Core i7
     * dual-core laptop on PHP7, and 0.64 million times per second on PHP5.
     * And directly embedding these same steps instead of calling this function
     * will only gain 5% more speed in PHP7 and 16% more speed in PHP5. But the
     * numbers are already so astronomically fast that it doesn't matter!
     *
     * This splitting into `get` and `SomeVariable` is easy and super efficient.
     * It is this class' final translation of the FunctionCase `SomeVariable`
     * part into `some_variable` and `someVariable` properties that's the HARD
     * step which should be cached.
     *
     * Recommended usage:
     *
     *  ```php
     *  list($functionType, $funcCase) = FunctionTranslation::splitFunctionName($name);
     *  // if $functionType is now NULL, the input was invalid. otherwise it was ok.
     *  ```
     *
     * @param string $functionName The function name to split. It's your job to
     *                             make sure this is a string-type variable! We
     *                             will not validate its type. Empty strings ok.
     *
     * @return string[]|null[] Two-element array of `functionType` (element 0) &
     *                         `funcCase` (element 1). If the input name wasn't
     *                         valid a `doSomething` (function-camelCase), then
     *                         both elements are `NULL` instead of strings.
     */
    public static function splitFunctionName(
        $functionName)
    {
        // Split the input on the FIRST encountered NON-LOWERCAZE ("a-z")
        // character. And allow empty splits (that's important). Because of the
        // fact that it splits on non-lowercase character types, it means that
        // if there are 2 chunks then we KNOW that there was a non-lowercase
        // character (such as _, 0-9, A-Z, etc) in the input. And if there are
        // two chunks but the first chunk is an empty string then we know that
        // there was no lowercase a-z PREFIX in the input. And if there is only
        // 1 chunk then we know the entire input was all-lowercase a-z.
        //
        // Examples of this preg_split()'s behavior:
        //
        // "get_" => 2 chunks: "get" & "_"
        // "getgetX" => 2 chunks: "getget" & "X"
        // "eraseSomething" => 2 chunks: "erase" & "Something"
        // "getgetget" (only lowercase a-z) => 1 chunk: "getgetget"
        // "GetSomething" (no lowercase prefix) => 2 chunks: "" & "GetSomething"
        // "G" => 2 chunks: "" & "G"
        // "GX" => 2 chunks: "" & "GX"
        // "Gx" => 2 chunks: "" & "Gx"
        // "0" => 2 chunks: "" & "0"
        // "gx" => 1 chunk: "gx"
        // "gX" => 2 chunks: "g" & "X"
        // "g0" => 2 chunks: "g" & "0"
        // "" (empty string input) => 1 chunk: ""
        //
        // Therefore, we know that the input was valid (a lowercase a-z prefix
        // followed by at least one non-lowercase a-z after that) if we have two
        // chunks and the first chunk is non-empty!
        $chunks = preg_split('/(?=[^a-z])/', $functionName, 2);
        if (count($chunks) === 2 && $chunks[0] !== '') {
            // [0] = prefix (functionType), [1] = suffix (FuncCase).
            return $chunks;
        }

        // Invalid input. Return NULL prefix and NULL suffix values.
        static $invalidChunks = [null, null]; // Static=Only created first call.

        return $invalidChunks;
    }

    /**
     * Converts a FunctionCase name to snake and camel properties.
     *
     * See input/output examples in class documentation above.
     *
     * @param string $funcCase
     *
     * @return array|bool Associative array with `snake` & `camel` elements if
     *                    successful, otherwise `FALSE`.
     */
    protected function _funcCaseToProperties(
        $funcCase)
    {
        // This algorithm is the exact inverse of PropertyTranslation.
        // Read that class for more information.

        // There's nothing to do if the input is empty...
        if (!strlen($funcCase)) {
            return false;
        }

        // First, we must decode our encoded representation of any special PHP
        // operators, just in case their property name had illegal chars.
        $funcCase = SpecialOperators::decodeOperators($funcCase);

        // Now remove and count all leading underscores (important!).
        // Example: "__MessageList" => "MessageList".
        $result = ltrim($funcCase, '_');
        $leadingUnderscores = strlen($funcCase) - strlen($result);

        // Verify that the REMAINING input result doesn't contain lowercase a-z
        // as its FIRST character. In that case, we were given invalid input,
        // because the FuncCase style REQUIRES that the first character is a
        // NON-LOWERCASE. Anything else is fine, such as UpperCase, numbers or
        // special characters, etc, but never lowercase, since our splitter
        // splitFunctionName() would NEVER give us a FuncName part with a
        // leading lowercase letter! However, the splitter COULD give us
        // something like "__m" from "get__m". But our PropertyTranslation would
        // NEVER create output like "__m". It would have created "__M". So
        // anything that now remains at the start of the string after stripping
        // leading underscores MUST be non-lowercase.
        if (preg_match('/^[a-z]/', $result)) {
            return false;
        }

        // Split the input into chunks on all camelcase boundaries.
        // NOTE: Since all chunks are split on camelcase boundaries below, it
        // means that each chunk ONLY holds a SINGLE fragment which can ONLY
        // contain at most a SINGLE capital letter (the chunk's first letter).
        // NOTE: The "PREG_SPLIT_NO_EMPTY" ensures that we don't get an empty
        // leading array entry when the input begins with an "Upper" character.
        // NOTE: If this doesn't match anything (meaning there are no uppercase
        // characters to split on), the input is returned as-is. Such as "123".
        // NOTE: If $result is an empty string, this returns an empty array.
        // Example: "MessageList" => "Message" & "List"
        $chunks = preg_split('/(?=[A-Z])/', $result, -1, PREG_SPLIT_NO_EMPTY);
        if ($chunks === false) {
            return false; // Only happens on regex engine failure, NOT mismatch!
        }
        $chunkCount = count($chunks);

        // Handle the scenario where there are no chunks ($result was empty).
        // NOTE: Thanks to all of the validation above, this can ONLY happen
        // when input consisted entirely of underscores with nothing after that.
        if ($chunkCount === 0) {
            // Insert a fake, empty element to act as the first chunk, to ensure
            // that we have something to insert the underscores into.
            $chunks[] = '';
            $chunkCount++;
        }

        // Lowercase the leading uppercase of 1st chunk (whatever is in there),
        // since that chunk needs lowercase in both snake_case and camelCase.
        // Example: "Message" & "List" => "message" & "List"
        $chunks[0] = lcfirst($chunks[0]);

        // If there were any leading underscores, prepend all of them to the 1st
        // chunk, which ensures that they become part of the first chunk.
        // Example: "__message" & "List"
        if ($leadingUnderscores > 0) {
            $chunks[0] = str_repeat('_', $leadingUnderscores).$chunks[0];
        }

        // Now let's create the snake_case and camelCase variable names.

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

        // Return the final snake_case and camelCase property names.
        return [
            'snake' => $snakePropName,
            'camel' => $camelPropName,
        ];
    }
}
