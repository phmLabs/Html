<?php

namespace whm\Html;

interface CookieAware
{
    public function hasCookies();

    public function getCookies();

    public function addCookie($key, $value);

    public function addCookies(array $cookies);

    public function getCookieString();
}