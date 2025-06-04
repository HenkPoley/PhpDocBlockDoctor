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
     */
    public function run($argv): int
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
        // 2) Next, parse --verbose (or -v) and the optional <path>.
        // ------------------------------------------------------------
        $verbose = false;
        $rootDir = null;
        // Skip $argv[0]; start at 1
        $counter = count($argv);

        // Skip $argv[0]; start at 1
        for ($i = 1; $i < $counter; $i++) {
            $arg = $argv[$i];

            if ($arg === '--verbose' || $arg === '-v') {
                $verbose = true;
                continue;
            }

            // Treat the first non‐flag (and non‐help) argument as the path
            if ($rootDir === null) {
                $rootDir = $arg;
            }
        }

        // Default to current working directory if no path provided
        if ($rootDir === null) {
            $rootDir = getcwd();
        }

        if ($verbose) {
            echo "[Verbose] Running DocBlockDoctor on: {$rootDir}\n";
        }

        // ------------------------------------------------------------
        // 3) Main logic: gather files, resolve throws, update docblocks.
        // ------------------------------------------------------------

        $parser    = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 1));
        $nodeFinder = new NodeFinder();
        $astUtils   = new AstUtils();

        // Collect all .php files under $rootDir
        $phpFilePaths = [];
        try {
            $rii = new RecursiveIteratorIterator(
                new RecursiveCallbackFilterIterator(
                    new RecursiveDirectoryIterator(
                        $rootDir,
                        RecursiveDirectoryIterator::SKIP_DOTS | RecursiveDirectoryIterator::FOLLOW_SYMLINKS
                    ),
                    function ($file, $key, $iterator): bool {
                        $filename = $file->getFilename();
                        if ($iterator->hasChildren()) {
                            // removed 'vendor' from this exclusion list so we read vendor/ now
                            return !in_array($filename, ['.git', 'node_modules', '.history', 'tests', 'cache'], true);
                        }
                        return $file->isFile() && $file->getExtension() === 'php';
                    }
                ),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
        } catch (\UnexpectedValueException $e) {
            // If $rootDir is not a directory (or not accessible), report and exit
            fwrite(STDERR, "Error: Cannot open directory '{$rootDir}'.\n");
            return 1;
        }

        foreach ($rii as $fileInfo) {
            if ($fileInfo->getRealPath()) {
                $phpFilePaths[] = $fileInfo->getRealPath();
            }
        }

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
                            $throwsFromCallees = array_merge($throwsFromCallees, $exceptionsFromCallee);
                        }
                    }
                }

                $newThrows = array_values(array_unique(array_merge($baseThrows, $throwsFromCallees)));
                sort($newThrows);

                $oldThrows = GlobalCache::$resolvedThrows[$funcKey] ?? [];
                if ($newThrows !== $oldThrows) {
                    GlobalCache::$resolvedThrows[$funcKey] = $newThrows;
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
        // 5) Pass 2: Update files (skip vendor/)
        // ------------------------------------------------------------
        // Before starting Pass 2, remove any paths under “vendor/” so that we don’t modify third-party code.
        $phpFilePaths = array_filter($phpFilePaths, static function (string $path): bool {
            // On Windows the directory separator may be “\”, so use DIRECTORY_SEPARATOR.
            $needle = DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR;
            return strpos($path, $needle) === false;
        });

        echo "\nPass 2: Updating files (excluding vendor/) ...\n";
        if ($verbose) {
            echo 'Processing ' . count($phpFilePaths) . " files...\n";
        }

        // Keep track of which files were actually modified (fixed)
        $filesFixed = [];

        foreach ($phpFilePaths as $filePath) {
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
                    usort($patches, function (array $a, array $b): int {
                        return $b['startPos'] <=> $a['startPos'];
                    });

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

                $docBlockUpdater   = new DocBlockUpdater($astUtils, $filePath);
                $traverserDocBlock = new NodeTraverser();
                $traverserDocBlock->addVisitor($docBlockUpdater);
                $traverserDocBlock->traverse($currentAST);

                if ($docBlockUpdater->pendingPatches !== []) {
                    $currentFileContent = @file_get_contents($filePath);
                    if ($currentFileContent === false) {
                        echo "  ! Cannot read file: {$filePath}\n";
                        break;
                    }

                    $originalLinesForIndent = explode("\n", $currentFileContent);
                    $patchesForFile = $docBlockUpdater->pendingPatches;
                    usort($patchesForFile, function (array $a, array $b): int {
                        return $b['patchStart'] <=> $a['patchStart'];
                    });

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
                            $indentedDocBlock = "";
                            foreach ($docBlockLines as $idx => $docLine) {
                                $indentedDocBlock .= $baseIndent . $docLine;
                                if ($idx < count($docBlockLines) - 1) {
                                    $indentedDocBlock .= "\n";
                                }
                            }

                            if ($patch['type'] === 'add') {
                                $replacementText = $indentedDocBlock . "\n";
                                $lineStartPos    = strrpos(
                                    substr($newFileContent, 0, $patch['patchStart']),
                                    "\n"
                                );
                                $currentAppliedPatchStartPos = ($lineStartPos !== false ? $lineStartPos + 1 : 0);
                                $currentAppliedOriginalLength = 0;
                            } else {
                                $lf      = "\n";
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
                                    "\n"
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

                            $charAfter       = ($patch['patchEnd'] + 1 < strlen($newFileContent))
                                ? $newFileContent[$patch['patchEnd'] + 1]
                                : '';
                            $newlineLenAfter = 0;
                            if ($charAfter === "\n") {
                                $newlineLenAfter = 1;
                            } elseif ($charAfter === "\r"
                                && ($patch['patchEnd'] + 2 < strlen($newFileContent))
                                && $newFileContent[$patch['patchEnd'] + 2] === "\n"
                            ) {
                                $newlineLenAfter = 2;
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
  -h, --help       Display this help message and exit
  -v, --verbose    Enable verbose output (show each file being processed)

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