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

    public function __construct($htmlContent)
    {
        $this->content = $htmlContent;
        $this->crawler = new Crawler($this->content);
    }

    public function getImages(UriInterface $originUrl = null)
    {
        if (!$this->images) {
            $this->images = $this->getUrls("//img", "src", $originUrl);
        }
        return $this->images;
    }

    public function getOutgoingLinks(UriInterface $originUrl = null)
    {
        if (!$this->outgoingLinks) {
            $this->outgoingLinks = $this->getUrls("//a", "href", $originUrl);
        }
        return $this->outgoingLinks;
    }

    public function getDependencies(UriInterface $originUrl = null)
    {
        if (!$this->dependencies) {
            $deps = $this->getOutgoingLinks($originUrl);
            $deps = array_merge($deps, $this->getImages($originUrl));
            $deps = array_merge($deps, $this->getUrls("//link", "href", $originUrl));
            $deps = array_merge($deps, $this->getUrls("//script", "src", $originUrl));

            $this->dependencies = $deps;
        }
        return $this->dependencies;
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
                    if (strpos(strrev($originUrl->getPath()), '/') !== 0) {
                        $separator = '/';
                    } else {
                        $separator = '';
                    }
                    $uriString = $originUrl->getScheme() . '://' . $originUrl->getHost() . $originUrl->getPath() . $separator . $uri->getPath() . $query;
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
            if ($node->hasAttribute($attribute)) {
                if ($originUrl) {
                    $url = $this->createAbsoulteUrl(new Uri($node->getAttribute($attribute)), $originUrl);
                } else {
                    $url = new Uri($node->getAttribute($attribute));
                }
                $urls[$node->getAttribute($attribute)] = $url;
            }
        }
        return $urls;
    }
}
