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

// This hastily written file tests various algorithms for doing
// the initial splitting of a magic function call into their
// prefix and suffix parts. Such as parsing "getSomethingSomething"
// into "get" and "SomethingSomething".
//
// This kind of micro-optimization is kind of overkill. But so is
// everything else about the LazyJsonMapper project! ;-) And I was
// curious about the performance implications of each method!
//
// NOTE about algorithm speed:
// In all of these test algorithms I do a "get" check LAST, to
// force PHP to check less common prefixes first, to ensure
// that it fairly represents normal usage (since the test-data
// overrepresents its amount of "get" functions and would therefore
// usually short-circuit each test very quickly). in a real
// algorithm, get should be first, since it's the most common prefix.

// -------------------------------------------------
// How many times to run through the 10,000 item test data!
$iterations = 300; // 3 million items (to test accurate per-item performance)
// $iterations = 1; // test 10,000 items (emulates VERY heavy normal script)
// -------------------------------------------------

$data = unserialize(file_get_contents(__DIR__.'/funcListData.serialized'));

// build a 10,000 item key-value map for isset() test usage
$keys = [];
foreach ($data as $function) {
    $keys[$function] = true;
}

$items = $iterations * count($data);

printf("Items per test (amount of function call prefix checks): %s.\n", number_format($items, 0, '.', ','));
echo "Elapsed times shown in Microseconds (1 millionth of a second)\n\n";

// preg_match algorithm:
// slow... but at least it validates the suffix
// to ensure that it starts with non-lowercase.

$start = microtime(true);
for ($i = 0; $i < $iterations; ++$i) {
    foreach ($data as $function) {
        if (preg_match('/^(set|has|is|unset|get)([^a-z].*)$/', $function, $matches)) {
            // NOTE: no need to validate that the prefix is part
            // of a list of valid prefixes, since the regex validated
            // it for us already.
            $prefix = &$matches[1];
            $suffix = $matches[2];

            // now emulate the necessary "is it cached?" lookup:
            // NOTE: in real usage, this would just check the suffix
            if (isset($keys[$function])) {
                $suffix = $matches[2]; // "get from cache" emulation
            }
        }
    }
}
$elapsed = microtime(true) - $start;
$elapsed *= 1000000;

var_dump('preg_match');
printf("- Total: %.4f Microseconds (%.10f seconds)\n- Per Item: %.4f Microseconds (%.10f seconds)\n\n", $elapsed, $elapsed / 1000000, $elapsed / $items, ($elapsed / $items) / 1000000);

// preg_split algorithm:
// very fast. splits on the first non-lowercase ("not a-z") character.
// never any false positives! the prefix-split is truly perfect,
// so "getgetgetSomething" would split as "getgetget" and "Something".
// this is basically like the preg_match, except that we do the split
// and THEN use regular string comparison to check if the prefix
// is a valid one. this has the benefit of allowing us to add lots
// of other prefixes over time, without slowing down a preg_match
// regex even more (since that one has to slowly look for EACH possible
// prefix string repeatedly and requires far more regex engine steps).
//
// NOTE: in both PHP5 and PHP7, the preg_split method is almost exactly
// 5x slower than isset(), but we're talking about microsecond level!
// a microsecond is a MILLIONTH of a second. so I'm going to choose
// the preg_split method. it saves tons of RAM compared to caching
// EVERY individual exact function name via isset(). the only really
// heavy part is the FuncCase-to-property_name translation from the
// SomethingSomething part of "getSomethingSomething". so THAT is the
// only part we really need to cache. This initial prefix-splitting
// is better handled live on every function call to save RAM.
//
// benchmarks:
// PHP7:
// preg_split per item: 0.5883 Microseconds (0.0000005883 seconds)
// isset() per item: 0.1053 Microseconds (0.0000001053 seconds)
// PHP5:
// preg_split per item: 2.3158 Microseconds (0.0000023158 seconds)
// isset() per item: 0.4752 Microseconds (0.000004752 seconds)
//
// so for a ~5x increase in operation time at a microsecond-level,
// we get MUCH LOWER RAM USAGE by only having to cache the shared
// "SomethingSomething" part, rather than "getSomethingSomething",
// "setSomethingSomething", "isSomethingSomething", "hasSomethingSomething"
// and "unsetSomethingSomething".
//
// for someone using tens of thousands of calls, the prefix splitting
// will be impressively instant. just try this file with $iterations = 1!
// in that case, all of the prefix-splittings for 10,000 function
// calls run in a COMBINED total of ~3.4 milliseconds! for ALL of them!
// even in PHP5 it's around ~11 milliseconds for 10,000 prefix-splits.
//
// even if someone has millions of magic function calls, they won't
// notice this preg_split() processing in relation to all of their
// other processing when they have that many calls! that's how fast
// the splitting is. and it saves 5x RAM at the moment (and even more
// if more function prefix variations are added later).

$start = microtime(true);
for ($i = 0; $i < $iterations; ++$i) {
    foreach ($data as $function) {
        // NOTE: splits to an empty 1st chunk in case of input like
        // "SomethingSomething" instead of "getSomethingSomething", or
        // "GetSomething" instead of "getSomething". which is how we can
        // detect if the 1st chunk had a lowercase prefix or not.
        // NOTE: chunkCount is 1 IF the input is an all-lowercase word with no
        // other character types. such as just "get". whereas "get__" would be
        // chunkCount 2. so count must be 2, and the first chunk must be
        // non-empty, THEN we'll know that a prefix existed.
        $chunks = preg_split('/(?=[^a-z])/', $function, 2);
        $chunkCount = count($chunks);
        if ($chunkCount === 2 && $chunks[0] !== '') {
            // verify the prefix
            switch ($chunks[0]) {
            case 'set':
            case 'has':
            case 'is':
            case 'unset':
            case 'get':
                // we know suffix (second chunk) is non-empty since the split
                // specifically looked for anything "NOT lowercase a-z" to split
                // on, and then placed that character and the rest of the string
                // in chunk number 2.
                $prefix = $chunks[0];
                $suffix = $chunks[1];

                // now emulate the necessary "is it cached?" lookup:
                // NOTE: in real usage, this would just check the suffix
                if (isset($keys[$function])) {
                    $suffix = $chunks[1]; // "get from cache" emulation
                }
            }
        }
    }
}
$elapsed = microtime(true) - $start;
$elapsed *= 1000000;

var_dump('preg_split');
printf("- Total: %.4f Microseconds (%.10f seconds)\n- Per Item: %.4f Microseconds (%.10f seconds)\n\n", $elapsed, $elapsed / 1000000, $elapsed / $items, ($elapsed / $items) / 1000000);

// binary string comparison algorithm:
// a bit faster but requires tons of separate calls, which slows it down.
// and suffers from false positives like "getgetgetSomething".

$start = microtime(true);
for ($i = 0; $i < $iterations; ++$i) {
    foreach ($data as $function) {
        $suffix = null;
        if (strncmp('set', $function, 3) === 0) {
            $prefix = 'set';
            $suffix = substr($function, 3);
        } elseif (strncmp('has', $function, 3) === 0) {
            $prefix = 'has';
            $suffix = substr($function, 3);
        } elseif (strncmp('is', $function, 2) === 0) {
            $prefix = 'is';
            $suffix = substr($function, 2);
        } elseif (strncmp('unset', $function, 5) === 0) {
            $prefix = 'unset';
            $suffix = substr($function, 3);
        } elseif (strncmp('get', $function, 3) === 0) {
            $prefix = 'get';
            $suffix = substr($function, 3);
        }
        if ($suffix !== null) {
            // now emulate the necessary "is it cached?" lookup:
            // NOTE: in real usage, this would just check the suffix
            if (isset($keys[$function])) {
                $suffix = $function; // "get from cache" emulation
            }
        }
    }
}
$elapsed = microtime(true) - $start;
$elapsed *= 1000000;

var_dump('strncmp (risk of false positives)');
printf("- Total: %.4f Microseconds (%.10f seconds)\n- Per Item: %.4f Microseconds (%.10f seconds)\n\n", $elapsed, $elapsed / 1000000, $elapsed / $items, ($elapsed / $items) / 1000000);

// substr + strncmp algorithm:
// uses a mixture of strncmp and substr to reduce the number of calls.
// still suffers from false positives like "getgetgetSomething".

$start = microtime(true);
for ($i = 0; $i < $iterations; ++$i) {
    foreach ($data as $function) {
        $prefix = substr($function, 0, 3);
        $suffix = null;
        if ($prefix === 'set' || $prefix === 'has' || $prefix === 'get') {
            $suffix = substr($function, 3);
        } elseif ($prefix === 'uns') {
            if (strncmp('unset', $function, 5) === 0) {
                $prefix = 'unset';
                $suffix = substr($function, 5);
            } else {
                $prefix = null;
            }
        } elseif (strncmp('is', $function, 2) === 0) {
            $prefix = 'is';
            $result = substr($function, 2);
        }
        if ($suffix !== null) {
            // now emulate the necessary "is it cached?" lookup:
            // NOTE: in real usage, this would just check the suffix
            if (isset($keys[$function])) {
                $suffix = $function; // "get from cache" emulation
            }
        }
    }
}
$elapsed = microtime(true) - $start;
$elapsed *= 1000000;

var_dump('substr + strncmp (risk of false positives)');
printf("- Total: %.4f Microseconds (%.10f seconds)\n- Per Item: %.4f Microseconds (%.10f seconds)\n\n", $elapsed, $elapsed / 1000000, $elapsed / $items, ($elapsed / $items) / 1000000);

// lastly, the good-old "fully-cached" isset() algorithm:
// extremely fast. but the downside is that we would have to store
// every function name variation in RAM. so "getSomething", "hasSomething",
// "setSomething", "isSomething" and "unsetSomething" would all have to
// be stored and parsed separately. they cannot store any cached lookup
// table of what just the "Something" part refers to. which is very wasteful.

$start = microtime(true);
for ($i = 0; $i < $iterations; ++$i) {
    foreach ($data as $function) {
        if (isset($keys[$function])) {
            // NOTE: in a real algorithm this would retrieve the
            // cached prefix and suffix from the cache.
            $prefix = $keys[$function];
            $suffix = $keys[$function];
        }
    }
}
$elapsed = microtime(true) - $start;
$elapsed *= 1000000;

var_dump('isset (wastes 5x more RAM, but is obviously always fastest)');
printf("- Total: %.4f Microseconds (%.10f seconds)\n- Per Item: %.4f Microseconds (%.10f seconds)\n\n", $elapsed, $elapsed / 1000000, $elapsed / $items, ($elapsed / $items) / 1000000);
