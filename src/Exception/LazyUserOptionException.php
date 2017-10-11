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
use LazyJsonMapper\Utilities;

/**
 * Means that a user-option constant prevented normal execution.
 *
 * This is used for actions that would not normally be errors, but whose
 * behaviors have been modified by user-option constants for the current
 * class object instance. Such as attempting to perform actions forbidden
 * by the user's customized options.
 *
 * @copyright 2017 The LazyJsonMapper Project
 * @license http://www.apache.org/licenses/LICENSE-2.0
 * @author SteveJobzniak (https://github.com/SteveJobzniak)
 */
class LazyUserOptionException extends LazyUserException
{
    /**
     * The default error message.
     *
     * @var int
     */
    const ERR_DEFAULT = 0;

    /**
     * The error message about virtual properties being disabled.
     *
     * @var int
     */
    const ERR_VIRTUAL_PROPERTIES_DISABLED = 1;

    /**
     * The error message about virtual functions being disabled.
     *
     * @var int
     */
    const ERR_VIRTUAL_FUNCTIONS_DISABLED = 2;

    /**
     * Constructor.
     *
     * @param LazyJsonMapper $owner     Class instance that triggered the error.
     * @param int            $errorCode Which error message to display.
     */
    public function __construct(
        LazyJsonMapper $owner,
        $errorCode = self::ERR_DEFAULT)
    {
        // It is very important that we pinpoint exactly which class triggered
        // the error, since the user may have chained operators and traveled
        // deep within an object hierarchy.
        $className = Utilities::createStrictClassPath(get_class($owner));
        switch ($errorCode) {
        case self::ERR_VIRTUAL_PROPERTIES_DISABLED:
            $message = sprintf(
                'Virtual property access is disabled for class "%s".',
                $className
            );
            break;
        case self::ERR_VIRTUAL_FUNCTIONS_DISABLED:
            $message = sprintf(
                'Virtual functions are disabled for class "%s".',
                $className
            );
            break;
        default:
            $errorCode = self::ERR_DEFAULT;
            $message = sprintf(
                'Action forbidden by options for class "%s".',
                $className
            );
        }

        parent::__construct($message, $errorCode);
    }
}
