# DocBlockDoctor for PHP

It cleans up the `@throws` exception PhpDoc annotations for functions throughout your codebase using static analysis.

Something of a longstanding gap in the PHP tooling. The best tooling would not bubble exceptions through the call chain once it encountered a super class such as \Throwable, that everything inherits from. Since inheritance _kind of_ means you may ignore specialisation. Except of course, highly specific exceptions can be useful to properly inform the end user. Specific cases can be notified of fixed. Very generic cases have no solution except noting that something failed. Don't know what, but it's broken. PhpStorm has a habit of dumping duplicates in your docblock if multiple calls throw the same exception class. Which you then tediously need to clean up. This effectively meant nearly nobody really properly uses exceptions in the PHP ecosystem.

Willed this into existence with Google Gemini Pro 2.5, and OpenAI o4-mini-high, and of course some experience with PHP. Code is probably a mess.

## Commandline syntax

php src/doc-block-doctor.php <path>

## Result

```php
/**
 * <first everything else>
 * 
 * @throws \All\Your\SpecificExceptions Then an empty line and, all the `@throws` as Fully Qualified Class Name (FQCN) plus their description.
 * @throws \Throwable Even if you annotate a super class like \Exception, it will *still* add all the specific exceptions you can expect to catch as well. 
 */
```

Thrown exceptions and `@throws` annotations bubble up along the call chain. It tracks direct function calls, function calls from object variables, and class instantiation. It sorts the exceptions, and de-duplicates them. Exception classes that do not exist, do not propagate along the call chain.

It cannot track runtime dynamically attached class functions. To compensate it just believes any `@throws` that are already annotated. Sadly the `@method` annotation for "Laravel Facade"-like cannot specify thrown exceptions, it is not in the spec, so good luck. Maybe submit a patch if you know how to fix this for specific use cases. By blindly believing existing annotations, this also means we cannot automatically clean up old exceptions that are no longer thrown.

## Dependencies

If you copy doc-block-doctor.php into your own project so it knows your composer classes, you need to run `php composer require nikic/php-parser` to add the `nikic/php-parser` dependency.

It should run down to PHP 7.1, made sure using Rector 2.0.17 (`php composer global require rector/rector 2.0.17`), end of May 2025.

# TODO:

* Handle PHP 'magic' such as Laravel Facades.
* Make it a proper PHP command, if possible (your project's vendored classes?).