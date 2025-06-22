<?php
namespace HenkPoley\DocBlockDoctor\TestFixtures\AnonymousClassProperty\Store;

use HenkPoley\DocBlockDoctor\TestFixtures\AnonymousClassProperty\Client;

class RedisStore
{
    public function __construct(Client $client)
    {
        throw new \RuntimeException();
    }
}
