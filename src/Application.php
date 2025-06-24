<?php

declare(strict_types=1);

namespace HenkPoley\DocBlockDoctor;

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class Application
{
    private FileSystem $fileSystem;

    private AstParser $astParser;

    public function __construct(?FileSystem $fileSystem = null, ?AstParser $astParser = null)
    {
        $this->fileSystem = $fileSystem ?? new NativeFileSystem();
        $this->astParser  = $astParser  ?? new PhpParserAstParser();
    }

    /**
     * @param string[] $argv
     *
     * @throws \InvalidArgumentException
     * @throws \LogicException
     * @throws \RangeException
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

        GlobalCache::clear();

        $opt = $this->parseOptions($argv);
        $this->resolveDirectories($opt);

        $nodeFinder = new NodeFinder();
        $astUtils   = new AstUtils();

        $phpFiles  = $this->collectPhpFiles($opt);
        $filesRead = $this->processFilesPass1($phpFiles, $nodeFinder, $astUtils, $opt);

        $this->resolveThrowsGlobally($nodeFinder, $astUtils, $opt);

        $filesFixed = $this->updateFiles($phpFiles, $astUtils, $opt);

        if ($opt->verbose && !$opt->quiet) {
            echo "\n=== Summary ===\n";
            echo 'Files read (' . count($filesRead) . "):\n";
            foreach ($filesRead as $f) {
                echo "  - $f\n";
            }
            echo "\nFiles fixed (" . count($filesFixed) . "):\n";
            foreach ($filesFixed as $f) {
                echo "  - $f\n";
            }
            $resolvedCount = count(GlobalCache::getAllResolvedThrows());
            $firstKey = array_key_first(GlobalCache::$resolvedThrows);
            if (is_string($firstKey)) {
                $resolvedForKey = GlobalCache::getResolvedThrowsForKey($firstKey);
                $originsForKey  = GlobalCache::getThrowOriginsForKey($firstKey);
                $resolvedCount += count($resolvedForKey) + count($originsForKey);
            }
            echo "\nResolved throws: $resolvedCount\n\n";
        }

        return 0;
    }

    /**
     * @param string[] $argv
     *
     * @throws \RuntimeException
     */
    private function parseOptions(array $argv): ApplicationOptions
    {
        $opt = new ApplicationOptions();

        $counter = count($argv);
        for ($i = 1; $i < $counter; $i++) {
            $arg = $argv[$i];

            if ($arg === '--verbose' || $arg === '-v') {
                $opt->verbose = true;

                continue;
            }

            if ($arg === '--quiet' || $arg === '-q') {
                $opt->quiet = true;

                continue;
            }

            if ($arg === '--trace-throw-origins') {
                $opt->traceOrigins = true;

                continue;
            }

            if ($arg === '--trace-throw-call-sites') {
                $opt->traceCallSites = true;

                continue;
            }

            if ($arg === '--ignore-annotated-throws') {
                $opt->ignoreAnnotatedThrows = true;

                continue;
            }

            if (strncmp($arg, '--read-dirs=', strlen('--read-dirs=')) === 0) {
                $dirs       = (string) substr($arg, 12);
                $opt->readDirs = array_filter(array_map('trim', explode(',', $dirs)));

                continue;
            }

            if (strncmp($arg, '--write-dirs=', strlen('--write-dirs=')) === 0) {
                $dirs        = (string) substr($arg, 13);
                $opt->writeDirs = array_filter(array_map('trim', explode(',', $dirs)));

                continue;
            }

            if ($opt->rootDir === '') {
                $opt->rootDir = $arg;
            }
        }

        if ($opt->rootDir === '') {
            $cwd = $this->fileSystem->getCurrentWorkingDirectory();
            if ($cwd === false) {
                throw new \RuntimeException('Cannot determine current working directory');
            }
            $opt->rootDir = $cwd;
        }

        $opt->rootDir = rtrim($opt->rootDir, DIRECTORY_SEPARATOR);

        return $opt;
    }

    private function resolveDirectories(ApplicationOptions $opt): void
    {
        $rootDir   = $opt->rootDir;
        $readDirs  = $opt->readDirs;
        $writeDirs = $opt->writeDirs;

        if ($readDirs === null) {
            $readDirs = [];
            if ($this->fileSystem->isDir($rootDir . DIRECTORY_SEPARATOR . 'src')) {
                $readDirs[] = $rootDir . DIRECTORY_SEPARATOR . 'src';
            }
            if ($this->fileSystem->isDir($rootDir . DIRECTORY_SEPARATOR . 'tests')) {
                $readDirs[] = $rootDir . DIRECTORY_SEPARATOR . 'tests';
            }
            if ($this->fileSystem->isDir($rootDir . DIRECTORY_SEPARATOR . 'vendor')) {
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

        if ($writeDirs === null) {
            $writeDirs = [];
            if ($this->fileSystem->isDir($rootDir . DIRECTORY_SEPARATOR . 'src')) {
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

        $opt->readDirs  = $readDirs;
        $opt->writeDirs = $writeDirs;

        if ($opt->verbose && !$opt->quiet) {
            echo "[Verbose] Running DocBlockDoctor on: {$rootDir}\n";
            echo '[Verbose] Reading from: ' . implode(', ', $readDirs) . "\n";
            echo '[Verbose] Writing to:  ' . implode(', ', $writeDirs) . "\n";
        }
    }

    /**
     * @return string[]
     */
    private function collectPhpFiles(ApplicationOptions $opt): array
    {
        /** @var list<string> $phpFilePaths */
        $phpFilePaths = [];
        foreach ($opt->readDirs ?? [] as $dir) {
            if ($this->fileSystem->isFile($dir)) {
                if (pathinfo($dir, PATHINFO_EXTENSION) === 'php') {
                    $real = $this->fileSystem->realPath($dir);
                    if ($real !== false) {
                        $phpFilePaths[] = $real;
                    }
                }

                continue;
            }

            try {
                /** @var RecursiveIteratorIterator $rii */
                $rii = new RecursiveIteratorIterator(
                    new RecursiveCallbackFilterIterator(
                        new RecursiveDirectoryIterator(
                            $dir,
                            RecursiveDirectoryIterator::SKIP_DOTS | RecursiveDirectoryIterator::FOLLOW_SYMLINKS
                        ),
                        function ($file, $key, \RecursiveDirectoryIterator $iterator): bool {
                            $fileInfo = $file instanceof \SplFileInfo ? $file : new \SplFileInfo((string)$file);
                            $filename = $fileInfo->getFilename();
                            if ($iterator->hasChildren()) {
                                return !in_array($filename, ['.git', 'node_modules', '.history', 'cache'], true);
                            }

                            return $fileInfo->isFile() && $fileInfo->getExtension() === 'php';
                        }
                    ),
                    RecursiveIteratorIterator::LEAVES_ONLY
                );
            } catch (\UnexpectedValueException $e) {
                fwrite(STDERR, "Error: Cannot open directory '{$dir}'.\n");

                continue;
            }

            /** @var \SplFileInfo $fileInfo */
            foreach ($rii as $fileInfo) {
                $realPath = $fileInfo->getRealPath();
                if ($realPath !== false) {
                    $phpFilePaths[] = $realPath;
                }
            }
        }

        return array_values(array_unique($phpFilePaths));
    }

    /**
     * @param string[] $phpFilePaths
     * @return string[]
     *
     * @throws \LogicException
     * @throws \RangeException
     */
    private function processFilesPass1(array $phpFilePaths, NodeFinder $nodeFinder, AstUtils $astUtils, ApplicationOptions $opt): array
    {
        if (!$opt->quiet) {
            if ($opt->verbose) {
                echo 'Pass 1: Gathering info on ' . count($phpFilePaths) . " files...\n";
            } else {
                echo "Pass 1: Gathering info...\n";
            }
        }

        GlobalCache::clear();

        $filesRead = [];
        $nameResolverForPass1    = new NameResolver(null, ['replaceNodes' => false, 'preserveOriginalNames' => true]);
        $parentConnectorForPass1 = new ParentConnectingVisitor();

        foreach ($phpFilePaths as $filePath) {
            $filesRead[] = $filePath;
            if ($opt->verbose && !$opt->quiet) {
                echo "  • Processing: {$filePath}\n";
            }

            $code = $this->fileSystem->getContents($filePath);
            if ($code === false) {
                if (!$opt->quiet) {
                    echo "  ! Cannot read file: {$filePath}\n";
                }

                continue;
            }

            try {
                $ast = $this->astParser->parse($code);
                if ($ast === null) {
                    if ($opt->verbose && !$opt->quiet) {
                        echo "    → No AST for {$filePath}\n";
                    }

                    continue;
                }

                $this->astParser->traverse($ast, [
                    $nameResolverForPass1,
                    $parentConnectorForPass1,
                    new ThrowsGatherer(
                        $nodeFinder,
                        $astUtils,
                        $filePath,
                        $opt->ignoreAnnotatedThrows
                    ),
                ]);

            } catch (Error $e) {
                if (!$opt->quiet) {
                    echo "Parse error (Pass 1) in {$filePath}: {$e->getMessage()}\n";
                }
            }
        }

        if (!$opt->quiet) {
            echo "Pass 1 Complete.\n";
        }

        return $filesRead;
    }

    /**
     * @throws \InvalidArgumentException
     * @throws \LogicException
     */
    private function resolveThrowsGlobally(NodeFinder $nodeFinder, AstUtils $astUtils, ApplicationOptions $opt): void
    {
        if (!$opt->quiet) {
            echo "\nIntermediate Phase: Globally resolving throws...\n";
        }

        GlobalCache::$resolvedThrows = [];
        foreach (array_keys(GlobalCache::getAstNodeMap()) as $funcKey) {
            $direct    = GlobalCache::getDirectThrowsForKey($funcKey);
            $annotated = GlobalCache::getAnnotatedThrowsForKey($funcKey);
            $initial   = $direct;
            if (!$opt->ignoreAnnotatedThrows) {
                $initial = array_values(array_unique(array_merge($initial, $annotated)));
            } else {
                $initial = array_values(array_unique($initial));
            }
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

        $maxGlobalIterations   = count(GlobalCache::getAstNodeMap()) + 5;
        $currentGlobalIteration = 0;

        do {
            $changedInThisGlobalIteration = false;
            $currentGlobalIteration++;

            foreach (GlobalCache::getAstNodeMap() as $funcKey => $funcNode) {
                $filePathOfFunc  = GlobalCache::getFilePathForKey($funcKey) ?? '';
                $callerNamespace = GlobalCache::getFileNamespace($filePathOfFunc);
                $callerUseMap    = GlobalCache::getFileUseMap($filePathOfFunc);

                $baseThrows = GlobalCache::getDirectThrowsForKey($funcKey);
                if (!$opt->ignoreAnnotatedThrows) {
                    $baseThrows = array_values(array_unique(array_merge(
                        $baseThrows,
                        GlobalCache::getAnnotatedThrowsForKey($funcKey)
                    )));
                } else {
                    $baseThrows = array_values(array_unique($baseThrows));
                }

                $throwsFromCallees = [];
                $originsFromCallees = [];
                if ($funcNode->stmts === null) {
                    // Interface or abstract method - preserve previously resolved throws
                    $existing   = GlobalCache::$resolvedThrows[$funcKey] ?? [];
                    $newThrows  = array_values(array_unique(array_merge($baseThrows, $existing)));
                    sort($newThrows);
                    $newOrigins = GlobalCache::$throwOrigins[$funcKey] ?? [];
                    if ($this->storeResolvedData($funcKey, $newThrows, $newOrigins)) {
                        $changedInThisGlobalIteration = true;
                    }

                    continue;
                }
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
                        if ($calleeKey !== null && $calleeKey !== $funcKey) {
                            $exceptionsFromCallee = GlobalCache::$resolvedThrows[$calleeKey] ?? [];
                            foreach ($exceptionsFromCallee as $ex) {
                                if ($astUtils->isExceptionCaught($callNode, $ex, $funcNode, $callerNamespace, $callerUseMap)) {
                                    continue;
                                }
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

                if ($this->storeResolvedData($funcKey, $newThrows, $newOrigins)) {
                    $changedInThisGlobalIteration = true;
                }
            }

            $changedFromInterfaces = $this->propagateInterfaceThrows();
            if ($changedFromInterfaces) {
                $changedInThisGlobalIteration = true;
            }

            if ($currentGlobalIteration >= $maxGlobalIterations) {
                if (!$opt->quiet) {
                    echo "Warning: Global Throws Resolution max iterations ({$currentGlobalIteration}).\n";
                }
                break;
            }
        } while ($changedInThisGlobalIteration);

        if (!$opt->quiet) {
            echo "Global Throws Resolution Complete.\n";
        }
    }

    private function propagateInterfaceThrows(): bool
    {
        $changed = false;
        foreach (GlobalCache::getInterfaceImplementations() as $iface => $impls) {
            $impls = array_values(array_unique($impls));
            $ifacePrefix = ltrim($iface, '\\') . '::';
            foreach (array_keys(GlobalCache::getAstNodeMap()) as $key) {
                if (strncmp($key, $ifacePrefix, strlen($ifacePrefix)) !== 0) {
                    continue;
                }
                $method = (string) substr($key, strlen($ifacePrefix));
                $throws = GlobalCache::$resolvedThrows[$key] ?? [];
                $orig   = GlobalCache::$throwOrigins[$key] ?? [];
                foreach ($impls as $class) {
                    $implKey = ltrim($class, '\\') . '::' . $method;
                    foreach (GlobalCache::$resolvedThrows[$implKey] ?? [] as $ex) {
                        if (!in_array($ex, $throws, true)) {
                            $throws[] = $ex;
                        }
                        foreach (GlobalCache::$throwOrigins[$implKey][$ex] ?? [] as $ch) {
                            if (!isset($orig[$ex])) {
                                $orig[$ex] = [];
                            }
                            if (!in_array($ch, $orig[$ex], true) && count($orig[$ex]) < GlobalCache::MAX_ORIGIN_CHAINS) {
                                $orig[$ex][] = $ch;
                            }
                        }
                    }
                }
                sort($throws);
                foreach ($orig as $ex => $list) {
                    $list = array_values(array_unique($list));
                    if (count($list) > GlobalCache::MAX_ORIGIN_CHAINS) {
                        $list = array_slice($list, 0, GlobalCache::MAX_ORIGIN_CHAINS);
                    }
                    $orig[$ex] = $list;
                }
                if ($throws !== (GlobalCache::$resolvedThrows[$key] ?? []) || $orig !== (GlobalCache::$throwOrigins[$key] ?? [])) {
                    GlobalCache::$resolvedThrows[$key] = $throws;
                    GlobalCache::$throwOrigins[$key] = $orig;
                    $changed = true;
                }
            }
        }

        return $changed;
    }

    /**
     * Store resolved throws and origins for a function key.
     *
     * @param string   $funcKey
     * @param string[] $newThrows
     * @param array<string, string[]> $newOrigins
     *
     * @return bool True when data changed.
     */
    private function storeResolvedData(string $funcKey, array $newThrows, array $newOrigins): bool
    {
        $oldThrows  = GlobalCache::$resolvedThrows[$funcKey] ?? [];
        $oldOrigins = GlobalCache::$throwOrigins[$funcKey] ?? [];
        if ($newThrows !== $oldThrows || $newOrigins !== $oldOrigins) {
            GlobalCache::$resolvedThrows[$funcKey] = $newThrows;
            GlobalCache::$throwOrigins[$funcKey]   = $newOrigins;
            return true;
        }

        return false;
    }

    /**
     * @param string[] $phpFilePaths
     * @return string[]
     *
     * @throws \LogicException
     * @throws \RangeException
     */
    private function updateFiles(array $phpFilePaths, AstUtils $astUtils, ApplicationOptions $opt): array
    {
        $writeDirs     = $opt->writeDirs;
        $verbose       = $opt->verbose;
        $traceOrigins  = $opt->traceOrigins;
        $traceCallSites = $opt->traceCallSites;

        $phpFilesForWriting = array_filter($phpFilePaths, function (string $path) use ($writeDirs): bool {
            $realPath = $this->fileSystem->realPath($path);
            if ($realPath === false) {
                return false;
            }
            foreach (($writeDirs ?? []) as $dir) {
                $dirReal = $this->fileSystem->realPath($dir);
                if ($dirReal !== false && (strncmp($realPath, $dirReal . DIRECTORY_SEPARATOR, strlen($dirReal . DIRECTORY_SEPARATOR)) === 0 || $realPath === $dirReal)) {
                    return true;
                }
            }

            return false;
        });

        if (!$opt->quiet) {
            echo "\nPass 2: Updating files ...\n";
            if ($verbose) {
                echo 'Processing ' . count($phpFilesForWriting) . " files...\n";
            }
        }

        $filesFixed = [];

        foreach ($phpFilesForWriting as $filePath) {
            if ($verbose && !$opt->quiet) {
                echo "  • Processing (Pass 2): {$filePath}\n";
            }

            $fileOverallModified     = false;
            $maxFilePassIterations   = 3;
            $currentFilePassIteration = 0;

            do {
                $currentFilePassIteration++;
                if ($currentFilePassIteration > $maxFilePassIterations) {
                    if (!$opt->quiet) {
                        echo "Warning: Max iterations for file {$filePath}. Skipping further passes on this file.\n";
                    }
                    break;
                }

                $modifiedInThisPass = false;
                $codeAtStart       = $this->fileSystem->getContents($filePath);
                if ($codeAtStart === false) {
                    if (!$opt->quiet) {
                        echo "  ! Cannot read file: {$filePath}\n";
                    }
                    break;
                }

                try {
                    $currentNameResolver  = new NameResolver(null, ['replaceNodes' => false, 'preserveOriginalNames' => true]);
                    $currentParentConnector = new ParentConnectingVisitor();
                    $currentAST = $this->astParser->parse($codeAtStart);

                    if ($currentAST === null) {
                        if (!$opt->quiet) {
                            echo "Error parsing {$filePath} (Pass 2).\n";
                        }
                        break;
                    }

                    $this->astParser->traverse($currentAST, [
                        $currentNameResolver,
                        $currentParentConnector,
                    ]);
                } catch (Error $e) {
                    if (!$opt->quiet) {
                        echo "Parse error (Pass 2) in {$filePath}: {$e->getMessage()}\n";
                    }
                    break;
                }

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
                    $writeOk = $this->fileSystem->putContents($filePath, $newCode);
                    if (!$writeOk && !$opt->quiet) {
                        echo "  ! Unable to write file: {$filePath}\n";
                    }
                        if ($verbose && !$opt->quiet) {
                            echo "    → Surgically simplified use statements in {$filePath}\n";
                        }
                        $fileOverallModified = true;

                        continue; // Re‐parse from scratch after a surgical change
                    }
                }

                $docBlockUpdater   = new DocBlockUpdater($astUtils, $filePath, $traceOrigins, $traceCallSites, $opt->quiet);
                $this->astParser->traverse($currentAST, [$docBlockUpdater]);

                if ($docBlockUpdater->pendingPatches !== []) {
                    $currentFileContent = $this->fileSystem->getContents($filePath);
                    if ($currentFileContent === false) {
                        if (!$opt->quiet) {
                            echo "  ! Cannot read file: {$filePath}\n";
                        }
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

                            $newDocText = $patch['newDocText'];
                            if ($newDocText === null) {
                                continue;
                            }
                            $docBlockLines   = explode("\n", $newDocText);
                            $indentedLines = [];
                            foreach ($docBlockLines as $docLine) {
                                $indentedLines[] = $baseIndent . $docLine;
                            }
                            $indentedDocBlock = implode($lineEnding, $indentedLines);

                            if ($patch['type'] === 'add') {
                                $replacementText = $indentedDocBlock . $lineEnding;
                                $lineStartPos    = strrpos(
                                    (string) substr($newFileContent, 0, $patch['patchStart']),
                                    $newlineSearch
                                );
                                $currentAppliedPatchStartPos = ($lineStartPos !== false ? $lineStartPos + 1 : 0);
                                $currentAppliedOriginalLength = 0;
                            } else {
                                $lf      = $newlineSearch;
                                $upToSlash = (string) substr($newFileContent, 0, $patch['patchStart']);
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
                                    (string) substr($newFileContent, 0, $currentAppliedPatchStartPos),
                                    $newlineSearch
                                );
                                $startOfLine = ($startOfLine === false) ? 0 : $startOfLine + 1;
                            } else {
                                $startOfLine = 0;
                            }

                            $isDocBlockAlone = trim(
                                    (string) substr(
                                        $newFileContent,
                                        $startOfLine,
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
                    $writeOk = $this->fileSystem->putContents($filePath, $newFileContent);
                    if (!$writeOk && !$opt->quiet) {
                        echo "  ! Unable to write file: {$filePath}\n";
                    }
                        if ($verbose && !$opt->quiet) {
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
                if ($verbose && !$opt->quiet) {
                    echo "  ✓ Finished {$filePath} after modifications.\n";
                }
                $filesFixed[] = $filePath;
            }
        }

        if (!$opt->quiet) {
            echo "All done.\n";
        }

        return $filesFixed;
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
  -q, --quiet               Suppress all non-error output
  --read-dirs=DIRS          Comma-separated list of directories to read
  --write-dirs=DIRS         Comma-separated list of directories to update
  --trace-throw-origins     Replace @throws descriptions with origin locations and call chain
  --trace-throw-call-sites  Replace @throws descriptions with call site line numbers
  --ignore-annotated-throws Ignore existing @throws annotations when analyzing

Arguments:
  <path>           Path to a file or directory to process.
                   If omitted, defaults to the current working directory.

Description:
  DocBlockDoctor cleans up `@throws` annotations and simplifies `use …{…}` statements
  in your PHP codebase. It statically analyzes each PHP file, gathers thrown exceptions
  (including those bubbled up from called methods/functions), and writes updated DocBlocks.

Examples:
  # Process the current directory (quiet mode)
  php vendor/bin/doc-block-doctor --quiet

  # Process a specific directory, with verbose logging
  php vendor/bin/doc-block-doctor --verbose /path/to/project

  # Show help
  php vendor/bin/doc-block-doctor --help

USAGE;

        echo $help . "\n";
    }
}
