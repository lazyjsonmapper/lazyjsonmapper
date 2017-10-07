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

// This file tests namespace support in the property definition parser.
// It checks global, relative, global override (via \Class), as well
// as ensuring that the target class extends from LazyJsonMapper as intended.

namespace Deeper {
    require __DIR__.'/../../vendor/autoload.php';

    use LazyJsonMapper\LazyJsonMapper;

    class MyClass extends LazyJsonMapper
    {
        const JSON_PROPERTY_MAP = [
            'hello' => 'string',
        ];
    }
}

namespace Foo\Deeper {
    require __DIR__.'/../../vendor/autoload.php';

    use LazyJsonMapper\LazyJsonMapper;

    class NoExtendsClass
    {
    }

    class MyClass extends LazyJsonMapper
    {
        const JSON_PROPERTY_MAP = ['foo' => 'string'];
    }
}

namespace Other\Space\VerySpace {
    require __DIR__.'/../../vendor/autoload.php';

    use LazyJsonMapper\LazyJsonMapper;

    class VeryDeepInSpace extends LazyJsonMapper
    {
        const JSON_PROPERTY_MAP = [
            'deepspacenine' => 'string',
        ];
    }
}

namespace Other\Space {
    require __DIR__.'/../../vendor/autoload.php';

    use LazyJsonMapper\LazyJsonMapper;
    use LazyJsonMapper\Property\PropertyDefinition;

    class OtherClass extends LazyJsonMapper
    {
        const JSON_PROPERTY_MAP = [
            'from_other_class' => 'string',
        ];
    }

    class MyClass extends LazyJsonMapper
    {
        const JSON_PROPERTY_MAP = [
            'foo'                 => 'int',
            // tests handling of missing relative classes (the warning will
            // display the namespace of this class which defined the property):
            // 'missing_relative'    => 'NoSuchClass', // uncomment to test
            // tests support for relative classes within same namespace:
            'relative_class_path'     => 'OtherClass',
            'relative_sub_class_path' => 'VerySpace\VeryDeepInSpace',
            // and global overrides (the "\" prefix makes PropertyDefinition
            // use the global namespace instead):
            'global_class_path'   => '\Foo\Deeper\MyClass',
            // just for fun, let's import a class map too, via relative:
            // (can be done via global or relative paths)
            VerySpace\VeryDeepInSpace::class,
        ];
    }

    $resolved = new MyClass();
    var_dump($resolved);
}

namespace Foo\Other\Space {
    require __DIR__.'/../../vendor/autoload.php';

    use LazyJsonMapper\LazyJsonMapper;

    // This class is here to show that new $x->propType() construction technique
    // is a bad idea since it may lead to relative resolving like this one, if
    // the global path cannot be found.
    class MyClass extends LazyJsonMapper
    {
    }
}

namespace Foo {
    require __DIR__.'/../../vendor/autoload.php';

    use LazyJsonMapper\LazyJsonMapper;
    use LazyJsonMapper\Property\PropertyDefinition;

    var_dump(\Other\Space\MyClass::class);
    var_dump(class_exists('\Other\Space\MyClass'));
    var_dump(class_exists('\Other\Space\\\MyClass'));
    var_dump(Deeper\MyClass::class);
    var_dump(__NAMESPACE__);

    echo "-----\n";

    // test various combinations of namespaces and class prefixes:
    // $x = new PropertyDefinition('\MyClass', __NAMESPACE__);
    // $x = new PropertyDefinition('\MyClass\Deeper', __NAMESPACE__);
    // $x = new PropertyDefinition('MyClass', __NAMESPACE__);
    // $x = new PropertyDefinition('MyClass\Deeper', __NAMESPACE__);
    // $x = new PropertyDefinition('MyClass');
    // $x = new PropertyDefinition('\MyClass');
    // $x = new PropertyDefinition('MyClass\Deeper');
    // var_dump($x);

    // test a valid relative path (and the cleanup/normalization of a bad name).
    $x = new PropertyDefinition('deePER\MYClass[][]', __NAMESPACE__);
    var_dump($x);
    var_dump($x->asString());
    $y = new $x->propType(); // BAD! WE ALWAYS THE GLOBAL PATH, DO NOT USE THIS
                             // always use getStrictClassPath() instead!
    var_dump($y); // \Foo\Deeper\MyClass instance

    // test a valid path in other space (via global path)
    $x = new PropertyDefinition('\Other\SPACe\MYCLASS[][]', __NAMESPACE__);
    var_dump($x);
    var_dump($x->asString());

    $type = "Deeper\MyClass"; // PHP would resolve this locally due to no \
    $y = new $type();
    var_dump($y);

    $type = "\Deeper\MyClass";
    $y = new $type();
    var_dump($y);

    echo "------\n";
    var_dump($x);
    $y = new $x->propType(); // BAD IDEA! This field has no "\" prefix and may not
                             // resolve to the intended class in all situations
    // correct way for extra safety is always:
    $strictClassPath = $x->getStrictClassPath();
    var_dump($strictClassPath);
    $y = new $strictClassPath();

    var_dump($y); // \Other\Space\MyClass instance

    // test bad class warning (no extends)
    // $x = new PropertyDefinition('deePER\noextendsCLASS', __NAMESPACE__);

    // test bad class warning via mistyped basic typename:
    // $x = new PropertyDefinition('ints', __NAMESPACE__);
}
