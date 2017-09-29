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

namespace LazyJsonMapper\Exceptions;

/**
 * Means that there is a circular reference in the property map's imports.
 *
 * Examples: "B extends A, A imports B" (which is impossible since no class
 * "extends" are allowed to import any part of their own inheritance tree), or
 * "AB[C] imports ABC[D]" (which is impossible, since D relies on C which is not
 * yet done being compiled, since C is currently trying to import D to resolve
 * itself).
 *
 * @copyright 2017 The LazyJsonMapper Project
 * @license http://www.apache.org/licenses/LICENSE-2.0
 * @author SteveJobzniak (https://github.com/SteveJobzniak)
 */
class CircularPropertyMapException extends BadPropertyMapException
{
    /** @var string|null */
    private $_badClassName;

    /**
     * Constructor.
     *
     * @param string $badClassName The name of the class that's being referred
     *                             to again while it's already being compiled.
     */
    public function __construct(
        $badClassName)
    {
        if (!is_string($badClassName)) {
            $badClassName = null;
        }
        $this->_badClassName = $badClassName;

        if ($badClassName !== null) {
            parent::__construct(sprintf('Circular reference to "%s" in JSON property map import instruction.', $badClassName));
        } else {
            parent::__construct('Circular reference in JSON property map import instruction.');
        }
    }

    /**
     * Get the name of the class that couldn't be resolved due to circular map.
     *
     * @return string|null
     */
    public function getBadClassName()
    {
        return $this->_badClassName;
    }
}
