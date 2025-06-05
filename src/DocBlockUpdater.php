<?php

namespace HenkPoley\DocBlockDoctor;

use PhpParser\NodeVisitorAbstract;
use PhpParser\Node;

class DocBlockUpdater extends NodeVisitorAbstract
{
    private \HenkPoley\DocBlockDoctor\AstUtils $astUtils;
    private string $currentFilePath;
    /**
     * @var mixed[]
     */
    public $pendingPatches = [];
    /**
     * @var string
     */
    private $currentNamespace = '';

    public function __construct(\HenkPoley\DocBlockDoctor\AstUtils $astUtils, string $currentFilePath)
    {
        $this->astUtils = $astUtils;
        $this->currentFilePath = $currentFilePath;
    }

    /**
     * @param mixed[] $nodes
     */
    public function beforeTraverse($nodes)
    {
        $this->pendingPatches = [];
        $this->currentNamespace = \HenkPoley\DocBlockDoctor\GlobalCache::$fileNamespaces[$this->currentFilePath] ?? '';
        return null;
    }

    private function normalizeDocBlockString(?string $contentOnlyText): ?string
    {
        if ($contentOnlyText === null) {
            return null;
        }
        $contentLines = preg_split('/\R/u', $contentOnlyText);
        $actualContent = [];
        $hasMeaningfulContent = false;
        foreach ($contentLines as $line) {
            $trimmedLine = trim($line);
            if ($trimmedLine !== "" || $line === "") {
                $actualContent[] = $line;
                if ($trimmedLine !== "") {
                    $hasMeaningfulContent = true;
                }
            }
        }
        if (!$hasMeaningfulContent && array_filter($actualContent, fn($l): bool => trim($l) !== "") === []) {
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

        $outputDocBlock = ["/**"];
        while (count($actualContent) > 0 && trim($actualContent[0]) === "") {
            array_shift($actualContent);
        }
        while (count($actualContent) > 0 && trim(end($actualContent)) === "") {
            array_pop($actualContent);
        }

        if ($actualContent === []) {
            return null;
        }

        foreach ($actualContent as $line) {
            if (trim($line) === '') {
                // preserve a blank docblock line, but no space after the asterisk
                $outputDocBlock[] = " *";
            } else {
                $outputDocBlock[] = " * " . rtrim($line);
            }
        }

        $outputDocBlock[] = " */";
        return implode("\n", $outputDocBlock);
    }

    /**
     * @param \PhpParser\Node $node
     */
    public function leaveNode($node)
    {
        if (!$node instanceof Node\Stmt\Function_ && !$node instanceof Node\Stmt\ClassMethod) {
            return null;
        }
        $nodeKey = $this->astUtils->getNodeKey($node, $this->currentNamespace);
        if (!$nodeKey) {
            return null;
        }

        /** @var list<class-string> $analyzedThrowsFqcns */
        $analyzedThrowsFqcns = \HenkPoley\DocBlockDoctor\GlobalCache::$resolvedThrows[$nodeKey] ?? [];
        // Filter out any classes or interfaces that don’t actually exist
        $analyzedThrowsFqcns = array_filter($analyzedThrowsFqcns, fn(string $fqcn): bool => class_exists($fqcn) || interface_exists($fqcn));
        $analyzedThrowsFqcns = array_values($analyzedThrowsFqcns);
        sort($analyzedThrowsFqcns);
        $docCommentNode = $node->getDocComment();
        $originalNodeDescriptions = \HenkPoley\DocBlockDoctor\GlobalCache::$originalDescriptions[$nodeKey] ?? [];

        $newDocBlockContentLines = [];
        $hasAnyContentForNewDocBlock = false;

        if ($docCommentNode instanceof \PhpParser\Comment\Doc) {
            $originalLines = preg_split('/\R/u', $docCommentNode->getText());
            $currentGenericTagLines = [];
            $isInsideGenericTag = false;

            for ($lineIdx = 1; $lineIdx < count($originalLines) - 1; $lineIdx++) {
                $currentDocLine = $originalLines[$lineIdx];
                $lineContent = preg_replace('/^\s*\*?\s?/', '', $currentDocLine);
                $trimmedLineContent = trim((string)$lineContent);

                if (preg_match('/^@throws\s/i', $trimmedLineContent)) {
                    if ($currentGenericTagLines !== []) {
                        // TODO: [EA] 'array_merge(...)' is used in a loop and is a resources greedy construction.
                        $newDocBlockContentLines = array_merge($newDocBlockContentLines, $currentGenericTagLines);
                        $currentGenericTagLines = [];
                    }
                    $isInsideGenericTag = false;
                    while ($lineIdx + 1 < count($originalLines) - 1 &&
                        !preg_match('/^@\w+/', trim((string)preg_replace('/^\s*\*?\s?/', '', $originalLines[$lineIdx + 1])))) {
                        $lineIdx++;
                    }
                    continue;
                }

                if (preg_match('/^@\w+/', $trimmedLineContent)) {
                    if ($currentGenericTagLines !== []) {
                        // TODO: [EA] 'array_merge(...)' is used in a loop and is a resources greedy construction.
                        $newDocBlockContentLines = array_merge($newDocBlockContentLines, $currentGenericTagLines);
                    }
                    $currentGenericTagLines = [$lineContent];
                    $isInsideGenericTag = true;
                    $hasAnyContentForNewDocBlock = true;
                } elseif ($isInsideGenericTag) {
                    $currentGenericTagLines[] = $lineContent;
                    if ($trimmedLineContent !== "") {
                        $hasAnyContentForNewDocBlock = true;
                    }
                } elseif ($trimmedLineContent !== '') {
                    if ($currentGenericTagLines !== []) {
                        // TODO: [EA] 'array_merge(...)' is used in a loop and is a resources greedy construction.
                        $newDocBlockContentLines = array_merge($newDocBlockContentLines, $currentGenericTagLines);
                        $currentGenericTagLines = [];
                    }
                    $newDocBlockContentLines[] = $lineContent;
                    $hasAnyContentForNewDocBlock = true;
                    $isInsideGenericTag = false;
                } elseif ($newDocBlockContentLines !== [] && trim((string)end($newDocBlockContentLines)) !== "") {
                    $newDocBlockContentLines[] = "";
                }
            }
            if ($currentGenericTagLines !== []) {
                $newDocBlockContentLines = array_merge($newDocBlockContentLines, $currentGenericTagLines);
            }
        }

        if ($analyzedThrowsFqcns !== []) {
            $hasAnyContentForNewDocBlock = true;
            if ($newDocBlockContentLines !== [] && trim((string)end($newDocBlockContentLines)) !== "") {
                $newDocBlockContentLines[] = "";
            }
            foreach ($analyzedThrowsFqcns as $fqcn) {
                // This foreach() is not dead code.
                // tombstone("ERROR: NoValue - src/DocBlockUpdater.php:188:46 - All possible types for this assignment were invalidated - This may be dead code (see https://psalm.dev/179)");
                $fqcnWithBackslash = '\\' . ltrim((string)$fqcn, '\\');
                $description = $originalNodeDescriptions[$fqcn] ?? ($originalNodeDescriptions[ltrim((string)$fqcn, '\\')] ?? '');
                $throwsLine = '@throws ' . $fqcnWithBackslash;
                if (!empty($description)) {
                    $descLines = explode("\n", (string)$description);
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
            while (count($newDocBlockContentLines) > 0 && trim((string)$newDocBlockContentLines[0]) === "") {
                array_shift($newDocBlockContentLines);
            }
            while (count($newDocBlockContentLines) > 0 && trim((string)end($newDocBlockContentLines)) === "") {
                array_pop($newDocBlockContentLines);
            }

            if ($newDocBlockContentLines !== []) {
                $textToNormalize = implode("\n", $newDocBlockContentLines);
                $finalNormalizedNewDocText = $this->normalizeDocBlockString($textToNormalize);
            }
        }

        $originalNormalizedDocText = null;
        if ($docCommentNode instanceof \PhpParser\Comment\Doc) {
            $originalDocText = $docCommentNode->getText();
            $originalContentOnlyLines = [];
            $lines = preg_split('/\R/u', $originalDocText);
            if (count($lines) > 1) {
                for ($i = 1; $i < count($lines) - 1; $i++) {
                    $originalContentOnlyLines[] = preg_replace('/^\s*\*?\s?/', '', $lines[$i]);
                }
                $originalTextToNormalize = implode("\n", $originalContentOnlyLines);
                $originalNormalizedDocText = $this->normalizeDocBlockString($originalTextToNormalize);
            } else {
                $originalNormalizedDocText = null;
            }
        }

        if ($finalNormalizedNewDocText !== $originalNormalizedDocText) {
            $patchType = '';
            $patchStart = 0;
            $patchEnd = 0;
            if ($finalNormalizedNewDocText === null && $originalNormalizedDocText !== null) {
                $patchType = 'remove';
                $patchStart = $docCommentNode->getStartFilePos();
                $patchEnd = $docCommentNode->getEndFilePos();
            } elseif ($finalNormalizedNewDocText !== null) {
                if ($originalNormalizedDocText === null) {
                    $patchType = 'add';
                    // For adding, $patchStart will be the start of the node itself (e.g., 'p' in 'public function')
                    // The actual insertion point will be adjusted before this based on the node's line indent.
                    $patchStart = $node->getStartFilePos();
                    $patchEnd = $node->getStartFilePos() - 1; // No original length
                } else {
                    $patchType = 'update';
                    $patchStart = $docCommentNode->getStartFilePos();
                    $patchEnd = $docCommentNode->getEndFilePos();
                }
            }
            if ($patchType !== '') {
                echo "Scheduling DocBlock " . strtoupper($patchType) . " for " . $this->getNodeSignatureForMessage($node) . "\n";
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
            return $key ?: (($node->name->toString() . '()'));
        }
        return $key ?: ("unknown_node_type");
    }
}