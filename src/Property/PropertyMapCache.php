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
 * Storage container for compiled class property maps.
 *
 * @copyright 2017 The LazyJsonMapper Project
 * @license http://www.apache.org/licenses/LICENSE-2.0
 * @author SteveJobzniak (https://github.com/SteveJobzniak)
 */
class PropertyMapCache
{
    /**
     * Compiled property map definition cache.
     *
     * Class maps are built at runtime the first time we encounter each class.
     * This cache is necessary so that we instantly have fully-validated maps
     * without needing to constantly re-build/validate the property definitions.
     *
     * @var array
     */
    public $classMaps = [];

    /**
     * Classes that are locked during their map compilation.
     *
     * This is used internally for detecting circular references in "import
     * class" instructions during property map compilation. For example, if
     * `A extends LazyJsonMapper, imports B` and `B extends LazyJsonMapper,
     * imports A`, then the compilation of either class will fail gracefully
     * instead of attempting to recursively allocate infinite memory and dying.
     *
     * For example, compiling `A` would mark `A` as an "unresolved (locked)
     * class", and then we'd encounter its instruction to `import B`, and
     * then we'll attempt to resolve `B`. Then, `B` encounters its instruction
     * to `import A` and sees that it's a locked class and understands that
     * `A` is currently being compiled which means that we've detected
     * a circular import reference. At that point, we'll throw an exception.
     *
     * @var array
     */
    public $compilerLocks = [];

    /**
     * Clear the contents of the cache.
     *
     * Be aware that clearing the cache will only release OUR references to the
     * compiled property maps. If there are any other variables linked to the
     * contents of the cache, then those cache entries will NOT be freed by PHP
     * until those additional variable references are garbage-collected.
     */
    public function clearCache()
    {
        $this->classMaps = [];
        $this->compilerLocks = [];
    }
}
