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

/*
 * I created these stack-based array loop algorithms for fun, just to try out
 * an idea I had in mind. They are algorithms for iterating through multi-level
 * arrays without needing any recursive function calls. And they are fully
 * capable of tracking the current array-depth that they're operating at...
 *
 * These are the algorithms I tested:
 * - RecursiveIteratorIterator = PHP's built-in object-based iterator library
 * - array_flat_topdown_traverse = my stack-based algorithm v1
 * - array_flat_topdown_traverse2 = my stack-based algorithm v2 (optimized)
 * - array_recursive_traverse = simple recursive function calls
 *
 * The recursive function calls add 1 extra level to the call-stack for every
 * deeper array depth-level that it enters.
 *
 * Therefore, I tested everything with test-data that contained array-depth 6
 * (that's usually max in normal JSON data), 50 (rare), 500 (insane) and finally
 * 5000 (hellish). The latter means that the recursive function calls-stack will
 * reach a depth of 5000 recursive calls inside of each other. Hell indeed.
 *
 * The algorithms were a success; MASSIVELY beating `RecursiveIteratorIterator`
 * from PHP's standard library by a factor of being almost 10x faster than it.
 *
 * Here's the fun thing, though: Simple, old-school recursive function-calls
 * beats even my algorithm by a factor of being 2x faster!
 *
 * As for memory usage? The 500-levels deep recursive function calls didn't even
 * make a noticeable blip on the radar...! They don't cause any stack memory
 * overflows. On PHP5, this WHOLE test-script used 7.4 MB at peak while running
 * all of the various tests. On PHP7, it used 4.7 MB at peak. And that's for the
 * whole PHP binary, its runtime, etc! I didn't test the 5000-levels deep, but
 * we'll never have such insane JSON data. So there's absolutely ZERO reason to
 * worry about memory-usage of the recursive array resolver in LazyJsonMapper.
 * Especially since normal JSON maps usually have 1-2 levels of array-depth. Now
 * we know that we'll easily handle 500 levels or more. So no need to worry!
 *
 * Therefore there's no need to implement any kind of non-recursive resolver for
 * array processing in this library! Recursive function calls are by far the
 * fastest way to traverse the whole data array.
 *
 * I'll keep this test-file just in case anyone ever complains about the
 * decision to use recursive function calls for the multi-level array
 * processing in LazyJsonMapper.
 *
 * Raw test results:
 *
 *   [Running 1x tests on PHP version 7.0.20]
 *   [RecursiveIteratorIterator Tests: Enabled]
 *   500000x normal-6 RecursiveIteratorIterator: 52372 milliseconds.
 *   500000x normal-6 array_flat_topdown_traverse: 6544 milliseconds.
 *   500000x normal-6 array_flat_topdown_traverse2: 5998 milliseconds.
 *   500000x normal-6 array_recursive_traverse: 3445 milliseconds.
 *   100000x rare-50 RecursiveIteratorIterator: 85326 milliseconds.
 *   100000x rare-50 array_flat_topdown_traverse: 9858 milliseconds.
 *   100000x rare-50 array_flat_topdown_traverse2: 8756 milliseconds.
 *   100000x rare-50 array_recursive_traverse: 5184 milliseconds.
 *   10000x insane-500 RecursiveIteratorIterator: 89780 milliseconds.
 *   10000x insane-500 array_flat_topdown_traverse: 10057 milliseconds.
 *   10000x insane-500 array_flat_topdown_traverse2: 8926 milliseconds.
 *   10000x insane-500 array_recursive_traverse: 9059 milliseconds.
 *   100x hellish-5000 RecursiveIteratorIterator: 19651 milliseconds.
 *   100x hellish-5000 array_flat_topdown_traverse: 1596 milliseconds.
 *   100x hellish-5000 array_flat_topdown_traverse2: 1540 milliseconds.
 *   100x hellish-5000 array_recursive_traverse: 7934 milliseconds.
 *
 *   [Running 1x tests on PHP version 5.6.30]
 *   [RecursiveIteratorIterator Tests: Enabled]
 *   500000x normal-6 RecursiveIteratorIterator: 126769 milliseconds.
 *   500000x normal-6 array_flat_topdown_traverse: 24656 milliseconds.
 *   500000x normal-6 array_flat_topdown_traverse2: 23131 milliseconds.
 *   500000x normal-6 array_recursive_traverse: 15577 milliseconds.
 *   100000x rare-50 RecursiveIteratorIterator: 215920 milliseconds.
 *   100000x rare-50 array_flat_topdown_traverse: 39429 milliseconds.
 *   100000x rare-50 array_flat_topdown_traverse2: 35650 milliseconds.
 *   100000x rare-50 array_recursive_traverse: 26012 milliseconds.
 *   10000x insane-500 RecursiveIteratorIterator: 293588 milliseconds.
 *   10000x insane-500 array_flat_topdown_traverse: 49929 milliseconds.
 *   10000x insane-500 array_flat_topdown_traverse2: 46279 milliseconds.
 *   10000x insane-500 array_recursive_traverse: 32609 milliseconds.
 *   100x hellish-5000 RecursiveIteratorIterator: 33332 milliseconds.
 *   100x hellish-5000 array_flat_topdown_traverse: 6540 milliseconds.
 *   100x hellish-5000 array_flat_topdown_traverse2: 6473 milliseconds.
 *   100x hellish-5000 array_recursive_traverse: 5116 milliseconds.
 *
 *  Increased size of iterations by 10x to spot granular differences:
 *
 *   [Running 10x tests on PHP version 7.0.20]
 *   [RecursiveIteratorIterator Tests: Disabled]
 *   5000000x normal-6 array_flat_topdown_traverse: 75978 milliseconds.
 *   5000000x normal-6 array_flat_topdown_traverse2: 62781 milliseconds.
 *   5000000x normal-6 array_recursive_traverse: 31367 milliseconds.
 *   1000000x rare-50 array_flat_topdown_traverse: 102414 milliseconds.
 *   1000000x rare-50 array_flat_topdown_traverse2: 92691 milliseconds.
 *   1000000x rare-50 array_recursive_traverse: 53280 milliseconds.
 *   100000x insane-500 array_flat_topdown_traverse: 101892 milliseconds.
 *   100000x insane-500 array_flat_topdown_traverse2: 91317 milliseconds.
 *   100000x insane-500 array_recursive_traverse: 82365 milliseconds.
 *   1000x hellish-5000 array_flat_topdown_traverse: 16035 milliseconds.
 *   1000x hellish-5000 array_flat_topdown_traverse2: 15551 milliseconds.
 *   1000x hellish-5000 array_recursive_traverse: 45832 milliseconds.
 *
 * @author SteveJobzniak (https://github.com/SteveJobzniak)
 */

// Special RecursiveArrayIterator which handles objects-in-arrays properly.
class RecursiveArrayOnlyIterator extends \RecursiveArrayIterator
{
    public function hasChildren()
    {
        // This is necessary, otherwise RecursiveArrayIterator will treat all
        // objects as "having children" and will attempt to traverse into them
        // instead of returning the actual objects as values. That's probably
        // because objects in PHP are a special, optimize form of arrays (that's
        // why you can assign any new object property even if the class doesn't
        // have that property at all).
        //
        // Surprisingly, overriding hasChildren() didn't slow down iteration.
        return is_array($this->current());
    }
}

// Algorithm v1: The initial idea I had...
function array_flat_topdown_traverse(
    array &$input)
{
    // Traverse top-down, processing level by level (going deeper and deeper).
    $workStack = [&$input]; // The stack processes one array level at a time.
    $nextStack = []; // Next stack with all deeper arrays found on this level.
    $currentDepth = 1; // First level of input array should count from 1.
    while (!empty($workStack)) {
        // Pop a direct reference off the start of our FIFO stack.
        reset($workStack);
        $firstKey = key($workStack);
        $pointer = &$workStack[$firstKey];
        unset($workStack[$firstKey]);

        // Now we're ready to act on the popped stack element...
        foreach ($pointer as $k => &$v) {
            // printf(
            //     "[D] %d %s\"%s\":%s\n",
            //     $currentDepth,
            //     str_repeat('-', $currentDepth),
            //     $k,
            //     is_array($v) ? '[]' : var_export($v, true)
            // );

            // Analyze the current array-child...
            if (is_array($v)) {
                // Add the discovered child-array to the end of the next-stack.
                $nextStack[] = &$v;
            } else {
                // The child is a non-array element... Send it to the callback!
                // TODO: Give callback key + value ref + array depth
            }
        }

        // If the work-stack is finished, switch to the next (deeper) stack.
        if (empty($workStack)) {
            $workStack = $nextStack;
            $nextStack = [];
            $currentDepth++;
        }
    }
}

// Algorithm v2: Avoids two count() calls per stack-element iteration.
function array_flat_topdown_traverse2(
    array &$input)
{
    // Traverse top-down, processing level by level (going deeper and deeper).
    $workStack = [&$input]; // The stack processes one array level at a time.
    $workStackSize = 1; // Hardcoded result of count($workStack).
    $nextStack = []; // Next stack with all deeper arrays found on this level.
    $currentDepth = 1; // First level of input array should count from 1.
    while ($workStackSize > 0) {
        // Pop a direct reference off the start of our FIFO stack.
        reset($workStack);
        $firstKey = key($workStack);
        $pointer = &$workStack[$firstKey];
        unset($workStack[$firstKey]);
        $workStackSize--;

        // Now we're ready to act on the popped stack element...
        foreach ($pointer as $k => &$v) {
            // printf(
            //     "[D] %d %s\"%s\":%s\n",
            //     $currentDepth,
            //     str_repeat('-', $currentDepth),
            //     $k,
            //     is_array($v) ? '[]' : var_export($v, true)
            // );

            // Analyze the current array-child...
            if (is_array($v)) {
                // Add the discovered child-array to the end of the next-stack.
                $nextStack[] = &$v;
            } else {
                // The child is a non-array element... Send it to the callback!
                // TODO: Give callback key + value ref + array depth
            }
        }

        // If the work-stack is finished, switch to the next (deeper) stack.
        if ($workStackSize <= 0) {
            // NOTE: There's no need to assign to workStack by reference to
            // avoid copy-on-write. Because when we set nextStack to a new
            // value, PHP will realize that workStack is the only instance.
            // In fact, by-ref is slower it also needs an unset($nextStack)
            // call to break its own reference before doing $nextStack = [].
            $workStack = $nextStack;
            $workStackSize = count($workStack);
            $nextStack = [];
            $currentDepth++;
        }
    }
}

// Regular, old-school recursive function calls.
function array_recursive_traverse(
    array &$input,
    $currentDepth = 1)
{
    // Recursion adds 1 level to the function call stack
    // per depth-level of the array:
    // debug_print_backtrace();

    $nextDepth = $currentDepth + 1;
    foreach ($input as $k => &$v) {
        // printf(
        //     "[D] %d %s\"%s\":%s\n",
        //     $currentDepth,
        //     str_repeat('-', $currentDepth),
        //     $k,
        //     is_array($v) ? '[]' : var_export($v, true)
        // );

        if (is_array($v)) {
            array_recursive_traverse($v, $nextDepth);
        }
    }
}

// Build an array data tree.
function generateData(
    $depth)
{
    $data = [];
    $pointer = &$data;
    for ($d = 0; $d < $depth; ++$d) {
        // $subArr = ['x', 'y', ['z'], ['foo'], [['xxyyzzyy']]]; // Harder data.
        $subArr = ['x', 'y', 'z', ['foo'], 'xxyyzzyy'];
        $pointer[] = &$subArr;
        $pointer = &$subArr;
        unset($subArr); // Unlink, otherwise next assignment overwrites pointer.
    }

    return $data;
}

// Run a single test.
function runTest(
    $description,
    $data,
    $algorithm,
    $iterations)
{
    $start = microtime(true);

    switch ($algorithm) {
    case 'array_flat_topdown_traverse':
        for ($i = 0; $i < $iterations; ++$i) {
            array_flat_topdown_traverse($data);
        }
        break;
    case 'array_flat_topdown_traverse2':
        for ($i = 0; $i < $iterations; ++$i) {
            array_flat_topdown_traverse2($data);
        }
        break;
    case 'array_recursive_traverse':
        for ($i = 0; $i < $iterations; ++$i) {
            array_recursive_traverse($data);
        }
        break;
    case 'RecursiveIteratorIterator':
        for ($i = 0; $i < $iterations; ++$i) {
            $iterator = new \RecursiveIteratorIterator(
                new RecursiveArrayOnlyIterator($data),
                \RecursiveIteratorIterator::SELF_FIRST
            );
            // foreach ($iterator as $key => $value) {
            //     // echo "$key => $value\n";
            // }
            // This iteration method takes 15% longer than foreach,
            // but it's the only way to get the depth, which we
            // absolutely need to know in this project.
            for (; $iterator->valid(); $iterator->next()) {
                $key = $iterator->key();
                $value = $iterator->current();
                $depth = $iterator->getDepth();
            }
        }
        break;
    }

    printf(
        "%dx %s %s: %.0f milliseconds.\n",
        $iterations, $description, $algorithm,
        1000 * (microtime(true) - $start)
    );
}

// Run all algorithm tests at once.
function runTestMulti(
    $description,
    $data,
    $iterations,
    $iteratorTestMode) // Time-saver: -1 off, 0 divide by ten, 1 normal
{
    if ($iteratorTestMode > -1) {
        runTest($description, $data, 'RecursiveIteratorIterator',
                $iteratorTestMode > 0 ? $iterations : (int) floor($iterations / 10));
    }
    runTest($description, $data, 'array_flat_topdown_traverse', $iterations);
    runTest($description, $data, 'array_flat_topdown_traverse2', $iterations);
    runTest($description, $data, 'array_recursive_traverse', $iterations);
}

// Special data test-tree for use together with debug-output (uncomment it in
// the algorithms), to verify that each algorithm detects the current depth.
$data = [
    '1one' => [
        '1two' => [
            '1three-nonarr1' => '1',
            '1three'         => [
                '1four-nonarr1' => '2',
                '1four'         => [
                    '1five' => '3',
                ],
            ],
        ],
    ],
    '2one-nonarr1' => null,
    '3one'         => [
        '3two-1' => [
            '3three-nonarr1' => '4',
            '3three-1'       => [
                '3four-1' => [
                    '3five-1' => [
                        '3six-nonarr1' => '5',
                    ],
                ],
            ],
            '3three-nonarr2' => '6',
        ],
        '3two-nonarr1' => '7',
        '3two-2'       => [
            '3three-nonarr3' => '8',
        ],
        '3two-nonarr2' => '9',
    ],
];

// The "RecursiveIteratorIterator" is ~10x slower, so this setting saves time.
// Values: -1 off, 0 divide by ten, 1 normal.
$iteratorTestMode = -1;

// Globally extend/shorten the amount of test iterations, or "1" for no scaling.
$testScale = 1;

// Output PHP version details.
printf("[Running %dx tests on PHP version %s]\n", $testScale, PHP_VERSION);
printf("[RecursiveIteratorIterator Tests: %s]\n", ['Disabled', 'Shortened by /10', 'Enabled'][$iteratorTestMode + 1]);

// Test with normal data (6 levels deep).
runTestMulti('normal-6', generateData(6), $testScale * 500000, $iteratorTestMode);

// Test unusual data (50 levels deep).
runTestMulti('rare-50', generateData(50), $testScale * 100000, $iteratorTestMode);

// Now test with insanely deeply nested data.
runTestMulti('insane-500', generateData(500), $testScale * 10000, $iteratorTestMode);

// Let's do one final test with even more disgustingly deep arrays.
runTestMulti('hellish-5000', generateData(5000), $testScale * 100, $iteratorTestMode);
