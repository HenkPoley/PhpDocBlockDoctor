<?php
// tests/fixtures/static-call-wrong-case/CaseInsensitiveStaticCall.php

namespace Pitfalls\StaticCallWrongCase;

class Configuration
{
    public static function setPreLoadedConfig(Configuration $config, string $file = 'config.php', string $set = 'simplesaml'): void
    {
        throw new \Exception('fail');
    }

    public static function loadFromArray(array $config): Configuration
    {
        return new Configuration();
    }
}

class Module
{
    public static function getModules(): array
    {
        return [];
    }
}

class ArrayLogger
{
}

class UnusedTranslatableStringsCommand
{
    protected function configure(): void
    {
        Configuration::setPreloadedConfig(
            Configuration::loadFromArray([
                'module.enable' => array_fill_keys(Module::getModules(), true),
                'logging.handler' => ArrayLogger::class,
            ]),
            'config.php',
            'simplesaml',
        );
    }
}
