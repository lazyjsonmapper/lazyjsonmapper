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

use LazyJsonMapper\LazyJsonMapper;

/**
 * Describes that an error happened in user-written subclass code or options.
 *
 * This exception is never purposefully thrown by the core `LazyJsonMapper`
 * class by default. It's only thrown if problems happen in subclass code or
 * if there are problems related to the user-class options.
 *
 * Currently, that's only possible in the `LazyJsonMapper::_init()` function,
 * or when the user has overridden certain user-class options away from
 * the default values and then attempts to perform an action affected by
 * their non-standard class option.
 *
 * @copyright 2017 The LazyJsonMapper Project
 * @license http://www.apache.org/licenses/LICENSE-2.0
 * @author SteveJobzniak (https://github.com/SteveJobzniak)
 *
 * @see LazyJsonMapper::_init()
 */
class LazyUserException extends LazyJsonMapperException
{
}
