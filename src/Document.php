<?php

namespace whm\Html;

use Psr\Http\Message\UriInterface;
use Symfony\Component\DomCrawler\Crawler;

class Document
{
    private $content;
    private $crawler;
    private $outgoingLinks = null;
    private $dependencies = null;
    private $images = null;
    private $cssFiles = null;
    private $jsFiles = null;
    private $repairUrls;

    /**
     * @param $htmlContent the html content
     * @param bool|false $repairUrls try to repair broken uris like a browser would do
     */
    public function __construct($htmlContent, $repairUrls = false)
    {
        $this->content = $htmlContent;
        $this->crawler = new Crawler($this->content);
        $this->repairUrls = $repairUrls;
    }

    /**
     * @param UriInterface $originUrl
     * @return UriInterface[]
     */
    public function getImages(UriInterface $originUrl = null)
    {
        if (!$this->images) {
            $this->images = $this->getUrls("//img", "src", $originUrl);
        }
        return $this->images;
    }

    public function getCssFiles(UriInterface $originUrl = null)
    {
        if (!$this->cssFiles) {
            $this->cssFiles = $this->getUrls("//link[@rel='stylesheet']", "href", $originUrl);
        }
        return $this->cssFiles;
    }

    public function getJsFiles(UriInterface $originUrl = null)
    {
        if (!$this->jsFiles) {
            $this->jsFiles = $this->getUrls("//script", "src", $originUrl);
        }
        return $this->jsFiles;
    }

    public function getOutgoingLinks(UriInterface $originUrl = null)
    {
        if (!$this->outgoingLinks) {
            $this->outgoingLinks = $this->getUrls("//a", "href", $originUrl);
        }
        return $this->outgoingLinks;
    }

    /**
     * @param UriInterface $originUrl
     * @return UriInterface[]
     */
    public function getDependencies(UriInterface $originUrl = null, $includeOutgoingLinks = true)
    {
        if (is_null($this->dependencies)) {
            $deps = array();

            if ($includeOutgoingLinks) {
                $deps = $this->getOutgoingLinks($originUrl);
            }

            $deps = array_merge($deps, $this->getImages($originUrl));
            $deps = array_merge($deps, $this->getCssFiles($originUrl));
            $deps = array_merge($deps, $this->getJsFiles($originUrl));

            $this->dependencies = $deps;
        }
        return $this->dependencies;
    }

    public function getUnorderedDependencies(UriInterface $originUrl = null)
    {
        $deps = $this->getDependencies($originUrl);
        usort($deps, function ($a, $b) {
            return md5($a) < md5($b);
        });

        return $deps;
    }

    private function isFollowableUrl($url)
    {
        if (strpos($url, 'data:') !== false) {
            return false;
        }
        if ($urlParts = parse_url($url)) {
            if (isset($urlParts['scheme']) && !in_array($urlParts['scheme'], ['http', 'https'], true)) {
                return false;
            }

            return true;
        }

        return false;
    }

    /**
     * @param $urlString
     * @param UriInterface $originUrl
     * @return UriInterface
     */
    private function createAbsoulteUrl(UriInterface $uri, UriInterface $originUrl)
    {
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

            $resultUri = new Uri($uriString);
        } else {
            $resultUri = $uri;
        }
        return $resultUri;
    }

    private function getUrls($xpath, $attribute, UriInterface $originUrl = null)
    {
        $urls = array();
        foreach ($this->crawler->filterXPath($xpath) as $node) {
            if ($node->hasAttribute($attribute) && $this->isFollowableUrl($node->getAttribute($attribute))) {
                $uriString = $node->getAttribute($attribute);

                if( $this->repairUrls) {
                    $uriString = trim($uriString);
                    if(preg_match("/\r|\n/", $uriString)) {
                        $uriString = preg_replace('/[ \t]+/', '', preg_replace('/[\r|\n]+/', "", $uriString));
                    }
                }

                if ($originUrl) {
                    $url = $this->createAbsoulteUrl(new Uri($uriString), $originUrl);
                } else {
                    $url = new Uri($uriString);
                }

                $urls[$uriString] = $url;
            }
        }
        return $urls;
    }
}
