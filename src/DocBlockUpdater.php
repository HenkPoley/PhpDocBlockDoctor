<?php

declare(strict_types=1);

namespace HenkPoley\DocBlockDoctor;

use PhpParser\NodeVisitorAbstract;
use PhpParser\Node;

class DocBlockUpdater extends NodeVisitorAbstract
{
    private \HenkPoley\DocBlockDoctor\AstUtils $astUtils;

    private string $currentFilePath;

    private bool $traceOrigins;

    private bool $traceCallSites;

    private bool $quiet;

    /** @var array<string, list<array{line: int, delta: int}>> */
    private array $lineShiftMap = [];

    /**
     * @var list<array{type:string,node:\PhpParser\Node,newDocText:null|string,patchStart:int,patchEnd:int}>
     */
    public $pendingPatches = [];

    /**
     * @var string
     */
    private $currentNamespace = '';

    public function __construct(\HenkPoley\DocBlockDoctor\AstUtils $astUtils, string $currentFilePath, bool $traceOrigins = false, bool $traceCallSites = false, bool $quiet = false)
    {
        $this->astUtils = $astUtils;
        $this->currentFilePath = $currentFilePath;
        $this->traceOrigins = $traceOrigins;
        $this->traceCallSites = $traceCallSites;
        $this->quiet = $quiet;
    }

    /**
     * @param array<string, list<array{line: int, delta: int}>> $lineShiftMap
     */
    public function setLineShiftMap(array $lineShiftMap): void
    {
        $this->lineShiftMap = $lineShiftMap;
    }

    /**
     * @param array $nodes
     * @return null
     */
    public function beforeTraverse(array $nodes)
    {
        /** @var Node[] $nodes */
        $this->pendingPatches = [];
        $this->currentNamespace = \HenkPoley\DocBlockDoctor\GlobalCache::getFileNamespace($this->currentFilePath);

        return null;
    }

    private function normalizeDocBlockString(?string $contentOnlyText): ?string
    {
        if ($contentOnlyText === null) {
            return null;
        }
        $contentLines = preg_split('/\R/u', $contentOnlyText) ?: [];
        /** @var list<string> $actualContent */
        $actualContent = [];
        $hasMeaningfulContent = false;
        foreach ($contentLines as $line) {
            $trimmedLine = trim($line);
            if ($trimmedLine !== '' || $line === '') {
                $actualContent[] = $line;
                if ($trimmedLine !== '') {
                    $hasMeaningfulContent = true;
                }
            }
        }
        if (!$hasMeaningfulContent && array_filter($actualContent, fn($l): bool => trim($l) !== '') === []) {
            return null;
        }

        $isEffectivelyEmpty = true;
        foreach ($actualContent as $acLine) {
            if (trim($acLine) !== '') {
                $isEffectivelyEmpty = false;
                break;
            }
        }
        if ($isEffectivelyEmpty) {
            return null;
        }

        $outputDocBlock = ['/**'];
        while (count($actualContent) > 0 && trim($actualContent[0]) === '') {
            array_shift($actualContent);
        }
        while (count($actualContent) > 0 && trim(end($actualContent)) === '') {
            array_pop($actualContent);
        }

        if ($actualContent === []) {
            return null;
        }

        foreach ($actualContent as $line) {
            if (trim($line) === '') {
                // preserve a blank docblock line, but no space after the asterisk
                $outputDocBlock[] = ' *';
            } else {
                $outputDocBlock[] = ' * ' . rtrim($line);
            }
        }

        $outputDocBlock[] = ' */';

        return implode("\n", $outputDocBlock);
    }

    /**
     * Split a DocBlock string into cleaned content-only lines.
     *
     * @return list<string>
     */
    private function splitDocLines(string $docText): array
    {
        $lines = preg_split('/\R/u', $docText) ?: [];
        /** @var list<string> $result */
        $result = [];
        foreach ($lines as $i => $line) {
            $isFirst = ($i === 0 && preg_match('/^\s*\/\*\*/', $line));
            $isLast  = ($i === count($lines) - 1 && preg_match('/\*\/\s*$/', $line));
            if ($isFirst) {
                $line = preg_replace('/^\s*\/\*\*\s?/', '', $line) ?? '';
            }
            if ($isLast) {
                $line = preg_replace('/\s*\*\/$/', '', $line) ?? '';
            }
            $result[] = preg_replace('/^\s*\*?\s?/', '', $line) ?? '';
        }

        return $result;
    }

    /**
     * @param \PhpParser\Node $node
     * @return null
     */
    public function leaveNode(Node $node)
    {
        if (!$node instanceof Node\Stmt\Function_ && !$node instanceof Node\Stmt\ClassMethod) {
            return null;
        }
        $nodeKey = $this->astUtils->getNodeKey($node, $this->currentNamespace);
        if ($nodeKey === null || $nodeKey === '') {
            return null;
        }

        /** @var list<class-string> $analyzedThrowsFqcns */
        $analyzedThrowsFqcns = \HenkPoley\DocBlockDoctor\GlobalCache::getResolvedThrowsForKey($nodeKey);
        // Filter out any classes or interfaces that don’t actually exist
        $analyzedThrowsFqcns = array_filter(
            $analyzedThrowsFqcns,
            static fn(string $fqcn): bool => AstUtils::classOrInterfaceExistsNoAutoload($fqcn)
        );
        $analyzedThrowsFqcns = array_values($analyzedThrowsFqcns);
        sort($analyzedThrowsFqcns);
        $docCommentNode = $node->getDocComment();
        $originalNodeDescriptions = \HenkPoley\DocBlockDoctor\GlobalCache::getOriginalDescriptionsForKey($nodeKey);

        /** @var list<string> $newDocBlockContentLines */
        $newDocBlockContentLines = [];
        $hasAnyContentForNewDocBlock = false;

        if ($docCommentNode instanceof \PhpParser\Comment\Doc) {
            $originalLines = $this->splitDocLines($docCommentNode->getText());
            /** @var list<string> $currentGenericTagLines */
            $currentGenericTagLines = [];
            $isInsideGenericTag = false;

            foreach ($originalLines as $lineIdx => $lineContent) {
                $trimmedLineContent = trim($lineContent);

                if (preg_match('/^@throws\s/i', $trimmedLineContent)) {
                    if ($currentGenericTagLines !== []) {
                        foreach ($currentGenericTagLines as $tagLine) {
                            $newDocBlockContentLines[] = $tagLine;
                        }
                        $currentGenericTagLines = [];
                    }
                    $isInsideGenericTag = false;
                    while ($lineIdx + 1 < count($originalLines) - 1 &&
                        !preg_match('/^@\w+/', trim($originalLines[$lineIdx + 1]))) {
                        $lineIdx++;
                    }

                    continue;
                }

                if (preg_match('/^@\w+/', $trimmedLineContent)) {
                    if ($currentGenericTagLines !== []) {
                        foreach ($currentGenericTagLines as $tagLine) {
                            $newDocBlockContentLines[] = $tagLine;
                        }
                    }
                    $currentGenericTagLines = [$lineContent];
                    $isInsideGenericTag = true;
                    $hasAnyContentForNewDocBlock = true;
                } elseif ($isInsideGenericTag) {
                    $currentGenericTagLines[] = $lineContent;
                    if ($trimmedLineContent !== '') {
                        $hasAnyContentForNewDocBlock = true;
                    }
                } elseif ($trimmedLineContent !== '') {
                    if ($currentGenericTagLines !== []) {
                        foreach ($currentGenericTagLines as $tagLine) {
                            $newDocBlockContentLines[] = $tagLine;
                        }
                        $currentGenericTagLines = [];
                    }
                    $newDocBlockContentLines[] = $lineContent;
                    $hasAnyContentForNewDocBlock = true;
                    $isInsideGenericTag = false;
                } elseif ($newDocBlockContentLines !== [] && trim(end($newDocBlockContentLines)) !== '') {
                    $newDocBlockContentLines[] = '';
                }
            }
            if ($currentGenericTagLines !== []) {
                foreach ($currentGenericTagLines as $tagLine) {
                    $newDocBlockContentLines[] = $tagLine;
                }
            }
        }

        if ($analyzedThrowsFqcns !== []) {
            $hasAnyContentForNewDocBlock = true;
            if ($newDocBlockContentLines !== [] && trim(end($newDocBlockContentLines)) !== '') {
                $newDocBlockContentLines[] = '';
            }
            foreach ($analyzedThrowsFqcns as $fqcn) {
                // This foreach() is not dead code.
                // tombstone("ERROR: NoValue - src/DocBlockUpdater.php:188:46 - All possible types for this assignment were invalidated - This may be dead code (see https://psalm.dev/179)");
                $fqcnWithBackslash = '\\' . ltrim($fqcn, '\\');
                if ($this->traceOrigins) {
                    $originChains = \HenkPoley\DocBlockDoctor\GlobalCache::getThrowOriginsForKey($nodeKey)[$fqcn] ?? [];
                    $cleaned = [];
                    foreach ($originChains as $ch) {
                        if (strpos($ch, $nodeKey . ' <- ') === 0) {
                            $ch = substr($ch, strlen($nodeKey . ' <- '));
                        } elseif (preg_match('/^(.*?:\d+) <- ' . preg_quote($nodeKey, '/') . ' <- (.*)$/', $ch, $m)) {
                            $ch = $m[1] . ' <- ' . $m[2];
                        }
                        $cleaned[] = $ch;
                    }
                    $description = implode(', ', $cleaned);
                } elseif ($this->traceCallSites) {
                    $originChains = \HenkPoley\DocBlockDoctor\GlobalCache::getThrowOriginsForKey($nodeKey)[$fqcn] ?? [];
                    $lines = [];
                    foreach ($originChains as $ch) {
                        if (strpos($ch, $nodeKey . ' <- ') === 0) {
                            $ch = substr($ch, strlen($nodeKey . ' <- '));
                        } elseif (preg_match('/^(.*?:\d+) <- ' . preg_quote($nodeKey, '/') . ' <- (.*)$/', $ch, $m)) {
                            $ch = $m[1] . ' <- ' . $m[2];
                        }
                        $parts = explode(' <- ', $ch);
                        $first = $parts[0] ?? '';
                        if (preg_match('/^(.*?):(\d+)$/', $first, $m2)) {
                            $path = $m2[1];
                            $line = (int) $m2[2];
                            $lines[] = $this->adjustLineNumber($path, $line);
                        }
                    }
                    sort($lines);
                    $lines = array_values(array_unique($lines));
                    $lineStrs = array_map(static fn(int $n): string => ':' . $n, $lines);
                    $description = implode(', ', $lineStrs);
                } else {
                    $description = $originalNodeDescriptions[$fqcn] ?? ($originalNodeDescriptions[ltrim($fqcn, '\\')] ?? '');
                }
                $throwsLine = '@throws ' . $fqcnWithBackslash;
                if (!empty($description)) {
                    $descLines = explode("\n", $description);
                    $throwsLine .= ' ' . array_shift($descLines);
                    $newDocBlockContentLines[] = $throwsLine;
                    foreach ($descLines as $dLine) {
                        $newDocBlockContentLines[] = $dLine;
                    }
                } else {
                    $newDocBlockContentLines[] = $throwsLine;
                }
            }
        }

        $finalNormalizedNewDocText = null;
        if ($hasAnyContentForNewDocBlock || $newDocBlockContentLines !== []) {
            while (count($newDocBlockContentLines) > 0 && trim($newDocBlockContentLines[0]) === '') {
                array_shift($newDocBlockContentLines);
            }
            while (count($newDocBlockContentLines) > 0 && trim(end($newDocBlockContentLines)) === '') {
                array_pop($newDocBlockContentLines);
            }

            if ($newDocBlockContentLines !== []) {
                $textToNormalize = implode("\n", $newDocBlockContentLines);
                $finalNormalizedNewDocText = $this->normalizeDocBlockString($textToNormalize);
            }
        }

        $originalNormalizedDocText = null;
        if ($docCommentNode instanceof \PhpParser\Comment\Doc) {
            $lines = $this->splitDocLines($docCommentNode->getText());
            if ($lines !== []) {
                $originalTextToNormalize = implode("\n", $lines);
                $originalNormalizedDocText = $this->normalizeDocBlockString($originalTextToNormalize);
            } else {
                $originalNormalizedDocText = null;
            }
        }

        if ($finalNormalizedNewDocText !== $originalNormalizedDocText) {
            $patchType = '';
            $patchStart = 0;
            $patchEnd = 0;
            if ($finalNormalizedNewDocText === null && $originalNormalizedDocText !== null && $docCommentNode instanceof \PhpParser\Comment\Doc) {
                $patchType = 'remove';
                $patchStart = $docCommentNode->getStartFilePos();
                $patchEnd = $docCommentNode->getEndFilePos();
            } elseif ($finalNormalizedNewDocText !== null) {
                if ($originalNormalizedDocText === null) {
                    if ($docCommentNode instanceof \PhpParser\Comment\Doc) {
                        $patchType = 'update';
                        $patchStart = $docCommentNode->getStartFilePos();
                        $patchEnd = $docCommentNode->getEndFilePos();
                    } else {
                        $patchType = 'add';
                        // For adding, $patchStart will be the start of the node itself (e.g., 'p' in 'public function')
                        // The actual insertion point will be adjusted before this based on the node's line indent.
                        $patchStart = $node->getStartFilePos();
                        $patchEnd = $node->getStartFilePos() - 1; // No original length
                    }
                } else {
                    $patchType = 'update';
                    if ($docCommentNode instanceof \PhpParser\Comment\Doc) {
                        $patchStart = $docCommentNode->getStartFilePos();
                        $patchEnd = $docCommentNode->getEndFilePos();
                    } else {
                        $patchStart = $node->getStartFilePos();
                        $patchEnd = $node->getStartFilePos();
                    }
                }
            }
            if ($patchType !== '') {
                if (!$this->quiet) {
                    echo 'Scheduling DocBlock ' . strtoupper($patchType) . ' for ' . $this->getNodeSignatureForMessage($node) . "\n";
                }
                $this->pendingPatches[] = [
                    'type' => $patchType, 'node' => $node,
                    'newDocText' => $finalNormalizedNewDocText,
                    'patchStart' => $patchStart, 'patchEnd' => $patchEnd,
                ];
            }
        }

        return null;
    }

    private function getNodeSignatureForMessage(Node $node): string
    {
        $key = $this->astUtils->getNodeKey($node, $this->currentNamespace);
        if ($node instanceof Node\Stmt\ClassMethod || $node instanceof Node\Stmt\Function_) {
            return ($key !== null && $key !== '') ? $key : ($node->name->toString() . '()');
        }

        return ($key !== null && $key !== '') ? $key : 'unknown_node_type';
    }

    private function adjustLineNumber(string $path, int $line): int
    {
        $lineShift = $this->lineShiftMap[$path] ?? null;
        if ($lineShift === null) {
            $real = realpath($path);
            if ($real !== false) {
                $lineShift = $this->lineShiftMap[$real] ?? null;
            }
        }
        if ($lineShift === null) {
            return $line;
        }

        $adjusted = $line;
        foreach ($lineShift as $shift) {
            if ($shift['line'] <= $line) {
                $adjusted += $shift['delta'];
                continue;
            }

            break;
        }

        return $adjusted;
    }
}
