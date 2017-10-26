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

namespace LazyJsonMapper\Exception;

/**
 * Means that there is a circular reference in the property map's imports.
 *
 * Examples: `B extends A, B imports A` (which is impossible since no class
 * `extends` are allowed to import any part of their own inheritance tree), or
 * `AB[C] imports ABC[D]` (which is impossible, since `D` relies on `C` which is
 * not yet done being compiled, since `C` is currently trying to import `D` to
 * resolve itself), or `X imports Y, Y imports X` (where two otherwise-unrelated
 * classes are trying to import each other, which is an unresolvable circular
 * reference where neither class can finish their compilation, since they both
 * depend on each other).
 *
 * @copyright 2017 The LazyJsonMapper Project
 * @license http://www.apache.org/licenses/LICENSE-2.0
 * @author SteveJobzniak (https://github.com/SteveJobzniak)
 */
class CircularPropertyMapException extends BadPropertyMapException
{
    /**
     * Name of the first class involved in the circular map.
     *
     * @var string|null
     */
    private $_badClassNameA;

    /**
     * Name of the second class involved in the circular map.
     *
     * @var string|null
     */
    private $_badClassNameB;

    /**
     * Constructor.
     *
     * @param string $badClassNameA Name of the first class involved in the circular map.
     * @param string $badClassNameB Name of the second class involved in the circular map.
     */
    public function __construct(
        $badClassNameA = null,
        $badClassNameB = null)
    {
        if (!is_string($badClassNameA)) {
            $badClassNameA = null;
        }
        $this->_badClassNameA = $badClassNameA;
        if (!is_string($badClassNameB)) {
            $badClassNameB = null;
        }
        $this->_badClassNameB = $badClassNameB;

        if ($badClassNameA !== null && $badClassNameB !== null) {
            parent::__construct(sprintf(
                'Circular reference between classes "%s" and "%s" in JSON property map import instruction.',
                $badClassNameA, $badClassNameB
            ));
        } else {
            parent::__construct('Circular reference in JSON property map import instruction.');
        }
    }

    /**
     * Get the name of the first class involved in the circular map.
     *
     * @return string|null
     */
    public function getBadClassNameA()
    {
        return $this->_badClassNameA;
    }

    /**
     * Get the name of the second class involved in the circular map.
     *
     * @return string|null
     */
    public function getBadClassNameB()
    {
        return $this->_badClassNameB;
    }
}
