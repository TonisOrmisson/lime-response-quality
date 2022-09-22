<?php

class ApiConfig
{
    public string $url;
    public string $authBearerToken;
    public function __construct(string $url, string $authBearerToken)
    {
        $this->url = $url;
        $this->authBearerToken = $authBearerToken;
    }

}
