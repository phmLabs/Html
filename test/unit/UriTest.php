<?php

use whm\Html\Uri;

class UriTest extends PHPUnit_Framework_TestCase
{
    public function testGetHost()
    {
        $uri = new Uri("http://www.example.com");

        $this->assertEquals('www.example.com', $uri->getHost());
        $this->assertEquals('com', $uri->getHost(1));
        $this->assertEquals('example.com', $uri->getHost(2));
        $this->assertEquals('www.example.com', $uri->getHost(3));
    }
}
