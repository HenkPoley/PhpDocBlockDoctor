<?php

namespace HenkPoley\DocBlockDoctor;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class Application
{
    /**
     * @param string[] $argv
     *
     * @throws \LogicException
     * @throws \RuntimeException
     */
    public function run(array $argv): int
    {
        // ------------------------------------------------------------
        // 1) First, check for help flags. If --help or -h is anywhere
        //    on the command line, print usage and exit immediately.
        // ------------------------------------------------------------
        foreach ($argv as $arg) {
            if ($arg === '--help' || $arg === '-h') {
                $this->printHelp();
                return 0;
            }
        }

        // ------------------------------------------------------------
        // 2) Next, parse --verbose and directory options.
        // ------------------------------------------------------------
        $verbose       = false;
        $traceOrigins  = false;
        $traceCallSites = false;
        $rootDir       = null;
        $readDirs      = null;
        $writeDirs     = null;
        $counter    = count($argv);

        for ($i = 1; $i < $counter; $i++) {
            $arg = $argv[$i];

            if ($arg === '--verbose' || $arg === '-v') {
                $verbose = true;
                continue;
            }

            if ($arg === '--trace-throw-origins') {
                $traceOrigins = true;
                continue;
            }

            if ($arg === '--trace-throw-call-sites') {
                $traceCallSites = true;
                continue;
            }

            if (strncmp($arg, '--read-dirs=', strlen('--read-dirs=')) === 0) {
                $dirs     = substr($arg, 12);
                $readDirs = array_filter(array_map('trim', explode(',', $dirs)));
                continue;
            }

            if (strncmp($arg, '--write-dirs=', strlen('--write-dirs=')) === 0) {
                $dirs      = substr($arg, 13);
                $writeDirs = array_filter(array_map('trim', explode(',', $dirs)));
                continue;
            }

            if ($rootDir === null) {
                $rootDir = $arg;
            }
        }

        // Default to current working directory if no path provided
        if ($rootDir === null) {
            $cwd = getcwd();
            if ($cwd === false) {
                throw new \RuntimeException('Cannot determine current working directory');
            }
            $rootDir = $cwd;
        }

        $rootDir = rtrim($rootDir, DIRECTORY_SEPARATOR);

        // Determine directories to read from
        if ($readDirs === null) {
            $readDirs = [];
            if (is_dir($rootDir . DIRECTORY_SEPARATOR . 'src')) {
                $readDirs[] = $rootDir . DIRECTORY_SEPARATOR . 'src';
            }
            if (is_dir($rootDir . DIRECTORY_SEPARATOR . 'tests')) {
                $readDirs[] = $rootDir . DIRECTORY_SEPARATOR . 'tests';
            }
            if (is_dir($rootDir . DIRECTORY_SEPARATOR . 'vendor')) {
                $readDirs[] = $rootDir . DIRECTORY_SEPARATOR . 'vendor';
            }
            if ($readDirs === []) {
                $readDirs[] = $rootDir;
            }
        } else {
            $readDirs = array_map(function (string $d) use ($rootDir): string {
                if (strncmp($d, DIRECTORY_SEPARATOR, strlen(DIRECTORY_SEPARATOR)) !== 0 && !preg_match('/^[A-Za-z]:\\\\/', $d)) {
                    return $rootDir . DIRECTORY_SEPARATOR . $d;
                }
                return $d;
            }, $readDirs);
        }

        // Determine directories eligible for writing
        if ($writeDirs === null) {
            $writeDirs = [];
            if (is_dir($rootDir . DIRECTORY_SEPARATOR . 'src')) {
                $writeDirs[] = $rootDir . DIRECTORY_SEPARATOR . 'src';
            }
            if ($writeDirs === []) {
                $writeDirs = $readDirs;
            }
        } else {
            $writeDirs = array_map(function (string $d) use ($rootDir): string {
                if (strncmp($d, DIRECTORY_SEPARATOR, strlen(DIRECTORY_SEPARATOR)) !== 0 && !preg_match('/^[A-Za-z]:\\\\/', $d)) {
                    return $rootDir . DIRECTORY_SEPARATOR . $d;
                }
                return $d;
            }, $writeDirs);
        }

        if ($verbose) {
            echo "[Verbose] Running DocBlockDoctor on: {$rootDir}\n";
            echo "[Verbose] Reading from: " . implode(', ', $readDirs) . "\n";
            echo "[Verbose] Writing to:  " . implode(', ', $writeDirs) . "\n";
        }

        // ------------------------------------------------------------
        // 3) Main logic: gather files, resolve throws, update docblocks.
        // ------------------------------------------------------------

        $parser    = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 1));
        $nodeFinder = new NodeFinder();
        $astUtils   = new AstUtils();

        // Collect all .php files from the configured read directories
        $phpFilePaths = [];
        foreach ($readDirs as $dir) {
            if (is_file($dir)) {
                if (pathinfo($dir, PATHINFO_EXTENSION) === 'php') {
                    $phpFilePaths[] = realpath($dir);
                }
                continue;
            }

            try {
                $rii = new RecursiveIteratorIterator(
                    new RecursiveCallbackFilterIterator(
                        new RecursiveDirectoryIterator(
                            $dir,
                            RecursiveDirectoryIterator::SKIP_DOTS | RecursiveDirectoryIterator::FOLLOW_SYMLINKS
                        ),
                        function ($file, $key, $iterator): bool {
                            $filename = $file->getFilename();
                            if ($iterator->hasChildren()) {
                                return !in_array($filename, ['.git', 'node_modules', '.history', 'cache'], true);
                            }
                            return $file->isFile() && $file->getExtension() === 'php';
                        }
                    ),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );
            } catch (\UnexpectedValueException $e) {
                fwrite(STDERR, "Error: Cannot open directory '{$dir}'.\n");
                continue;
            }

            foreach ($rii as $fileInfo) {
                if ($fileInfo->getRealPath()) {
                    $phpFilePaths[] = $fileInfo->getRealPath();
                }
            }
        }

        $phpFilePaths = array_values(array_unique($phpFilePaths));

        if ($verbose) {
            echo "Pass 1: Gathering info on " . count($phpFilePaths) . " files...\n";
        } else {
            echo "Pass 1: Gathering info...\n";
        }

        GlobalCache::clear();

        // Keep track of every file we try to read
        $filesRead = [];

        // Pass 1 visitors
        $nameResolverForPass1    = new NameResolver(null, ['replaceNodes' => false, 'preserveOriginalNames' => true]);
        $parentConnectorForPass1 = new ParentConnectingVisitor();

        foreach ($phpFilePaths as $filePath) {
            $filesRead[] = $filePath; // record that we read this file
            if ($verbose) {
                echo "  • Processing: {$filePath}\n";
            }

            $code = @file_get_contents($filePath);
            if ($code === false) {
                echo "  ! Cannot read file: {$filePath}\n";
                continue;
            }

            try {
                $ast = $parser->parse($code);
                if (!$ast) {
                    if ($verbose) {
                        echo "    → No AST for {$filePath}\n";
                    }
                    continue;
                }

                $traverserPass1 = new NodeTraverser();
                $traverserPass1->addVisitor($nameResolverForPass1);
                $traverserPass1->addVisitor($parentConnectorForPass1);
                $traverserPass1->addVisitor(new ThrowsGatherer($nodeFinder, $astUtils, $filePath));
                $traverserPass1->traverse($ast);

            } catch (Error $e) {
                echo "Parse error (Pass 1) in {$filePath}: {$e->getMessage()}\n";
            }
        }

        echo "Pass 1 Complete.\n";

        // ------------------------------------------------------------
        // 4) Intermediate: Resolve all throws globally
        // ------------------------------------------------------------
        echo "\nIntermediate Phase: Globally resolving throws...\n";

        GlobalCache::$resolvedThrows = [];
        foreach (array_keys(GlobalCache::$astNodeMap) as $funcKey) {
            $direct    = GlobalCache::$directThrows[$funcKey]    ?? [];
            $annotated = GlobalCache::$annotatedThrows[$funcKey] ?? [];
            $initial   = array_values(array_unique(array_merge($direct, $annotated)));
            sort($initial);
            GlobalCache::$resolvedThrows[$funcKey] = $initial;
            if (!isset(GlobalCache::$throwOrigins[$funcKey])) {
                GlobalCache::$throwOrigins[$funcKey] = [];
            }
            foreach (array_merge($direct, $annotated) as $ex) {
                if (!isset(GlobalCache::$throwOrigins[$funcKey][$ex])) {
                    GlobalCache::$throwOrigins[$funcKey][$ex] = [];
                }
            }
        }

        $maxGlobalIterations   = count(GlobalCache::$astNodeMap) + 5;
        $currentGlobalIteration = 0;

        do {
            $changedInThisGlobalIteration = false;
            $currentGlobalIteration++;

            foreach (GlobalCache::$astNodeMap as $funcKey => $funcNode) {
                $filePathOfFunc  = GlobalCache::$nodeKeyToFilePath[$funcKey];
                $callerNamespace = GlobalCache::$fileNamespaces[$filePathOfFunc] ?? '';
                $callerUseMap    = GlobalCache::$fileUseMaps[$filePathOfFunc] ?? [];

                $baseThrows = array_values(array_unique(array_merge(
                    GlobalCache::$directThrows[$funcKey]    ?? [],
                    GlobalCache::$annotatedThrows[$funcKey] ?? []
                )));

                $throwsFromCallees = [];
                $originsFromCallees = [];
                if (isset($funcNode->stmts) && is_array($funcNode->stmts)) {
                    $callNodes = array_merge(
                        $nodeFinder->findInstanceOf($funcNode->stmts, Node\Expr\MethodCall::class),
                        $nodeFinder->findInstanceOf($funcNode->stmts, Node\Expr\StaticCall::class),
                        $nodeFinder->findInstanceOf($funcNode->stmts, Node\Expr\FuncCall::class),
                        $nodeFinder->findInstanceOf($funcNode->stmts, Node\Expr\New_::class)
                    );

                    foreach ($callNodes as $callNode) {
                        if ($astUtils->isNodeAfterExecutionEndingStmt($callNode, $funcNode)) {
                            continue;
                        }
                        $calleeKey = $astUtils->getCalleeKey($callNode, $callerNamespace, $callerUseMap, $funcNode);
                        if ($calleeKey && $calleeKey !== $funcKey) {
                            $exceptionsFromCallee = GlobalCache::$resolvedThrows[$calleeKey] ?? [];
                            foreach ($exceptionsFromCallee as $ex) {
                                $throwsFromCallees[] = $ex;
                                $orig = GlobalCache::$throwOrigins[$calleeKey][$ex] ?? [];
                                if (!isset($originsFromCallees[$ex])) {
                                    $originsFromCallees[$ex] = [];
                                }
                                foreach ($orig as $chain) {
                                    if (strpos($chain, $funcKey . ' <- ') !== false) {
                                        continue; // avoid infinite recursion
                                    }
                                    $segments = explode(' <- ', $chain);
                                    if (preg_match('/:\d+$/', $segments[0])) {
                                        array_shift($segments);
                                    }
                                    $chainWithoutSite = implode(' <- ', $segments);
                                    if ($chainWithoutSite === '') {
                                        continue;
                                    }
                                    $callSite = $filePathOfFunc . ':' . $callNode->getStartLine();
                                    $newChain = $callSite . ' <- ' . $funcKey . ' <- ' . $chainWithoutSite;
                                    if (!in_array($newChain, $originsFromCallees[$ex], true) && count($originsFromCallees[$ex]) < GlobalCache::MAX_ORIGIN_CHAINS) {
                                        $originsFromCallees[$ex][] = $newChain;
                                    }
                                }
                            }
                        }
                    }
                }

                $newThrows = array_values(array_unique(array_merge($baseThrows, $throwsFromCallees)));
                sort($newThrows);

                $newOrigins = GlobalCache::$throwOrigins[$funcKey] ?? [];
                foreach ($originsFromCallees as $ex => $list) {
                    if (!isset($newOrigins[$ex])) {
                        $newOrigins[$ex] = [];
                    }
                    foreach ($list as $ch) {
                        if (!in_array($ch, $newOrigins[$ex], true) && count($newOrigins[$ex]) < GlobalCache::MAX_ORIGIN_CHAINS) {
                            $newOrigins[$ex][] = $ch;
                        }
                    }
                }
                foreach ($newOrigins as $ex => $list) {
                    $list = array_values(array_unique($list));
                    if (count($list) > GlobalCache::MAX_ORIGIN_CHAINS) {
                        $list = array_slice($list, 0, GlobalCache::MAX_ORIGIN_CHAINS);
                    }
                    $newOrigins[$ex] = $list;
                }

                $oldThrows = GlobalCache::$resolvedThrows[$funcKey] ?? [];
                $oldOrigins = GlobalCache::$throwOrigins[$funcKey] ?? [];
                if ($newThrows !== $oldThrows || $newOrigins !== $oldOrigins) {
                    GlobalCache::$resolvedThrows[$funcKey] = $newThrows;
                    GlobalCache::$throwOrigins[$funcKey] = $newOrigins;
                    $changedInThisGlobalIteration = true;
                }
            }

            if ($currentGlobalIteration >= $maxGlobalIterations) {
                echo "Warning: Global Throws Resolution max iterations ({$currentGlobalIteration}).\n";
                break;
            }
        } while ($changedInThisGlobalIteration);

        echo "Global Throws Resolution Complete.\n";

        // ------------------------------------------------------------
        // 5) Pass 2: Update files
        // ------------------------------------------------------------
        // Build list of files eligible for writing
        $phpFilesForWriting = array_filter($phpFilePaths, static function (string $path) use ($writeDirs): bool {
            $realPath = realpath($path);
            if ($realPath === false) {
                return false;
            }
            foreach ($writeDirs as $dir) {
                $dirReal = realpath($dir);
                if ($dirReal !== false && (strncmp($realPath, $dirReal . DIRECTORY_SEPARATOR, strlen($dirReal . DIRECTORY_SEPARATOR)) === 0 || $realPath === $dirReal)) {
                    return true;
                }
            }
            return false;
        });

        echo "\nPass 2: Updating files ...\n";
        if ($verbose) {
            echo 'Processing ' . count($phpFilesForWriting) . " files...\n";
        }

        // Keep track of which files were actually modified (fixed)
        $filesFixed = [];

        foreach ($phpFilesForWriting as $filePath) {
            if ($verbose) {
                echo "  • Processing (Pass 2): {$filePath}\n";
            }

            $fileOverallModified     = false;
            $maxFilePassIterations   = 3;
            $currentFilePassIteration = 0;

            do {
                $currentFilePassIteration++;
                if ($currentFilePassIteration > $maxFilePassIterations) {
                    echo "Warning: Max iterations for file {$filePath}. Skipping further passes on this file.\n";
                    break;
                }

                $modifiedInThisPass = false;
                $codeAtStart       = @file_get_contents($filePath);
                if ($codeAtStart === false) {
                    echo "  ! Cannot read file: {$filePath}\n";
                    break;
                }

                try {
                    $currentNameResolver  = new NameResolver(null, ['replaceNodes' => false, 'preserveOriginalNames' => true]);
                    $currentParentConnector = new ParentConnectingVisitor();
                    $currentAST = $parser->parse($codeAtStart);

                    if (!$currentAST) {
                        echo "Error parsing {$filePath} (Pass 2).\n";
                        break;
                    }

                    $setupTraverser = new NodeTraverser();
                    $setupTraverser->addVisitor($currentNameResolver);
                    $setupTraverser->addVisitor($currentParentConnector);
                    $currentAST = $setupTraverser->traverse($currentAST);
                } catch (Error $e) {
                    echo "Parse error (Pass 2) in {$filePath}: {$e->getMessage()}\n";
                    break;
                }

                // --- Use Statement Simplification (Surgical) ---
                $useSimplifierSurgical = new UseStatementSimplifierSurgical();
                $traverserUseSurgical  = new NodeTraverser();
                $traverserUseSurgical->addVisitor($useSimplifierSurgical);
                $traverserUseSurgical->traverse($currentAST);

                if ($useSimplifierSurgical->pendingPatches !== []) {
                    $newCode = $codeAtStart;
                    $patches = $useSimplifierSurgical->pendingPatches;
                    usort($patches, fn(array $a, array $b): int => $b['startPos'] <=> $a['startPos']);

                    foreach ($patches as $patch) {
                        $newCode = substr_replace(
                            $newCode,
                            $patch['replacementText'],
                            $patch['startPos'],
                            $patch['length']
                        );
                    }

                    if ($newCode !== $codeAtStart) {
                        file_put_contents($filePath, $newCode);
                        if ($verbose) {
                            echo "    → Surgically simplified use statements in {$filePath}\n";
                        }
                        $fileOverallModified = true;
                        continue; // Re‐parse from scratch after a surgical change
                    }
                }
                // --- End Use Statement Simplification ---

                $docBlockUpdater   = new DocBlockUpdater($astUtils, $filePath, $traceOrigins, $traceCallSites);
                $traverserDocBlock = new NodeTraverser();
                $traverserDocBlock->addVisitor($docBlockUpdater);
                $traverserDocBlock->traverse($currentAST);

                if ($docBlockUpdater->pendingPatches !== []) {
                    $currentFileContent = @file_get_contents($filePath);
                    if ($currentFileContent === false) {
                        echo "  ! Cannot read file: {$filePath}\n";
                        break;
                    }

                    if (strpos($currentFileContent, "\r\n") !== false) {
                        $lineEnding = "\r\n";
                    } elseif (strpos($currentFileContent, "\r") !== false) {
                        $lineEnding = "\r";
                    } else {
                        $lineEnding = "\n";
                    }
                    $originalLinesForIndent = preg_split('/\R/u', $currentFileContent) ?: [];
                    $newlineSearch = $lineEnding === "\r\n" ? "\n" : $lineEnding;
                    $patchesForFile = $docBlockUpdater->pendingPatches;
                    usort($patchesForFile, fn(array $a, array $b): int => $b['patchStart'] <=> $a['patchStart']);

                    $newFileContent = $currentFileContent;
                    foreach ($patchesForFile as $patch) {
                        $baseIndent = '';
                        if ($patch['type'] === 'add' || $patch['type'] === 'update') {
                            $nodeToIndentFor = $patch['node'];
                            $nodeStartLine   = $nodeToIndentFor->getStartLine();

                            if ($nodeStartLine > 0
                                && isset($originalLinesForIndent[$nodeStartLine - 1])
                                && preg_match('/^(\s*)/', $originalLinesForIndent[$nodeStartLine - 1], $indentMatches)
                            ) {
                                $baseIndent = $indentMatches[1];
                            }

                            $docBlockLines   = explode("\n", (string)$patch['newDocText']);
                            $indentedLines = [];
                            foreach ($docBlockLines as $docLine) {
                                $indentedLines[] = $baseIndent . $docLine;
                            }
                            $indentedDocBlock = implode($lineEnding, $indentedLines);

                            if ($patch['type'] === 'add') {
                                $replacementText = $indentedDocBlock . $lineEnding;
                                $lineStartPos    = strrpos(
                                    substr($newFileContent, 0, $patch['patchStart']),
                                    $newlineSearch
                                );
                                $currentAppliedPatchStartPos = ($lineStartPos !== false ? $lineStartPos + 1 : 0);
                                $currentAppliedOriginalLength = 0;
                            } else {
                                $lf      = $newlineSearch;
                                $upToSlash = substr($newFileContent, 0, $patch['patchStart']);
                                $lastNl  = strrpos($upToSlash, $lf);
                                $lineStart = $lastNl === false ? 0 : $lastNl + 1;

                                $currentAppliedPatchStartPos = $lineStart;
                                $currentAppliedOriginalLength = $patch['patchEnd'] - $lineStart + 1;
                                $replacementText = $indentedDocBlock;
                            }
                        } elseif ($patch['type'] === 'remove') {
                            $replacementText = '';
                            $currentAppliedPatchStartPos = $patch['patchStart'];
                            $currentAppliedOriginalLength = $patch['patchEnd'] - $patch['patchStart'] + 1;

                            if ($currentAppliedPatchStartPos > 0) {
                                $startOfLine = strrpos(
                                    substr($newFileContent, 0, $currentAppliedPatchStartPos),
                                    $newlineSearch
                                );
                                $startOfLine = ($startOfLine === false) ? 0 : $startOfLine + 1;
                            } else {
                                $startOfLine = 0;
                            }

                            $isDocBlockAlone = trim(
                                    substr($newFileContent, $startOfLine,
                                        $currentAppliedPatchStartPos - $startOfLine
                                    )
                                ) === '';

                            $newlineLenAfter = 0;
                            if (substr($newFileContent, $patch['patchEnd'] + 1, strlen($lineEnding)) === $lineEnding) {
                                $newlineLenAfter = strlen($lineEnding);
                            }

                            if ($isDocBlockAlone && $newlineLenAfter > 0) {
                                $currentAppliedPatchStartPos   = $startOfLine;
                                $currentAppliedOriginalLength = ($patch['patchEnd'] + $newlineLenAfter)
                                    - $currentAppliedPatchStartPos;
                            }
                        } else {
                            // Should not happen
                            continue;
                        }

                        $newFileContent = substr_replace(
                            $newFileContent,
                            $replacementText,
                            $currentAppliedPatchStartPos,
                            $currentAppliedOriginalLength
                        );
                    }

                    if ($newFileContent !== $currentFileContent) {
                        file_put_contents($filePath, $newFileContent);
                        if ($verbose) {
                            echo "    → Applied DocBlock changes to {$filePath}\n";
                        }
                        $modifiedInThisPass = true;
                        $fileOverallModified = true;
                    }
                }

                if (! $modifiedInThisPass) {
                    break;
                }
            } while (true);

            if ($fileOverallModified) {
                if ($verbose) {
                    echo "  ✓ Finished {$filePath} after modifications.\n";
                }
                $filesFixed[] = $filePath;
            }
        }

        echo "All done.\n";

        // ------------------------------------------------------------
        // 6) Print separate lists of files read vs. files fixed
        // ------------------------------------------------------------
        if ($verbose) {
            echo "\n=== Summary ===\n";
            echo "Files read (" . count($filesRead) . "):\n";
            foreach ($filesRead as $f) {
                echo "  - $f\n";
            }
            echo "\nFiles fixed (" . count($filesFixed) . "):\n";
            foreach ($filesFixed as $f) {
                echo "  - $f\n";
            }
            echo "\n";
        }

        return 0;
    }

    /**
     * Print usage instructions for this CLI utility.
     */
    private function printHelp(): void
    {
        $help = <<<'USAGE'
Usage:
  php vendor/bin/doc-block-doctor [options] [<path>]

Options:
  -h, --help                Display this help message and exit
  -v, --verbose             Enable verbose output (show each file being processed)
  --read-dirs=DIRS          Comma-separated list of directories to read
  --write-dirs=DIRS         Comma-separated list of directories to update
  --trace-throw-origins     Replace @throws descriptions with origin locations and call chain
  --trace-throw-call-sites  Replace @throws descriptions with call site line numbers

Arguments:
  <path>           Path to a file or directory to process.
                   If omitted, defaults to the current working directory.

Description:
  DocBlockDoctor cleans up `@throws` annotations and simplifies `use …{…}` statements
  in your PHP codebase. It statically analyzes each PHP file, gathers thrown exceptions
  (including those bubbled up from called methods/functions), and writes updated DocBlocks.

Examples:
  # Process the current directory (quiet mode)
  php vendor/bin/doc-block-doctor

  # Process a specific directory, with verbose logging
  php vendor/bin/doc-block-doctor --verbose /path/to/project

  # Show help
  php vendor/bin/doc-block-doctor --help

USAGE;

        echo $help . "\n";
    }
}