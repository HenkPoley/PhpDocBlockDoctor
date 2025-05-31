<?php

namespace HenkPoley\DocBlockDoctor;

use PhpParser\Error;
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
    public function run(array $argv): int
    {
        // --- Main Script ---
        $parser = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 1));
        // $prettyPrinter is no longer needed globally, UseStatementSimplifierSurgical creates its own.
        $nodeFinder = new NodeFinder();
        $astUtils = new AstUtils();
        $rootDir = $argv[1] ?? getcwd();
        $phpFilePaths = [];
        $rii = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($rootDir, RecursiveDirectoryIterator::SKIP_DOTS | RecursiveDirectoryIterator::FOLLOW_SYMLINKS),
                function ($file, $key, $iterator): bool {
                    $filename = $file->getFilename();
                    if ($iterator->hasChildren()) {
                        return !in_array($filename, ['vendor', '.git', 'node_modules', '.history', 'tests', 'cache']);
                    }
                    return $file->isFile() && $file->getExtension() === 'php';
                }
            ), RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($rii as $fileInfo) {
            if ($fileInfo->getRealPath()) {
                $phpFilePaths[] = $fileInfo->getRealPath();
            }
        }

        echo "Pass 1: Gathering info...\n";
        GlobalCache::clear();
        $nameResolverForPass1 = new NameResolver(null, ['replaceNodes' => false, 'preserveOriginalNames' => true]);
        $parentConnectorForPass1 = new ParentConnectingVisitor();
        foreach ($phpFilePaths as $filePath) {
            $code = file_get_contents($filePath);
            try {
                $ast = $parser->parse($code);
                if (!$ast) {
                    continue;
                }

                $traverserPass1 = new NodeTraverser();
                $traverserPass1->addVisitor($nameResolverForPass1);
                $traverserPass1->addVisitor($parentConnectorForPass1);
                $traverserPass1->addVisitor(new ThrowsGatherer($nodeFinder, $astUtils, $filePath));
                $traverserPass1->traverse($ast);

            } catch (Error $e) {
                echo "Parse error Pass 1 {$filePath}: {$e->getMessage()}\n";
            }
        }
        echo "Pass 1 Complete.\n";

        echo "\nIntermediate Phase: Globally resolving throws...\n";
        GlobalCache::$resolvedThrows = [];
        foreach (array_keys(GlobalCache::$astNodeMap) as $funcKey) {
            $direct = GlobalCache::$directThrows[$funcKey] ?? [];
            $annotated = GlobalCache::$annotatedThrows[$funcKey] ?? [];
            $initialThrows = array_values(array_unique(array_merge($direct, $annotated)));
            sort($initialThrows);
            GlobalCache::$resolvedThrows[$funcKey] = $initialThrows;
        }

        $maxGlobalIterations = count(GlobalCache::$astNodeMap) + 5;
        $currentGlobalIteration = 0;
        do {
            $changedInThisGlobalIteration = false;
            $currentGlobalIteration++;
            foreach (GlobalCache::$astNodeMap as $funcKey => $funcNode) {
                $filePathOfFunc = GlobalCache::$nodeKeyToFilePath[$funcKey];
                $callerNamespace = GlobalCache::$fileNamespaces[$filePathOfFunc] ?? '';
                $callerUseMap = GlobalCache::$fileUseMaps[$filePathOfFunc] ?? [];
                $currentIterationBaseThrows = array_values(array_unique(array_merge(
                    GlobalCache::$directThrows[$funcKey] ?? [],
                    GlobalCache::$annotatedThrows[$funcKey] ?? []
                )));

                $throwsFromCallees = [];
                if (isset($funcNode->stmts) && is_array($funcNode->stmts)) {
                    $callNodes = array_merge(
                        $nodeFinder->findInstanceOf($funcNode->stmts, Node\Expr\MethodCall::class),
                        $nodeFinder->findInstanceOf($funcNode->stmts, Node\Expr\StaticCall::class),
                        $nodeFinder->findInstanceOf($funcNode->stmts, Node\Expr\FuncCall::class),
                        // pick up "new X()" so we can pull in X::__construct throws
                        $nodeFinder->findInstanceOf($funcNode->stmts, Node\Expr\New_::class)
                    );
                    foreach ($callNodes as $callNode) {
                        $calleeKey = $astUtils->getCalleeKey($callNode, $callerNamespace, $callerUseMap, $funcNode);
                        if ($calleeKey && $calleeKey !== $funcKey) {
                            $exceptionsFromCallee = GlobalCache::$resolvedThrows[$calleeKey] ?? [];
                            // TODO: [EA] 'array_merge(...)' is used in a loop and is a resources greedy construction.
                            $throwsFromCallees = array_merge($throwsFromCallees, $exceptionsFromCallee);
                        }
                    }
                }

                $newlyCalculatedThrowsForFunc = array_values(array_unique(array_merge($currentIterationBaseThrows, $throwsFromCallees)));
                sort($newlyCalculatedThrowsForFunc);
                $previouslyStoredResolvedThrows = GlobalCache::$resolvedThrows[$funcKey] ?? [];
                if ($newlyCalculatedThrowsForFunc !== $previouslyStoredResolvedThrows) {
                    GlobalCache::$resolvedThrows[$funcKey] = $newlyCalculatedThrowsForFunc;
                    $changedInThisGlobalIteration = true;
                }
            }
            if ($currentGlobalIteration >= $maxGlobalIterations) {
                echo "Warning: Global Throws Resolution max iterations ({$currentGlobalIteration}).\n";
                break;
            }
        } while ($changedInThisGlobalIteration);
        echo "Global Throws Resolution Complete.\n";

        echo "\nPass 2: Updating files...\n";
        foreach ($phpFilePaths as $filePath) {
            echo "Processing: " . $filePath . PHP_EOL;
            $fileOverallModified = false;
            $maxFilePassIterations = 3;
            $currentFilePassIteration = 0;
            do {
                $currentFilePassIteration++;
                if ($currentFilePassIteration > $maxFilePassIterations) {
                    echo "Warning: Max iterations for file {$filePath}. Skipping further passes on this file.\n";
                    break;
                }
                $modifiedInThisSpecificPassIteration = false;
                $codeAtStartOfThisIteration = file_get_contents($filePath);
                $currentAST = null;

                try {
                    $currentNameResolver = new NameResolver(null, ['replaceNodes' => false, 'preserveOriginalNames' => true]);
                    $currentParentConnector = new ParentConnectingVisitor();
                    $currentAST = $parser->parse($codeAtStartOfThisIteration);
                    if (!$currentAST) {
                        echo "Error parsing {$filePath} Pass 2.\n";
                        break;
                    }

                    $setupTraverser = new NodeTraverser();
                    $setupTraverser->addVisitor($currentNameResolver);
                    $setupTraverser->addVisitor($currentParentConnector);
                    $currentAST = $setupTraverser->traverse($currentAST);
                } catch (Error $e) {
                    echo "Parse error Pass 2 {$filePath}: {$e->getMessage()}\n";
                    break;
                }

                // --- Use Statement Simplification (Surgical) ---
                $useSimplifierSurgical = new UseStatementSimplifierSurgical();
                $traverserUseSurgical = new NodeTraverser();
                $traverserUseSurgical->addVisitor($useSimplifierSurgical);
                // Traverse the AST. The visitor collects patches but does not modify the AST itself.
                $traverserUseSurgical->traverse($currentAST);

                if (!empty($useSimplifierSurgical->pendingPatches)) {
                    $newCode = $codeAtStartOfThisIteration; // Start with the code from the beginning of this file pass
                    $patches = $useSimplifierSurgical->pendingPatches;
                    // Sort patches by start position in descending order to apply them correctly
                    usort($patches, function (array $a, array $b): int {
                        return $b['startPos'] <=> $a['startPos'];
                    });
                    foreach ($patches as $patch) {
                        $newCode = substr_replace($newCode, $patch['replacementText'], $patch['startPos'], $patch['length']);
                    }

                    if ($newCode !== $codeAtStartOfThisIteration) {
                        file_put_contents($filePath, $newCode);
                        echo "Surgically simplified use statements in {$filePath}\n";
                        $modifiedInThisSpecificPassIteration = true;
                        $fileOverallModified = true;
                        continue; // Re-process the file from start due to textual modification, ensuring next ops see this change
                    }
                }
                // END Use Statement Simplification ---


                $docBlockUpdater = new DocBlockUpdater($astUtils, $filePath);
                $traverserDocBlock = new NodeTraverser();
                $traverserDocBlock->addVisitor($docBlockUpdater);
                $traverserDocBlock->traverse($currentAST);

                // TODO: [EA] \HenkPoley\DocBlockDoctor\DocBlockUpdater needs to implement __isset to properly work here.
                if (!empty($docBlockUpdater->pendingPatches)) {
                    $currentFileContentForPatching = file_get_contents($filePath);
                    $originalFileLinesForIndent = explode("\n", $currentFileContentForPatching);

                    $patchesForFile = $docBlockUpdater->pendingPatches;
                    usort($patchesForFile, function (array $a, array $b): int {
                        return $b['patchStart'] <=> $a['patchStart'];
                    });
                    $newFileContent = $currentFileContentForPatching; // Work on a copy

                    foreach ($patchesForFile as $patch) {
                        $replacementText = '';
                        $currentAppliedPatchStartPos = $patch['patchStart']; // Start with scheduled position
                        $currentAppliedOriginalLength = 0;
                        $baseIndent = ''; // Indentation for the docblock lines themselves

                        if ($patch['type'] === 'add' || $patch['type'] === 'update') {
                            $nodeToIndentFor = $patch['node'];
                            $nodeStartLine = $nodeToIndentFor->getStartLine();
                            // Determine the base indentation from the line of the code element (method/function)
                            // $originalFileLinesForIndent is based on the file content that $currentAST was parsed from.
                            if ($nodeStartLine > 0 && isset($originalFileLinesForIndent[$nodeStartLine - 1]) && preg_match('/^(\s*)/', $originalFileLinesForIndent[$nodeStartLine - 1], $indentMatches)) {
                                $baseIndent = $indentMatches[1];
                            }

                            // Construct the new docblock string, with each line indented by $baseIndent
                            $docBlockLines = explode("\n", (string)$patch['newDocText']);
                            $indentedDocBlockString = "";
                            foreach ($docBlockLines as $idx => $docLine) {
                                $indentedDocBlockString .= $baseIndent . $docLine;
                                if ($idx < count($docBlockLines) - 1) {
                                    $indentedDocBlockString .= "\n";
                                }
                            }

                            if ($patch['type'] === 'add') {
                                $replacementText = $indentedDocBlockString . "\n";
                                // Insert the new DocBlock at the very start of the line containing the method/function
                                $lineStartPos = strrpos(substr($newFileContent, 0, $patch['patchStart']), "\n");
                                $currentAppliedPatchStartPos = ($lineStartPos !== false ? $lineStartPos + 1 : 0);
                                $currentAppliedOriginalLength = 0;
                            } elseif ($patch['type'] === 'update') {
                                // 1) Figure out where this docblock really starts (including its indent).
                                $lf = "\n";
                                $upToSlash = substr($newFileContent, 0, $patch['patchStart']);
                                $lastNl = strrpos($upToSlash, $lf);
                                // if there’s no newline, start at the beginning of the file
                                $lineStart = $lastNl === false ? 0 : $lastNl + 1;

                                // 2) Remove everything from that indent up through the end of the old comment
                                $currentAppliedPatchStartPos = $lineStart;
                                $currentAppliedOriginalLength = $patch['patchEnd'] - $lineStart + 1;

                                // 3) Replace with the new docblock (already built with the correct $baseIndent).
                                //    We add a "\n" so that the method’s `public function…` stays on its own line.
                                $replacementText = $indentedDocBlockString;
                            } else {

                                $replacementText = $indentedDocBlockString;
                                // For 'update', $patch['patchStart'] is $docCommentNode->getStartFilePos()
                                // This position should be the start of the old docblock's first line.
                                $currentAppliedPatchStartPos = $patch['patchStart'];
                                $currentAppliedOriginalLength = $patch['patchEnd'] - $patch['patchStart'] + 1;
                            }
                        } elseif ($patch['type'] === 'remove') {
                            $replacementText = '';
                            $currentAppliedPatchStartPos = $patch['patchStart'];
                            $currentAppliedOriginalLength = $patch['patchEnd'] - $patch['patchStart'] + 1;

                            // Refined logic to remove the whole line if the docblock was alone on it.
                            // This needs to operate on $newFileContent as it might have been changed by prior patches in this loop.
                            $startPosOfDocBlockLine = -1;
                            if ($currentAppliedPatchStartPos > 0) {
                                $startPosOfDocBlockLine = strrpos(substr($newFileContent, 0, $currentAppliedPatchStartPos), "\n");
                                $startPosOfDocBlockLine = ($startPosOfDocBlockLine === false) ? 0 : $startPosOfDocBlockLine + 1;
                            } else {
                                $startPosOfDocBlockLine = 0;
                            }

                            $isDocBlockAloneOnLine = trim(substr($newFileContent, $startPosOfDocBlockLine, $currentAppliedPatchStartPos - $startPosOfDocBlockLine)) === '';

                            $charAfterDocEnd = ($patch['patchEnd'] + 1 < strlen($newFileContent)) ? $newFileContent[$patch['patchEnd'] + 1] : '';
                            $newlineLengthAfterDoc = 0;
                            if ($charAfterDocEnd === "\n") {
                                $newlineLengthAfterDoc = 1;
                            } elseif ($charAfterDocEnd === "\r" && ($patch['patchEnd'] + 2 < strlen($newFileContent)) && $newFileContent[$patch['patchEnd'] + 2] === "\n") {
                                $newlineLengthAfterDoc = 2;
                            }

                            if ($isDocBlockAloneOnLine && $newlineLengthAfterDoc > 0) {
                                $currentAppliedPatchStartPos = $startPosOfDocBlockLine;
                                $currentAppliedOriginalLength = ($patch['patchEnd'] + $newlineLengthAfterDoc) - $currentAppliedPatchStartPos;
                            }
                        } else {
                            continue; // Should not happen
                        }

                        $newFileContent = substr_replace($newFileContent, $replacementText, $currentAppliedPatchStartPos, $currentAppliedOriginalLength);
                    }
                    if ($newFileContent !== $currentFileContentForPatching) {
                        file_put_contents($filePath, $newFileContent);
                        echo "Applied DocBlock changes surgically to {$filePath}\n";
                        $modifiedInThisSpecificPassIteration = true;
                        $fileOverallModified = true;
                    }
                }
                if (!$modifiedInThisSpecificPassIteration) {
                    break;
                }
            } while (true);
            if ($fileOverallModified) {
                echo "Finished {$filePath} after mods.\n";
            }
        }
        echo "All done.\n";

        return 0;
    }
}