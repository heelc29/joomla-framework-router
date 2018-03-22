<?php
/**
 * Part of the Joomla Framework Router Package
 *
 * @copyright  Copyright (C) 2005 - 2018 Open Source Matters, Inc. All rights reserved.
 * @license    GNU General Public License version 2 or later; see LICENSE
 */

namespace Joomla\Router;

use Jeremeamia\SuperClosure\SerializableClosure;

/**
 * A path router.
 *
 * @since  1.0
 */
class Router implements \Serializable
{
	/**
	 * An array of rules, each rule being an associative for routing the request.
	 *
	 * Example: array(
	 *     'regex' => $regex,
	 *     'vars' => $vars,
	 *     'controller' => $controller,
	 *     'defaults' => $defaults,
	 * )
	 *
	 * @var    array
	 * @since  __DEPLOY_VERSION__
	 */
	protected $routes = [
		'GET'     => [],
		'PUT'     => [],
		'POST'    => [],
		'DELETE'  => [],
		'HEAD'    => [],
		'OPTIONS' => [],
		'TRACE'   => [],
		'PATCH'   => []
	];

	/**
	 * Constructor.
	 *
	 * @param   array  $maps  An optional array of route maps
	 *
	 * @since   1.0
	 */
	public function __construct(array $maps = [])
	{
		if (! empty($maps))
		{
			$this->addRoutes($maps);
		}
	}

	/**
	 * Add a route of the specified method to the router. If the pattern already exists it will be overwritten.
	 *
	 * @param   string  $method      Request method to match. One of GET, POST, PUT, DELETE, HEAD, OPTIONS, TRACE or PATCH
	 * @param   string  $pattern     The route pattern to use for matching.
	 * @param   mixed   $controller  The controller to map to the given pattern.
	 * @param   array   $rules       An array of regex rules keyed using the named route variables.
	 * @param   array   $defaults    An array of default values that are used when the URL is matched.
	 *
	 * @return  $this
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	public function addRoute(string $method, string $pattern, $controller, array $rules = [], array $defaults = [])
	{
		list($regex, $vars) = $this->buildRegexAndVarList($pattern, $rules);

		$this->routes[strtoupper($method)][] = [
			'regex'      => $regex,
			'vars'       => $vars,
			'controller' => $controller,
			'defaults'   => $defaults
		];

		return $this;
	}

	/**
	 * Parse the given pattern to extract the named variables and build a proper regular expression for use when parsing the routes.
	 *
	 * @param   string  $pattern  The route pattern to use for matching.
	 * @param   array   $rules    An array of regex rules keyed using the named route variables.
	 *
	 * @return  array
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	protected function buildRegexAndVarList(string $pattern, array $rules = []): array
	{
		// Sanitize and explode the pattern.
		$pattern = explode('/', trim(parse_url((string) $pattern, PHP_URL_PATH), ' /'));

		// Prepare the route variables
		$vars = [];

		// Initialize regular expression
		$regex = [];

		// Loop on each segment
		foreach ($pattern as $segment)
		{
			if ($segment == '*')
			{
				// Match a splat with no variable.
				$regex[] = '.*';
			}
			elseif (isset($segment[0]) && $segment[0] == '*')
			{
				// Match a splat and capture the data to a named variable.
				$vars[] = substr($segment, 1);
				$regex[] = '(.*)';
			}
			elseif (isset($segment[0]) && $segment[0] == '\\' && $segment[1] == '*')
			{
				// Match an escaped splat segment.
				$regex[] = '\*' . preg_quote(substr($segment, 2));
			}
			elseif ($segment == ':')
			{
				// Match an unnamed variable without capture.
				$regex[] = '([^/]*)';
			}
			elseif (isset($segment[0]) && $segment[0] == ':')
			{
				// Match a named variable and capture the data.
				$varName = substr($segment, 1);
				$vars[] = $varName;

				// Use the regex in the rules array if it has been defined.
				$regex[] = array_key_exists($varName, $rules) ? '(' . $rules[$varName] . ')' : '([^/]*)';
			}
			elseif (isset($segment[0]) && $segment[0] == '\\' && $segment[1] == ':')
			{
				// Match a segment with an escaped variable character prefix.
				$regex[] = preg_quote(substr($segment, 1));
			}
			else
			{
				// Match the standard segment.
				$regex[] = preg_quote($segment);
			}
		}

		return [
			chr(1) . '^' . implode('/', $regex) . '$' . chr(1),
			$vars
		];
	}

	/**
	 * Add an array of route maps to the router.  If the pattern already exists it will be overwritten.
	 *
	 * @param   array  $routes  A list of route maps to add to the router as $pattern => $controller.
	 *
	 * @return  $this
	 *
	 * @since   __DEPLOY_VERSION__
	 * @throws  \UnexpectedValueException  If missing the `pattern` or `controller` keys from the map.
	 */
	public function addRoutes(array $routes)
	{
		foreach ($routes as $route)
		{
			// Ensure a `pattern` key exists
			if (! array_key_exists('pattern', $route))
			{
				throw new \UnexpectedValueException('Route map must contain a pattern variable.');
			}

			// Ensure a `controller` key exists
			if (! array_key_exists('controller', $route))
			{
				throw new \UnexpectedValueException('Route map must contain a controller variable.');
			}

			// If defaults, rules have been specified, add them as well.
			$defaults = $route['defaults'] ?? [];
			$rules    = $route['rules'] ?? [];
			$method   = $route['method'] ?? 'GET';

			$this->addRoute($method, $route['pattern'], $route['controller'], $rules, $defaults);
		}

		return $this;
	}

	/**
	 * Parse the given route and return the name of a controller mapped to the given route.
	 *
	 * @param   string  $route   The route string for which to find and execute a controller.
	 * @param   string  $method  Request method to match. One of GET, POST, PUT, DELETE, HEAD, OPTIONS, TRACE or PATCH
	 *
	 * @return  ResolvedRoute
	 *
	 * @since   1.0
	 * @throws  \InvalidArgumentException
	 * @throws  Exception\MethodNotAllowedException
	 */
	public function parseRoute($route, $method = 'GET')
	{
		$method = strtoupper($method);

		if (! array_key_exists($method, $this->routes))
		{
			throw new \InvalidArgumentException(sprintf('%s is not a valid HTTP method.', $method));
		}

		// Get the path from the route and remove and leading or trailing slash.
		$route = trim(parse_url($route, PHP_URL_PATH), ' /');

		// Iterate through all of the known routes looking for a match.
		foreach ($this->routes[$method] as $rule)
		{
			if (preg_match($rule['regex'], $route, $matches))
			{
				// If we have gotten this far then we have a positive match.
				$vars = $rule['defaults'];

				foreach ($rule['vars'] as $i => $var)
				{
					$vars[$var] = $matches[$i + 1];
				}

				return new ResolvedRoute($rule['controller'], $vars);
			}
		}

		// See if this route is available with another method
		$allowedMethods = [];

		foreach ($this->routes as $knownMethod => $rules)
		{
			if ($method === $knownMethod)
			{
				continue;
			}

			foreach ($rules as $rule)
			{
				if (preg_match($rule['regex'], $route, $matches))
				{
					$allowedMethods[] = $knownMethod;
				}
			}
		}

		if (!empty($allowedMethods))
		{
			throw new Exception\MethodNotAllowedException(
				array_unique($allowedMethods),
				sprintf('Route `%s` does not support `%s` requests.', $route, strtoupper($method)),
				405
			);
		}

		throw new Exception\RouteNotFoundException(sprintf('Unable to handle request for route `%s`.', $route), 404);
	}

	/**
	 * Add a GET route to the router. If the pattern already exists it will be overwritten.
	 *
	 * @param   string  $pattern     The route pattern to use for matching.
	 * @param   mixed   $controller  The controller to map to the given pattern.
	 * @param   array   $rules       An array of regex rules keyed using the route variables.
	 * @param   array   $defaults    An array of default values that are used when the URL is matched.
	 *
	 * @return  $this
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	public function get(string $pattern, $controller, array $rules = [], array $defaults = [])
	{
		return $this->addRoute('GET', $pattern, $controller, $rules, $defaults);
	}

	/**
	 * Add a POST route to the router. If the pattern already exists it will be overwritten.
	 *
	 * @param   string  $pattern     The route pattern to use for matching.
	 * @param   mixed   $controller  The controller to map to the given pattern.
	 * @param   array   $rules       An array of regex rules keyed using the route variables.
	 * @param   array   $defaults    An array of default values that are used when the URL is matched.
	 *
	 * @return  $this
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	public function post(string $pattern, $controller, array $rules = [], array $defaults = [])
	{
		return $this->addRoute('POST', $pattern, $controller, $rules, $defaults);
	}

	/**
	 * Add a PUT route to the router. If the pattern already exists it will be overwritten.
	 *
	 * @param   string  $pattern     The route pattern to use for matching.
	 * @param   mixed   $controller  The controller to map to the given pattern.
	 * @param   array   $rules       An array of regex rules keyed using the route variables.
	 * @param   array   $defaults    An array of default values that are used when the URL is matched.
	 *
	 * @return  $this
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	public function put(string $pattern, $controller, array $rules = [], array $defaults = [])
	{
		return $this->addRoute('PUT', $pattern, $controller, $rules, $defaults);
	}

	/**
	 * Add a DELETE route to the router. If the pattern already exists it will be overwritten.
	 *
	 * @param   string  $pattern     The route pattern to use for matching.
	 * @param   mixed   $controller  The controller to map to the given pattern.
	 * @param   array   $rules       An array of regex rules keyed using the route variables.
	 * @param   array   $defaults    An array of default values that are used when the URL is matched.
	 *
	 * @return  $this
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	public function delete(string $pattern, $controller, array $rules = [], array $defaults = [])
	{
		return $this->addRoute('DELETE', $pattern, $controller, $rules, $defaults);
	}

	/**
	 * Add a HEAD route to the router. If the pattern already exists it will be overwritten.
	 *
	 * @param   string  $pattern     The route pattern to use for matching.
	 * @param   mixed   $controller  The controller to map to the given pattern.
	 * @param   array   $rules       An array of regex rules keyed using the route variables.
	 * @param   array   $defaults    An array of default values that are used when the URL is matched.
	 *
	 * @return  $this
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	public function head(string $pattern, $controller, array $rules = [], array $defaults = [])
	{
		return $this->addRoute('HEAD', $pattern, $controller, $rules, $defaults);
	}

	/**
	 * Add a OPTIONS route to the router. If the pattern already exists it will be overwritten.
	 *
	 * @param   string  $pattern     The route pattern to use for matching.
	 * @param   mixed   $controller  The controller to map to the given pattern.
	 * @param   array   $rules       An array of regex rules keyed using the route variables.
	 * @param   array   $defaults    An array of default values that are used when the URL is matched.
	 *
	 * @return  $this
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	public function options(string $pattern, $controller, array $rules = [], array $defaults = [])
	{
		return $this->addRoute('OPTIONS', $pattern, $controller, $rules, $defaults);
	}

	/**
	 * Add a TRACE route to the router. If the pattern already exists it will be overwritten.
	 *
	 * @param   string  $pattern     The route pattern to use for matching.
	 * @param   mixed   $controller  The controller to map to the given pattern.
	 * @param   array   $rules       An array of regex rules keyed using the route variables.
	 * @param   array   $defaults    An array of default values that are used when the URL is matched.
	 *
	 * @return  $this
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	public function trace(string $pattern, $controller, array $rules = [], array $defaults = [])
	{
		return $this->addRoute('TRACE', $pattern, $controller, $rules, $defaults);
	}

	/**
	 * Add a PATCH route to the router. If the pattern already exists it will be overwritten.
	 *
	 * @param   string  $pattern     The route pattern to use for matching.
	 * @param   mixed   $controller  The controller to map to the given pattern.
	 * @param   array   $rules       An array of regex rules keyed using the route variables.
	 * @param   array   $defaults    An array of default values that are used when the URL is matched.
	 *
	 * @return  $this
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	public function patch(string $pattern, $controller, array $rules = [], array $defaults = [])
	{
		return $this->addRoute('PATCH', $pattern, $controller, $rules, $defaults);
	}

	/**
	 * Add a UNIVERSAL (catchall) route to the router. If the pattern already exists it will be overwritten.
	 *
	 * @param   string  $pattern     The route pattern to use for matching.
	 * @param   mixed   $controller  The controller to map to the given pattern.
	 * @param   array   $rules       An array of regex rules keyed using the route variables.
	 *
	 * @return  $this
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	public function all(string $pattern, $controller, array $rules = [])
	{
		list($regex, $vars) = $this->buildRegexAndVarList($pattern, $rules);

		foreach ($this->routes as $method => $routes)
		{
			$this->routes[$method][] = [
				'regex'      => $regex,
				'vars'       => $vars,
				'controller' => $controller
			];
		}

		return $this;
	}

	/**
	 * String representation of the Router object
	 *
	 * @return  string  The string representation of the object or null
	 *
	 * @link    http://php.net/manual/en/serializable.serialize.php
	 * @since   __DEPLOY_VERSION__
	 */
	public function serialize()
	{
		$routesCopy = $this->routes;

		foreach ($routesCopy as $httpRequestMethod => $routes)
		{
			foreach ($routes as $i => $route)
			{
				if ($route['controller'] instanceof \Closure)
				{
					$routesCopy[$httpRequestMethod][$i]['controller'] = new SerializableClosure($route['controller']);
				}
			}
		}

		return serialize($routesCopy);
	}

	/**
	 * Constructs the object from a serialized string
	 *
	 * @param   string  $serialized  The string representation of the object.
	 *
	 * @return  void
	 *
	 * @link    http://php.net/manual/en/serializable.unserialize.php
	 * @since   __DEPLOY_VERSION__
	 */
	public function unserialize($serialized)
	{
		$this->routes = unserialize($serialized);
	}
}
