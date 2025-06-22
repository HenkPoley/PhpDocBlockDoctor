<?php
namespace Pitfalls\GroupUseAnonymousClass\SimpleSAML\Utils;
class Config {
    public function getSecretSalt(): string {
        throw new \RuntimeException('fail');
    }
}
