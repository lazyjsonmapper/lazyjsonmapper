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

namespace LazyJsonMapper;

/**
 * Collection of shared utility functions for the library.
 *
 * @copyright 2017 The LazyJsonMapper Project
 * @license http://www.apache.org/licenses/LICENSE-2.0
 * @author SteveJobzniak (https://github.com/SteveJobzniak)
 */
class Utilities
{
    /**
     * Create a strict, global class path.
     *
     * This helper ensures that the input is a non-empty string, and then
     * automatically prepends a leading `\` if missing, so that PHP understands
     * that the class search MUST happen only in the global namespace.
     *
     * @param string $className The class name to convert.
     *
     * @return string|null String if non-empty string input, otherwise `NULL`.
     */
    public static function createStrictClassPath(
        $className = '')
    {
        if (is_string($className) && strlen($className) > 0) {
            // Prepend "\" if missing, to force PHP to use the global namespace.
            if ($className[0] !== '\\') {
                $className = '\\'.$className;
            }

            return $className;
        }

        return null;
    }

    /**
     * Splits a strict class-path into its namespace and class name components.
     *
     * To rejoin them later, just use: `'namespace' + '\\' + 'class'`.
     *
     * The class path should be in `get_class()` aka `TheClass::class` format.
     *
     * @param string $strictClassPath Class output of `createStrictClassPath()`.
     *
     * @return array Associative array with keys for `namespace` and `class`.
     *
     * @see Utilities::createStrictClassPath()
     */
    public static function splitStrictClassPath(
        $strictClassPath = '')
    {
        // Split on the rightmost backslash. In a strict path there's always at
        // least one backslash, for the leading "global namespace" backslash.
        $lastDelimPos = strrpos($strictClassPath, '\\');
        // Global: "". Other: "\Foo" or "\Foo\Bar" (if nested namespaces).
        $namespace = substr($strictClassPath, 0, $lastDelimPos);
        // Always: "TheClass".
        $class = substr($strictClassPath, $lastDelimPos + 1);

        return [
            'namespace' => $namespace,
            'class'     => $class,
        ];
    }

    /**
     * Compare two class paths and generate the shortest path between them.
     *
     * @param array $sourceComponents Source class as `splitStrictClassPath()`.
     * @param array $targetComponents Target class as `splitStrictClassPath()`.
     *
     * @return string The final path to reach from the source to the target.
     *
     * @see Utilities::splitStrictClassPath()
     */
    public static function createRelativeClassPath(
        array $sourceComponents,
        array $targetComponents)
    {
        $sourceNs = &$sourceComponents['namespace'];
        $targetNs = &$targetComponents['namespace'];
        $finalType = null;

        // If either the source or the target lives in the global namespace,
        // we won't do this processing. (Those need a strict, global path.)
        if ($sourceNs !== '' && $targetNs !== '') {
            // Check if the source-class namespace is at pos 0 of target space.
            $pos = strpos($targetNs, $sourceNs);
            if ($pos === 0) {
                // Look at the character after the source-class namespace in the
                // target namespace. Check for "" (str end) or "\\" (subspace).
                $sourceNsLen = strlen($sourceNs);
                $chr = substr($targetNs, $sourceNsLen, 1);
                if ($chr === '') { // Exact same space, without any subspace.
                    $finalType = $targetComponents['class'];
                } elseif ($chr === '\\') { // Same space, followed by subspace.
                    $finalType = sprintf(
                        '%s\\%s',
                        substr($targetNs, $sourceNsLen + 1),
                        $targetComponents['class']
                    );
                } // Else: Was false positive, not in same namespace.
            }
        }

        // In case of totally different spaces, or if any of the classes are in
        // the global namespace, then just use the strict, global target path.
        if ($finalType === null) {
            $finalType = sprintf(
                '%s\\%s',
                $targetNs,
                $targetComponents['class']
            );
        }

        return $finalType;
    }

    /**
     * Atomic filewriter.
     *
     * Safely writes new contents to a file using an atomic two-step process.
     * If the script is killed before the write is complete, only the temporary
     * trash file will be corrupted.
     *
     * The algorithm also ensures that 100% of the bytes were written to disk.
     *
     * @param string $filename     Filename to write the data to.
     * @param string $data         Data to write to file.
     * @param string $atomicSuffix Lets you optionally provide a different
     *                             suffix for the temporary file.
     *
     * @return int|bool Number of bytes written on success, otherwise `FALSE`.
     */
    public static function atomicWrite(
        $filename,
        $data,
        $atomicSuffix = 'atomictmp')
    {
        // Perform an exclusive (locked) overwrite to a temporary file.
        $filenameTmp = sprintf('%s.%s', $filename, $atomicSuffix);
        $writeResult = @file_put_contents($filenameTmp, $data, LOCK_EX);

        // Only proceed if we wrote 100% of the data bytes to disk.
        if ($writeResult !== false && $writeResult === strlen($data)) {
            // Now move the file to its real destination (replaces if exists).
            $moveResult = @rename($filenameTmp, $filename);
            if ($moveResult === true) {
                // Successful write and move. Return number of bytes written.
                return $writeResult;
            }
        }

        // We've failed. Remove the temporary file if it exists.
        if (is_file($filenameTmp)) {
            @unlink($filenameTmp);
        }

        return false; // Failed.
    }
}
