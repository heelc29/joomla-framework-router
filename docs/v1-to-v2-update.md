## Updating from v1 to v2

The following changes were made to the Router package between v1 and v2.

### Minimum supported PHP version raised

All Framework packages now require PHP 7.0 or newer.

### Return value of `Joomla\Router\Router::parseRoute()` changed

The `Joomla\Router\Router::parseRoute()` method has been changed to return a `Joomla\Router\ResolvedRoute` object instead of an array.