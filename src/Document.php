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
     * @param string $htmlContent the html content
     * @param bool|false $repairUrls try to repair broken uris like a browser would do
     */
    public function __construct($htmlContent, $repairUrls = false)
    {
        $this->content = $htmlContent;
        $this->crawler = new Crawler($this->content);
        $this->repairUrls = $repairUrls;
    }

    public static function removeScriptTags($htmlContent)
    {
        $withoutScriptTags = preg_replace('^<script(.*?)</script>^', '', $htmlContent);
        return $withoutScriptTags;
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

    public function getOutgoingLinks(UriInterface $originUrl = null, $includeExternalLinks = true, $percentEncode = false)
    {
        $originUrl = $this->handleBaseHeader($originUrl);

        if (!$this->outgoingLinks) {
            $this->outgoingLinks = $this->getUrls("//a", "href", $originUrl, $percentEncode);
        }

        if ($includeExternalLinks || is_null($originUrl)) {
            return $this->outgoingLinks;
        } else {
            $links = [];
            foreach ($this->outgoingLinks as $outgoingLink) {
                if (Uri::isEqualDomain($originUrl, $outgoingLink)) {
                    $links[] = $outgoingLink;
                }
            }
            return $links;
        }
    }

    private function handleBaseHeader(UriInterface $originUrl)
    {
        $baseCrawler = $this->crawler->filterXPath('//html/head/base/@href');
        $baseUrlNode = $baseCrawler->getNode(0);

        if ($baseUrlNode) {
            if (strpos($baseUrlNode->nodeValue, '/') === 0) {
                $originUrl = $originUrl->withPath($baseUrlNode->nodeValue);
            } else if (strpos($baseUrlNode->nodeValue, 'http') === 0) {
                $originUrl = new Uri($baseUrlNode->nodeValue);
            }
        }

        return $originUrl;
    }

    /**
     * @param UriInterface $originUrl
     * @param bool $includeOutgoingLinks
     * @param bool $includeAssets
     * @return UriInterface[]
     */
    public function getDependencies(UriInterface $originUrl, $includeOutgoingLinks = true, $includeAssets = true)
    {
        $originUrl = $this->handleBaseHeader($originUrl);

        if (is_null($this->dependencies)) {
            $deps = array();

            if ($includeOutgoingLinks) {
                $deps = $this->getOutgoingLinks($originUrl);
            }

            if ($includeAssets) {
                $deps = array_merge($deps, $this->getImages($originUrl));
                $deps = array_merge($deps, $this->getCssFiles($originUrl));
                $deps = array_merge($deps, $this->getJsFiles($originUrl));
            }

            $this->dependencies = $deps;
        }
        return $this->dependencies;
    }

    public function getUnorderedDependencies(UriInterface $originUrl = null, $includeOutgoingLinks = true, $includeAssets = true)
    {
        $deps = $this->getDependencies($originUrl, $includeOutgoingLinks, $includeAssets);

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

    private function getUrls($xpath, $attribute, UriInterface $originUrl = null, $encodePercent = false)
    {
        $urls = array();
        foreach ($this->crawler->filterXPath($xpath) as $node) {
            if ($node->hasAttribute($attribute) && $this->isFollowableUrl($node->getAttribute($attribute))) {
                $uriString = $node->getAttribute($attribute);

                if ($this->repairUrls) {
                    $uriString = trim($uriString);
                    if (preg_match("/\r|\n/", $uriString)) {
                        $uriString = preg_replace('/[ \t]+/', '', preg_replace('/[\r|\n]+/', "", $uriString));
                    }
                }

                if ($originUrl) {
                    try {
                        $newUri = new Uri($uriString, $encodePercent);
                        $url = Uri::createAbsoluteUrl($newUri, $originUrl);
                    } catch (\InvalidArgumentException $e) {

                    }
                } else {
                    $url = new Uri($uriString, $encodePercent);
                }

                $urls[$uriString] = $url;
            }
        }
        return $urls;
    }
}
