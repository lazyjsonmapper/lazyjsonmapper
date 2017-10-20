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
 * Automatically translates a property name into equivalent FunctionCase name.
 *
 * The translation into a function name will differ based on the style of
 * the property name that was sent in as a parameter. That's intentional.
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
 * We do NOT support property names in "HumpBack" notation, whose first word is
 * uppercased (even if it sits after leading underscores).
 *
 * For example, `__MessageList` or `MessageList` as HumpBack input are invalid,
 * but `__message_list`/`message_list` (snake) or `__messageList`/`messageList`
 * (camel) are valid property names.
 *
 * We WILL however accept HumpBack input and will provide a translation for it,
 * but it will NOT be possible for `FunctionTranslation` to translate HumpBack
 * style back to a property name. Just be aware of that! It's intentional, since
 * HumpBack style is extremely rare and we save processing by not supporting it.
 *
 * ---------------------------------------------------------------------
 *
 * Translation Examples (RESULT LISTED FIRST, then what input was used):
 *
 * - `__Foo_Bar__XBaz__` => `__foo__bar___x_baz__` (snake)
 *                          `__foo_Bar__XBaz__` (camel)
 * - `0m__AnUn0x`        => `0m___an_un0x` (snake) & `0m__AnUn0x` (camel)
 * - `Some0XThing`       => `some0_x_thing` (snake) & `some0XThing` (camel)
 * - `Some0xThing`       => `some0x_thing` (snake) & `some0xThing` (camel)
 * - `SomeThing`         => `some_thing` (snake) & `someThing` (camel)
 * - `Something`         => `something` (snake & camel identical; no ucwords)
 * - `___`               => `___` (snake & camel identical; no ucwords)
 * - `_0`                => `_0` (snake & camel identical; no ucwords)
 * - `_Messages`         => `_messages` (snake & camel identical; no ucwords)
 * - `__MessageList`     => `__message_list` (snake) & `__messageList` (camel)
 * - `123`               => `123` (snake & camel identical; no ucwords)
 * - `123prop`           => `123prop` (snake & camel identical; no ucwords)
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
 * @see FunctionTranslation
 */
class PropertyTranslation
{
    /**
     * The property name in "FunctionCase" style.
     *
     * For example `AwesomeProperty`.
     *
     * @var string
     */
    public $propFuncCase;

    /**
     * Constructor.
     *
     * @param string $propertyName The name of the property to translate, in
     *                             either `snake_case` or `camelCase` style,
     *                             such as `awesome_property` (snake)
     *                             or `awesomeProperty` (camel). The
     *                             translations will differ based on
     *                             which style is used. (That's intentional.)
     *
     * @throws MagicTranslationException If the property name is unparseable.
     */
    public function __construct(
        $propertyName)
    {
        if (!is_string($propertyName) || $propertyName === '') {
            throw new MagicTranslationException('The property name must be a non-empty string value.');
        }

        $this->propFuncCase = $this->_propToFunctionCase($propertyName);
    }

    /**
     * Converts a property name to FunctionCase.
     *
     * See input/output examples in class documentation above.
     *
     * @param string $propName The property name, as either `snake_case` or
     *                         `camelCase`. The translations will differ based
     *                         on which style is used. (That's intentional.)
     *
     * @return string
     */
    protected function _propToFunctionCase(
        $propName)
    {
        // To translate a property name to FunctionCase, we must simply convert
        // any leading lowercase (a-z) character to uppercase, as well as any
        // (a-z) that comes after an underscore. In the latter case, the
        // underscore must be removed during the conversion.
        // For example: "example_property" = "ExampleProperty".
        // (If multiple underscores, still just remove ONE: "a__b"="A_B").
        //
        // However, there is a special case: All LEADING underscores must be
        // preserved exactly as-is: "__messages" = "__Messages" (still 2).
        //
        // As for camelCasePropertyNames? They're already perfect, except that
        // the first letter must be made uppercase. And IF they have any inner
        // "_[a-z]" (lowercase) chunks, those should be translated as they would
        // for a snake_case string. But any "_[A-Z]" should not be touched.
        // In other words, the algorithm is exactly the same for camelCase.

        // Begin by removing and counting all leading underscores (important!).
        // NOTE: The reason why we have to do this is because otherwise a
        // property named "_messages" would be translated to just "Messages",
        // which then becomes incredibly hard to guess whether it means
        // "messages" or "_messages". So by preserving any leading underscores,
        // we remove that ambiguity. It also make the function names more
        // logical (they match the property's amount of leading underscores) and
        // it removes clashes. For example if the user defines both "messages"
        // and "_messages", they can safely use their "getMessages()" and
        // "get_Messages()" without any ambiguity about what they refer to.
        $result = ltrim($propName, '_');
        $leadingUnderscores = strlen($propName) - strlen($result);

        // Now simply uppercase any lowercase (a-z) character that is either at
        // the start of the string or appears immediately after an underscore.
        //
        // ----------------------------------------
        // TODO: If someone is using Unicode in their JSON data, we should
        // simply extend this (and also the FunctionTranslation class) to run
        // with PHP's slower mb_* multibyte functions and "//u" UTF-8 flags,
        // so that we can support functions like "getÅngbåt()", for a property
        // named '{"ångbåt":1}'. But honestly, I doubt that even international
        // users name their JSON data in anything but pure, highly-compressible
        // ASCII, such as "angbat" and "getAngbat()" in the example above. So
        // for now, we can have the efficient ASCII algorithms here. In the
        // future we may want to provide a class-constant to override the
        // parsers to enable UTF-8 mode, so that the user can have that slower
        // parsing behavior in certain classes only. And in that case, the magic
        // function translations can still be stored in the same global cache
        // together with all the ASCII entries, since they'd use their UTF-8
        // names as key and thus never clash with anything from this algorithm.
        // ----------------------------------------
        $result = preg_replace_callback('/(?:^|_)([a-z])/', function ($matches) {
            return ucfirst($matches[1]); // Always contains just 1 character.
        }, $result);

        // Now just prepend any leading underscores that we removed earlier.
        if ($leadingUnderscores > 0) {
            $result = str_repeat('_', $leadingUnderscores).$result;
        }

        // Lastly, we must now translate special PHP operators to an encoded
        // representation, just in case their property name has illegal chars.
        $result = SpecialOperators::encodeOperators($result);

        return $result;
    }
}
