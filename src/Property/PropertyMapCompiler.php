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
    /** @var bool Whether this is the root-level compiler or a sub-compiler. */
    private $_isRootCompiler;

    /** @var PropertyMapCache The cache used by root and all sub-compilers. */
    private $_propertyMapCache;

    /** @var string Path of the solve-class, without leading backslash. */
    private $_solveClassName;

    /** @var string Strict global path (with leading backslash) to solve-class. */
    private $_strictSolveClassName;

    /** @var array All classes that were compiled by us or our sub-compilers. */
    public $compiledClasses; // Public to allow parent-access.

    /** @var array Uncompiled classes seen in properties by us or our sub-compilers. */
    public $uncompiledPropertyClasses; // Public to allow parent-access.

    /** @var array The reflected class hierarchy for the solve-class. */
    private $_classHierarchy;

    /** @var array Classes that WE have locked during hierarchy compilation. */
    private $_ourCompilerClassLocks;

    /** @var mixed Used by old PHP (before 7.1) for detecting unique (non-inherited) class constants. */
    private $_previousMapConstantValue;

    /** @var array The current class we're processing in the hierarchy. */
    private $_currentClassInfo;

    /** @var array Holds current class' property map as it's being built. */
    private $_currentClassPropertyMap;

    /**
     * Compiles the JSON property map for a class (incl. class inheritance).
     *
     * Parses the entire chain of own, inherited and imported JSON property maps
     * for the given class, and validates all definitions in the entire map.
     *
     * Each class definition is only resolved and parsed a single time; the
     * first time we encounter it. From then on, it is stored in the cache so
     * that object creations can instantly link to their compiled map. And so
     * any other classes which rely on that class can instantly re-use its map.
     *
     * And all maps are built hierarchically, starting with their base class.
     * Every sub-object which extends that object re-uses its parent's compiled
     * PropertyDefinition objects, to reduce the memory requirements even more.
     * The same is true for any imported maps via the import mechanism.
     *
     * As an additional layer of protection, ALL properties (in the current
     * class and its extends/import-hierarchy) that point to uncompiled classes
     * will ALSO be verified and compiled, to ensure that THEIR class property
     * maps compile successfully. And it will happen recursively until all
     * linked classes (inheritance, imports and property links) have been fully
     * compiled throughout the entire linked hierarchy. It ensures that users
     * will be able to FULLY trust that EVERY property in their class and its
     * ENTIRE tree of related classes are pointing at classes that have been
     * successfully compiled and are known to work and are ready for reliable
     * use at runtime.
     *
     * That extra protection does however also mean that your runtime's initial
     * compile-calls will take the longest, since they'll spider-crawl the
     * ENTIRE hierarchy of extends, imports, and ALL properties of each, and
     * then ALL extends and imports and properties of the classes pointed to by
     * those properties, and so on... But despite all of that extra work, it's
     * STILL blazingly FAST at compiling, and the extra security is well worth
     * it! And as soon as all relevant maps are compiled during your current
     * runtime, this function won't have to do any work anymore! Then you can
     * just relax and fully trust that all of your class maps are perfect! :-)
     *
     * If there are ANY compilation problems, then an automatic rollback takes
     * place which restores the `PropertyMapCache` to its original pre-call
     * state.
     *
     * This compiler algorithm provides perfect peace of mind. If it doesn't
     * throw any errors, it means that your entire class hierarchy has been
     * compiled and is ready for use in your cache!
     *
     * @param PropertyMapCache $propertyMapCache The cache to use for storage
     *                                           and lookups. Will be written to.
     * @param string           $solveClassName   The full path of the class to
     *                                           compile, but without any
     *                                           leading `\` global prefix. To
     *                                           save time, we assume the caller
     *                                           has already verified that it is
     *                                           a valid `LazyJsonMapper` class.
     *
     * @throws BadPropertyDefinitionException If there is a problem with any of
     *                                        the property definitions.
     * @throws BadPropertyMapException        If there is a problem with the
     *                                        map structure itself.
     * @throws CircularPropertyMapException   If there are bad circular map
     *                                        imports within either the class
     *                                        hierarchy (parents) or the imports
     *                                        of any part of the class you're
     *                                        trying to compile. This is a
     *                                        serious exception!
     */
    public static function compileClassPropertyMap(
        PropertyMapCache $propertyMapCache,
        $solveClassName)
    {
        $rootCompiler = new self( // Throws.
            true, // This is the ROOT compilation call!
            $propertyMapCache,
            $solveClassName
        );
        $rootCompiler->compile(); // Throws.
    }

    /**
     * Private Constructor.
     *
     * @param bool             $isRootCompiler   Whether this is the root-level
     *                                           compiler in a compile-stack.
     * @param PropertyMapCache $propertyMapCache The class/lock cache to use.
     * @param string           $solveClassName   The full path of the class to
     *                                           compile, but without any
     *                                           leading `\` global prefix. To
     *                                           save time, we assume the caller
     *                                           has already verified that it is
     *                                           a valid `LazyJsonMapper` class.
     *
     * @throws BadPropertyMapException
     */
    private function __construct(
        $isRootCompiler,
        PropertyMapCache $propertyMapCache,
        $solveClassName)
    {
        // Sanity-check the untyped arguments to protect against accidents.
        if (!is_bool($isRootCompiler)) {
            throw new BadPropertyMapException('The isRootCompiler argument must be a boolean.');
        }
        if (!is_string($solveClassName) || $solveClassName === '') {
            throw new BadPropertyMapException('The class name to solve must be a non-empty string.');
        }

        // Save user-properties.
        // NOTE: The compiler uses a concept of "root compiler" and recursive
        // "sub-compilers". The compilers automatically spawn sub-compilers for
        // any resources they need. And the ROOT compiler is responsible for
        // coordinating everything and doing any final post-processing work
        // AFTER the main hierarchy (extends) and "import" compilation steps.
        $this->_isRootCompiler = $isRootCompiler;
        $this->_propertyMapCache = $propertyMapCache;
        $this->_solveClassName = $solveClassName;

        // Generate a strict name (with a leading "\") to tell PHP to search for
        // it from the global namespace, in commands where we need that.
        // NOTE: We index the cache by "Foo\Bar" for easy lookups, since that is
        // PHP's internal get_class()-style representation. But we use strict
        // "\Foo\Bar" when we actually interact with a class or throw errors.
        // That way, both PHP and the user understands that it's a global path.
        $this->_strictSolveClassName = Utilities::createStrictClassPath($this->_solveClassName);

        // We will keep track of ALL classes compiled by ourselves and our
        // recursive sub-compilers. In case of errors ANYWHERE in the process,
        // we MUST ensure that all of those classes are erased from the cache
        // again, since they may have serious errors. This list of compiled
        // classes was specifically added to handle the fact that the class map
        // and its hierarchy/imports themselves often fully compile themselves
        // successfully, but then THEIR property class compilations (the root-
        // POST-PROCESSING step which compiles all classes pointed to by the
        // maps) MAY fail. In that case, or if there are ANY other compilation
        // problems in any of the class hierarchies, then we will be sure to
        // erase ALL of our compiled classes from the cache; otherwise it WOULD
        // look like those classes are fully compiled and reliable, despite the
        // bad and invalid classes some of their properties point to!
        $this->compiledClasses = [];

        // During the main compilation phase, we'll ONLY compile the actual
        // class that we've been told to compile, as well as its parent
        // hierarchy and any imports that any of them contain. But any of those
        // compiled maps MAY in turn contain PROPERTIES that point at OTHER
        // uncompiled classes. The PropertyDefinition construction will always
        // verify that those properties are pointing at reachable (having a
        // valid class-path) LazyJsonMapper classes. However, the actual CLASS
        // MAPS of THOSE classes may contain problems too and may be impossible
        // to compile. We obviously want to figure that out for the user RIGHT
        // NOW so that they don't run into issues later when trying to access
        // such a property someday (which would THEN trigger its map compilation
        // and throw a compiler failure deep into their runtime environment when
        // they least expected it). The truth is that normal people will
        // sometimes write bad classes, and then point properties at those bad
        // classes. And without us proactively pre-compiling all classes pointed
        // to by their properties, they would NEVER know about the problem until
        // someday later when their program suddenly accesses that bad property
        //
        // So we SHOULD analyze everything for the user's safety. But... we
        // unfortunately CANNOT compile them during the main compilation stage.
        // In fact, we cannot even compile them afterwards EITHER, UNLESS we are
        // the ABSOLUTE ROOT COMPILE-CALL which STARTED the current compilation
        // process. Only the root function call is allowed to resolve PROPERTY
        // references to other classes and ensure that THOSE maps are
        // successfully are compiled too. Otherwise we'd run into circular
        // compilation issues where properties fail to compile because they may
        // be pointing at things which are not compiled yet (such as a class
        // containing a property that points at itself, or "A" having a property
        // that points to class "B" which has a property that points to class
        // "C" which has a property that points back at class "A" and therefore
        // would cause a circular reference if we'd tried to compile those
        // property-classes instantly as-they're encountered). We must therefore
        // defer property-class compilation until ALL core classes in the
        // current inheritance/import chain are resolved. THEN it's safe to
        // begin compiling any other encountered classes from the properties...
        //
        // The solution is "simple": Build a list of the class-paths of all
        // encountered UNCOMPILED property classes within our own map tree and
        // within any imports (which are done via recursive compile-calls). To
        // handle imports, we simply ensure that our compile-function (this one)
        // lets the parent retrieve our list of "encountered uncompiled classes
        // from properties" so that we'll let it bubble up all the way to the
        // root class/call that began the whole compile-chain. And then just let
        // that root-level call resolve ALL uncompiled properties from the
        // ENTIRE tree by simply compiling the list of property-classes one by
        // one if they're still missing. That way, we'll get full protection
        // against uncompilable class-properties ANYWHERE in our tree, and we'll
        // do it at a totally safe time (at the end of the main compilation call
        // that kicked everything off)!
        //
        // NOTE: As an optimization, to avoid constant array-writes and the
        // creation of huge and rapidly growing "encountered classes" arrays, we
        // will attempt to ONLY track the encountered classes that are MISSING
        // from the cache (not yet compiled). And this list will ALSO be checked
        // one more time at the end, to truly clear everything that's already
        // done. That's intended to make the list as light-weight as possible
        // so that our parent-compiler doesn't have to do heavy lifting.
        //
        // NOTE: The fact is that most classes that properties point to will
        // already be pre-compiled, or at least large parts of their
        // inheritance/import hierarchy will already be compiled, so this
        // recursive "property-class" compilation isn't very heavy at all. It's
        // as optimized as it can be. Classes we encounter will ONLY compile the
        // specific aspects of their class maps which HAVEN'T been compiled yet,
        // such as a class that is a sub-tree (extends) of an already-compiled
        // class, which means that the extra class would be very fast to
        // compile. Either way, we basically spend a tiny bit of extra effort
        // here during compilation to save users from a TON of pain from THEIR
        // bad classes later. ;-)
        $this->uncompiledPropertyClasses = [];

        // Initialize the remaining class properties.
        $this->_classHierarchy = [];
        $this->_ourCompilerClassLocks = [];
        $this->_previousMapConstantValue = null;
        $this->_currentClassInfo = null;
        $this->_currentClassPropertyMap = [];
    }

    /**
     * Compile the solve-class, and its parent/import hierarchy (as-necessary).
     *
     * Intelligently watches for compilation errors, and if so it performs an
     * auto-rollback of ALL classes compiled by this `compile()`-call AND by all
     * of its recursive sub-compilations (if any took place).
     *
     * It performs the rollback if there were ANY issues with the solve-class or
     * ANY part of its inheritance hierarchy (extends/imports) OR with the final
     * post-processing compilation of all of the classes that THEIR properties
     * were pointing at. And note that the post-processing is ALSO recursive and
     * will FULLY validate the entire hierarchies and property trees of ALL
     * classes that IT compiles, and so on... until no more work remains.
     *
     * So if this function DOESN'T throw, then you can TRUST that the ENTIRE
     * hierarchy of property maps related to the requested solve-class has been
     * compiled. However, note that their final PROPERTY CLASS compilation step
     * and final success-validation doesn't happen until the top-level
     * rootCompiler is reached again, since it's the job of the root to handle
     * the recursive compilation and resolving of all classes encountered in
     * properties. So it's only the rootCompiler's `compile()`-call that TRULY
     * matters and will determine whether EVERYTHING was successful or not.
     *
     * Basically: If we fail ANY aspect of the request to compile, then we'll
     * FULLY roll back everything that we've changed during our processing.
     * Which safely guarantees that the FINAL map compilation cache ONLY
     * contains fully verified and trustable classes and their COMPLETE class
     * inheritance and property hierarchies!
     *
     * @throws BadPropertyDefinitionException
     * @throws BadPropertyMapException
     * @throws CircularPropertyMapException
     *
     * @see PropertyMapCompiler::compileClassPropertyMap() The public, static
     *                                                     class entry point.
     * @see PropertyMapCompiler::_compile()                The internal compiler
     *                                                     core.
     */
    public function compile()
    {
        try {
            $this->_compile();
        } catch (\Exception $e) { // NOTE: Could target exact type, but meh.
            // Our compilation or one of its sub-compilations has failed... We
            // MUST now perform a rollback and unset everything in our list of
            // compiled classes (which also includes everything that our
            // sub-compilers have compiled).
            // NOTE: Every compile()-call is responsible for unsetting ITS OWN
            // list of (sub-)compiled classes. Because the parent-handler that
            // reads our "compiledClasses" property may not run when an
            // exception happens. So it's OUR job to clear OUR changes to the
            // cache before we let the exception bubble up the call-stack.
            foreach ($this->compiledClasses as $className => $x) {
                unset($this->_propertyMapCache->classMaps[$className]);
                unset($this->compiledClasses[$className]);
            }

            throw $e; // Re-throw. (Keeps its stack trace & line number.)
        }
    }

    /**
     * The real, internal compiler algorithm entry point.
     *
     * MUST be wrapped in another function which handles compilation failures!
     *
     * @throws BadPropertyDefinitionException
     * @throws BadPropertyMapException
     * @throws CircularPropertyMapException
     *
     * @see PropertyMapCompiler::compile() The wrapper entry point.
     */
    private function _compile()
    {
        // There's nothing to do if the class is already in the cache.
        if (isset($this->_propertyMapCache->classMaps[$this->_solveClassName])) {
            return; // Abort.
        }

        // Let's compile the desired "solve-class", since it wasn't cached.
        // NOTE: This entire algorithm is EXCESSIVELY commented so that everyone
        // will understand it, since it's very complex thanks to its support for
        // inheritance and "multiple inheritance" (imports), which in turn uses
        // advanced anti-circular dependency protection to detect bad imports.
        // As well as its automatic end-of-run compilation of any encountered,
        // current uncompiled classes from any properties in the tree.

        // Prepare the class hierarchy for compilation.
        $this->_reflectSolveClassHierarchy(); // Throws.
        $this->_checkCurrentLocks(); // Throws.
        $this->_lockUncompiledClasses();

        // Traverse the class hierarchy and compile and merge their property
        // maps, giving precedence to later classes in case of name clashes.
        //
        // And because we build the list top-down through the chain of class
        // inheritance (starting at base and going to the deepest child), and
        // thanks to the fact that PHP classes can only extend from 1 parent
        // class, it also means that we're actually able to save the per-class
        // lists at every step of the way, for each class we encounter along the
        // way. To avoid needing to process those later.
        //
        // This builds a properly inherited class property map and constructs
        // and validates all property definitions so that we don't get any nasty
        // surprises during data-parsing later.
        //
        // The top-down order also ensures that we re-use our parent's inherited
        // PropertyDefinition objects, so that the compiled classes all share
        // memory whenever they inherit from the same parent classes & imports.
        try {
            foreach ($this->_classHierarchy as $classInfo) {
                $this->_currentClassInfo = $classInfo;
                $this->_processCurrentClass(); // Throws.
            }
        } finally {
            // IMPORTANT: If we've compiled all classes, or if uncaught
            // exceptions were thrown during processing, then we must simply
            // ensure that we unlock all of OUR remaining locks before we allow
            // the exception to keep bubbling upwards OR processing to continue.
            // NOTE: We AREN'T allowed to unlock classes we didn't lock.
            foreach ($this->_ourCompilerClassLocks as $lockedClassName => $x) {
                unset($this->_ourCompilerClassLocks[$lockedClassName]);
                unset($this->_propertyMapCache->compilerLocks[$lockedClassName]);
            }
        }

        // If we've reached this point, it means that our solve-class SHOULD be
        // in the cache since its entire compilation ran successfully above.
        // Just verify it to be extra safe against any random future mistakes.
        // NOTE: This step should never go wrong.
        if (!isset($this->_propertyMapCache->classMaps[$this->_solveClassName])) {
            throw new BadPropertyMapException(sprintf(
                'Error while compiling class "%s". Could not find the class in the cache afterwards.',
                $this->_strictSolveClassName
            ));
        }

        // If we came this far, it means that NOTHING above threw, which means
        // that our class and its hierarchy of inheritance (and map-imports), as
        // well as all PropertyDefinitions, have succeeded for the whole tree...
        //
        // Now the only remaining question is whether it's safe for us to check
        // the list of encountered classes in properties and ensure that those
        // can all be compiled too... And the answer is NO, unless we are the
        // absolute ROOT CALL which began the whole compilation process. If not,
        // then we should merely finish cleaning up our list of encountered
        // "uncompiled" classes and then let our parent-caller deal with it.

        // If we are a subcall (sub-compilation within a main compilation),
        // simply let our parent compiler bubble our data to the root call...
        if (!$this->_isRootCompiler) {
            // Some already-processed classes may have slipped onto the list due
            // to being encountered as properties before those classes became
            // compiled. So before returning to the parent, we'll just ensure
            // that we remove all keys that refer to classes that have already
            // been compiled. That saves RAM and processing power during the
            // array merging in the parent. Basically, we want this list to
            // always be as short as possible so there's less need for any HUGE
            // array manipulation at a higher stage while it's bubbling up.
            foreach ($this->uncompiledPropertyClasses as $uncompiledClassName => $x) {
                if (isset($this->_propertyMapCache->classMaps[$uncompiledClassName])) {
                    unset($this->uncompiledPropertyClasses[$uncompiledClassName]);
                }
            }

            return; // Skip the rest of the code.
        }

        // We're the root call! So it is now safe (and IMPORTANT!) to compile
        // all of the not-yet-compiled classes from the inheritance tree, to
        // ensure that EVERY part of the tree is ready as far as classmaps go.
        // NOTE: We have no information about how deeply the encountered classes
        // existed. But it doesn't matter, since they may exist in multiple
        // places. If there's a problem we'll simply say that "the class (this
        // initial root/non-subcall one) or one of its parents or imports has a
        // property which is linked to bad class X". Especially since there may
        // also be problems within properties of THESE classes that we're going
        // to compile. So trying to pinpoint exactly which class had the bad
        // reference to an uncompilable class is overkill. We'll just show the
        // uncompilable class name plus its regular high-quality compilation
        // error message which describes why that class failed to compile.
        // The user should simply fix their code in THAT particular class.
        while (!empty($this->uncompiledPropertyClasses)) {
            // IMPORTANT: Create a COPY-ON-WRITE version of the current contents
            // of the "uncompiledPropertyClasses" array. Because that array will
            // be changing whenever we sub-compile, so we'll use a stable COPY.
            $workQueue = $this->uncompiledPropertyClasses;

            // Process all entries (classes to compile) in the current queue.
            foreach ($workQueue as $uncompiledClassName => $x) {
                // Skip this class if it's already been successfully compiled.
                if (isset($this->_propertyMapCache->classMaps[$uncompiledClassName])) {
                    unset($this->uncompiledPropertyClasses[$uncompiledClassName]);
                    continue;
                }

                // Attempt to compile the missing class. We do this one by one
                // in isolation, so that each extra class we compile gets its
                // entirely own competition-free sub-compiler with its own
                // class-locks (since our own top-level rootCompiler's hierarchy
                // is already fully compiled by this point). Which means that
                // the sub-classes are welcome to refer to anything we've
                // already compiled, exactly as if each of these extra classes
                // were new top-level compiles "running in isolation". The only
                // difference is that they aren't marked as the root compiler,
                // since we still want to be the one to resolve all of THEIR
                // uncompiled property-classes here during THIS loop. Mainly so
                // that we can be the one to throw exception messages, referring
                // to the correct root-level class as the one that failed.
                try {
                    // NOTE: If this subcompile is successful and encounters any
                    // more uncompiled property-classes within the classes it
                    // compiles, they'll be automatically added to OUR OWN list
                    // of "uncompiledPropertyClasses" and WILL be resolved too.
                    //
                    // This will carry on until ALL of the linked classes are
                    // compiled and no more work remains. In other words, we
                    // will resolve the COMPLETE hierarchies of every linked
                    // class and ALL of their properties too. However, this
                    // subcompilation is still very fast since most end-stage
                    // jobs refer to classes that are already mostly-compiled
                    // due to having most of their own dependencies already
                    // pre-compiled at that point.
                    $this->_subcompile($uncompiledClassName); // Throws.
                } catch (\Exception $e) { // NOTE: Could target exact type, but meh.
                    // Failed to compile the class we discovered in a property.
                    // It can be due to all kinds of problems, such as circular
                    // property maps or bad imports or bad definitions or
                    // corrupt map variables or anything else. We'll wrap the
                    // error message in a slightly prefixed message just to hint
                    // that this problem is with a property. Because the
                    // compilation error message itself is already very clear
                    // about which class failed to compile and why.
                    // NOTE: This "prefixed" message handling is unlike parents
                    // and imports, where we simply let their original exception
                    // message bubble up as if they were part of the core class,
                    // since imports are a more "integral" part of a class (a
                    // class literally CANNOT be built without its parents and
                    // its imports compiling). But CLASSES IN PROPERTIES are
                    // different and are more numerous and are capable of being
                    // resolved by _getProperty() LATER without having been
                    // pre-compiled when the main class map itself was compiled
                    // (although we just DID that pre-compilation above; we'll
                    // NEVER let them wait to get resolved later).
                    // NOTE: This WILL cause us to lose the specific class-type
                    // of the exception, such as CircularPropertyMapException,
                    // etc. That's intentional, since THIS error is about a bad
                    // map in the PARENT-hierarchy (IT pointing at a bad class).
                    throw new BadPropertyMapException(sprintf(
                        'Compilation of sub-property hierarchy failed for class "%s". Reason: %s',
                        $this->_strictSolveClassName, $e->getMessage()
                    ));
                } // End of _subcompile() try-catch.

                // The sub-compile was successful (nothing was thrown), which
                // means that it's now in the compiled property map cache. Let's
                // unset its entry from our list of uncompiled classes.
                unset($this->uncompiledPropertyClasses[$uncompiledClassName]);
            } // End of work-queue loop.
        } // End of "handle uncompiled property classes" loop.
    }

    /**
     * Reflect all classes in the solve-class hierarchy.
     *
     * Builds a list of all classes in the inheritance chain, with the
     * base-level class as the first element, and the solve-class as the
     * last element. (The order is important!)
     *
     * @throws BadPropertyMapException
     */
    private function _reflectSolveClassHierarchy()
    {
        if (!empty($this->_classHierarchy)) {
            throw new BadPropertyMapException('Detected multiple calls to _reflectSolveClassHierarchy().');
        }

        try {
            // Begin reflecting the "solve-class". And completely refuse to
            // proceed if the class name we were asked to solve doesn't match
            // its EXACT real name. It's just a nice bonus check for safety,
            // to ensure that our caller will be able to find their expected
            // result in the correct cache key later.
            $reflector = new ReflectionClass($this->_strictSolveClassName);
            if ($this->_solveClassName !== $reflector->getName()) {
                throw new BadPropertyMapException(sprintf(
                    'Unable to compile class "%s" due to mismatched class name parameter value (the real class name is: "%s").',
                    $this->_strictSolveClassName, Utilities::createStrictClassPath($reflector->getName())
                ));
            }

            // Now resolve all classes in its inheritance ("extends") chain.
            do {
                // Store the class in the hierarchy.
                // NOTE: The key is VERY important because it's used later when
                // checking if a specific class exists in the current hierarchy.
                $this->_classHierarchy[$reflector->getName()] = [
                    'reflector'       => $reflector,
                    'namespace'       => $reflector->getNamespaceName(),
                    'className'       => $reflector->getName(), // Includes namespace.
                    'strictClassName' => Utilities::createStrictClassPath($reflector->getName()),
                ];

                // Update the reflector variable to point at the next parent
                // class in its hierarchy (or false if no more exists).
                $reflector = $reflector->getParentClass();
            } while ($reflector !== false);

            // Reverse the list to fix the order, since we built it "bottom-up".
            $this->_classHierarchy = array_reverse($this->_classHierarchy, true);
        } catch (ReflectionException $e) {
            // This should only be able to fail if the classname was invalid.
            throw new BadPropertyMapException(sprintf(
                'Reflection of class hierarchy failed for class "%s". Reason: "%s".',
                $this->_strictSolveClassName, $e->getMessage()
            ));
        }
    }

    /**
     * Check for already-locked classes in the solve-class hierarchy.
     *
     * Analyzes the solve-class hierarchy and verifies that we don't violate
     * any current anti-circular map locks.
     *
     * @throws CircularPropertyMapException
     */
    private function _checkCurrentLocks()
    {
        foreach ($this->_classHierarchy as $classInfo) {
            // FATAL: If any part of our class hierarchy is already locked ("is
            // being compiled right now", higher up the call stack), then it's a
            // bad map with circular "import"-statements.
            // NOTE: This specifically protects against the dangerous scenario
            // of importing classes derived from our hierarchy but whose
            // classname isn't in the current class hierarchy, so they weren't
            // blocked by the "import-target sanity checks". So when they get
            // to their own sub-compilation, their class hierarchy is checked
            // here and we'll discover their circular inheritance.
            if (isset($this->_propertyMapCache->compilerLocks[$classInfo['className']])) {
                // NOTE: strictClassName goes in "arg1" because it's being
                // compiled earlier than us, so we're definitely the "arg2".
                throw new CircularPropertyMapException(
                    $classInfo['strictClassName'],
                    $this->_strictSolveClassName
                );
            }
        }
    }

    /**
     * Lock all uncompiled classes in the solve-class hierarchy.
     *
     * @throws BadPropertyMapException
     */
    private function _lockUncompiledClasses()
    {
        if (!empty($this->_ourCompilerClassLocks)) {
            throw new BadPropertyMapException('Detected multiple calls to _lockUncompiledClasses().');
        }

        foreach ($this->_classHierarchy as $classInfo) {
            // If the class isn't already compiled, we'll lock it so nothing
            // else can refer to it. We'll lock every unresolved class, and then
            // we'll be unlocking them one by one as we resolve them during THIS
            // exact compile-call. No other sub-compilers are allowed to use
            // them while we're resolving them!
            if (!isset($this->_propertyMapCache->classMaps[$classInfo['className']])) {
                // NOTE: We now know that NONE of these unresolved classes were
                // in the lock-list before WE added them to it. That means that
                // we can safely clear ANY of those added locks if there's a
                // problem. But WE are NOT allowed to clear ANY OTHER LOCKS!
                $this->_ourCompilerClassLocks[$classInfo['className']] = true;
                $this->_propertyMapCache->compilerLocks[$classInfo['className']] = true;
            }
        }
    }

    /**
     * Process the current class in the solve-class hierarchy.
     *
     * @throws BadPropertyDefinitionException
     * @throws BadPropertyMapException
     * @throws CircularPropertyMapException
     */
    private function _processCurrentClass()
    {
        // We must always begin by reflecting its "JSON_PROPERTY_MAP" class
        // constant to determine if this particular class in the hierarchy
        // re-defines its value (compared to inheriting it from its "extends"
        // parent). This protects against accidentally re-parsing inherited
        // constants, which would both waste time AND would be VERY dangerous,
        // since that would re-interpret all inherited relative properties (ones
        // pointing at classes relative to the class which ACTUALLY declared
        // that constant) as if they were relative to the INHERITING class
        // instead, which is VERY wrong (and could lead to classes being either
        // mismapped or "not found"). Luckily the code below fully protects us
        // against that scenario, by detecting if our value is "ours" or not.
        try {
            // Use different techniques based on what their PHP supports.
            $foundConstant = false;
            if (version_compare(PHP_VERSION, '7.1.0') >= 0) {
                // In PHP 7.1.0 and higher, they've finally added "reflection
                // constants" which allow us to get accurate extra information.
                $reflectionConstant = $this->_currentClassInfo['reflector']
                                    ->getReflectionConstant('JSON_PROPERTY_MAP');
                if ($reflectionConstant !== false) {
                    $foundConstant = true;

                    // Just read its value. We don't have to care about its
                    // isPrivate() flags etc. It lets us read the value anyway.
                    $rawClassPropertyMap = $reflectionConstant->getValue();

                    // Use PHP7.1's ReflectionClassConstant's ability to tell us
                    // EXACTLY which class declared the current (inherited or
                    // new) value for the constant. If OUR class didn't declare
                    // it, then we know that it was inherited and should NOT be
                    // parsed again. But if we DID declare its value, then we
                    // know that we MUST parse it ("has different constant").
                    // NOTE: This method is 100% accurate even when re-declared
                    // to the exact same value as the inherited (parent) value!
                    $hasDifferentConstant = ($reflectionConstant
                                             ->getDeclaringClass()->getName()
                                             === $this->_currentClassInfo['className']);
                }
            } else {
                // In older PHP versions, we're pretty limited... we don't get
                // ANY extra information about the constants. We just get their
                // values. And if we try to query a specific constant, we get
                // FALSE if it doesn't exist (which is indistinguishable from it
                // actually having FALSE values). So we MUST get an array of all
                // constants to be able to check whether it TRULY exists or not.
                $classConstants = $this->_currentClassInfo['reflector']
                                ->getConstants();
                if (array_key_exists('JSON_PROPERTY_MAP', $classConstants)) {
                    $foundConstant = true;

                    // Read its value. Unfortunately old versions of PHP don't
                    // give us ANY information about which class declared it
                    // (meaning whether it was inherited or re-declared here).
                    $rawClassPropertyMap = $classConstants['JSON_PROPERTY_MAP'];

                    // MAGIC: This is the best we can do on old PHP versions...
                    // The "!==" ensures that this constant really differs from
                    // its parent. In case of arrays, they are only treated as
                    // identical if both arrays have the same key & value types
                    // and values in the exact same order and same count(). And
                    // it checks recursively!
                    //
                    // NOTE: This method is great, but it's not as accurate as
                    // the perfect PHP 7.1 method. It actually has a VERY TINY
                    // issue where it will fail to detect a new constant: If you
                    // manually re-declare a constant to EXACTLY the same value
                    // as what you would have inherited from your parent
                    // ("extends") hierarchy, then we won't be able to detect
                    // that you have a new value. Instead, you will inherit the
                    // compiled parent class map as if you hadn't declared any
                    // value of your own at all. In almost all cases, that won't
                    // matter, and will give you the same result. It only
                    // matters in a very tiny case which probably won't happen
                    // to anybody in real-world usage:
                    //
                    // namespace A { class A { "foo":"B[]" } class B {} }
                    // namespace Z { class Z extends \A\A { "foo":"B[]" } class B {} }
                    //
                    // In that situation, Z\Z would inherit the constant from
                    // A\A, which compiled the relative-class "foo" property as
                    // "\A\B". And when we look at Z's own constant, we would
                    // see a 100% identical JSON_PROPERTY_MAP constant compared
                    // to A\A, so we would assume that since all the array keys
                    // and values match exactly, its constant "was inherited".
                    // Therefore the "identical" constant of Z will not be
                    // parsed. And Z's foo will therefore also link to "\A\B",
                    // instead of "\Z\B".
                    //
                    // In reality, I doubt that ANY user would EVER have such a
                    // weird inheritance structure, with "extends" across
                    // namespaces and relative paths to classes that exist in
                    // both namespaces, etc. And the problem above would not be
                    // able to happen as long as their "Z\Z" map has declared
                    // ANY other value too (or even just changed the order of
                    // the declarations), so that we detect that their constant
                    // differs in "Z\Z". So 99.9999% of users will be safe.
                    //
                    // And no... we CAN'T simply always re-interpret it, because
                    // then we'd get a FAR more dangerous bug, which means that
                    // ALL relative properties would re-compile every time they
                    // are inherited, "as if they had been declared by us".
                    //
                    // So no, this method really is the BEST that PHP < 7.1 has.
                    $hasDifferentConstant = ($rawClassPropertyMap
                                             !== $this->_previousMapConstantValue);

                    // Update the "previous constant value" since we will be the
                    // new "previous" value after this iteration.
                    $this->_previousMapConstantValue = $rawClassPropertyMap;
                }
            }
            if (!$foundConstant) {
                // The constant doesn't exist. Should never be able to happen
                // since the class inherits from LazyJsonMapper.
                throw new ReflectionException(
                    // NOTE: This exception message mimics PHP's own reflection
                    // error message style.
                    'Constant JSON_PROPERTY_MAP does not exist'
                );
            }
        } catch (ReflectionException $e) {
            // Unable to read the map constant from the class...
            throw new BadPropertyMapException(sprintf(
                'Reflection of JSON_PROPERTY_MAP constant failed for class "%s". Reason: "%s".',
                $this->_currentClassInfo['strictClassName'], $e->getMessage()
            ));
        }

        // If we've already parsed that class before, it means that we've
        // already fully parsed it AND its parents. If so, just use the cache.
        if (isset($this->_propertyMapCache->classMaps[$this->_currentClassInfo['className']])) {
            // Overwrite our whole "_currentClassPropertyMap" with the one from
            // our parent instead. That's faster than merging the arrays, and
            // guarantees PERFECT PropertyDefinition instance re-usage.
            // NOTE: To explain it, imagine class B extending from A. A has
            // already been parsed and cached. We then instantiate B for the
            // first time and we need to build B's definitions. By copying the
            // cache from A (its parent), we'll re-use all of A's finished
            // PropertyDefinition objects in B and throughout any other chain
            // that derives from the same base object (A). This holds true for
            // ALL objects that inherit (or "import") ANYTHING (such as
            // something that later refers to "B"), since we ALWAYS resolve the
            // chains top-down from the base class to the deepest child class,
            // and therefore cache the base (hierarchically shared) definitions
            // FIRST.
            $this->_currentClassPropertyMap = $this->_propertyMapCache->classMaps[
                $this->_currentClassInfo['className']
            ];
        } else {
            // We have not parsed/encountered this class before...

            // Only parse its class constant if it differs from its inherited
            // parent's constant value. Otherwise we'll keep the parent's map
            // (the value that's currently in "_currentClassPropertyMap").
            if ($hasDifferentConstant) {
                // Process and validate the JSON_PROPERTY_MAP of the class.
                if (!is_array($rawClassPropertyMap)) {
                    throw new BadPropertyMapException(sprintf(
                        'Invalid JSON property map in class "%s". The map must be an array.',
                        $this->_currentClassInfo['strictClassName']
                    ));
                }
                foreach ($rawClassPropertyMap as $propName => $propDefStr) {
                    // Process the current entry and add it to the current
                    // class' compiled property map if the entry is new/diff.
                    $this->_processPropertyMapEntry( // Throws.
                        $propName,
                        $propDefStr
                    );
                }
            }

            // Mark the fact that we have compiled this class, so that we'll be
            // able to know which classes we have compiled later. This list will
            // bubble through the compile-call-hierarchy as-needed to keep track
            // of EVERY compiled class during the current root compile()-run.
            $this->compiledClasses[$this->_currentClassInfo['className']] = true;

            // Now cache the final property-map for this particular class in the
            // inheritance chain... Note that if it had no new/different map
            // declarations of its own (despite having a different constant),
            // then we're still simply re-using the exact property-map objects
            // of its parent class here again.
            $this->_propertyMapCache->classMaps[
                $this->_currentClassInfo['className']
            ] = $this->_currentClassPropertyMap;
        } // End of class-map "lookup-or-compilation".

        // IMPORTANT: We've finished processing a class. If this was one of the
        // classes that WE locked, we MUST NOW unlock it, but ONLY if we
        // successfully processed the class (added it to the cache). Otherwise
        // we'll unlock it at the end (where we unlock any stragglers) instead.
        // NOTE: We AREN'T allowed to unlock classes we didn't lock.
        // NOTE: Unlocking is IMPORTANT, since it's necessary for being able to
        // import classes from diverging branches with shared inheritance
        // ancestors. If we don't release the parent-classes one by one as we
        // resolve them, then we would never be able to import classes from
        // other branches of our parent/class hierarchy, since the imports would
        // see that one of their parents (from our hierarchy) is still locked
        // and would refuse to compile themselves.
        if (isset($this->_ourCompilerClassLocks[$this->_currentClassInfo['className']])) {
            unset($this->_ourCompilerClassLocks[$this->_currentClassInfo['className']]);
            unset($this->_propertyMapCache->compilerLocks[$this->_currentClassInfo['className']]);
        }
    }

    /**
     * Process a property map entry for the current class.
     *
     * Validates and compiles a map entry, and merges it with the current class
     * property map if everything went okay and the entry was new/different.
     *
     * @param string|int $propName   The property name, or a numeric array key.
     * @param mixed      $propDefStr Should be a string describing the property
     *                               or the class to import, but may be
     *                               something else if the user has written an
     *                               invalid class property map.
     *
     * @throws BadPropertyDefinitionException
     * @throws BadPropertyMapException
     * @throws CircularPropertyMapException
     */
    private function _processPropertyMapEntry(
        $propName,
        $propDefStr)
    {
        if (is_string($propName) && is_string($propDefStr)) {
            // This is a string key -> string value pair, so let's attempt to
            // compile it as a regular property definition and then add it to
            // our current class map if the entry is new/different.
            $this->_processPropertyDefinitionString( // Throws.
                $propName,
                $propDefStr
            );
        } else {
            // It cannot be a regular property definition. Check if this is an
            // "import class map" command. They can exist in the map and tell us
            // to import other classes, and their instructions are written as
            // [OtherClass::class, 'ownfield'=>'string'].
            $isImportCommand = false;
            if (is_int($propName) && is_string($propDefStr)) {
                // This is an int key -> string value pair, so we should treat
                // it as a potential "import class map" command. We must first
                // ensure that the class has a strictly global "\" prefix.
                $strictImportClassName = Utilities::createStrictClassPath($propDefStr);

                // Now check if the target class fits the "import class"
                // requirements.
                if (class_exists($strictImportClassName)
                    && is_subclass_of($strictImportClassName, '\\'.LazyJsonMapper::class)) {
                    // This is an "import other class" command! Thanks to the
                    // lack of an associative key, we saw a numeric array key
                    // (non-associative). And we've verified (above) that its
                    // value points to another valid class which is a sub-class
                    // of LazyJsonMapper (we don't bother allowing to import
                    // LazyJsonMapper itself, since it is the lowest possible
                    // base class and has 0 values).
                    $isImportCommand = true;

                    // Perform the import, which will compile the target (if
                    // necessary) and then merge its map with our current map.
                    // NOTE: There is no need for us to prevent the user from
                    // doing multiple imports of the same class, because the way
                    // the importing works ensures that we always re-use the
                    // same PropertyDefinition object instances from the other
                    // class every time we import the other class.
                    $this->_importClassMap( // Throws.
                        $strictImportClassName
                    );
                }
            }
            if (!$isImportCommand) {
                // This map-array value is definitely NOT okay.
                throw new BadPropertyMapException(sprintf(
                    'Invalid JSON property map entry "%s" in class "%s".',
                    $propName, $this->_currentClassInfo['strictClassName']
                ));
            }
        }
    }

    /**
     * Compile a property definition string and add it to the current class.
     *
     * Attempts to compile the definition, and then appends it to the current
     * class' final compiled map, IF the definition is valid and describes a
     * new/different value compared to what's already in the current class map.
     *
     * @param string $propName   The property name.
     * @param string $propDefStr A string describing the property.
     *
     * @throws BadPropertyDefinitionException
     */
    private function _processPropertyDefinitionString(
        $propName,
        $propDefStr)
    {
        try {
            // Validates the definition and throws if bad.
            // NOTE: The namespace argument here is INCREDIBLY important. It's
            // what allows each class to refer to classes relative to its own
            // namespace, rather than needing to type the full, global class
            // path. Without that parameter, the PropertyDefinition would only
            // search in the global namespace.
            // NOTE: "use" statements are ignored since PHP has no mechanism
            // for inspecting those. But the user will quickly notice such a
            // problem, since such classnames will warn as "not found" here.
            $propDefObj = new PropertyDefinition( // Throws.
                $propDefStr,
                $this->_currentClassInfo['namespace']
            );

            // MEMORY OPTIMIZATION TRICK: If we wanted to be "naive", we could
            // simply assign this new PropertyDefinition directly to our current
            // class property map, regardless of whether the property-name key
            // already exists. It would certainly give us the "right" result...
            //
            // But imagine if one of our parent objects or previous imports have
            // already created a property as "foo":"string[]", and imagine that
            // we ALSO define a "foo":"string[]", even though we've already
            // inherited that EXACT compiled instruction. What would happen?
            //
            // Well, if we instantly assign our own, newly created object, even
            // though it's equal to an identically named and identically defined
            // property that we've already inherited/imported, then we waste RAM
            // since we've now got two INDEPENDENT "compiled property" object
            // instances that describe the EXACT same property-settings.
            //
            // Therefore, we can save RAM by first checking if the property name
            // already exists in our currently compiled map; and if so, whether
            // its pre-existing PropertyDefinition already describes the EXACT
            // same settings. If so, we can keep the existing object (which we
            // KNOW is owned by a parent/import and will therefore always remain
            // in memory). Thus saving us the RAM-size of a PropertyDefinition
            // object. In fact, the re-use means the RAM is the same as if the
            // sub-class hadn't even overwritten the inherited/imported property
            // AT ALL! So this protective algorithm makes class JSON property
            // re-definitions to identical settings a ZERO-cost act.
            //
            // IMPORTANT: This only works because compiled properties are
            // immutable, meaning we can trust that the borrowed object will
            // remain the same. We are NEVER going to allow any runtime
            // modifications of the compiled maps. So re-use is totally ok.
            if (!isset($this->_currentClassPropertyMap[$propName])
                || !$propDefObj->equals($this->_currentClassPropertyMap[$propName])) {
                // Add the unique (new/different) property to our class map.
                $this->_currentClassPropertyMap[$propName] = $propDefObj;

                // Alright, we've encountered a brand new property, and we know
                // it's pointing at a LazyJsonMapper if it's an object. But we
                // DON'T know if the TARGET class map can actually COMPILE. We
                // therefore need to add the class to the list of encountered
                // property classes. But only if we haven't already compiled it.
                if ($propDefObj->isObjectType
                    && !isset($this->_propertyMapCache->classMaps[$propDefObj->propType])) {
                    // NOTE: During early compilations, at the startup of a PHP
                    // runtime, this will pretty much always add classes it sees
                    // since NOTHING is compiled while the first class is being
                    // compiled. Which means that this list will contain classes
                    // that are currently BEING SOLVED as the class hierarchy
                    // keeps resolving/compiling itself. But we'll take care of
                    // that by double-checking the list later before we're done.
                    // TODO: PERHAPS we can safely optimize this HERE by also
                    // checking for the target class in _propertyMapCache's list
                    // of locked classes... But planning would be necessary.
                    $this->uncompiledPropertyClasses[$propDefObj->propType] = true;
                }
            }
        } catch (BadPropertyDefinitionException $e) {
            // Add details and throw from here instead.
            throw new BadPropertyDefinitionException(sprintf(
                'Bad property definition for "%s" in class "%s" (Error: "%s").',
                $propName, $this->_currentClassInfo['strictClassName'], $e->getMessage()
            ));
        }
    }

    /**
     * Import another class map into the current class.
     *
     * @param string $strictImportClassName The strict, global path to the
     *                                      class, with leading backslash `\`.
     *                                      To save time, we assume the caller
     *                                      has already verified that it is a
     *                                      valid `LazyJsonMapper` class.
     *
     * @throws BadPropertyDefinitionException
     * @throws BadPropertyMapException
     * @throws CircularPropertyMapException
     */
    private function _importClassMap(
        $strictImportClassName)
    {
        // Begin via reflection to resolve the EXACT name of the class they want
        // to import, so that we can trust its name completely.
        try {
            // NOTE: The strict, global "\"-name is necessary here, to guarantee
            // that we find the correct target class via the global namespace.
            $reflector = new ReflectionClass($strictImportClassName);
            $importClassName = $reflector->getName(); // The clean, real name.
            $strictImportClassName = Utilities::createStrictClassPath($importClassName);
        } catch (ReflectionException $e) {
            // This should never be able to fail (since our caller already
            // verified that the class exists), but treat failure as a bad map.
            throw new BadPropertyMapException(sprintf(
                'Reflection failed for class "%s", when trying to import it into class "%s". Reason: "%s".',
                $strictImportClassName, $this->_currentClassInfo['strictClassName'], $e->getMessage()
            ));
        }

        // FATAL: If the encountered import-statement literally refers to itself
        // (the class we're currently compiling in the hierarchy), then it's a
        // totally ridiculous circular self-reference of the most insane kind.
        if ($importClassName === $this->_currentClassInfo['className']) {
            throw new CircularPropertyMapException(
                $strictImportClassName,
                $strictImportClassName
            );
        }

        // FATAL: If the import-statement refers to ANY class in OUR OWN
        // current class' hierarchy, then it's a circular self-reference. We
        // forbid those because they make ZERO sense. For example: "ABCD", in
        // that case, "D" COULD definitely import "B" because "D" is later in
        // the chain and "B" is therefore already resolved. But WHY? We already
        // inherit from it! And we could NEVER do the reverse; "B" could never
        // import "D", because "D" depends on "B" being fully compiled already.
        // Likewise, we can't allow stupid things like "B imports B". Doesn't
        // make sense. Therefore, we disallow ALL self-imports.
        //
        // NOTE: In the example of "B" importing "D", that's NOT caught here
        // since the classname "D" wouldn't be part of B's hierarchy. But it
        // will be caught during our upcoming attempt to sub-compile "D" to
        // parse ITS hierarchy, since "D" will begin to compile and check its
        // own class hierarchy, and will arrive at the "B" class and will then
        // detect that "B" is locked as "already being resolved", since the
        // ongoing resolving of "B" was what triggered the compilation of "D".
        if (isset($this->_classHierarchy[$importClassName])) {
            // NOTE: strictSolveClassName goes in "arg1" because we're the ones
            // trying to import an invalid target class that's already part of
            // our own hierarchy.
            throw new CircularPropertyMapException(
                $this->_strictSolveClassName,
                $strictImportClassName
            );
        }

        // FATAL: Also prevent users from importing any class that's in the list
        // of "locked and currently being resolved", since that means that the
        // class they're trying to import is ITSELF part of a compilation-chain
        // that's CURRENTLY trying to resolve itself right now.
        //
        // NOTE: This catches scenarios where class-chains have common ancestors
        // but then diverge and go into a circular reference to each other. For
        // example, "A extends LazyJsonMapper, imports B", "B extends
        // LazyJsonMapper, imports A". Imagine that you construct "A", which
        // locks "A" during its compilation process. The parent "LazyJsonMapper"
        // class is successfully compiled since it has no dependencies. Then "A"
        // sees "import B" and sub-compiles B. (Remember that "A" remains locked
        // when that happens, since "A" is waiting for "B" to resolve itself.)
        // The compilation code of "B" begins running. It first checks its
        // inheritance hierarchy and sees nothing locked (LazyJsonMapper is
        // unlocked because it's already done, and B is unlocked because B has
        // not locked itself yet). Next, "B" sees an "import A" statement. It
        // succeeds the "block import of A if it's part of our own hierarchy"
        // check above, since B is NOT "extended from A".
        //
        // So then we're at a crossroads... there IS a circular reference, but
        // we don't know it yet. We COULD let it proceed to "resolve A by
        // sub-compiling the map for A", which would give us a call-stack of
        // "compile A -> compile B -> compile A", but then the 2nd "A" compiler
        // would run, and would do its early lock-check and notice that itself
        // (A) is locked, since the initial compilation of "A" is not yet done.
        // It would then throw a non-sensical error saying that there's a
        // circular reference between "A and A", which is technically true, but
        // makes no sense. That's because we waited too long!
        //
        // So, what the code BELOW does instead, is that when "B" is trying to
        // resolve itself (during the "compile A -> import B -> compile B"
        // phase), the "B" compiler will come across its "import A" statement,
        // and will now detect that "A" is already locked, and therefore throws
        // a proper exception now instead of letting "A" attempt to compile
        // itself a second time as described above. The result of this early
        // sanity-check is a better exception message (correct class names).
        foreach ($this->_propertyMapCache->compilerLocks as $lockedClassName => $isLocked) {
            if ($isLocked && $lockedClassName === $importClassName) {
                // NOTE: strictSolveClassName goes in "arg2" because we're
                // trying to import something unresolved that's therefore higher
                // than us in the call-stack.
                throw new CircularPropertyMapException(
                    $strictImportClassName,
                    $this->_strictSolveClassName
                );
            }
        }

        // Now check if the target class is missing from our cache, and if so
        // then we must attempt to compile (and cache) the map of the target
        // class that we have been told to import.
        //
        // NOTE: This sub-compilation will perform the necessary recursive
        // compilation and validation of the import's WHOLE map hierarchy. When
        // the compiler runs, it will ensure that nothing in the target class
        // hierarchy is currently locked (that would be a circular reference),
        // and then it will lock its own hierarchy too until it has resolved
        // itself. This will go on recursively if necessary, until ALL parents
        // and ALL further imports have been resolved. And if there are ANY
        // circular reference ANYWHERE, or ANY other problems with its
        // map/definitions, then the sub-compilation(s) will throw appropriate
        // exceptions.
        //
        // We won't catch them; we'll just let them bubble up to abort the
        // entire chain of compilation, since a single error anywhere in the
        // whole chain means that whatever root-level compile-call initially
        // began this compilation process _cannot_ be resolved either. So we'll
        // let the error bubble up all the way to the original call.
        if (!isset($this->_propertyMapCache->classMaps[$importClassName])) {
            // Compile the target class to import.
            // NOTE: We will not catch the error. So if the import fails to
            // compile, it'll simply output its own message saying that
            // something was wrong in THAT class. We don't bother including any
            // info about our class (the one which imported it). That's still
            // very clear in this case, since the top-level class compiler and
            // its hierarchy will be in the stack trace as well as always having
            // a clearly visible import-command in their class property map.
            $this->_subcompile($importClassName); // Throws.
        }

        // Now simply loop through the compiled property map of the imported
        // class... and add every property to our own compiled property map. In
        // case of clashes, we use the imported one.
        // NOTE: We'll directly assign its PD objects as-is, which will assign
        // the objects by shallow "shared instance", so that we re-use the
        // PropertyDefinition object instances from the compiled class that
        // we're importing. That will save memory.
        // NOTE: There's no point doing an equals() clash-check here, since
        // we're importing an EXISTING, necessary definition from another class,
        // so we won't save any memory by avoiding their version even if the
        // definitions are equal.
        foreach ($this->_propertyMapCache->classMaps[$importClassName] as $importedPropName => $importedPropDefObj) {
            $this->_currentClassPropertyMap[$importedPropName] = $importedPropDefObj;
        }
    }

    /**
     * Used internally when this compiler needs to run a sub-compilation.
     *
     * Performs the sub-compile. And if it was successful (meaning it had no
     * auto-rollbacks due to ITS `compile()` call failing), then we'll merge its
     * compiler state with our own so that we preserve important state details.
     *
     * @param string $className The full path of the class to sub-compile, but
     *                          without any leading `\` global prefix. To save
     *                          time, we assume the caller has already verified
     *                          that it is a valid `LazyJsonMapper` class.
     *
     * @throws BadPropertyDefinitionException
     * @throws BadPropertyMapException
     * @throws CircularPropertyMapException
     */
    private function _subcompile(
        $className)
    {
        // Sub-compile the target class. If this DOESN'T throw, we know that the
        // target class successfully exists in the compilation cache afterwards.
        // NOTE: If it throws, we know that IT has already rolled back all its
        // changes itself via its own compile()-catch, so WE don't have to worry
        // about reading its state on error. We ONLY care when it succeeds.
        $subCompiler = new self( // Throws.
            false, // Is NOT the root call!
            $this->_propertyMapCache, // Use the same cache to share locks.
            $className
        );
        $subCompiler->compile(); // Throws.

        // Add its state of successfully compiled classes to our own list of
        // compiled classes, so that the info is preserved throughout the stack.
        // NOTE: This is EXTREMELY important, so that we can clear those classes
        // from the cache in case ANY other step of the compilation chain fails
        // unexpectedly. Because in that case, we'll NEED to be able to roll
        // back ALL of our entire compile-chain's property map cache changes!
        // NOTE: The merge function de-duplicates keys!
        $this->compiledClasses = array_merge(
            $this->compiledClasses,
            $subCompiler->compiledClasses
        );

        // Add the sub-compiled class hierarchy's own encountered property-
        // classes to our list of encountered classes. It gives us everything
        // from their extends-hierarchy and everything from their own imports.
        // And in case they had multi-level chained imports (classes that import
        // classes that import classes), it'll actually be containing a FULLY
        // recursively resolved and merged sub-import list. We will get them ALL
        // from their WHOLE sub-hierarchy! Exactly as intended.
        // NOTE: The merge function de-duplicates keys!
        $this->uncompiledPropertyClasses = array_merge(
            $this->uncompiledPropertyClasses,
            $subCompiler->uncompiledPropertyClasses
        );
    }
}
