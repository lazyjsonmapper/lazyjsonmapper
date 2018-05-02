# LazyJsonMapper

## Advanced, intelligent & automatic object-oriented JSON containers for PHP.

Implements a highly efficient, automatic, object-oriented and lightweight
(memory-wise) JSON data container. It provides intelligent data conversion
and parsing, to give you a nice, reliable interface to your JSON data,
without having to worry about doing any of the tedious parsing yourself.

### Features:

- Provides a completely object-oriented interface to all of your JSON data.

- Automatically maps complex, nested JSON data structures onto real PHP
  objects, with total support for nested objects and multi-level arrays.

- Extremely optimized for very high performance and very low memory usage.
  Much lower than other PHP JSON mappers that people have used in the past.

  For example, normal PHP objects with manually defined `$properties`, which
  is what's used by _other_ JSON mappers, will consume memory for every
  property even if that property wasn't in the JSON data (is a `NULL`). Our
  system on the other hand takes up ZERO bytes of RAM for any properties
  that don't exist in the current object's JSON data!

- Automatically provides "direct virtual properties", which lets you
  interact with the JSON data as if it were regular object properties,
  such as `echo $item->some_value` and `$item->some_value = 'foo'`.

  The virtual properties can be disabled via an option.

- Automatically provides object-oriented "virtual functions", which let you
  interact with the data in a fully object-oriented way via functions such
  as `$item->getSomeValue()` and `$item->setSomeValue('foo')`. We support a
  large range of different functions for manipulating the JSON data, and you
  can see a list of all available function names for all of your properties
  by simply running `$item->printPropertyDescriptions()`.

  The virtual functions can be disabled via an option.

- Includes the `LazyDoctor` tool, which _automatically_ documents all of
  your `LazyJsonMapper`-based classes so that their virtual properties and
  functions become _fully_ visible to your IDE and to various intelligent
  code analysis tools. It also performs class diagnostics by compiling all
  of your class property maps, which means that you can be 100% sure that
  all of your maps are valid (compilable) if this tool runs successfully.

- We provide a complete, internal API which your subclasses can use to
  interact with the data inside of the JSON container. This allows you to
  easily override the automatic functions or create additional functions
  for your objects. To override core functions, just define a function with
  the exact same name on your object and make it do whatever you want to.

  Here are some examples of function overriding:

    ```php
    public function getFoo()
    {
        // try to read property, and handle a special "current_time" value.
        $value = $this->_getProperty('foo');
        if ($value === 'current_time') { return time(); }
        return $value;
    }
    public function setFoo(
        $value)
    {
        // if they try to set value to "md5", we use a special value instead
        if ($value === 'md5') { $value = md5(time()); }
        return $this->_setProperty('foo', $value);
    }
    ```

- All mapping/data conversion is done "lazily", on a per-property basis.
  When you access a property, that specific property is mapped/converted to
  the proper type as defined by your class property map. No time or memory
  is wasted converting properties that you never touch.

- Strong type-system. The class property map controls the exact types and
  array depths. You can fully trust that the data you get/set will match
  your specifications. Invalid data that mismatches the spec is impossible.

- Advanced settings system. Everything is easily configured via PHP class
  constants, which means that your class-settings are stateless (there's no
  need for any special "settings/mapper object" to keep track of settings),
  and that all settings are immutable constants (which means that they are
  reliable and can never mutate at runtime, so that you can fully trust that
  classes will always behave as-defined in their code).

  If you want to override multiple core settings identically for all of your
  classes, then simply create a subclass of `LazyJsonMapper` and configure
  all of your settings on that, and then derive all of your other classes
  from your re-configured subclass!

- The world's most advanced mapper definition system. Your class property
  maps are defined in an easy PHPdoc-style format, and support multilevel
  arrays (such as `int[][]` for "an array of arrays of ints"), relative
  types (so you can map properties to classes/objects that are relative to
  the namespace of the class property map), parent inheritance (all of your
  parent `extends`-hierarchy's maps will be included in your final property
  map) and even multiple inheritance (you can literally "import" an infinite
  number of other maps into your class, which don't come from your own
  parent `extends`-hierarchy).

- Inheriting properties from parent classes or importing properties from
  other classes is a zero-cost operation thanks to how efficient our
  property map compiler is. So feel free to import everything you need.
  You can even use this system to create importable classes that just hold
  "collections" of shared properties, which you import into other classes.

- The class property maps are compiled a single time per-class at runtime,
  the first time a class is used. The compilation process fully verifies
  and compiles all property definitions, all parent maps, all inherited
  maps, and all maps of all classes you link properties to.

  If there are any compilation problems due to a badly written map anywhere
  in your hierarchy, you will be shown the exact problem in great detail.

  In case of success, the compiled and verified maps are all stored in an
  incredibly memory-efficient format in a global cache which is shared by
  your whole PHP runtime, which means that anything in your code or in any
  other libraries which accesses the same classes will all share the cached
  compilations of those classes, for maximum memory efficiency.

- You are also able to access JSON properties that haven't been defined in
  the class property map. In that case, they are treated as undefined and
  untyped (`mixed`) and there won't be any automatic type-conversion of such
  properties, but it can still be handy in a pinch.

- There are lots of data export/output options for your object's JSON data,
  to get it back out of the object again: As a multi-level array, as nested
  stdClass objects, or as a JSON string representation of your object.

- We include a whole assortment of incredibly advanced debugging features:

  You can run the constructor with `$requireAnalysis` to ensure that all
  of your JSON data is successfully mapped according to your class property
  map, and that you haven't missed defining any properties that exist in the
  data. In case of any problems, the analysis message will give you a full
  list of all problems encountered in your entire JSON data hierarchy.

  For your class property maps themselves, you can run functions such as
  `printPropertyDescriptions()` to see a complete list of all properties and
  how they are defined. This helps debug your class inheritance and imports
  to visually see what your final class map looks like, and it also helps
  users see all available properties and all of their virtual functions.

  And for the JSON data, you can use functions such as `printJson()` to get
  a beautiful view of all internal JSON data, which is incredibly helpful
  when you (or your users) need to figure out what's available inside the
  current object instance's data storage.

- A fine-grained and logical exception-system which ensures that you can
  always trust the behavior of your objects and can catch problems easily.
  And everything we throw is _always_ based on `LazyJsonMapperException`,
  which means that you can simply catch that single "root" exception
  whenever you don't care about fine-grained differentiation.

- Clean and modular code ensures stability and future extensibility.

- Deep code documentation explains everything you could ever wonder about.

- Lastly, we implement super-efficient object serialization. Everything is
  stored in a tightly packed format which minimizes data size when you need
  to transfer your objects between runtimes.

### Installation

You need at least PHP 5.6 or higher. PHP 7+ is also fully supported and is recommended.

Run the following [Composer](https://getcomposer.org/download/) installation command:

```
composer require lazyjsonmapper/lazyjsonmapper
```

### Examples

View the contents of the [`examples/`](https://github.com/SteveJobzniak/LazyJsonMapper/tree/master/examples) folder.

### Documentation

Everything is fully documented directly within the source code of this library.

You can also [read the same documentation online](https://mgp25.github.io/lazyjsonmapper-docs/namespaces/LazyJsonMapper.html) as nicely formatted HTML pages.

### LazyDoctor

Our automatic class-documentation and diagnostic utility will be placed within
your project's `./vendor/bin/` folder. Simply run it without any parameters to
see a list of all available options. You can also open that file in a regular
text editor to read some general usage tips and tricks at the top of the
utility's source code.

### Copyright

Copyright 2017 The LazyJsonMapper Project

### License

[Apache License, Version 2.0](http://www.apache.org/licenses/LICENSE-2.0)

### Author

SteveJobzniak

### Contributing

If you would like to contribute to this project, please feel free to submit a
pull request, or perhaps even sending a donation to a team member as a token of
your appreciation.

