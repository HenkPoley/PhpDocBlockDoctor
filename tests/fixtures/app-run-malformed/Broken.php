<?php
declare(strict_types=1);

namespace HenkPoley\DocBlockDoctor\TestFixtures\AppRunMalformed;

class Broken
{
    public function bad(): void
    {
        echo 'fail';
// missing closing braces intentionally
