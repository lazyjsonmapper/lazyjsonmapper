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

namespace LazyJsonMapper\Export;

use LazyJsonMapper\Exception\LazyJsonMapperException;
use LazyJsonMapper\LazyJsonMapper;

/**
 * Container for the result of a LazyJsonMapper class analysis.
 *
 * Describes any problems (bad/missing definitions) of a `LazyJsonMapper` class
 * property map versus its actual object instance's JSON data.
 *
 * `NOTE:` The class validates all parameters, but provides public properties to
 * avoid needless function calls. It's therefore your responsibility to never
 * assign any bad values to the public properties after this object's creation!
 *
 * @copyright 2017 The LazyJsonMapper Project
 * @license http://www.apache.org/licenses/LICENSE-2.0
 * @author SteveJobzniak (https://github.com/SteveJobzniak)
 *
 * @see LazyJsonMapper::exportClassAnalysis()
 */
class ClassAnalysis
{
    /**
     * Associative array of class names and problems with their class property maps.
     *
     * Bad definitions that failed to construct or whose types cannot be
     * coerced as-requested or whose JSON data doesn't match the definition,
     * such as a mismatch with the array-depth of the actual data.
     *
     * @var array[]
     */
    public $bad_definitions = [];

    /**
     * Associative array of class names and what JSON properties they are missing.
     *
     * Undefined properties that don't exist in the class property map, but
     * which exist in the object instance's JSON data.
     *
     * @var array[]
     */
    public $missing_definitions = [];

    /**
     * Merge another ClassAnalysis object's result into this instance.
     *
     * @param ClassAnalysis $other The other object instance.
     */
    public function mergeAnalysis(
        ClassAnalysis $other)
    {
        $this->bad_definitions = array_merge_recursive(
            $this->bad_definitions,
            $other->bad_definitions
        );
        $this->missing_definitions = array_merge_recursive(
            $this->missing_definitions,
            $other->missing_definitions
        );
    }

    /**
     * Adds a problem description to the internal state.
     *
     * @param string $definitionSource The class which has the problem.
     * @param string $problemType      Type of problem. Either `bad_definitions`
     *                                 or `missing_definitions`.
     * @param string $problemMessage   A message describing the actual problem.
     *
     * @throws LazyJsonMapperException If any of the parameters are invalid.
     *
     * @see ClassAnalysis::hasProblems()
     */
    public function addProblem(
        $definitionSource,
        $problemType,
        $problemMessage)
    {
        if (!is_string($definitionSource) || !is_string($problemMessage)) {
            throw new LazyJsonMapperException('The definitionSource and problemMessage parameters must be strings.');
        }
        if ($problemType !== 'bad_definitions' && $problemType !== 'missing_definitions') {
            throw new LazyJsonMapperException('The problemType parameter must be either "bad_definitions" or "missing_definitions".');
        }

        if (!isset($this->{$problemType}[$definitionSource])) {
            $this->{$problemType}[$definitionSource] = [];
        }

        $this->{$problemType}[$definitionSource][] = $problemMessage;
    }

    /**
     * Convert the per-class arrays to sorted lists of missing/bad properties.
     *
     * Removes all duplicate messages and sorts everything nicely. It is
     * recommended to only call this function a single time, on the final
     * `ClassAnalysis` object (after all other steps are finished).
     */
    public function sortProblemLists()
    {
        foreach (['bad_definitions', 'missing_definitions'] as $problemType) {
            // Sort the problem messages within each class.
            foreach ($this->{$problemType} as $definitionSource => $messages) {
                $this->{$problemType}[$definitionSource] = array_unique($messages);
                natcasesort($this->{$problemType}[$definitionSource]);
            }

            // Sort the outer array (the class names).
            ksort($this->{$problemType}, SORT_NATURAL | SORT_FLAG_CASE);
        }
    }

    /**
     * Check whether any problems were discovered.
     *
     * In that case, it's recommended to use `generateNiceSummaries()` to format
     * user-readable messages about the problems.
     *
     * @return bool
     *
     * @see ClassAnalysis::generateNiceSummaries()
     */
    public function hasProblems()
    {
        return !empty($this->bad_definitions) || !empty($this->missing_definitions);
    }

    /**
     * Generates nicely formatted problem summaries for this class analysis.
     *
     * @return array An array with formatted messages for every type of analysis
     *               which actually had errors, keyed by the problem type. If no
     *               errors, the returned array will be empty.
     *
     * @see ClassAnalysis::hasProblems()
     * @see ClassAnalysis::generateNiceSummariesAsString()
     */
    public function generateNiceSummaries()
    {
        $problemSummaries = [];
        if (!empty($this->bad_definitions)) {
            // Build a nice string containing all encountered bad definitions.
            $strSubChunks = [];
            foreach ($this->bad_definitions as $className => $messages) {
                $strSubChunks[] = sprintf(
                    '"%s": ([\'%s\'])',
                    $className, implode('\'], and [\'', $messages)
                );
            }
            $problemSummaries['bad_definitions'] = sprintf(
                'Bad JSON property definitions in %s.',
                implode(', and in ', $strSubChunks)
            );
        }
        if (!empty($this->missing_definitions)) {
            // Build a nice string containing all missing class properties.
            $strSubChunks = [];
            foreach ($this->missing_definitions as $className => $messages) {
                $strSubChunks[] = sprintf(
                    '"%s": ("%s")',
                    $className, implode('", "', $messages)
                );
            }
            $problemSummaries['missing_definitions'] = sprintf(
                'Missing JSON property definitions in %s.',
                implode(', and in ', $strSubChunks)
            );
        }

        return $problemSummaries;
    }

    /**
     * Generates a nicely formatted problem summary string for this class analysis.
     *
     * This helper combines all summaries and returns them as a single string
     * (rather than as an array), which is very useful when displaying ALL
     * errors to a user as a message.
     *
     * @return string The final string. Is an empty string if no errors exist.
     *
     * @see ClassAnalysis::hasProblems()
     * @see ClassAnalysis::generateNiceSummaries()
     */
    public function generateNiceSummariesAsString()
    {
        return implode(' ', $this->generateNiceSummaries());
    }
}
