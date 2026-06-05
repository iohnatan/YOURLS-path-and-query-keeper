<?php
/**
 * @package iona_yourls
 *
 * Plugin Name: Path & Query Keeper
 * Plugin URI: https://github.com/YOURLS-path-and-query-keeper
 * Description: Add a path and query string to the short URL and it will be included in the long url.
 * Version: 1.0.0
 * Author: ionatans
 * Author URI: https://www.ionatan.cl
 */

// No direct call
if( !defined( 'YOURLS_ABSPATH' ) ) die();

// phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash
// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
// phpcs:disable Squiz.Commenting.FunctionComment.Missing
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound

use IonaTools as t;

/** Undocumented class */
class Iona_Yourls_Plugin {

	/** @var string Request path relative to YOURLS base, query and base removed in yourls_get_request() */
	private static $yourls_request_path = null;

	public static function on_init() {
		yourls_add_filter(
			# @see: yourls_get_request() in functions.php.
			'get_request',
			[ self::class, 'path_to_root_segment' ]
		);

		# Hook our custom function into the 'pre_redirect' event.
		yourls_add_filter(
			'redirect_location',
			[ self::class, 'add_short_url_path_query_to_target_url' ]
		);
	}

	/** Reduce the request path to the root path segment.
	 *
	 * @since 1.0.0
	 * @see yourls_get_request() in functions.php.
	 *
	 * @param string $request_path request path relative to YOURLS base (eg 'abdc')
	 *                             (eg in 'http://sho.rt/yourls/abcd' get 'abdc')
	 *
	 * @return string
	 */
	public static function path_to_root_segment( string $request_path ) {
		# keep only the root path segment of the request path,
		# so in 'http://sho.rt/yourls/abcd/extra' get 'abdc'.
		$result = $request_path;
		if ( str_contains($request_path, '/' ) ) {
			$result = strstr($request_path, '/', true );
			self::$yourls_request_path = strstr($request_path, '/', false );
		}
		return $result;
	}


	/** Add the path and query string of the short URL to the final long destination url.
	 *
	 * @since 1.0.0
	 *
	 * @param string $target_url URL to redirect to.
	 *
	 * @return string
	 */
	public static function add_short_url_path_query_to_target_url( string $target_url ) {
		$request_query = t::current_url_query();
		$request_path  = self::$yourls_request_path;
		if ( empty( $request_query ) &&
			empty( $request_path )
		) {
			return $target_url;
		}

		$parsed_target_url = parse_url( $target_url );

		$target_url_path = t::url_path( $parsed_target_url, true );
		$request_path    = ltrim( $request_path, '/' );
		if ( ! empty( $request_path ) ) { // so a path to be added.
			if ( '/' === $target_url_path ) {
				$target_url_path = '';
			}
			$target_url_path .= "/$request_path";
		}

		# Check if the request already has a query string and append accordingly.
		$target_url_query = t::url_query( $parsed_target_url );
		if ( ! empty( $request_query ) ) { // so a query to be added.
			if ( ! empty( $target_url_query ) ) {
				$target_url_query .= "&$request_query";
			} else {
				$target_url_query = $request_query;
			}
		}

		$target_url_scheme = t::url_scheme( $parsed_target_url );
		$target_url_host   = t::url_host( $parsed_target_url );

		$target_url = t::build_url(
			$target_url_host, $target_url_path, $target_url_query,
			$target_url_scheme
		);

		return $target_url;
	}
}
Iona_Yourls_Plugin::on_init();

/** Undocumented class */
class IonaTools {

	/** Get the current request URL query string.
	 *
	 * Complete URL:
	 * https://example.org/path/to/file?param=42#fragment
	 * Query part:
	 * param=42
	 *
	 * @since 3.59.0
	 *
	 * @return string
	 */
	public static function current_url_query() {
		static $current_url_query = null;
		if ( ! empty( $current_url_query ) ) {
			return $current_url_query;
		}

		$current_url_query = urldecode( self::array_prop( 'QUERY_STRING', $_SERVER, '' ) );
		$current_url_query = self::unescape_html_special_chars( $current_url_query );

		return $current_url_query;
	}

	/** Get the "scheme" part of an absolute URL.
	 *
	 * @since 3.42.0
	 *
	 * @param string|array $url - An absolute URL.
	 *                          - A parsed URL array result from parse_url() or wp_parse_url().
	 *
	 * @return string|null
	 */
	public static function url_scheme( $url ) {
		if ( is_string( $url ) ) {
			// null if the component doesn't exist.
			$scheme = self::parse_absolute_url( $url, PHP_URL_SCHEME ); // phpcs:ignore
		} elseif ( is_array( $url ) ) {
			$scheme = self::array_prop( 'scheme', $url, null );
		} else {
			return null;
		}

		if ( empty( $scheme ) ) {
			return null;
		}
		return $scheme;
	}

	/** Get the "host" part of an absolute URL.
	 *
	 * @since 3.14.0
	 *
	 * @param string|array $url - An absolute URL.
	 *                          - A parsed URL array result from parse_url() or wp_parse_url().
	 *
	 * @return string|null
	 */
	public static function url_host( $url ) {
		if ( is_string( $url ) ) {
			// null if the component doesn't exist.
			$host = self::parse_absolute_url( $url, PHP_URL_HOST ); // phpcs:ignore
		} elseif ( is_array( $url ) ) {
			$host = self::array_prop( 'host', $url, null );
		} else {
			return null;
		}

		if ( empty( $host ) ) {
			return null;
		}

		// WP trim dots in wp_http_validate_url().
		$host = trim( $host, '.' );
		return $host;
	}

	/** Get the normalized "path" part of an URL( absolute or relative ).
	 *
	 * @since 3.1.0
	 * @link https://stackoverflow.com/questions/6969645/how-to-remove-the-querystring-and-get-only-the-url
	 *
	 * @param string|array $url           - An absolute or relative URL.
	 *                                    - A parsed URL array result from parse_url() or wp_parse_url().
	 * @param boolean      $untraillashit [Optional]. Removes last slashes and backslashes if they exist.
	 *
	 * @return string|null
	 */
	public static function url_path( $url, bool $untraillashit = false ) {
		if ( is_string( $url ) ) {
			/** DO NOT USE parse_url() to get the path on relative or invalid URLs, CAUTION:
			 * "This function may not give correct results, and the results may not even match common behavior of HTTP clients".
			 * https://www.php.net/manual/en/function.parse-url.php.
			 *
			 * The parse_url() expects an absolute URL, so you will get incorrect results in some cases,
			 * e.g. if your REQUEST_URL starts with multiple consecutive slashes. Those will be interpreted as a protocol-relative URI.
			 * https://stackoverflow.com/a/25050598/11420308
			 *
			 * PHP 5.4.7 expanded parse_url()'s ability to handle non-absolute URLs,
			 * including schemeless and relative URLs with "://" in the path.
			 * so wp_parse_url() works around those limitations providing a standard output.
			 *
			 * The wp_parse_url( $url, PHP_URL_PATH ) handles well absolute URLs:
			 * http://example.com        => ''
			 * http://example.com/       => '/'
			 * http://example.com/path   => '/test'
			 * http://example.com/path/  => '/test/'
			 * http://example.com//path/ => '//test/'
			 *
			 * But relative URL starting with multiple slashes FAILS:
			 * (WP assumess that starting // is a schemeless URL eg. //example.com/path)
			 * {test string} => 'path result'.
			 * ''            => ''
			 * '/'           => '/'
			 * '/test'       => '/test'
			 * '/test/'      => '/test/'
			 * ## schemeless asumed:
			 * '//test/'     => 'test'(host) & '/'(path)
			 * '://test/'    => '://test/'(path)
			 *
			 * Absolute-path reference: a relative reference that begins with a single slash character.
			 * Relative-path reference: a relative reference that doesn't begin with a slash character.
			 *
			 * @link https://datatracker.ietf.org/doc/html/rfc3986#section-4.2.
			 */
			if ( self::is_absolute_url( $url ) ) {
				// if is absolute use wp_parse_url() so the 'scheme://host' part is removed.
				$path = wp_parse_url( $url, PHP_URL_PATH );
				$path = is_null( $path ) ? '' : $path; // null if the component doesn't exist in the given URL.
			} else {
				$path = strtok( $url, '?' );
			}
		} elseif ( is_array( $url ) ) {
			$path = self::array_prop( 'path', $url, '' );
		} else {
			return null;
		}

		// untrailingslashit() on relative url will change '/asdf/' => '/asdf' but may return empty: '/' => ''.
		if ( $untraillashit && strlen( $path ) < 2 ) {
			$untraillashit = false;
		}

		$path = ! $untraillashit ? $path : untrailingslashit( $path );
		$path = self::normalize_relative_url( $path );

		return $path;
	}

	/** Get the "path & query" part of an URL (absolute or relative).
	 *
	 * @since 3.23.0
	 *
	 * @param string  $url           Absolute or relative URL.
	 * @param boolean $untraillashit [Optional]. Removes last slashes and backslashes if they exist.
	 *
	 * @return string|null String on success, null(unknown) on error.
	 */
	public static function url_pathquery( string $url, bool $untraillashit = false ) {
		// see self::url_path() comments.
		$path_query = '';
		if ( self::is_absolute_url( $url ) ) {
			// if is absolute use wp_parse_url() so the 'scheme://host' part is removed.
			$components = wp_parse_url( $url );
			if ( false === $components ) {
				return null;
			}

			// respect the last URL slash if any.
			$path     = self::array_prop( 'path', $components, '' );
			$query    = self::array_prop( 'query', $components, '' );
			$fragment = self::array_prop( 'fragment', $components, '' );

			$path_query  = $path;
			$path_query .= ! empty( $query ) ? "?$query" : '';
			$path_query .= ! empty( $fragment ) ? "#$fragment" : '';
		}

		// untrailingslashit() on relative url will change '/asdf/' => '/asdf' but may return empty: '/' => ''.
		if ( $untraillashit && strlen( $path_query ) < 2 ) {
			$untraillashit = false;
		}

		$path_query = ! $untraillashit ? $path_query : untrailingslashit( $path_query );
		$path_query = self::normalize_relative_url( $path_query );

		return $path_query;
	}

	/** Get the "query" part of an URL( absolute or relative ).
	 *
	 * @since 3.21.0
	 *
	 * @param string  $url           - An absolute or relative URL.  
	 *                               - A query string with the '?' prefix.  
	 *                               - A parsed URL array result from parse_url() or wp_parse_url().
	 * @param boolean $untraillashit [Optional]. Removes last slashes and backslashes if they exist.
	 *
	 * @return string|null A query string without the '?' separator.
	 */
	public static function url_query( $url, bool $untraillashit = false ) {
		if ( is_string( $url ) ) {
			// only a querystring?.
			if ( substr( $url, 0, 1 ) === '?' ) {
				$query = substr( $url, 1 );
			} else { // URL.
				// parse_url() and wp_parse_url() doesn't give correct path results over relative URL's.
				// but the query part is correct, see self::url_path() comments.
				// use parse_url() is faster than wp_parse_url().
				$query = parse_url( $url, PHP_URL_QUERY ); // phpcs:ignore
				$query = is_null( $query ) ? '' : $query; // null if the component doesn't exist in the given URL.
			}
		} elseif ( is_array( $url ) ) {
			$query = self::array_prop( 'query', $url, '' );
		} else {
			return null;
		}

		// untrailingslashit() on relative url will change '/asdf/' => '/asdf' but may return empty: '/' => ''.
		if ( $untraillashit && strlen( $query ) > 1 ) {
			$query = untrailingslashit( $query );
		}

		return $query;
	}

	/** Convert special HTML entities back to characters ( eg.: `&nbsp;` -> ' ' ) and removes null chars ( \0 ).
	 *
	 * @param string $path .
	 *
	 * @return string
	 */
	public static function unescape_html_special_chars( string $path ) {
		// \0 -> null char.
		// entities to chars, &nbsp; -> ' '
		return htmlspecialchars_decode( str_replace( "\0", '', trim( $path ) ) );
	}

	/** Build a valid and secure URL.
	 * ( domain.com/page ≠ domain.com/page/ ≠ domain.com//page/ )
	 *
	 * @since 4.0.0
	 *
	 * @param string       $host          A device connected to a computer network.
	 *                                    (all can include the port) https://superuser.com/q/887173.
	 *                                    >- An URL with the host part specified.
	 *                                    >- An Internet Hostname ( label translated via the hosts file, or a DNS resolver, may have appended a DNS domain ).
	 *                                    >- A Local Hostname/Computer Name(Netbios) eg. DESKTOP-S6COHAF ( can be changed, but this won't automatically update any DNS server unless you have software specifically doing that (such as Active Directory )
	 *                                    >- An IP address. eg ‘192.168.1.1:22’, IPv4 addresses must be in dot-decimal notation, and IPv6 addresses must be enclosed in brackets ([]).
	 * @param string       $path          [Optional]. The URL path.
	 * @param array|string $query         [Optional]. Query component as a query string (without the '?' separator)
	 *                                    or as an array that will be URL-encoded to a query string.
	 * @param string       $scheme        [Optional]. The URL Scheme, only 'http' or 'https'. URL $host scheme has preference. Default 'https'.
	 * @param int          $port          [Optional]. The URL Port, URL $host port has preference. Default null.
	 * @param bool         $use_host_path [Optional]. Use the host path if any as a base to the passed path arg.
	 *
	 * @return string
	 */
	public static function build_url(
		string $host,
		string $path = null,
		$query = [],
		string $scheme = null,
		int $port = null,
		bool $use_host_path = false
	) {
		if ( ! in_array( $scheme, [ 'http', 'https' ], true ) ) {
			$scheme = 'https';
		}

		$query_args = [];
		if ( is_array( $query ) ) {
			$query_args = $query;
		} elseif ( is_string( $query ) && ! empty( $query ) ) {
			$query_args = self::url_query_to_array( $query );
		}

		// parse_url() handles only absolute URL's, wp_parse_url() handles schemeless and relative URLs.
		// if no scheme wp_parse_url( 'example.com' ) returns host as path -> [ 'path' => 'example.com' ].
		// wp_parse_url( '192.168.1.1:22' ) => [ "host" => "192.168.1.1", "port" => 22 ].
		// wp_parse_url( 'localhost:22' )   => [ "host" => "localhost", "port" => 22 ].
		$host_components = wp_parse_url( $host );
		if ( ! empty( $host_components['host'] ) ) {
			$scheme = self::array_prop( 'scheme', $host_components, $scheme );
			$host   = $host_components['host'];
			$port   = self::array_prop( 'port', $host_components, $port );
		} else {
			$use_host_path = false;
		}

		# domain.com/page ≠ domain.com/page/.
		# final URL is absolute so respect an empty ended $path '', read normalize_relative_url().
		if ( ! empty( $path ) ) {
			$path = self::normalize_relative_url( $path, false );
		}

		if ( $use_host_path ) {
			$base_path = self::array_prop( 'path', $host_components, '' );
			# final URL is absolute so respect an empty ended $path '', read normalize_relative_url().
			if ( ! empty( $base_path ) ) {
				$base_path = self::normalize_relative_url(
					$base_path, false
				);
			}

			$path = self::merge_url_paths( $base_path, $path );
		}

		$url = "$scheme://$host" . ( empty( $port ) ? '' : ":$port" ) . $path;

		if ( ! empty( $query_args ) ) {
			// http_build_query(): Generates a URL-encoded query string.
			$query_string = http_build_query( $query_args, '', '&' );

			// domain.com/page? ≠ domain.com/page/?.
			$url = empty( $query_string ) ? $url : "$url?$query_string";
		}

		return $url;
	}

	/** Undocumented.
	 *
	 * @since 3.42.0
	 *
	 * @param string $base_path     .
	 * @param string $appended_path .
	 *
	 * @return string
	 */
	public static function merge_url_paths( string $base_path, string $appended_path ) {
		if ( empty( $appended_path ) ) {
			return $base_path;
		}
		return untrailingslashit( $base_path ) .
			self::normalize_relative_url(
				$appended_path, false
			);
	}

	/** Normalize a relative URL,
	 * - replace multiple starting slashes with one.
	 * - empty path is converted to '/'.
	 *
	 * ======== domain.com/page ≠ domain.com/page/.
	 * - Respect the last trailing slash, RFC 3986:
	 *   /users path includes one segment: "users".
	 *   /users/ path includes two segments: "users" and an empty segment.
	 * - Each will have a different cache entry, so both are valid and different.
	 * - https://stackoverflow.com/a/61547216.
	 *
	 * @since 3.1.0
	 *
	 * @param string $relative_url   .
	 * @param bool   $check_url_type [Optional]. Check if the URL is really relative
	 *                               before the normalization.
	 *
	 * @return string
	 */
	public static function normalize_relative_url(
		string $relative_url,
		bool $check_url_type = true
	) {
		if ( $check_url_type &&
			self::is_absolute_url( $relative_url )
		) {
			return $relative_url;
		}

		/** Remove multiple starting slashes.
		 *
		 * When authority is present (absolute URI), the path can either be empty or begin with a slash ("/") character.
		 * When authority is not present (relative URI), the path cannot begin with two slash characters ("//").
		 *
		 * Component parts:
		 *   foo://example.com:8042/over/there?name=ferret#nose
		 *   \_/   \______________/\_________/ \_________/ \__/
		 *    |           |            |            |        |
		 * scheme     authority       path        query   fragment
		 *
		 * @link https://www.rfc-editor.org/rfc/rfc3986#section-3.
		 * @link https://stackoverflow.com/questions/20523318/is-a-url-with-in-the-path-section-valid
		 */

		# Return empty path as '/'.
		# (Do not confuse "absolute path" with "absolute URI").
		# "An absolute path cannot be empty; if none is present in the original URI,
		# it MUST be given as "/" (the server root)."
		# https://www.w3.org/Protocols/rfc2616/rfc2616-sec5.html.

		return '/' . ltrim( $relative_url, '/\\' );
	}

	/** Parses a URL query string into an array.
	 * Wrapper of parse_str().
	 *
	 * @since 3.40.0
	 * @see parse_str()
	 *
	 * @param string $query_string A query string without the '?' separator.
	 *
	 * @return array
	 */
	public static function url_query_to_array( string $query_string ) {
		parse_str( $query_string, $result );
		return $result;
	}

	/** Wrapper of parse_url() with all the components in the returning array.
	 * Parse a URL and return its components.
	 *
	 * @since 3.42.0
	 * @link https://php.net/manual/en/function.parse-url.php
	 *
	 * @param string $url The URL to parse. Invalid characters are replaced by _.
	 * @param int    $component [optional] <p>
	 * Specify one of PHP_URL_SCHEME,
	 * PHP_URL_HOST, PHP_URL_PORT,
	 * PHP_URL_USER, PHP_URL_PASS,
	 * PHP_URL_PATH, PHP_URL_QUERY
	 * or PHP_URL_FRAGMENT to retrieve just a specific
	 * URL component as a string.
	 * </p>.
	 *
	 * @return array{scheme:string, host:string, port:int, user:string, pass:string, query:string, path:string, fragment:string}|string|int|null|false On seriously malformed URLs, parse_url() may return FALSE.
	 * If the component parameter is omitted, an associative array is returned.
	 * At least one element will be present within the array. Potential keys within this array are:
	 * scheme - e.g. http
	 * host
	 * port
	 * user
	 * pass
	 * path
	 * query - after the question mark ?
	 * fragment - after the hashmark #
	 * </p>
	 * <p>
	 * If the component parameter is specified a
	 * string is returned instead of an array.
	 */
	public static function parse_absolute_url( string $url, int $component = -1 ) {
		/** DO NOT USE parse_url() to get the path on relative or invalid URLs, CAUTION:
		 * "This function may not give correct results, and the results may not even match common behavior of HTTP clients".
		 * https://www.php.net/manual/en/function.parse-url.php.
		 *
		 * The parse_url() expects an absolute URL, so you will get incorrect results in some cases,
		 * e.g. if your REQUEST_URL starts with multiple consecutive slashes. Those will be interpreted as a protocol-relative URI.
		 * https://stackoverflow.com/a/25050598/11420308
		 */
		if ( ! self::is_absolute_url( $url ) ) {
			return false;
		}
		$parse_result = parse_url( $url, $component ); // phpcs:ignore
		if ( false === $parse_result ) {
			return false;
		}

		if ( is_array( $parse_result ) ) {
			$parse_result = self::parse_url_args( $parse_result );
		}

		return $parse_result;
	}

	/** Is an absolute URL.
	 * wich means is the full URL, including protocol (http, https, ftp), domain ( www.example.com ), and path (which includes the directory and slug)
	 *
	 * @since 3.1.0
	 * @link https://stackoverflow.com/a/40512987/11420308
	 *
	 * @param string|array $url Absolute URL or parsed URL array result from parse_url() or wp_parse_url()..
	 *
	 * @return string|false|null URL scheme if is absolute, false otherwise and null(unknown) on invalid URL.
	 */
	public static function is_absolute_url( $url ) {
		if ( is_string( $url ) ) {
			if ( substr( $url, 0, 1 ) === '/' ||
				// minimun absolute url lenght 5: a://a.
				strlen( $url ) < 5
			) {
				return false;
			}

			// chars before the first '://' in the URL are only alphabetic letters?
			$scheme = strtok( $url, '://' );
			if ( ! $scheme || // no scheme found.
				! ctype_alpha( $scheme ) // scheme with only alphabetic letters.
			) {
				return false;
			}

			// confirm URL has a valid scheme, !!! dont use self::parse_absolute_url() a circular deadlock will occur.
			$scheme = parse_url( $url, PHP_URL_SCHEME ); // phpcs:ignore
			return null !== $scheme ? $scheme : false;
		} elseif ( is_array( $url ) ) {
			return isset( $url['scheme'], $url['host'] ) ? $url['scheme'] : false;
		} else {
			return null;
		}
	}

	/** Undocumented.
	 *
	 * @since 3.42.0
	 *
	 * @param array $url_args .
	 * @param bool  $check_errors .
	 *
	 * @return array
	 */
	public static function parse_url_args( array $url_args, bool $check_errors = true ) {
		// default empty string, because other functions could use trim(), untrailingslashit(), etc.
		$url_args_defaults = [
			'host'     => '',
			'scheme'   => '',
			'port'     => null, // an empty integer doesn't exist.
			'user'     => '',
			'pass'     => '',
			'path'     => '',
			'query'    => [],
			'fragment' => '',
		];

		$url_args = wp_parse_args( $url_args, $url_args_defaults );
		if ( ! $check_errors ) {
			return $url_args;
		}

		return $url_args;
	}

	/** Get a specific key or key path value of an array without needing to check if that key or key path exists.
	 * - A default value can be pass wich will be returned if the key or key path value is not set or REALLY empty:
	 *   doesn't exists or his value is: null, '', [].
	 *
	 * ( Based in rgar() from gravity forms \gravityforms\gravityforms.php ).
	 *
	 * @since  4.2.0
	 * @access public
	 *
	 * @param string|int|array $key        Key or key path( [ 'key', 'subkey'] ) of the value to be retrieved.
	 * @param mixed            $array      Array from which the key or key path value should be retrieved.
	 * @param mixed            $default    [Optional]. Value that should be returned if the key or key path value is not set or REALLY empty. Defaults to null.
	 * @param bool             $check_type [Optional]. Check whether the key or key path value type is valid based in the default value,
	 *                                     if isn't valid the default value will be returned.
	 *
	 * @return null|string|mixed The value.
	 */
	public static function array_prop(
		$key,
		$array,
		$default = null,
		bool $check_type = false
	) {
		$key = ! is_array( $key ) ? [ $key ] : $key;
		return self::multiarray_prop(
			$key, $array, $default, $check_type
		);
	}

	/** Get a specific key path value of a multidimensional array without needing to check if that key path exists.
	 * - A default value can be pass wich will be returned if the key path value is not set or is REALLY empty.
	 *
	 * @since 1.0.0
	 * @since 3.50.0 $check_type param added.
	 * @link https://stackoverflow.com/questions/36334635/dynamically-accessing-multidimensional-array-value
	 *
	 * @param string[] $keys_path  Key path of the value to be retrieved, in the format: [ 'key', 'subkey', 'subsubkey' ].
	 * @param mixed    $array      Multidimensional array from which the key path value should be retrieved.
	 * @param mixed    $default    [Optional]. Value that should be returned if the key path value is not set or REALLY empty.
	 * @param bool     $check_type [Optional]. Check whether the key path value type is valid based in the default value,
	 *                             if isn't valid the default value will be returned.
	 *
	 * @return mixed
	 */
	public static function multiarray_prop(
		array $keys_path,
		$array,
		$default = null,
		bool $check_type = false
	) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
		if ( ! ( is_array( $array ) ||
			( is_object( $array ) && $array instanceof \ArrayAccess ) )
		) {
			return $default;
		}

		$array_value = $array;
		foreach ( $keys_path as $key ) {
			if ( ! ( is_string( $key ) || is_int( $key ) ) ) {
				return $default;
			}

			// array value doesnt exist, so set it as null (not have a value).
			if ( ! isset( $array_value[ $key ] ) ) {
				$array_value = null;
				break;
			}

			// check next subarray.
			$array_value = $array_value[ $key ];
		}

		// ¿a default value was set and ..
		if ( null !== $default && (
				// the existing value is empty.
				self::is_empty( $array_value ) ||
				// or the existing value type is invalid.
				(
					$check_type &&
					gettype( $array_value ) !== gettype( $default )
				)
			)
		) {
			return $default;
		} else {
			return $array_value;
		}
	}

	/** Determine whether a set of variables are really empty.
	 * i.e. the variables don't exists or its values are: null, '', [].
	 *
	 * Native empty() considers also: false, '0', 0.
	 *
	 * If multiple parameters are supplied then will return true only if all of the parameters are considered empty.
	 *
	 * @since 4.2.0
	 *
	 * @param mixed ...$vars The variables to be checked.
	 *
	 * @return bool
	 */
	public static function is_empty( ...$vars ) {
		if ( count( $vars ) > 1 ) {
			foreach ( $vars as $var ) {
				if ( self::is_empty( $var ) ) {
					continue;
				}
				return false;
			}
			return true;
		}

		$var = $vars[0];

		if ( ! isset( $var ) ) {
			return true;
		} else {
			if ( is_null( $var ) ||
				in_array( $var, [ '', [] ], true )
			) {
				return true;
			}
			return false;
		}
	}
}

/**
 * Removes trailing forward slashes and backslashes if they exist.
 *
 * The primary use of this is for paths and thus should be used for paths. It is
 * not restricted to paths and offers no specific path support.
 *
 * @since 2.2.0
 *
 * @param string $value Value from which trailing slashes will be removed.
 * @return string String without the trailing slashes.
 */
function untrailingslashit( $value ) {
	return rtrim( $value, '/\\' );
}

/**
 * A wrapper for PHP's parse_url() function that handles consistency in the return values
 * across PHP versions.
 *
 * Across various PHP versions, schemeless URLs containing a ":" in the query
 * are being handled inconsistently. This function works around those differences.
 *
 * @since 4.4.0
 * @since 4.7.0 The `$component` parameter was added for parity with PHP's `parse_url()`.
 *
 * @link https://www.php.net/manual/en/function.parse-url.php
 *
 * @param string $url       The URL to parse.
 * @param int    $component The specific component to retrieve. Use one of the PHP
 *                          predefined constants to specify which one.
 *                          Defaults to -1 (= return all parts as an array).
 * @return mixed False on parse failure; Array of URL components on success;
 *               When a specific component has been requested: null if the component
 *               doesn't exist in the given URL; a string or - in the case of
 *               PHP_URL_PORT - integer when it does. See parse_url()'s return values.
 */
function wp_parse_url( $url, $component = -1 ) {
	$to_unset = array();
	$url      = (string) $url;

	if ( str_starts_with( $url, '//' ) ) {
		$to_unset[] = 'scheme';
		$url        = 'placeholder:' . $url;
	} elseif ( str_starts_with( $url, '/' ) ) {
		$to_unset[] = 'scheme';
		$to_unset[] = 'host';
		$url        = 'placeholder://placeholder' . $url;
	}

	$parts = parse_url( $url );

	if ( false === $parts ) {
		// Parsing failure.
		return $parts;
	}

	// Remove the placeholder values.
	foreach ( $to_unset as $key ) {
		unset( $parts[ $key ] );
	}

	return _get_component_from_parsed_url_array( $parts, $component );
}

/**
 * Retrieves a specific component from a parsed URL array.
 *
 * @internal
 *
 * @since 4.7.0
 * @access private
 *
 * @link https://www.php.net/manual/en/function.parse-url.php
 *
 * @param array|false $url_parts The parsed URL. Can be false if the URL failed to parse.
 * @param int         $component The specific component to retrieve. Use one of the PHP
 *                               predefined constants to specify which one.
 *                               Defaults to -1 (= return all parts as an array).
 * @return mixed False on parse failure; Array of URL components on success;
 *               When a specific component has been requested: null if the component
 *               doesn't exist in the given URL; a string or - in the case of
 *               PHP_URL_PORT - integer when it does. See parse_url()'s return values.
 */
function _get_component_from_parsed_url_array( $url_parts, $component = -1 ) {
	if ( -1 === $component ) {
		return $url_parts;
	}

	$key = _wp_translate_php_url_constant_to_key( $component );
	if ( false !== $key && is_array( $url_parts ) && isset( $url_parts[ $key ] ) ) {
		return $url_parts[ $key ];
	} else {
		return null;
	}
}

/**
 * Translates a PHP_URL_* constant to the named array keys PHP uses.
 *
 * @internal
 *
 * @since 4.7.0
 * @access private
 *
 * @link https://www.php.net/manual/en/url.constants.php
 *
 * @param int $constant PHP_URL_* constant.
 * @return string|false The named key or false.
 */
function _wp_translate_php_url_constant_to_key( $constant ) {
	$translation = array(
		PHP_URL_SCHEME   => 'scheme',
		PHP_URL_HOST     => 'host',
		PHP_URL_PORT     => 'port',
		PHP_URL_USER     => 'user',
		PHP_URL_PASS     => 'pass',
		PHP_URL_PATH     => 'path',
		PHP_URL_QUERY    => 'query',
		PHP_URL_FRAGMENT => 'fragment',
	);

	if ( isset( $translation[ $constant ] ) ) {
		return $translation[ $constant ];
	} else {
		return false;
	}
}

/**
 * Merges user defined arguments into defaults array.
 *
 * This function is used throughout WordPress to allow for both string or array
 * to be merged into another array.
 *
 * @since 2.2.0
 * @since 2.3.0 `$args` can now also be an object.
 *
 * @param string|array|object $args     Value to merge with $defaults.
 * @param array               $defaults Optional. Array that serves as the defaults.
 *                                      Default empty array.
 * @return array Merged user defined values with defaults.
 */
function wp_parse_args( $args, $defaults = array() ) {
	if ( is_object( $args ) ) {
		$parsed_args = get_object_vars( $args );
	} elseif ( is_array( $args ) ) {
		$parsed_args =& $args;
	} else {
		parse_str( $args, $parsed_args );
	}

	if ( is_array( $defaults ) && $defaults ) {
		return array_merge( $defaults, $parsed_args );
	}
	return $parsed_args;
}