<?php

require __DIR__.'/../vendor/autoload.php';

use LazyJsonMapper\Exception\LazyJsonMapperException;
use LazyJsonMapper\LazyJsonMapper;

/*
 * This is a VERY ADVANCED example which demonstrates various strategies for
 * handling JSON data that contains unpredictable keys. Almost all data you'll
 * ever encounter will have predictable, statically named keys, which means that
 * you should just define them via JSON_PROPERTY_MAP as usual.
 *
 * However, you may _sometimes_ come across JSON data keyed by something totally
 * unpredictable, such as the ID of each user. In that case, you can use various
 * awesome features of LazyJsonMapper's API to work with the data anyway!
 *
 * The code is heavily commented to explain these advanced concepts properly.
 *
 * Let's dive in...!
 */

/**
 * This class describes a single user.
 */
class User extends LazyJsonMapper
{
    const JSON_PROPERTY_MAP = [
        'name' => 'string',
    ];
}

/**
 * This container supports unpredictable keys.
 *
 * Note that it doesn't define any properties in its JSON_PROPERTY_MAP. Instead,
 * we use a custom getter which reads _all_ JSON properties and processes them!
 *
 * In other words, this _entire_ container will hold nothing but unpredictable
 * data keys. (If you have a mixture, there are other examples further down.)
 */
class UnpredictableContainer extends LazyJsonMapper
{
    /**
     * Cached key-value object translations.
     *
     * This is optional, but speeds up repeated calls to `getUserList()`, since
     * it will store all previously converted objects for future re-use.
     *
     * @var array
     */
    protected $_userList;

    /**
     * Get the list of users.
     *
     * @throws LazyJsonMapperException
     *
     * @return array Associative array of key-value pairs, keyed by userID.
     */
    public function getUserList()
    {
        // Tell LazyJsonMapper to give us all of our internal data as an array.
        // NOTE: This creates a COPY of the array. It's not attached to the main
        // storage, which means that any changes we make to the new array aren't
        // going to affect the actual LazyJsonMapper object's own data. That
        // won't matter for pure getter-based objects like this one (which is
        // all you'll need in 99.9999999% of cases where you'll want to read
        // unpredictable data). So if the user serializes this LazyJsonMapper
        // object, or gets it "asJson()", etc, then they'd still be serializing
        // the original, untouched internal data, which is exactly what we are
        // retrieving here. So everything works out perfectly as long as we
        // don't have any setters! (But setters will be demonstrated later.)
        if ($this->_userList === null) {
            // Get a copy of the internal data as an array, and cache it.
            // NOTE: This only throws if there is unmappable data internally.
            $this->_userList = $this->asArray(); // Throws.
        }

        // Loop through the list of JSON properties and convert every "array"
        // value to a User object instead. That's because all of JSON's nested,
        // not-yet-converted "JSON object values" are associative sub-arrays.
        foreach ($this->_userList as &$value) {
            if (is_array($value)) {
                // NOTE: The User constructor can only throw if a custom _init()
                // fails or if its class property map can't be compiled.
                $value = new User($value); // Throws.
            }
        }

        // Now just return our key-value array. The inner array values have
        // been converted to User objects, which makes them easy to work with.
        return $this->_userList;
    }
}

/*
 * Let's try it out with two sets of data that use unpredictable (numeric) keys!
 */

/*
 * This JSON data consists of various numeric keys pointing at "User"-objects.
 */
$unpredictable_json1 = <<<EOF
{
    "8432483": {
        "name": "Edmund McMundensson"
    },
    "94343": {
        "name": "Mabe Mabers"
    }
}
EOF;

echo "Examples for working with unpredictable data...\n";
echo "\n\nUnpredictable data #1:\n";
$unpredictable1 = new UnpredictableContainer(json_decode($unpredictable_json1, true));

/*
 * Let's print what's in the actual object's internal JSON storage.
 */
$unpredictable1->printJson();

/*
 * Now let's call our custom getter which retrieves all values and gives them to
 * us as User objects. As you can see, their contents perfectly match the
 * printJson() results, since our custom getter doesn't manipulate any data.
 */
foreach ($unpredictable1->getUserList() as $userId => $userInfo) {
    printf("User ID: %s\n   Name: %s\n", $userId, $userInfo->getName());
}

/*
 * Let's do the same for another set of data with other keys...
 */
$unpredictable_json2 = <<<EOF
{
    "0324": {
        "name": "Lisa Appleburg"
    },
    "493": {
        "name": "Chief Chieftain McChiefers"
    }
}
EOF;

echo "\n\nUnpredictable data #2:\n";
$unpredictable2 = new UnpredictableContainer(json_decode($unpredictable_json2, true));

$unpredictable2->printJson();

foreach ($unpredictable2->getUserList() as $userId => $userInfo) {
    printf("User ID: %s\n   Name: %s\n", $userId, $userInfo->getName());
}

/*
 * Alright, that's all great... but what if we want to manipulate the data?
 *
 * Well, the loop above still has $userId and $userInfo variables that point at
 * the last element it looped to... so let's try using those to set data! What
 * can possibly go wrong!? ;-)
 */
printf("*** Changing the name of user #%s to 'FOO'.\n", $userId);
$userInfo->setName('FOO');

/*
 * Now let's look at the contents of the "getUserList()" call...
 *
 * Because the user-list is cached internally in our custom object, it already
 * refers to the exact same object instances... So it will indeed have updated.
 */
foreach ($unpredictable2->getUserList() as $userId => $userInfo) {
    printf("User ID: %s\n   Name: %s\n", $userId, $userInfo->getName());
}

/*
 * But wait... what about the actual internal object data? Let's look at that!
 *
 * The name... is not updated...
 */
$unpredictable2->printJson();

/*
 * Uh oh... since we're using a custom getter which fetches the data totally
 * detached from the core LazyJsonMapper storage, we will need another solution!
 *
 * These extra steps are ONLY needed if you want setters that actually work...
 */

/**
 * Extends UnpredictableContainer with a custom setter.
 *
 * We could have put these functions on the main UnpredictableContainer, but
 * this example is clearer by only explaining it here as a separate step for
 * those who need setters...
 */
class UnpredictableContainerWithSetter extends UnpredictableContainer
{
    /**
     * Syncs the user list cache with the LazyJsonMapper core storage.
     *
     * Note that we could build these steps into `setUserList()`. But by having
     * it as a separate function, you are able to just update specific User
     * objects and then call `syncUserList()` to write them back to the core.
     *
     * @throws LazyJsonMapperException
     */
    public function syncUserList()
    {
        // If no internal cache exists yet, get its value from LazyJsonMapper.
        if ($this->_userList === null) {
            $this->getUserList(); // Builds our "_userList" variable. Throws.
        }

        // Now, we need to create a new, internal LazyJsonMapper data array for
        // our object. In undefined (unmapped) properties, you are ONLY allowed
        // to store basic data types. Not objects. So we will need to convert
        // all User objects to real array data. Otherwise OTHER calls would fail
        // due to invalid internal data when we try things like `printJson()`.
        $newObjectData = [];
        foreach ($this->_userList as $k => $v) {
            $newObjectData[$k] = is_object($v) && $v instanceof LazyJsonMapper
                               ? $v->asArray() // Throws.
                               : $v; // Is already a valid value.
        }

        // Now give the new object data to LazyJsonMapper, which ensures that it
        // contains all of the same values as our updated cache! This replaces
        // the ENTIRE internal JSON property storage, so be aware of that!
        $this->assignObjectData($newObjectData); // Throws.
    }

    /**
     * Replace the entire user list with another list.
     *
     * @param array $userList Associative array of User objects keyed by userID.
     *
     * @throws LazyJsonMapperException
     *
     * @return $this
     */
    public function setUserList(
        array $userList)
    {
        // First of all, let's instantly save their new value to our own
        // internal cache, since our cache supports User objects and doesn't
        // have to do any special transformations...
        $this->_userList = $userList;

        // Now sync our internal cache with the LazyJsonMapper object...! :-)
        $this->syncUserList(); // Throws.

        // Setters should always return "$this" to make them chainable!
        return $this;
    }
}

/*
 * Alright, let's try the example again, with the new container that has proper
 * support for setters!
 */
echo "\n\nUnpredictable data #2, with setter:\n";
$unpredictable2 = new UnpredictableContainerWithSetter(json_decode($unpredictable_json2, true));

$unpredictable2->printJson();

foreach ($unpredictable2->getUserList() as $userId => $userInfo) {
    printf("User ID: %s\n   Name: %s\n", $userId, $userInfo->getName());
}

/*
 * Now let's try manipulating the data again!
 */
printf("*** Changing the name of user #%s to 'FOO'.\n", $userId);
$userInfo->setName('FOO');

/*
 * Now let's look at the contents of the "getUserList()" call...
 *
 * Just as before, it has been updated since we use an internal cache which
 * already refers to the exact same object instances... so our update is there!
 */
foreach ($unpredictable2->getUserList() as $userId => $userInfo) {
    printf("User ID: %s\n   Name: %s\n", $userId, $userInfo->getName());
}

/*
 * Now let's look at the internal LazyJsonMapper data storage...
 *
 * It is NOT updated. As expected...
 */
$unpredictable2->printJson();

/*
 * Now let's SYNC our cache back to the internal LazyJsonMapper data storage!
 *
 * And voila...!
 */
$unpredictable2->syncUserList();
$unpredictable2->printJson();

/*
 * Let's also try our custom setter, which takes a whole array of User objects
 * and does the syncing automatically!
 */
$users = $unpredictable2->getUserList();
foreach ($users as $userId => $userInfo) {
    $userInfo->setName('Updated...'.$userId.'!');
}
$unpredictable2->setUserList($users); // Replaces entire cache and syncs!

/*
 * Now let's look at the contents of our cache AND the LazyJsonMapper data!
 *
 * Everything has been updated, since our "setUserList()" call replaced the
 * entire cache contents AND synced the new cache contents to the core storage.
 */
foreach ($unpredictable2->getUserList() as $userId => $userInfo) {
    printf("User ID: %s\n   Name: %s\n", $userId, $userInfo->getName());
}
$unpredictable2->printJson();

/**
 * This class handles a mixture of knowable and unknowable data.
 *
 * Let's end this with one more example... What if you DON'T want to define a
 * whole custom container? What if you only want a SPECIFIC value within your
 * map to be handling unpredictable keys? You could achieve that as follows!
 *
 * This class will contain less comments, for brevity. You hopefully understand
 * all of the major workflow concepts by now! We will only explain new concepts.
 * And this class won't show any `syncEmployees()` function, since you should
 * understand how to do that now if you want that feature.
 */
class UnpredictableMixtureContainer extends LazyJsonMapper
{
    protected $_employees;

    const JSON_PROPERTY_MAP = [
        // This is a statically named "manager" property, which is a User.
        'manager'   => 'User',
        // This is a statically named "employees" property, which consists of
        // unpredictable key-value pairs, keyed by userID.
        'employees' => 'mixed', // Must be 'mixed' (aka '') to allow sub-arrays.
    ];

    /**
     * Get the list of employees.
     *
     * NOTE: This overrides the normal, automatic LazyJsonMapper getter! By
     * naming our function identically, we override its behavior!
     *
     * @throws LazyJsonMapperException
     *
     * @return array
     */
    public function getEmployees()
    {
        if ($this->_employees === null) {
            // Use the internal _getProperty() API to read the actual property.
            // NOTE: This function only throws if "employees" contains any
            // invalid non-basic data. Only basic PHP types are accepted.
            $this->_employees = $this->_getProperty('employees'); // Throws.
        }

        foreach ($this->_employees as &$value) {
            if (is_array($value)) {
                $value = new User($value); // Throws.
            }
        }

        return $this->_employees;
    }

    /**
     * Set the list of employees.
     *
     * NOTE: This overrides the normal, automatic LazyJsonMapper setter! By
     * naming our function identically, we override its behavior!
     *
     * @param array $employees
     *
     * @throws LazyJsonMapperException
     *
     * @return $this
     */
    public function setEmployees(
        array $employees)
    {
        $this->_employees = $employees;

        // We now need to construct a new, inner value for the property. Since
        // it's a "mixed" property, it only accepts basic PHP types.
        $newInnerValue = [];
        foreach ($this->_employees as $k => $v) {
            $newInnerValue[$k] = is_object($v) && $v instanceof LazyJsonMapper
                               ? $v->asArray() // Throws.
                               : $v; // Is already a valid value.
        }

        // Now use the internal _setProperty() API to set the new inner value.
        // NOTE: This function only throws if the new value contains any
        // invalid non-basic data. Only basic PHP types are accepted.
        $this->_setProperty('employees', $newInnerValue);

        return $this;
    }
}

/*
 * Let's try it out!
 */
$unpredictable_json3 = <<<EOF
{
    "manager": {
        "name": "The Best Manager"
    },
    "employees":{
        "129": {
            "name": "Sally Wooper"
        },
        "193": {
            "name": "Sam Fisher"
        }
    }
}
EOF;

echo "\n\nUnpredictable data #3, with mixturecontainer:\n";
$unpredictable3 = new UnpredictableMixtureContainer(json_decode($unpredictable_json3, true));

/*
 * Let's look at all current data.
 */
$unpredictable3->printJson();
printf("The manager's name is: %s\n", $unpredictable3->getManager()->getName());
foreach ($unpredictable3->getEmployees() as $employeeId => $employeeInfo) {
    printf("- Employee #%s, Name: %s\n", $employeeId, $employeeInfo->getName());
}

/*
 * Now let's set some new employee data... This time, just for fun, let's give
 * it a virtual JSON array which we construct manually.
 */
$unpredictable3->setEmployees([
    // Let's provide one value as a User object, since our setter supports
    // the conversion of User objects back into plain arrays.
    '10' => new User(['name' => 'Employee Ten']),
    // However, we could also just provide the data as an array, since that's
    // the goal that our setter performs... this is for really advanced users.
    // Don't blame us if you break things by using this shortcut! ;-)
    '11' => ['name' => 'Employee Eleven'],
]);

/*
 * Let's also update the manager, which is done via a normal, automatic
 * LazyJsonMapper setter, since that property is completely defined. And since
 * the "manager" object is owned by the LazyJsonMapper core, we're able to chain
 * its getters and setters to select the manager's User object and set its name!
 */
$unpredictable3->getManager()->setName('New Manager!');

/*
 * Now let's look at all current data again!
 */
$unpredictable3->printJson();
printf("The manager's name is: %s\n", $unpredictable3->getManager()->getName());
foreach ($unpredictable3->getEmployees() as $employeeId => $employeeInfo) {
    printf("- Employee #%s, Name: %s\n", $employeeId, $employeeInfo->getName());
}

/*
 * But wait... there's yet another way to solve this!
 *
 * Since we know that our "mixture container"'s `employees` value is a key-value
 * storage of User objects, in other words it's "an unpredictable key-value
 * container", then we CAN just tell LazyJsonMapper to map that property to a
 * `UnpredictableContainer[WithSetter]` which we defined earlier. That way, the
 * "employees" values are handled automatically by a neat sub-container.
 *
 * In fact, we could do something even better! We could define a basic
 * "unpredictable keys" container, and then define subclasses of it for various
 * types of unpredictable containers. Let's do that instead!
 */

/**
 * This class defines a core "untyped" container of unpredictable data-keys.
 *
 * Unpredictable data is data with keys that cannot be known ahead of time, such
 * as objects whose values are keyed by things like user IDs.
 *
 * Here's an example of such unpredictable data: `{"9323":{"name":"foo"}}`
 *
 * The `getData()` function retrieves all key-value pairs, converted to the
 * optional `$_type` (if one is set via a subclass). And `setData()` writes
 * the new data back into the core `LazyJsonMapper` container. Most people will
 * not need to use the setter. It's just provided as an extra feature.
 *
 * @author SteveJobzniak (https://github.com/SteveJobzniak)
 */
class CoreUnpredictableContainer extends LazyJsonMapper
{
    // Let's disable direct access to this container via anything other than
    // the functions that WE define ourselves! That way, people cannot use
    // virtual properties/functions to manipulate the core data storage.
    const ALLOW_VIRTUAL_PROPERTIES = false;
    const ALLOW_VIRTUAL_FUNCTIONS = false;

    /**
     * Data cache to avoid constant processing every time the getter is used.
     *
     * @var array
     */
    protected $_cache;

    /**
     * What class-type to convert all sub-object values into.
     *
     * Defaults to no conversion. Override this value via a subclass!
     *
     * Always use the FULL path to the target class, with a leading backslash!
     * The leading backslash ensures that it's found via a strict, global path.
     *
     * Example: `\Foo\BarClass`.
     *
     * @var string
     */
    protected $_type;

    /**
     * Get the data array of this unpredictable container.
     *
     * @throws LazyJsonMapperException
     *
     * @return array
     */
    public function getData()
    {
        if ($this->_cache === null) {
            $this->_cache = $this->asArray(); // Throws.
        }

        if ($this->_type !== null) {
            foreach ($this->_cache as &$value) {
                if (is_array($value)) {
                    $value = new $this->_type($value); // Throws.
                }
            }
        }

        return $this->_cache;
    }

    /**
     * Set the data array of this unpredictable container.
     *
     * @param array $value The new data array.
     *
     * @throws LazyJsonMapperException
     *
     * @return $this
     */
    public function setData(
        array $value)
    {
        $this->_cache = $value;

        $newObjectData = [];
        foreach ($this->_cache as $k => $v) {
            $newObjectData[$k] = is_object($v) && $v instanceof LazyJsonMapper
                               ? $v->asArray() // Throws.
                               : $v; // Is already a valid value.
        }

        $this->assignObjectData($newObjectData); // Throws.

        return $this;
    }
}

/**
 * This class defines an "unpredictable container of User objects".
 *
 * It's very easy to define other containers. Simply create them like this and
 * override their `$_type` property to any other valid LazyJsonMapper class.
 */
class UserUnpredictableContainer extends CoreUnpredictableContainer
{
    // The FULL path to the target class, with leading backslash!
    // NOTE: The leading backslash ensures it's found via a strict, global path.
    protected $_type = '\User';
}

/**
 * This is our new and improved, final class!
 *
 * Here is our final object for mapping our unpredictable "employees" data...
 * As you can see, it is much easier to create this class now that we have
 * defined a core, re-usable "unpredictable container" above.
 */
class UnpredictableMixtureContainerTwo extends LazyJsonMapper
{
    const JSON_PROPERTY_MAP = [
        // This holds a regular User object.
        'manager'   => 'User',
        // This property is an unpredictable container of User objets.
        'employees' => 'UserUnpredictableContainer',
    ];
}

/*
 * Let's try it out!
 */
$unpredictable_json4 = <<<EOF
{
    "manager": {
        "name": "Unpredictable Manager"
    },
    "employees":{
        "12": {
            "name": "Unpredictable Employee"
        },
        "193": {
            "name": "Sam Fisher... again!"
        }
    }
}
EOF;

echo "\n\nUnpredictable data #4, with mixturecontainertwo and coreunpredictablecontainer:\n";
$unpredictable4 = new UnpredictableMixtureContainerTwo(json_decode($unpredictable_json4, true));

/*
 * Let's look at all current data.
 *
 * Note that we use `getData()` to get the unpredictable container's values.
 */
$unpredictable4->printJson();
printf("The manager's name is: %s\n", $unpredictable4->getManager()->getName());
foreach ($unpredictable4->getEmployees()->getData() as $employeeId => $employeeInfo) {
    printf("- Employee #%s, Name: %s\n", $employeeId, $employeeInfo->getName());
}

/*
 * And let's update the value of the inner, unpredictable container!
 *
 * The container itself takes care of updating the LazyJsonMapper data storage!
 */
$unpredictable4->getEmployees()->setData([
    '123' => ['name' => 'Final Employee 123'],
    '456' => ['name' => 'Final Employee 456'],
]);

/*
 * Now finish by looking at all of the data again, via the LazyJsonMapper core
 * object and via the `getEmployees()` object's cache... They are identical!
 */
$unpredictable4->printJson();
printf("The manager's name is: %s\n", $unpredictable4->getManager()->getName());
foreach ($unpredictable4->getEmployees()->getData() as $employeeId => $employeeInfo) {
    printf("- Employee #%s, Name: %s\n", $employeeId, $employeeInfo->getName());
}

/*
 * And that's it! Hopefully you NEVER have to work with nasty, unpredictable
 * data like this. If you're able to control the JSON format, you should always
 * design it properly with known keys instead. But at least you now know about
 * multiple great methods for working with objects that have unpredictable keys!
 *
 * There are other methods too, such as if your class contains a blend of known
 * (defined) keys and unpredictable keys, in which case you'd need to fetch via
 * `asArray()` as in the `UnpredictableContainer` example, and then you'd simply
 * filter out all known keys from your cache, to get _just_ the unpredictable
 * keys. And if you need setters in that scenario, your setter functions would
 * be a bit more complex since they would need to use `assignObjectData()` AND
 * would have to provide BOTH the known data AND the unknown data. One way of
 * doing that would be to use `_getProperty()` to merge in the CURRENT values of
 * each core property into your NEW object-data array BEFORE you assign it. But
 * I leave that extremely rare scenario as an excercise for you, dear reader!
 *
 * You should go out and adapt all of this code to fit your own needs! ;-)
 *
 * For example, if you don't need the unpredictable values to be converted
 * to/from a specific object type, then simply skip the conversion code.
 *
 * If you don't need caching for performance, then skip the caching code.
 *
 * If you don't need setters/syncing, then skip all of that code.
 *
 * You may also want to disable the user-options `ALLOW_VIRTUAL_PROPERTIES` and
 * `ALLOW_VIRTUAL_FUNCTIONS` on your unpredictable containers, so users cannot
 * manipulate the unpredictable data via LazyJsonMapper's automatic functions!
 * That's what we did for CoreUnpredictableContainer, to ensure that nobody can
 * destroy its internal data by touching it directly. They can only manipulate
 * the data via its safe, public `getData()` and `setData()` functions!
 *
 * And you may perhaps prefer to write a custom base-class which has a few other
 * helper-functions for doing these kinds of data translations, caching and
 * syncing, to make your own work easier (such as the CoreUnpredictableContainer
 * example above). That way, your various sub-classes could just call your
 * internal helper functions to do the required processing automatically! :-)
 *
 * The possibilities are endless! Have fun!
 */
echo "\n\nHave fun!\n";
