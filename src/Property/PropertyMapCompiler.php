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

use LazyJsonMapper\Exception\BadPropertyDefinitionException;
use LazyJsonMapper\Exception\BadPropertyMapException;
use LazyJsonMapper\Exception\CircularPropertyMapException;
use LazyJsonMapper\LazyJsonMapper;
use LazyJsonMapper\Utilities;
use ReflectionClass;
use ReflectionException;

/**
 * Compiles a class property map into object form.
 *
 * @copyright 2017 The LazyJsonMapper Project
 * @license http://www.apache.org/licenses/LICENSE-2.0
 * @author SteveJobzniak (https://github.com/SteveJobzniak)
 */
class PropertyMapCompiler
{
    /**
     * Compiles the JSON property map for a class (incl. class inheritance).
     *
     * Parses the entire chain of own, inherited and imported JSON property maps
     * for the given class, and validates all definitions in the entire map.
     *
     * Each class definition is only resolved and parsed a single time; the
     * first time we encounter it. From then on, it is stored in the cache
     * so that object creations can instantly link to their compiled map.
     *
     * And all maps are built hierarchically, starting with their base class.
     * Every sub-object which extends that object re-uses its parent's compiled
     * PropertyDefinition objects, to reduce the memory requirements even more.
     * The same is true for any imported maps via the import mechanism.
     *
     * @param PropertyMapCache $propertyMapCache The cache to use for storage
     *                                           and lookups. Will be written to.
     * @param string           $solveClassName   The full path of the class to
     *                                           compile, but without any
     *                                           leading "\" global prefix. To
     *                                           save time, we assume the caller
     *                                           has already verified that it is
     *                                           a valid LazyJsonMapper class.
     *
     * @throws BadPropertyDefinitionException If there is a problem with any of
     *                                        the property definitions.
     * @throws BadPropertyMapException        If there is a problem with the
     *                                        map structure itself.
     * @throws CircularPropertyMapException   If there are bad circular map imports
     *                                        within either the hierarchy (parents)
     *                                        or the imports of any part of the
     *                                        class you're trying to compile.
     *                                        This is a serious exception!
     */
    public static function compileClassPropertyMap(
        PropertyMapCache $propertyMapCache,
        $solveClassName)
    {
        // ----------------------------------
        // TODO:
        // Refactor this PropertyMapCompiler. I wrote it as a single function
        // while building the algorithm from scratch, since it was much easier
        // that way because it meant that I always had all variables in the
        // same scope while evolving the code. But it would be great to now
        // split it into various smaller, protected subroutines such as
        // "_importClassMap()" etc, since the algorithm itself is 100% finished.
        // ----------------------------------

        // Sanity-check the classname argument to protect against accidents.
        if (!is_string($solveClassName) || $solveClassName === '') {
            throw new BadPropertyMapException('Argument 1 must be a non-empty string.');
        }

        // There's nothing to do if the class is already in the cache.
        if (isset($propertyMapCache->classMaps[$solveClassName])) {
            return; // Abort.
        }

        // Generate a strict name (with a leading "\") to tell PHP to search for
        // it from the global namespace, in commands where we need that.
        // NOTE: We index the cache by "Foo\Bar" for easy lookups, since that is
        // PHP's internal get_class()-style representation. But we use strict
        // "\Foo\Bar" when we actually interact with a class or throw errors.
        // That way, both PHP and the user understands that it's a global path.
        $strictSolveClassName = Utilities::createStrictClassPath($solveClassName);

        // Let's compile the desired "solve-class", since it wasn't cached.
        // NOTE: This entire algorithm is EXCESSIVELY commented so that everyone
        // will understand it, since it's very complex thanks to its support for
        // inheritance and "multiple inheritance" (imports), which in turn uses
        // advanced anti-circular dependency protection to detect bad imports.

        // Build a list of all classes in the inheritance chain, with the
        // base-level class as the first element, and the solve-class as
        // the last element. (The order is important!)
        $classHierarchy = [];

        try {
            // Begin reflecting the "solve-class". And completely refuse to
            // proceed if the class name we were asked to solve doesn't match
            // its EXACT real name. It's just a nice bonus check for safety,
            // to ensure that our caller will be able to find their expected
            // result in the correct cache key later.
            $reflector = new ReflectionClass($strictSolveClassName);
            if ($solveClassName !== $reflector->getName()) {
                throw new BadPropertyMapException(sprintf(
                    'Unable to compile class "%s" due to mismatched class name parameter value (the real class name is: "%s").',
                    $strictSolveClassName, Utilities::createStrictClassPath($reflector->getName())
                ));
            }

            // Now resolve all classes in its inheritance ("extends") chain.
            do {
                // Store the class in the hierarchy.
                $classHierarchy[$reflector->getName()] = [
                    'reflector'       => $reflector,
                    'namespace'       => $reflector->getNamespaceName(),
                    'strictClassName' => Utilities::createStrictClassPath($reflector->getName()),
                ];

                // Update the reflector variable to point at the next
                // parent class (or false if no more exists).
                $reflector = $reflector->getParentClass();
            } while ($reflector !== false);

            // Reverse the list to fix the order, since we built it "bottom-up".
            $classHierarchy = array_reverse($classHierarchy, true);
        } catch (ReflectionException $e) {
            // This should only be able to fail if the classname was invalid.
            throw new BadPropertyMapException(sprintf(
                'Reflection of class hierarchy failed for class "%s". Reason: "%s".',
                $strictSolveClassName, $e->getMessage()
            ));
        }

        // Analyze the class hierarchy and verify that we don't violate any
        // current anti-circular map locks. And check what we'll need to lock.
        // NOTE: We won't set up our own locks until we're sure that we can
        // proceed with this compile. Otherwise we may partially lock things
        // and then suddenly throw an exception when we see a problem here.
        $ourCompilerClassLocks = [];
        foreach ($classHierarchy as $className => $classInfo) {
            // FATAL: If any part of our class hierarchy is already locked
            // ("is being compiled right now", higher up the call stack),
            // then it's a bad map with circular "import"-statements.
            // NOTE: Specifically, this protects against the scenario of
            // importing classes derived from our hierarchy but whose
            // classname isn't in the current class hierarchy, so they
            // weren't blocked by the import-sanity checks further down.
            // When they get to their own compilation, their class hierarchy
            // is checked here and we discover their circular inheritance.
            if (isset($propertyMapCache->compilerLocks[$className])) {
                // NOTE: $className goes in "arg1" because it's being
                // compiled earlier than us, so we're definitely the "arg2".
                throw new CircularPropertyMapException(
                    $classInfo['strictClassName'],
                    $strictSolveClassName
                );
            }

            // If the class isn't already compiled, we'll lock it so nothing
            // else can refer to it. We'll lock every unresolved class, and
            // then we'll unlock them one by one as we resolve them during
            // THIS exact "compileClassPropertyMap()"-call. No other
            // compilers are allowed to use them while we're resolving them!
            if (!isset($propertyMapCache->classMaps[$className])) {
                $ourCompilerClassLocks[$className] = true;
            }
        }

        // Now lock all of our unresolved classes.
        // NOTE: We now know that NONE of these unresolved classes were in
        // the lock-list before WE added them to it. That means that we can
        // safely clear ANY of those added locks if there's a problem. But
        // WE are NOT allowed to clear ANY OTHER LOCKS!
        foreach ($ourCompilerClassLocks as $className => $x) {
            $propertyMapCache->compilerLocks[$className] = true;
        }

        // Traverse the class hierarchy and compile and merge their property
        // maps, giving precedence to later classes in case of name clashes.
        //
        // And because we build the list top-down through the chain of
        // inheritance (starting at base and going to the deepest child),
        // and thanks to the fact that PHP classes can only extend from 1
        // parent class, it also means that we're actually able to save the
        // per-class lists at every step of the way, for each class we
        // encounter along the way. To avoid needing to process those later.
        //
        // This builds a properly inherited class property map and
        // constructs and validates all property definitions so that we
        // don't get any nasty surprises during data-parsing later.
        //
        // The top-down order also ensures that we re-use inherited
        // PropertyDefinition objects, so that the compiled classes all
        // share memory whenever they inherit from the same parent classes
        // and imports.
        try {
            $currentClassPropertyMap = [];
            $previousConstantValue = null; // Used to detect unique consts.
            foreach ($classHierarchy as $className => $classInfo) {
                // We must always begin by reflecting its "JSON_PROPERTY_MAP"
                // class constant to determine if this particular class in
                // the hierarchy re-defines it to a new value (compared to
                // its "extends"-parent). This just protects against
                // wasting time re-parsing inherited constants with
                // identical values. (Note that we also have protection
                // against "identical property re-definitions" further
                // down, which means re-parsing would be safe but useless.)
                try {
                    $rawClassPropertyMap = $classInfo['reflector']->getConstant('JSON_PROPERTY_MAP');
                    // MAGIC: The "!==" ensures that this constant differs
                    // from its parent. In case of arrays, they are only
                    // treated as identical if both arrays have the same
                    // key & value types and values in the exact same order
                    // and same count(). And it checks recursively.
                    $hasDifferentConstant = ($rawClassPropertyMap !== $previousConstantValue);

                    // --------------------------------------------
                    // TODO: When PHP 7.1 is more commonplace, add a check here
                    // for "\ReflectionClassConstant", and if so instantiate one
                    // and then look at getDeclaringClass() and update
                    // $hasDifferentConstant based on its perfect answer.
                    // - Why? To solve a very minor situation:
                    // namespace A { class A { "foo":"B[]" } class B {} }
                    // namespace Z { class Z extends \A\A { "foo":"B[]" } class B {} }
                    // In that situation, Z would inherit the constant from A,
                    // which compiled property "foo" as "\A\B". And when we look
                    // at Z, we would see a 100% identical JSON_PROPERTY_MAP
                    // constant, so we would assume that since all the fields
                    // and values match, the constant was inherited. Therefore
                    // the constant of Z will not be parsed. And Z's foo will
                    // therefore also link to "\A\B", instead of "\Z\B".
                    // - That situation is extremely rare, because I can't
                    // imagine someone having such a poorly designed inheritance
                    // that they inherit something which refers to a class via a
                    // local relative path, and then re-define that property to
                    // their own local relative path with the exact same name
                    // and no other properties added/changed at all in the whole
                    // map, thus making the maps look identical. It's just weird
                    // on so many levels and unlikely to ever happen.
                    // - However, we cannot solve this until PHP 7.1. Because
                    // simply "always re-compile even if the constant was
                    // inherited" would cause an insanely dangerous bug: All
                    // relative class names would get re-interpreted by each
                    // inheriting class. So no, we cannot simply always
                    // re-compile. We'll instead have to live with this very
                    // minor problem until PHP 7.1 is commonplace.
                    // --------------------------------------------

                    // Update the "previous constant value" since we will
                    // be the new "previous" value after this iteration.
                    $previousConstantValue = $rawClassPropertyMap;
                } catch (ReflectionException $e) {
                    // Unable to read the map constant from the class...
                    throw new BadPropertyMapException(sprintf(
                        'Reflection of JSON_PROPERTY_MAP constant failed for class "%s". Reason: "%s".',
                        $classInfo['strictClassName'], $e->getMessage()
                    ));
                }

                // If we've already parsed that class before, it means that
                // we've already parsed it AND its parents; use the cache.
                if (isset($propertyMapCache->classMaps[$className])) {
                    // Overwrite the whole $currentClassPropertyMap (faster
                    // than merging, and ensures PERFECT PropertyDefinition
                    // instance re-usage).
                    // NOTE: To explain it, imagine class B extending from
                    // A. A has already been parsed and cached. We then
                    // instantiate B for the first time and we need to build
                    // B's definitions. By copying the cache from A (its
                    // parent), we'll re-use all of A's PropertyDefinition
                    // objects in B and throughout any other chain that
                    // derives from the same base object (A). This holds
                    // true for ALL objects that inherit (or "import")
                    // ANYTHING (such as something that later refers to
                    // "B"), since we always resolve the chains top-down
                    // from the base class to the deepest child class, and
                    // therefore cache the base (hierarchically shared)
                    // definitions FIRST.
                    $currentClassPropertyMap = $propertyMapCache->classMaps[$className];
                } else {
                    // We have not parsed/encountered this class before...

                    // Only parse its class constant if it differs from its
                    // inherited parent's constant value.
                    if ($hasDifferentConstant) {
                        // Process and validate the JSON property map of the class.
                        if (!is_array($rawClassPropertyMap)) {
                            throw new BadPropertyMapException(sprintf(
                                'Invalid JSON property map in class "%s". The map must be an array.',
                                $classInfo['strictClassName']
                            ));
                        }
                        foreach ($rawClassPropertyMap as $propName => $propDefStr) {
                            // Validate each property map entry and merge it
                            // with the final map if okay. The key MUST be a
                            // string (we do not allow non-associative
                            // (numeric) array keys), and a string value.
                            if (is_string($propName) && is_string($propDefStr)) {
                                // This entry seems valid. It has an
                                // associative (string) key and a string
                                // value. Compile the definition and append
                                // it to the final compiled map, if the
                                // definition is fully valid.
                                try {
                                    // Validates the definition and throws if bad.
                                    // NOTE: The namespace here is INCREDIBLY
                                    // important. It's what allows each class to
                                    // refer to classes relative to its own
                                    // namespace, rather than needing to type
                                    // the full, global class path. Without that
                                    // parameter, the PropertyDefinition would
                                    // only search in the global namespace.
                                    // NOTE: "use" statements are ignored since
                                    // PHP has no mechanism for inspecting those.
                                    $propDefObj = new PropertyDefinition(
                                        $propDefStr,
                                        $classInfo['namespace']
                                    );

                                    // MEMORY OPTIMIZATION TRICK: If we
                                    // wanted to be "naive", we could simply
                                    // assign this new PropertyDefinition
                                    // directly to our current class
                                    // property map, regardless of whether
                                    // the property-name key already exists.
                                    // It would certainly give us the right
                                    // result... But imagine if one of our
                                    // parent objects or previous imports
                                    // have already created a property as
                                    // "foo":"string[]", and imagine that we
                                    // ALSO define a "foo":"string[]", even
                                    // though we've already inherited that
                                    // exact instruction. What would happen?
                                    //
                                    // Well, if we instantly assign our own,
                                    // newly created object, even though
                                    // it's equal to an identically named
                                    // and identically defined property that
                                    // we've already inherited/imported,
                                    // then we waste RAM since we've now got
                                    // two independent object instances that
                                    // describe the exact same settings.
                                    //
                                    // Therefore, we can save RAM by first
                                    // checking if the property name already
                                    // exists in our currently compiled map;
                                    // and if so, whether its pre-existing
                                    // PropertyDefinition already describes
                                    // the EXACT same settings. If so, we
                                    // can keep the existing object (which
                                    // we KNOW is owned by a parent/import
                                    // and will therefore always remain in
                                    // memory). Thus saving us the RAM-size
                                    // of a PropertyDefinition object. In
                                    // fact, the re-use means the RAM is the
                                    // same as if the sub-class hadn't even
                                    // overwritten the inherited/imported
                                    // property at all! So it makes class
                                    // JSON property re-definitions to
                                    // identical settings a ZERO-cost act.
                                    //
                                    // IMPORTANT: This only works because
                                    // properties are immutable, meaning we
                                    // can trust that the borrowed object
                                    // will remain the same. We are NEVER
                                    // going to allow runtime modifications
                                    // of the compiled maps. So re-use = ok.
                                    if (!isset($currentClassPropertyMap[$propName])
                                        || !$propDefObj->equals($currentClassPropertyMap[$propName])) {
                                        $currentClassPropertyMap[$propName] = $propDefObj;
                                    }
                                } catch (BadPropertyDefinitionException $e) {
                                    // Add details and throw from here instead.
                                    throw new BadPropertyDefinitionException(sprintf(
                                        'Bad property definition for "%s" in class "%s" (Error: "%s").',
                                        $propName, $classInfo['strictClassName'], $e->getMessage()
                                    ));
                                }
                            } else {
                                // This is not a string key -> string value
                                // pair, so let's check for "import class map".
                                $isImportCommand = false;
                                $importClassName = null;
                                $strictImportClassName = null; // With "\" prefix.
                                if (is_int($propName) && is_string($propDefStr)) {
                                    // Potential import... we must first ensure
                                    // that the class has a global "\" prefix.
                                    $importClassName = $propDefStr;
                                    $strictImportClassName = Utilities::createStrictClassPath($importClassName);

                                    // Now check if the target class fits the
                                    // "import class" requirements.
                                    if (class_exists($strictImportClassName)
                                        && is_subclass_of($strictImportClassName, LazyJsonMapper::class)) {
                                        $isImportCommand = true;
                                    }
                                }
                                if (!$isImportCommand) {
                                    // This map-array value is definitely NOT okay.
                                    throw new BadPropertyMapException(sprintf(
                                        'Invalid JSON property map entry "%s" in class "%s".',
                                        $propName, $classInfo['strictClassName']
                                    ));
                                }

                                // This was an "import other class" command!
                                // They exist in the map and tell us to
                                // import other classes, and the instructions
                                // are written as [OtherClass::class,
                                // 'ownfield'=>'string']. Thanks to the lack
                                // of an associative key, we saw a numeric
                                // (non-associative) array key. And we've
                                // verified (above) that its value points to
                                // another valid class which is a sub-class
                                // of LazyJsonMapper (we don't bother
                                // allowing to import LazyJsonMapper itself,
                                // since it is the lowest possible base
                                // class and has 0 values).
                                //
                                // ALSO NOTE: There is no need for us to
                                // prevent the user from doing multiple
                                // imports of the same class, because the
                                // way the importing works ensures that we
                                // always re-use the same PropertyDefinition
                                // object instances from the other class
                                // every time we import the other class.

                                // Begin via reflection to resolve the exact
                                // name of the class they want to import,
                                // so that we can trust its name completely.
                                try {
                                    // Note: The strict, global "\"-name is
                                    // necessary to guarantee that we find the
                                    // correct target class.
                                    $reflector = new ReflectionClass($strictImportClassName);
                                    $importClassName = $reflector->getName();
                                    $strictImportClassName = Utilities::createStrictClassPath($importClassName);
                                } catch (ReflectionException $e) {
                                    // This should never be able to fail,
                                    // but if it does, treat it as a bad map.
                                    throw new BadPropertyMapException(sprintf(
                                        'Reflection failed for class "%s", when trying to import it into class "%s". Reason: "%s".',
                                        $strictImportClassName, $classInfo['strictClassName'], $e->getMessage()
                                    ));
                                }

                                // FATAL: If the import-statement refers to ANY
                                // class in OUR OWN current class' hierarchy,
                                // then it's a circular self-reference. We
                                // forbid those because they make ZERO sense.
                                // For example: "ABCD", in that case, "D" COULD
                                // definitely import "B" because "D" is later
                                // in the chain and "B" is therefore already
                                // resolved. But WHY? We already inherit from
                                // it! And we could NEVER do the reverse; "B"
                                // could never import "D", because "D" depends
                                // on "B" being fully compiled already.
                                // Likewise, we can't allow stupid things
                                // like "B imports B". Doesn't make sense.
                                // Therefore, we disallow ALL self-imports.
                                // NOTE: In the example of "B" importing "D",
                                // that's NOT caught here since the classname
                                // "D" wouldn't be part of B's hierarchy. But it
                                // will be caught during our upcoming attempt to
                                // sub-compile "D" to parse ITS hierarchy, since
                                // "D" will begin to compile, and will arrive
                                // at the "B" class and will then detect that
                                // "B" is locked as "already being resolved",
                                // since the ongoing resolving of "B" was
                                // what triggered the compilation of "D".
                                if (array_key_exists($importClassName, $classHierarchy)) {
                                    // NOTE: $solveClassName goes in "arg1"
                                    // because we're the ones trying to
                                    // import an invalid target class that's
                                    // already part of our own hierarchy.
                                    throw new CircularPropertyMapException(
                                        $strictSolveClassName,
                                        $strictImportClassName
                                    );
                                }

                                // FATAL: Also prevent users from importing any
                                // class that's in the list of "locked and
                                // currently being resolved", since that means
                                // that the class they're trying to import is
                                // ITSELF part of a compilation-chain that's
                                // CURRENTLY trying to resolve itself right now.
                                // NOTE: This catches scenarios where
                                // class-chains have common ancestors but
                                // then diverge and go into a circular
                                // reference to each other. For example, "A
                                // extends LazyJsonMapper, imports B", "B
                                // extends LazyJsonMapper, imports A".
                                // Imagine that you construct "A", which
                                // locks "A" during its compilation process.
                                // The parent "LazyJsonMapper" class is
                                // successfully compiled since it has no
                                // dependencies. Then "A" sees "import B"
                                // and sub-compiles B. (Remember that "A"
                                // remains locked when that happens, since
                                // "A" is waiting for "B" to resolve
                                // itself.) The compilation code of "B"
                                // begins running. It first checks its
                                // inheritance hierarchy and sees nothing
                                // locked (LazyJsonMapper is unlocked
                                // because it's already done, and B is
                                // unlocked because B has not locked itself
                                // yet). Next, "B" sees an "import A"
                                // statement. It succeeds the "block import
                                // of A if it's part of our own hierarchy".
                                // So now we're at a crossroads... there is
                                // a circular reference, but we don't know
                                // it yet. We COULD let it proceed to
                                // "resolve A by sub-compiling the map for
                                // A", which would give us a call-stack of
                                // "compile A -> compile B -> compile
                                // A", but then A would run, and would do
                                // its early lock-check and notice that
                                // itself (A) is locked, since the initial
                                // compilation of "A" is not yet done. It
                                // would then throw a non-sensical error
                                // saying that there's a circular reference
                                // between "A and A", which is technically
                                // true, but makes no sense. That's because
                                // we waited too long. So, what the code
                                // BELOW does instead, is that when "B" is
                                // trying to resolve itself (during the "A
                                // -> import -> compile B" phase), the "B"
                                // parser will come across the "import A"
                                // statement, and will detect that "A" is
                                // locked, and therefore throw a proper
                                // exception now instead of letting "A"
                                // attempt to compile itself a second time
                                // as described above. The result of this
                                // early sanity-check is a better error msg.
                                foreach ($propertyMapCache->compilerLocks as $lockedClassName => $isLocked) {
                                    if ($isLocked && $lockedClassName === $importClassName) {
                                        // NOTE: $solveClassName goes in "arg2"
                                        // because we're trying to import
                                        // something unresolved that's therefore
                                        // higher than us in the call-stack.
                                        throw new CircularPropertyMapException(
                                            $strictImportClassName,
                                            $strictSolveClassName
                                        );
                                    }
                                }

                                // Now check if the target class is missing from
                                // our cache, and if so then we must attempt to
                                // compile (and cache) the map of the target
                                // class that we have been told to import.
                                // NOTE: The compilation itself will detect that
                                // the target class isn't in the compiled cache,
                                // and will perform the necessary compilation
                                // and validation of its WHOLE map. When the
                                // compiler runs, it will ensure that nothing
                                // in the target class hierarchy is currently
                                // locked (that would be a circular reference),
                                // and then it will lock its own hierarchy too
                                // until it has resolved itself. This will go
                                // on recursively if necessary, until all maps
                                // and all imports have been resolved. And if
                                // there are ANY circular reference anywhere,
                                // or ANY other problems with its map/definitions,
                                // then the sub-compilation will throw appropriate
                                // exceptions. We won't catch them; we'll just
                                // let them bubble up to abort the entire chain
                                // of compilation, since a single error anywhere
                                // in the whole chain means that whatever class
                                // initially began this compilation process
                                // _cannot_ be resolved. So we'll let the error
                                // bubble up all the way to the original call.
                                if (!isset($propertyMapCache->classMaps[$importClassName])) {
                                    // NOTE: Throws in case of serious errors!
                                    self::compileClassPropertyMap($propertyMapCache, $importClassName);
                                }

                                // If we've reached this point, it means that
                                // our target class should be in the cache since
                                // its compilation ran successfully (or it was
                                // already cached). Just verify it to be safe.
                                // NOTE: This step should never go wrong.
                                if (!isset($propertyMapCache->classMaps[$importClassName])) {
                                    throw new BadPropertyMapException(sprintf(
                                        'Unable to resolve property map for class "%s", when trying to import it into class "%s".',
                                        $strictImportClassName, $classInfo['strictClassName']
                                    ));
                                }

                                // Now simply loop through the compiled property
                                // map of the imported class... and add every
                                // property to our own compiled property map.
                                // In case of clashes, we use the imported one.
                                // NOTE: We'll directly assign its PD objects
                                // as-is, which will assign them by reference,
                                // so that we re-use the PropertyDefinition
                                // object instances from the compiled class that
                                // we're importing. That will save memory.
                                // NOTE: There's no point doing an equals()
                                // clash-check here, since we're importing an
                                // existing definition from another class, so
                                // we won't save any memory by avoiding their
                                // version even if the definitions are equal.
                                foreach ($propertyMapCache->classMaps[$importClassName] as $importedPropName => $importedPropDefObj) {
                                    $currentClassPropertyMap[$importedPropName] = $importedPropDefObj;
                                }
                            } // End of user map-value data validation.
                        } // End of "JSON_PROPERTY_MAP" array-variable loop.
                    } // End of "class has different constant" code block.

                    // Now cache the final property-map for this particular
                    // class in the inheritance chain... Note that if it had
                    // no map declarations of its own, then we're simply
                    // re-using the exact property-map of its parent here.
                    $propertyMapCache->classMaps[$className] = $currentClassPropertyMap;
                } // End of per-class compilation.

                // IMPORTANT: We've finished processing a class. If this was
                // one of the classes that WE locked, we MUST now unlock it,
                // but ONLY if we successfully processed the class (added it
                // to the cache). Otherwise we'll unlock it at the end where
                // we unlock all failures.
                // NOTE: We AREN'T allowed to unlock classes we didn't lock.
                // NOTE: Unlocking is IMPORTANT, since it's necessary for
                // being able to import classes from diverging branches with
                // shared inheritance ancestors. If we don't release the
                // parent-classes one by one as we resolve them, then we
                // would never be able to import classes from other branches
                // since they would see that one of their parents is still
                // locked and would refuse to compile themselves.
                if (isset($ourCompilerClassLocks[$className])) {
                    unset($ourCompilerClassLocks[$className]);
                    unset($propertyMapCache->compilerLocks[$className]);
                }
            } // End of class hierarchy loop.
        } finally {
            // IMPORTANT: If uncaught exceptions were thrown during
            // processing, then we must simply unlock all of OUR remaining
            // locks before we allow the exception to keep bubbling upwards.
            // NOTE: We AREN'T allowed to unlock classes we didn't lock.
            foreach ($ourCompilerClassLocks as $lockedClassName => $x) {
                unset($ourCompilerClassLocks[$lockedClassName]);
                unset($propertyMapCache->compilerLocks[$lockedClassName]);
            }
        } // End of try-finally.
    }
}
