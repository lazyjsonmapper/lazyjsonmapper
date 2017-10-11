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

/**
 * Represents an "undefined" property which isn't in the user's class map.
 *
 * This is used for properties that exist in the JSON data but not in the map.
 *
 * @copyright 2017 The LazyJsonMapper Project
 * @license http://www.apache.org/licenses/LICENSE-2.0
 * @author SteveJobzniak (https://github.com/SteveJobzniak)
 */
class UndefinedProperty extends PropertyDefinition
{
    /**
     * An internal, globally shared instance of this class.
     *
     * To save memory, always retrieve this global instance of the class.
     *
     * @var UndefinedProperty|null
     *
     * @see UndefinedProperty::getInstance()
     */
    private static $_undefinedInstance = null;

    /**
     * Constructor.
     *
     * Never call this manually. Use the globally shared instance instead!
     *
     * @see UndefinedProperty::getInstance()
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Get a shared, "undefined property" instance of this class.
     *
     * This function is great for memory purposes, since a single "undefined
     * property" object instance can be shared across all code, without needing
     * to allocate individual memory for any more instances of this class.
     *
     * `WARNING:` Because this class is optimized for `LazyJsonMapper`
     * performance (avoiding function call/stack overhead by having public
     * properties), it's extremely important that you do not modify ANY of the
     * properties of this object instance when you get it, otherwise you'll
     * break EVERY shared copy! You are not supposed to manually edit any public
     * property on this class anyway, as the main `PropertyDefinition` class
     * description explains, but it's even more important in this situation.
     *
     * @return UndefinedProperty
     */
    public static function getInstance()
    {
        if (self::$_undefinedInstance === null) {
            self::$_undefinedInstance = new self();
        }

        return self::$_undefinedInstance;
    }
}
