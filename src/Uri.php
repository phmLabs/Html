<?php

namespace whm\Html;

use InvalidArgumentException;
use Psr\Http\Message\UriInterface;

/**
 * Implementation of Psr\Http\UriInterface.
 *
 * Provides a value object representing a URI for HTTP requests.
 *
 * Instances of this class  are considered immutable; all methods that
 * might change state are implemented such that they retain the internal
 * state of the current instance and return a new instance that contains the
 * changed state.
 */
class Uri implements CookieAware, UriInterface
{
    /**
     * Sub-delimiters used in query strings and fragments.
     *
     * @const string
     */
    const CHAR_SUB_DELIMS = '!\$&\'\(\)\*\+,;=';

    /**
     * Unreserved characters used in paths, query strings, and fragments.
     *
     * @const string
     */
    const CHAR_UNRESERVED = 'a-zA-Z0-9_\-\.~';

    /**
     * @var int[] Array indexed by valid scheme names to their corresponding ports.
     */
    protected $allowedSchemes = [
        'http' => 80,
        'https' => 443,
    ];

    /**
     * @var string
     */
    private $scheme = '';

    /**
     * @var string
     */
    private $userInfo = '';

    /**
     * @var string
     */
    private $host = '';

    private $session;

    private $cookies = array();

    /**
     * @var int
     */
    private $port;

    /**
     * @var string
     */
    private $path = '';

    /**
     * @var string
     */
    private $query = '';

    /**
     * @var string
     */
    private $fragment = '';

    /**
     * generated uri string cache
     * @var string|null
     */
    private $uriString;

    /**
     * @param string $uri
     * @throws InvalidArgumentException on non-string $uri argument
     */
    public function __construct($uri = '', $encodePercent = false)
    {
        if ($uri instanceof UriInterface) {
            $uri = (string)$uri;
        }

        if ($encodePercent) {
            $uri = self::encodeUrl($uri);
        }

        if (!is_string($uri)) {
            throw new InvalidArgumentException(sprintf(
                'URI passed to constructor must be a string; received "%s"',
                (is_object($uri) ? get_class($uri) : gettype($uri))
            ));
        }

        if (!empty($uri)) {
            $this->parseUri($uri);
        }
    }

    /**
     * Operations to perform on clone.
     *
     * Since cloning usually is for purposes of mutation, we reset the
     * $uriString property so it will be re-calculated.
     */
    public function __clone()
    {
        $this->uriString = null;
    }

    /**
     * {@inheritdoc}
     */
    public function __toString()
    {
        if (null !== $this->uriString) {
            return $this->uriString;
        }

        $this->uriString = static::createUriString(
            $this->scheme,
            $this->getAuthority(),
            $this->getPath(), // Absolute URIs should use a "/" for an empty path
            $this->query,
            $this->fragment
        );

        return $this->uriString;
    }

    /**
     * {@inheritdoc}
     */
    public function getScheme()
    {
        return $this->scheme;
    }

    /**
     * {@inheritdoc}
     */
    public function getAuthority()
    {
        if (empty($this->host)) {
            return '';
        }

        $authority = $this->host;
        if (!empty($this->userInfo)) {
            $authority = $this->userInfo . '@' . $authority;
        }

        if ($this->isNonStandardPort($this->scheme, $this->host, $this->port)) {
            $authority .= ':' . $this->port;
        }

        return $authority;
    }

    /**
     * {@inheritdoc}
     */
    public function getUserInfo()
    {
        return $this->userInfo;
    }

    /**
     * {@inheritdoc}
     */
    public function getHost($depth = null)
    {
        if ($depth) {
            $domainElements = explode('.', $this->host);
            $host = '';
            for ($i = count($domainElements) - 1; $i > count($domainElements) - $depth - 1; --$i) {
                $host = $domainElements[$i] . '.' . $host;
            }
            return substr($host, 0, strlen($host) - 1);
        }
        return $this->host;
    }

    /**
     * {@inheritdoc}
     */
    public function getPort()
    {
        return $this->isNonStandardPort($this->scheme, $this->host, $this->port)
            ? $this->port
            : null;
    }

    /**
     * {@inheritdoc}
     */
    public function getPath()
    {
        return $this->path;
    }

    /**
     * {@inheritdoc}
     */
    public function getQuery()
    {
        return $this->query;
    }

    /**
     * {@inheritdoc}
     */
    public function getFragment()
    {
        return $this->fragment;
    }

    /**
     * {@inheritdoc}
     */
    public function withScheme($scheme)
    {
        $scheme = $this->filterScheme($scheme);

        if ($scheme === $this->scheme) {
            // Do nothing if no change was made.
            return clone $this;
        }

        $new = clone $this;
        $new->scheme = $scheme;

        return $new;
    }

    /**
     * {@inheritdoc}
     */
    public function withUserInfo($user, $password = null)
    {
        $info = $user;
        if ($password) {
            $info .= ':' . $password;
        }

        if ($info === $this->userInfo) {
            // Do nothing if no change was made.
            return clone $this;
        }

        $new = clone $this;
        $new->userInfo = $info;

        return $new;
    }

    /**
     * {@inheritdoc}
     */
    public function withHost($host)
    {
        if ($host === $this->host) {
            // Do nothing if no change was made.
            return clone $this;
        }

        $new = clone $this;
        $new->host = $host;

        return $new;
    }

    /**
     * {@inheritdoc}
     */
    public function withPort($port)
    {
        if (!(is_integer($port) || (is_string($port) && is_numeric($port)))) {
            throw new InvalidArgumentException(sprintf(
                'Invalid port "%s" specified; must be an integer or integer string',
                (is_object($port) ? get_class($port) : gettype($port))
            ));
        }

        $port = (int)$port;

        if ($port === $this->port) {
            // Do nothing if no change was made.
            return clone $this;
        }

        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException(sprintf(
                'Invalid port "%d" specified; must be a valid TCP/UDP port',
                $port
            ));
        }

        $new = clone $this;
        $new->port = $port;

        return $new;
    }

    /**
     * {@inheritdoc}
     */
    public function withPath($path)
    {
        if (!is_string($path)) {
            throw new InvalidArgumentException(
                'Invalid path provided; must be a string'
            );
        }

        if (strpos($path, '?') !== false) {
            throw new InvalidArgumentException(
                'Invalid path provided; must not contain a query string'
            );
        }

        if (strpos($path, '#') !== false) {
            throw new InvalidArgumentException(
                'Invalid path provided; must not contain a URI fragment'
            );
        }

        $path = $this->filterPath($path);

        if ($path === $this->path) {
            // Do nothing if no change was made.
            return clone $this;
        }

        $new = clone $this;
        $new->path = $path;

        return $new;
    }

    /**
     * {@inheritdoc}
     */
    public function withQuery($query)
    {
        if (!is_string($query)) {
            throw new InvalidArgumentException(
                'Query string must be a string'
            );
        }

        if (strpos($query, '#') !== false) {
            throw new InvalidArgumentException(
                'Query string must not include a URI fragment'
            );
        }

        $query = $this->filterQuery($query);

        if ($query === $this->query) {
            // Do nothing if no change was made.
            return clone $this;
        }

        $new = clone $this;
        $new->query = $query;

        return $new;
    }

    /**
     * {@inheritdoc}
     */
    public function withFragment($fragment)
    {
        $fragment = $this->filterFragment($fragment);

        if ($fragment === $this->fragment) {
            // Do nothing if no change was made.
            return clone $this;
        }

        $new = clone $this;
        $new->fragment = $fragment;

        return $new;
    }

    /**
     * Parse a URI into its parts, and set the properties
     */
    private function parseUri($uri)
    {
        $parts = parse_url($uri);

        if (false === $parts) {
            throw new \InvalidArgumentException(
                'The source URI string appears to be malformed'
            );
        }

        $this->scheme = isset($parts['scheme']) ? $this->filterScheme($parts['scheme']) : '';
        $this->userInfo = isset($parts['user']) ? $parts['user'] : '';
        $this->host = isset($parts['host']) ? $parts['host'] : '';
        $this->port = isset($parts['port']) ? $parts['port'] : null;
        $this->path = isset($parts['path']) ? $this->filterPath($parts['path']) : '';
        $this->query = isset($parts['query']) ? $this->filterQuery($parts['query']) : '';
        $this->fragment = isset($parts['fragment']) ? $this->filterFragment($parts['fragment']) : '';

        if (isset($parts['pass'])) {
            $this->userInfo .= ':' . $parts['pass'];
        }
    }

    /**
     * Create a URI string from its various parts
     *
     * @param string $scheme
     * @param string $authority
     * @param string $path
     * @param string $query
     * @param string $fragment
     * @return string
     */
    private static function createUriString($scheme, $authority, $path, $query, $fragment)
    {
        $uri = '';

        if (!empty($scheme)) {
            $uri .= sprintf('%s://', $scheme);
        }

        if (!empty($authority)) {
            $uri .= $authority;
        }

        if ($path) {
            if (empty($path) || '/' !== substr($path, 0, 1)) {
                $path = '/' . $path;
            }

            $uri .= $path;
        }

        if ($query) {
            $uri .= sprintf('?%s', $query);
        }

        if ($fragment) {
            $uri .= sprintf('#%s', $fragment);
        }

        return $uri;
    }

    /**
     * Is a given port non-standard for the current scheme?
     *
     * @param string $scheme
     * @param string $host
     * @param int $port
     * @return bool
     */
    private function isNonStandardPort($scheme, $host, $port)
    {
        if (!$scheme) {
            return true;
        }

        if (!$host || !$port) {
            return false;
        }

        return !isset($this->allowedSchemes[$scheme]) || $port !== $this->allowedSchemes[$scheme];
    }

    /**
     * Filters the scheme to ensure it is a valid scheme.
     *
     * @param string $scheme Scheme name.
     *
     * @return string Filtered scheme.
     */
    private function filterScheme($scheme)
    {
        $scheme = strtolower($scheme);
        $scheme = preg_replace('#:(//)?$#', '', $scheme);

        if (empty($scheme)) {
            return '';
        }

        if (!array_key_exists($scheme, $this->allowedSchemes)) {
            throw new InvalidArgumentException(sprintf(
                'Unsupported scheme "%s"; must be any empty string or in the set (%s)',
                $scheme,
                implode(', ', array_keys($this->allowedSchemes))
            ));
        }

        return $scheme;
    }

    /**
     * Filters the path of a URI to ensure it is properly encoded.
     *
     * @param string $path
     * @return string
     */
    private function filterPath($path)
    {
        return preg_replace_callback(
            '/(?:[^' . self::CHAR_UNRESERVED . ':@&=\+\$,\/;%]+|%(?![A-Fa-f0-9]{2}))/',
            [$this, 'urlEncodeChar'],
            $path
        );
    }

    /**
     * Filter a query string to ensure it is propertly encoded.
     *
     * Ensures that the values in the query string are properly urlencoded.
     *
     * @param string $query
     * @return string
     */
    private function filterQuery($query)
    {
        if (!empty($query) && strpos($query, '?') === 0) {
            $query = substr($query, 1);
        }

        $parts = explode('&', $query);
        foreach ($parts as $index => $part) {
            list($key, $value) = $this->splitQueryValue($part);
            if ($value === null) {
                $parts[$index] = $this->filterQueryOrFragment($key);
                continue;
            }
            $parts[$index] = sprintf(
                '%s=%s',
                $this->filterQueryOrFragment($key),
                $this->filterQueryOrFragment($value)
            );
        }

        return implode('&', $parts);
    }

    /**
     * Split a query value into a key/value tuple.
     *
     * @param string $value
     * @return array A value with exactly two elements, key and value
     */
    private function splitQueryValue($value)
    {
        $data = explode('=', $value, 2);
        if (1 === count($data)) {
            $data[] = null;
        }
        return $data;
    }

    /**
     * Filter a fragment value to ensure it is properly encoded.
     *
     * @param null|string $fragment
     * @return string
     */
    private function filterFragment($fragment)
    {
        if (null === $fragment) {
            $fragment = '';
        }

        if (!empty($fragment) && strpos($fragment, '#') === 0) {
            $fragment = substr($fragment, 1);
        }

        return $this->filterQueryOrFragment($fragment);
    }

    /**
     * Filter a query string key or value, or a fragment.
     *
     * @param string $value
     * @return string
     */
    private function filterQueryOrFragment($value)
    {
        return preg_replace_callback(
            '/(?:[^' . self::CHAR_UNRESERVED . self::CHAR_SUB_DELIMS . '%:@\/\?]+|%(?![A-Fa-f0-9]{2}))/',
            [$this, 'urlEncodeChar'],
            $value
        );
    }

    /**
     * URL encode a character returned by a regex.
     *
     * @param array $matches
     * @return string
     */
    private function urlEncodeChar(array $matches)
    {
        return rawurlencode($matches[0]);
    }

    public function setSessionIdentifier($identifier)
    {
        $this->session = $identifier;
    }

    public function getSessionIdentifier()
    {
        return $this->session;
    }

    public function hasCookies()
    {
        return count($this->cookies) > 0;
    }

    public function getCookies()
    {
        return $this->cookies;
    }

    public function addCookie($key, $value)
    {
        $this->cookies[$key] = $value;
    }

    public function addCookies(array $cookies)
    {
        foreach ($cookies as $key => $value) {
            $this->addCookie($key, $value);
        }
    }

    public function getCookieString()
    {
        $cookieString = "";

        foreach ($this->cookies as $key => $value) {
            $cookieString .= $key . '=' . $value . '; ';
        }

        return $cookieString;
    }

    /**
     * @param UriInterface $uri
     * @return UriInterface
     */
    public static function createAbsoluteUrl(UriInterface $uri, UriInterface $originUrl)
    {
        // @example href=""
        if ((string)$uri == "" || strpos((string)$uri, "#") === 0) {
            return $originUrl;
        }

        // @example href="?cat=1"
        if (strpos((string)$uri, "?") === 0) {
            return new Uri($originUrl->getScheme() . "://" . $originUrl->getHost() . $originUrl->getPath() . (string)$uri);
        }

        if ($uri->getScheme() === '') {
            if ($uri->getQuery() !== '') {
                $query = '?' . $uri->getQuery();
            } else {
                $query = '';
            }

            if ($uri->getHost() !== '') {
                $uriString = $originUrl->getScheme() . '://' . $uri->getHost() . $uri->getPath() . $query;
            } else {
                if (strpos($uri->getPath(), '/') === 0) {
                    // absolute path
                    $uriString = $originUrl->getScheme() . '://' . $originUrl->getHost() . $uri->getPath() . $query;
                } else {
                    // relative path
                    if (strrpos($originUrl->getPath(), '/') === strlen($originUrl->getPath()) - 1) {
                        $uriString = $originUrl->getScheme() . '://' . $originUrl->getHost() . $originUrl->getPath() . $uri->getPath() . $query;
                    } else {
                        $pathParts = pathinfo($originUrl->getPath());
                        if (array_key_exists('dirname', $pathParts)) {
                            $dirname = $pathParts['dirname'];
                            if ($dirname != "/") {
                                $dirname .= "/";
                            }
                        } else {
                            $dirname = "/";
                        }
                        $uriString = $originUrl->getScheme() . '://' . $originUrl->getHost() . $dirname . $uri->getPath() . $query;
                    }
                }
            }

            $resultUri = new Uri($uriString);
        } else {
            $resultUri = $uri;
        }

        $cleanUri = self::removeRelativeParts($resultUri);

        return $cleanUri;
    }

    private static function removeRelativeParts(UriInterface $uri)
    {
        $count = 1;
        $cleanPath = $uri->getPath();

        while ($count != 0) {
            $cleanPath = preg_replace('~[^\/\.]+\/\.\.\/~', '', $cleanPath, -1, $count);
        }

        return $uri->withPath($cleanPath);
    }

    private static function getDomain(UriInterface $uri)
    {
        $host = $uri->getHost();

        $host_names = explode(".", $host);

        if (count($host_names) > 1) {
            return $host_names[count($host_names) - 2] . "." . $host_names[count($host_names) - 1];
        } else {
            return $host_names[0];
        }
    }

    public static function getSubdomain(UriInterface $uri)
    {
        $parsedUrl = parse_url((string)$uri);
        $host = explode('.', $parsedUrl['host']);
        $subdomains = array_slice($host, 0, count($host) - 2);

        return implode('.', $subdomains);
    }

    public static function isEqualDomain(UriInterface $uri1, UriInterface $uri2)
    {
        return self::getDomain($uri1) == self::getDomain($uri2);
    }

    public static function isBasicAuth(UriInterface $uri)
    {
        $urlString = (string)$uri;
        return (bool)preg_match('^://(.*):(.*)@^', $urlString, $matches);
    }

    public static function getBasicAuthCredentials(UriInterface $uri)
    {
        preg_match('^://(.*):(.*)@^', (string)$uri, $matches);
        return ['username' => $matches[1], 'password' => $matches[2]];
    }

    public static function encodeUrl($urlString)
    {
        $parsedUrl = parse_url($urlString);

        if (array_key_exists('scheme', $parsedUrl)) {
            $domainWithScheme = $parsedUrl['scheme'] . '://' . $parsedUrl['host'] . '/';
            $path = str_replace($domainWithScheme, '', $urlString);
        } else {
            $domainWithScheme = '';
            $path = $urlString;
        }

        $normalPath = str_replace('/', 'encodedSlash', $path);
        $normalPath = str_replace('?', 'encodedQuestionMark', $normalPath);
        $normalPath = str_replace('&', 'encodedAmpersand', $normalPath);
        $normalPath = str_replace('=', 'encodedEquals', $normalPath);
        $normalPath = str_replace('#', 'encodedHash', $normalPath);
        $normalPath = str_replace('%', 'encodedPercent', $normalPath);

        $encodedUrl = $domainWithScheme . urlencode($normalPath);
        $encodedUrl = str_replace('encodedSlash', '/', $encodedUrl);
        $encodedUrl = str_replace('encodedQuestionMark', '?', $encodedUrl);
        $encodedUrl = str_replace('encodedAmpersand', '&', $encodedUrl);
        $encodedUrl = str_replace('encodedEquals', '=', $encodedUrl);
        $encodedUrl = str_replace('encodedHash', '#', $encodedUrl);
        $encodedUrl = str_replace('encodedPercent', '%', $encodedUrl);

        return $encodedUrl;
    }
}
