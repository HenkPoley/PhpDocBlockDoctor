<?php
namespace Pitfalls\GroupUseAnonymousClass\SimpleSAML\Utils;
class Config {
    /**
     * @throws \RuntimeException
     */
    public function getSecretSalt(): string {
        throw new \RuntimeException('fail');
    }
}
