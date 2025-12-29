# DocBlockDoctor for PHP

It cleans up the `@throws` exception PhpDoc annotations for functions throughout your codebase using static analysis.

Something of a longstanding gap in the PHP tooling. The best tooling would not bubble exceptions through the call chain once it encountered a super class such as \Throwable, that everything inherits from. Since inheritance _kind of_ means you may ignore specialisation. Except of course, highly specific exceptions can be useful to properly inform the end user. Specific cases can be notified of fixed. Very generic cases have no solution except noting that something failed. Don't know what, but it's broken. PhpStorm has a habit of dumping duplicates in your docblock if multiple calls throw the same exception class. Which you then tediously need to clean up. This effectively meant nearly nobody really properly uses exceptions in the PHP ecosystem.

Oh, and it also cleans up `use Foo\Bar\{Baz};` statements to `use Foo\Bar\Baz;`.

Vibe coded this into existence with Google Gemini Pro 2.5, and OpenAI o4-mini-high, and of course some experience with PHP. Code is probably a mess.

## Installing

This package is currently not in the Composer repository.

Add the GitHub repository as a source:

```shell
php composer config repositories.phpdocdoctor vcs https://github.com/HenkPoley/PhpDocBlockDoctor
php composer require henk-poley/doc-block-doctor:dev-main
```

Or add to your `composer.json`:

```json
{
	"require": {
		"henk-poley/doc-block-doctor": "dev-main"
	},
	"repositories": [
		{
			"type": "vcs",
			"url": "https://github.com/HenkPoley/PhpDocBlockDoctor"
		}
	]
}
```

## Commandline syntax

```shell
php ./vendor/bin/doc-block-doctor [options] <path>

Options:
  --read-dirs=DIRS   Comma-separated list of directories to read when gathering info (default: src,tests)
  --write-dirs=DIRS  Comma-separated list of directories that may be modified (default: src)
  --trace-throw-origins  Replace @throws descriptions with origin locations and call chain
  --trace-throw-call-sites  Replace @throws descriptions with call site line numbers
  --ignore-annotated-throws Ignore existing @throws annotations when analyzing
  --simplify-use-statements Enable `use Foo\Bar\{Baz}` simplification (default)
  --no-simplify-use-statements Disable `use` statement simplification
```

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

It cannot track runtime dynamically attached class functions. To compensate it normally believes any `@throws` that are already annotated. Sadly the `@method` annotation for "Laravel Facade"-like cannot specify thrown exceptions, it is not in the spec, so good luck. With the `--ignore-annotated-throws` option you can disable this behaviour and clean out stale annotations.

## Dependencies

Uses `nikic/php-parser` AST parser for PHP.

`nikic/php-parser` 5.x requires PHP 7.4 or higher, so DocBlockDoctor now
needs at least PHP 7.4 to run. If you require PHP 7.1 compatibility you will
have to rely on `php-parser` 4.x and an older Rector version.

## Backstory

Had a crashing "single"-sign-on system that uses costomisations of [SimpleSAMLphp](https://github.com/simplesamlphp/simplesamlphp). Found out that the project has the common PHP problem that the `@throws` annotations were not maintained, and thus the developers were not able to properly catching exceptions. After grappling it for a bit, I wrote this to clean that up, and then thought it might be useful for others.

## TODO

* Handle PHP 'magic' such as Laravel Facades. We already handle a bit of __callStatic(), just not all the runtime injection that Laravel must be doing.
* Propagate (full function-wide) `catch(\Specific\Exception $e)` through the call chain. We can be fairly sure that part of the code won't emit that exception. So you have at least some basic way to clean up the `@throws` annotations.
* Follow proper ordering of PhpDoc tags. If there is one? @return comes before @throws. @see comes after @throws?
* Somehow handle PHP's built-in functionality? Possibly stash `jetbrains/phpstorm-stubs` somewhere outside of vendor? (Should `composer` have a stubs section?) Make a derived version that only keeps the @throws tags? Even though to warn not to trust it, since they don't test exceptions are actually thrown, SOML.
* Symbolic execution. So we can prove that even though there is `throw new X()` in there, that code path will never execute (for now there's psalm and rector for that).
* Find some new repos to test on. SimpleSAMLphp seems to be handled reasonably complete. (Famous last words.)
