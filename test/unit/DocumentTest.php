<?php

use whm\Html\Document;
use whm\Html\Uri;

class DocumentTest extends PHPUnit_Framework_TestCase
{
    public function testGetOutgoingLinks()
    {
        $document = new Document(file_get_contents(__DIR__ . '/fixtures/referencedUrls.html'));

        $urls = $document->getOutgoingLinks(new Uri('http://www.example.com/test/'));

        foreach ($urls as $url) {
            $currentUrls[] = (string) $url;
        }

        $expectedUrls = array(
            'http://www.example.com/test/images/relative_path.html?withQuery',
            'http://www.example.com/test/images/relative_path.html',
            'http://www.example.com/',
            'http://www.notexample.com/foreign_domain.html',);

        sort($expectedUrls);
        sort($currentUrls);

        $this->assertEquals($currentUrls, $expectedUrls);
    }

    public function testGetDependencies()
    {
        $document = new Document(file_get_contents(__DIR__ . '/fixtures/referencedUrls.html'));

        $urls = $document->getDependencies(new Uri('http://www.example.com/test/'));

        foreach ($urls as $url) {
            $currentUrls[] = (string) $url;
        }

        $expectedUrls = array(
            'http://foreign-domain-schema-relative.com',
            'http://www.example.com/test/images/relative_path.html?withQuery',
            'http://www.example.com/test/images/relative_path.html',
            'http://foreign-domain-schema-relative.com/file.js',
            'http://www.example.com/',
            'http://fonts.googleapis.com/css?family=Dancing+Script',
            'http://www.example.com/absolute_path.php',
            'http://www.notexample.com/foreign_domain.html',);

        sort($expectedUrls);
        sort($currentUrls);

        $this->assertEquals($currentUrls, $expectedUrls);
    }

    public function testGetImages()
    {
        $document = new Document(file_get_contents(__DIR__ . '/fixtures/referencedUrls.html'));

        $urls = $document->getImages(new Uri('http://www.example.com/test/'));

        foreach ($urls as $url) {
            $currentUrls[] = (string) $url;
        }

        $expectedUrls = array(
            'http://www.example.com/absolute_path.php'
        );

        sort($expectedUrls);
        sort($currentUrls);

        $this->assertEquals($currentUrls, $expectedUrls);
    }

    public function testGetCssFiles()
    {
        $document = new Document(file_get_contents(__DIR__ . '/fixtures/referencedUrls.html'));

        $urls = $document->getCssFiles(new Uri('http://www.example.com/test/'));

        foreach ($urls as $url) {
            $currentUrls[] = (string) $url;
        }

        $expectedUrls = array(
            'http://fonts.googleapis.com/css?family=Dancing+Script'
        );

        sort($expectedUrls);
        sort($currentUrls);

        $this->assertEquals($currentUrls, $expectedUrls);
    }

}
