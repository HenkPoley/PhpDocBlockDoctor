<?php

namespace HenkPoley\DocBlockDoctor;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;

class ThrowsGatherer extends NodeVisitorAbstract
{
    private \PhpParser\NodeFinder $nodeFinder;
    private \HenkPoley\DocBlockDoctor\AstUtils $astUtils;
    private string $filePath;
    /**
     * @var string
     */
    private $currentNamespace = '';
    /**
     * @var mixed[]
     */
    private array $useMap = [];

    public function __construct(NodeFinder $nodeFinder, \HenkPoley\DocBlockDoctor\AstUtils $astUtils, string $filePath)
    {
        $this->nodeFinder = $nodeFinder;
        $this->astUtils = $astUtils;
        $this->filePath = $filePath;
    }

    /**
     * @param mixed[] $nodes
     *
     * @throws \LogicException
     */
    public function beforeTraverse($nodes)
    {
        $this->currentNamespace = '';
        $this->useMap = [];
        $nsNode = $this->nodeFinder->findFirstInstanceOf($nodes, Node\Stmt\Namespace_::class);
        if ($nsNode && $nsNode->name) {
            $this->currentNamespace = $nsNode->name->toString();
        }
        \HenkPoley\DocBlockDoctor\GlobalCache::$fileNamespaces[$this->filePath] = $this->currentNamespace;

        foreach ($this->nodeFinder->find($nodes, fn(Node $n): bool => $n instanceof Node\Stmt\Use_ || $n instanceof Node\Stmt\GroupUse) as $useNode) {
            if ($useNode instanceof Node\Stmt\Use_) {
                if ($useNode->type !== Node\Stmt\Use_::TYPE_NORMAL) {
                    continue;
                }
                foreach ($useNode->uses as $useUse) {
                    $alias = $useUse->alias ? $useUse->alias->toString() : $useUse->name->getLast();
                    if ($useUse->name->hasAttribute('resolvedName') && $useUse->name->getAttribute('resolvedName') instanceof Node\Name) {
                        $this->useMap[$alias] = $useUse->name->getAttribute('resolvedName')->toString();
                    } else {
                        $this->useMap[$alias] = $this->astUtils->resolveNameNodeToFqcn($useUse->name, '', [], false);
                    }
                }
            } elseif ($useNode instanceof Node\Stmt\GroupUse) {
                if ($useNode->type !== Node\Stmt\Use_::TYPE_NORMAL) {
                    continue;
                }
                foreach ($useNode->uses as $useUse) {
                    $alias = $useUse->alias ? $useUse->alias->toString() : $useUse->name->getLast();
                    if ($useUse->name->hasAttribute('resolvedName') && $useUse->name->getAttribute('resolvedName') instanceof Node\Name) {
                        $this->useMap[$alias] = $useUse->name->getAttribute('resolvedName')->toString();
                    } else {
                        $prefixStr = $useNode->prefix->toString();
                        if ($useNode->prefix->hasAttribute('resolvedName')) {
                            $prefixStr = (($nullsafeVariable1 = $useNode->prefix->getAttribute('resolvedName')) ? $nullsafeVariable1->toString() : null) ?? '';
                        }
                        $this->useMap[$alias] = $this->astUtils->resolveStringToFqcn($prefixStr . '\\' . $useUse->name->toString(), '', []);
                    }
                }
            }
        }
        \HenkPoley\DocBlockDoctor\GlobalCache::$fileUseMaps[$this->filePath] = $this->useMap;
        return null;
    }

    /**
     * @param \PhpParser\Node $node
     *
     * @throws \LogicException
     */
    public function enterNode($node)
    {
        if (!$node instanceof Node\Stmt\Function_ && !$node instanceof Node\Stmt\ClassMethod) {
            return null;
        }
        $key = $this->astUtils->getNodeKey($node, $this->currentNamespace);
        if (!$key) {
            return null;
        }
        \HenkPoley\DocBlockDoctor\GlobalCache::$astNodeMap[$key] = $node;
        \HenkPoley\DocBlockDoctor\GlobalCache::$nodeKeyToFilePath[$key] = $this->filePath;
        \HenkPoley\DocBlockDoctor\GlobalCache::$directThrows[$key] = [];
        \HenkPoley\DocBlockDoctor\GlobalCache::$originalDescriptions[$key] = [];
        $currentAnnotatedThrowsFqcns = [];

        $docComment = $node->getDocComment();
        if ($docComment instanceof \PhpParser\Comment\Doc) {
            $docText = $docComment->getText();
            $docLines = preg_split('/\R/u', $docText) ?: [];
            $currentThrowsFqcnForDesc = null;
            $accumulatedDescription = "";
            foreach ($docLines as $docLineIdx => $docLine) {
                $isFirstLine = ($docLineIdx === 0 && preg_match('/^\s*\/\*\*/', $docLine));
                $isLastLine = ($docLineIdx === count($docLines) - 1 && preg_match('/\*\/\s*$/', $docLine));
                $contentLine = $docLine;
                if ($isFirstLine) {
                    $contentLine = preg_replace('/^\s*\/\*\*\s?/', '', $contentLine);
                }
                if ($isLastLine) {
                    $contentLine = preg_replace('/\s*\*\/$/', '', (string)$contentLine);
                }
                $contentLine = preg_replace('/^\s*\*\s?/', '', (string)$contentLine);
                $contentLine = rtrim((string)$contentLine);

                if (preg_match('/^@throws\s+([^\s]+)\s*(.*)/i', $contentLine, $matches)) {
                    if ($currentThrowsFqcnForDesc && !isset(\HenkPoley\DocBlockDoctor\GlobalCache::$originalDescriptions[$key][$currentThrowsFqcnForDesc]) && !in_array(trim($accumulatedDescription), ['', '0'], true)) {
                        \HenkPoley\DocBlockDoctor\GlobalCache::$originalDescriptions[$key][$currentThrowsFqcnForDesc] = trim($accumulatedDescription);
                    }
                    $exceptionNameInAnnotation = trim($matches[1]);
                    $resolvedFqcnForAnnotation = $this->astUtils->resolveStringToFqcn($exceptionNameInAnnotation, $this->currentNamespace, $this->useMap);
                    $currentThrowsFqcnForDesc = $resolvedFqcnForAnnotation;
                    $accumulatedDescription = trim($matches[2]);
                    if ($resolvedFqcnForAnnotation) {
                        $currentAnnotatedThrowsFqcns[] = $resolvedFqcnForAnnotation;
                    }

                } elseif ($currentThrowsFqcnForDesc && !$isFirstLine && !$isLastLine && !preg_match('/^@\w+/', $contentLine)) {
                    $accumulatedDescription .= (in_array(trim($accumulatedDescription), ['', '0'], true) && $contentLine === '' ? '' : "\n") . $contentLine;
                } elseif ($isLastLine || preg_match('/^@\w+/', $contentLine)) {
                    if ($currentThrowsFqcnForDesc && !in_array(trim($accumulatedDescription), ['', '0'], true) && !isset(\HenkPoley\DocBlockDoctor\GlobalCache::$originalDescriptions[$key][$currentThrowsFqcnForDesc])) {
                        \HenkPoley\DocBlockDoctor\GlobalCache::$originalDescriptions[$key][$currentThrowsFqcnForDesc] = trim($accumulatedDescription);
                    }
                    $currentThrowsFqcnForDesc = null;
                    $accumulatedDescription = "";
                }
            }
            if ($currentThrowsFqcnForDesc && !in_array(trim($accumulatedDescription), ['', '0'], true) && !isset(\HenkPoley\DocBlockDoctor\GlobalCache::$originalDescriptions[$key][$currentThrowsFqcnForDesc])) {
                \HenkPoley\DocBlockDoctor\GlobalCache::$originalDescriptions[$key][$currentThrowsFqcnForDesc] = trim($accumulatedDescription);
            }
        }
        \HenkPoley\DocBlockDoctor\GlobalCache::$annotatedThrows[$key] = array_values(array_unique($currentAnnotatedThrowsFqcns));
        return null;
    }

    /**
     * @param \PhpParser\Node $node
     *
     * @throws \LogicException
     */
    public function leaveNode($node)
    {
        if (!$node instanceof Node\Stmt\Function_ && !$node instanceof Node\Stmt\ClassMethod) {
            return null;
        }
        $key = $this->astUtils->getNodeKey($node, $this->currentNamespace);
        if (!$key) {
            return null;
        }
        \HenkPoley\DocBlockDoctor\GlobalCache::$directThrows[$key] = $this->calculateDirectThrowsForNode($node);
        return null;
    }

    /**
     * @param Function_|ClassMethod $funcOrMethodNode
     * @throws \LogicException
     */
    private function calculateDirectThrowsForNode(Node $funcOrMethodNode): array
    {
        $fqcns = [];
        if ($funcOrMethodNode->stmts === null) {
            return [];
        }
        $throwNodes = $this->nodeFinder->findInstanceOf($funcOrMethodNode->stmts, Node\Expr\Throw_::class);
        foreach ($throwNodes as $throwExpr) {
            // Skip throws that are unreachable due to a prior throw/return
            // statement in the current statement list.
            if ($this->astUtils->isNodeAfterExecutionEndingStmt($throwExpr, $funcOrMethodNode)) {
                continue;
            }
            if ($throwExpr->expr instanceof Node\Expr\New_) {
                $newExpr = $throwExpr->expr;
                if ($newExpr->class instanceof Node\Name) {
                    $thrownFqcn = $this->astUtils->resolveNameNodeToFqcn($newExpr->class, $this->currentNamespace, $this->useMap, false);
                    if (!$this->astUtils->isExceptionCaught($throwExpr, $thrownFqcn, $funcOrMethodNode, $this->currentNamespace, $this->useMap)) {
                        $fqcns[] = $thrownFqcn;
                    }
                }
            } elseif ($throwExpr->expr instanceof Node\Expr\Variable) {
                $varName = $throwExpr->expr->name;
                if (is_string($varName)) {
                    $parent = $throwExpr->getAttribute('parent');
                    $catchNode = null;
                    while ($parent && $parent !== $funcOrMethodNode->getAttribute('parent')) {
                        if ($parent instanceof Node\Stmt\Catch_) {
                            $catchNode = $parent;
                            break;
                        }
                        if (($parent instanceof Node\Stmt\Function_ || $parent instanceof Node\Stmt\ClassMethod || $parent instanceof Node\Expr\Closure) && $parent !== $funcOrMethodNode) {
                            break;
                        }
                        $parent = $parent->getAttribute('parent');
                    }
                    if ($catchNode && $catchNode->var instanceof Node\Expr\Variable && $catchNode->var->name === $varName) {
                        foreach ($catchNode->types as $typeNode) {
                            if ($typeNode instanceof Node\Name) {
                                $thrownFqcn = $this->astUtils->resolveNameNodeToFqcn($typeNode, $this->currentNamespace, $this->useMap, false);
                                if (!$this->astUtils->isExceptionCaught($throwExpr, $thrownFqcn, $funcOrMethodNode, $this->currentNamespace, $this->useMap)) {
                                    $fqcns[] = $thrownFqcn;
                                }
                            }
                        }
                        $instanceofTypes = $this->getInstanceofTypesBeforeThrow($catchNode->stmts, $throwExpr, $varName);
                        foreach ($instanceofTypes as $fq) {
                            if (!$this->astUtils->isExceptionCaught($throwExpr, $fq, $funcOrMethodNode, $this->currentNamespace, $this->useMap)) {
                                $fqcns[] = $fq;
                            }
                        }
                    } else {
                        $instanceofTypes = $this->getInstanceofTypesBeforeThrow($funcOrMethodNode->stmts, $throwExpr, $varName);
                        foreach ($instanceofTypes as $fq) {
                            if (!$this->astUtils->isExceptionCaught($throwExpr, $fq, $funcOrMethodNode, $this->currentNamespace, $this->useMap)) {
                                $fqcns[] = $fq;
                            }
                        }
                    }
                }
            }
        }
        $filtered = array_filter(
            $fqcns,
            static fn(string $fqcn): bool => class_exists($fqcn) || interface_exists($fqcn)
        );
        return array_values(array_unique($filtered));
    }

    /** @param Node[] $stmts */
    private function getInstanceofTypesBeforeThrow(array $stmts, Node\Expr\Throw_ $throwExpr, string $varName): array
    {
        $types = [];
        foreach ($stmts as $stmt) {
            $types = array_merge($types, $this->findInstanceofTypes($stmt, $varName));
            if ($this->nodeFinder->findFirst($stmt, static fn(Node $n): bool => $n === $throwExpr)) {
                break;
            }
        }
        return array_values(array_unique(array_filter($types)));
    }

    private function findInstanceofTypes(Node $node, string $varName): array
    {
        $matches = $this->nodeFinder->find($node, static fn(Node $n): bool => $n instanceof Node\Expr\Instanceof_
            && $n->expr instanceof Node\Expr\Variable
            && $n->expr->name === $varName
            && $n->class instanceof Node\Name);
        $types = [];
        foreach ($matches as $ins) {
            /** @var Node\Expr\Instanceof_ $ins */
            if ($ins->class instanceof Node\Name) {
                $types[] = $this->astUtils->resolveNameNodeToFqcn($ins->class, $this->currentNamespace, $this->useMap, false);
            }
        }
        return $types;
    }
}
