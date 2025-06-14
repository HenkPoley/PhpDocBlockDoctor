<?php

namespace HenkPoley\DocBlockDoctor;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeVisitorAbstract;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use HenkPoley\DocBlockDoctor\AstUtils;

class ThrowsGatherer extends NodeVisitorAbstract
{
    private \PhpParser\NodeFinder $nodeFinder;
    private \HenkPoley\DocBlockDoctor\AstUtils $astUtils;
    private string $filePath;
    private bool $ignoreAnnotatedThrows;
    /**
     * @var string
     */
    private $currentNamespace = '';
    /**
     * @var mixed[]
     */
    private array $useMap = [];

    public function __construct(NodeFinder $nodeFinder, \HenkPoley\DocBlockDoctor\AstUtils $astUtils, string $filePath, bool $ignoreAnnotatedThrows = false)
    {
        $this->nodeFinder = $nodeFinder;
        $this->astUtils = $astUtils;
        $this->filePath = $filePath;
        $this->ignoreAnnotatedThrows = $ignoreAnnotatedThrows;
    }

    /**
     * @param mixed[] $nodes
     *
     * @throws \LogicException
     */
    public function beforeTraverse($nodes): null
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
                if (in_array($useNode->type, [Node\Stmt\Use_::TYPE_FUNCTION, Node\Stmt\Use_::TYPE_CONSTANT], true)) {
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
                if (in_array($useNode->type, [Node\Stmt\Use_::TYPE_FUNCTION, Node\Stmt\Use_::TYPE_CONSTANT], true)) {
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
    public function enterNode(Node $node): null
    {
        if ($node instanceof Node\Stmt\Class_) {
            $className = '';
            if ($node->hasAttribute('namespacedName') && $node->getAttribute('namespacedName') instanceof Node\Name) {
                $className = $node->getAttribute('namespacedName')->toString();
            } elseif ($node->name instanceof \PhpParser\Node\Identifier) {
                $className = ($this->currentNamespace ? $this->currentNamespace . '\\' : '') . $node->name->toString();
            }
            if ($className !== '') {
                $parentFqcn = null;
                if ($node->extends instanceof Node\Name) {
                    $parentFqcn = $this->astUtils->resolveNameNodeToFqcn($node->extends, $this->currentNamespace, $this->useMap, false);
                }
                \HenkPoley\DocBlockDoctor\GlobalCache::$classParents[$className] = $parentFqcn;
                foreach ($node->stmts as $stmt) {
                    if ($stmt instanceof Node\Stmt\TraitUse) {
                        foreach ($stmt->traits as $traitName) {
                            $traitFqcn = $this->astUtils->resolveNameNodeToFqcn($traitName, $this->currentNamespace, $this->useMap, false);
                            if ($traitFqcn !== '') {
                                \HenkPoley\DocBlockDoctor\GlobalCache::$classTraits[$className][] = $traitFqcn;
                            }
                        }
                    }
                }
            }
            return null;
        }

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
                    if ($resolvedFqcnForAnnotation !== '' && $resolvedFqcnForAnnotation !== '0' && !$this->ignoreAnnotatedThrows) {
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
    public function leaveNode(Node $node): null
    {
        if (!$node instanceof Node\Stmt\Function_ && !$node instanceof Node\Stmt\ClassMethod) {
            return null;
        }
        $key = $this->astUtils->getNodeKey($node, $this->currentNamespace);
        if (!$key) {
            return null;
        }
        \HenkPoley\DocBlockDoctor\GlobalCache::$directThrows[$key] =
            $this->calculateDirectThrowsForNode($node, $key);
        return null;
    }

    /**
     * @param \PhpParser\Node $funcOrMethodNode
     * @psalm-param Function_|ClassMethod $funcOrMethodNode
     * @param string $funcKey Fully qualified method/function key
     *
     * @return array
     */
    private function calculateDirectThrowsForNode(Node $funcOrMethodNode, string $funcKey): array
    {
        $fqcns = [];
        if ($funcOrMethodNode->stmts === null) {
            return [];
        }
        $throwNodes = $this->nodeFinder->findInstanceOf($funcOrMethodNode->stmts, Node\Expr\Throw_::class);
        if (!isset(\HenkPoley\DocBlockDoctor\GlobalCache::$throwOrigins[$funcKey])) {
            \HenkPoley\DocBlockDoctor\GlobalCache::$throwOrigins[$funcKey] = [];
        }
        foreach ($throwNodes as $throwExpr) {
            // Skip throws that are unreachable due to a prior throw/return
            // statement in the current statement list.
            if ($this->astUtils->isNodeAfterExecutionEndingStmt($throwExpr, $funcOrMethodNode)) {
                continue;
            }
            if ($throwExpr->expr instanceof Node\Expr\New_) {
                $newExpr = $throwExpr->expr;
                if ($newExpr->class instanceof Node\Name) {
                    $thrownFqcn = $this->astUtils->resolveNameNodeToFqcn(
                        $newExpr->class,
                        $this->currentNamespace,
                        $this->useMap,
                        false
                    );
                    if (!$this->astUtils->isExceptionCaught(
                        $throwExpr,
                        $thrownFqcn,
                        $funcOrMethodNode,
                        $this->currentNamespace,
                        $this->useMap
                    )) {
                        $fqcns[] = $thrownFqcn;
                        $loc = $this->filePath . ':' . $throwExpr->getStartLine();
                        $chain = $funcKey . ' <- ' . $loc;
                        if (!isset(\HenkPoley\DocBlockDoctor\GlobalCache::$throwOrigins[$funcKey][$thrownFqcn])) {
                            \HenkPoley\DocBlockDoctor\GlobalCache::$throwOrigins[$funcKey][$thrownFqcn] = [];
                        }
                        $origins = &\HenkPoley\DocBlockDoctor\GlobalCache::$throwOrigins[$funcKey][$thrownFqcn];
                        if (!in_array($chain, $origins, true) && count($origins) < \HenkPoley\DocBlockDoctor\GlobalCache::MAX_ORIGIN_CHAINS) {
                            $origins[] = $chain;
                        }
                    }
                } elseif ($newExpr->class instanceof Node\Expr\Variable) {
                    $varName = $newExpr->class->name;
                    if (is_string($varName)) {
                        $classFqcn = $this->findClassStringAssignment(
                            $funcOrMethodNode->stmts,
                            $newExpr,
                            $varName
                        );
                        if ($classFqcn !== null && !$this->astUtils->isExceptionCaught(
                            $throwExpr,
                            $classFqcn,
                            $funcOrMethodNode,
                            $this->currentNamespace,
                            $this->useMap
                        )) {
                            $fqcns[] = $classFqcn;
                            $loc = $this->filePath . ':' . $throwExpr->getStartLine();
                            $chain = $funcKey . ' <- ' . $loc;
                            if (!isset(\HenkPoley\DocBlockDoctor\GlobalCache::$throwOrigins[$funcKey][$classFqcn])) {
                                \HenkPoley\DocBlockDoctor\GlobalCache::$throwOrigins[$funcKey][$classFqcn] = [];
                            }
                            $origins = &\HenkPoley\DocBlockDoctor\GlobalCache::$throwOrigins[$funcKey][$classFqcn];
                            if (!in_array($chain, $origins, true) && count($origins) < \HenkPoley\DocBlockDoctor\GlobalCache::MAX_ORIGIN_CHAINS) {
                                $origins[] = $chain;
                            }
                        }
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
                                    $loc = $this->filePath . ':' . $throwExpr->getStartLine();
                                    $chain = $funcKey . ' <- ' . $loc;
                                    if (!isset(\HenkPoley\DocBlockDoctor\GlobalCache::$throwOrigins[$funcKey][$thrownFqcn])) {
                                        \HenkPoley\DocBlockDoctor\GlobalCache::$throwOrigins[$funcKey][$thrownFqcn] = [];
                                    }
                                    $origins = &\HenkPoley\DocBlockDoctor\GlobalCache::$throwOrigins[$funcKey][$thrownFqcn];
                                    if (!in_array($chain, $origins, true) && count($origins) < \HenkPoley\DocBlockDoctor\GlobalCache::MAX_ORIGIN_CHAINS) {
                                        $origins[] = $chain;
                                    }
                                }
                            }
                        }
                        $instanceofTypes = $this->getInstanceofTypesBeforeThrow($catchNode->stmts, $throwExpr, $varName);
                        foreach ($instanceofTypes as $fq) {
                            if (!$this->astUtils->isExceptionCaught($throwExpr, $fq, $funcOrMethodNode, $this->currentNamespace, $this->useMap)) {
                                $fqcns[] = $fq;
                                $loc = $this->filePath . ':' . $throwExpr->getStartLine();
                                $chain = $funcKey . ' <- ' . $loc;
                                if (!isset(\HenkPoley\DocBlockDoctor\GlobalCache::$throwOrigins[$funcKey][$fq])) {
                                    \HenkPoley\DocBlockDoctor\GlobalCache::$throwOrigins[$funcKey][$fq] = [];
                                }
                                $origins = &\HenkPoley\DocBlockDoctor\GlobalCache::$throwOrigins[$funcKey][$fq];
                                if (!in_array($chain, $origins, true) && count($origins) < \HenkPoley\DocBlockDoctor\GlobalCache::MAX_ORIGIN_CHAINS) {
                                    $origins[] = $chain;
                                }
                            }
                        }
                    } else {
                        $instanceofTypes = $this->getInstanceofTypesBeforeThrow($funcOrMethodNode->stmts, $throwExpr, $varName);
                        foreach ($instanceofTypes as $fq) {
                            if (!$this->astUtils->isExceptionCaught($throwExpr, $fq, $funcOrMethodNode, $this->currentNamespace, $this->useMap)) {
                                $fqcns[] = $fq;
                                $loc = $this->filePath . ':' . $throwExpr->getStartLine();
                                $chain = $funcKey . ' <- ' . $loc;
                                if (!isset(\HenkPoley\DocBlockDoctor\GlobalCache::$throwOrigins[$funcKey][$fq])) {
                                    \HenkPoley\DocBlockDoctor\GlobalCache::$throwOrigins[$funcKey][$fq] = [];
                                }
                                $origins = &\HenkPoley\DocBlockDoctor\GlobalCache::$throwOrigins[$funcKey][$fq];
                                if (!in_array($chain, $origins, true) && count($origins) < \HenkPoley\DocBlockDoctor\GlobalCache::MAX_ORIGIN_CHAINS) {
                                    $origins[] = $chain;
                                }
                            }
                        }
                    }
                }
            }
        }
        $filtered = array_filter(
            $fqcns,
            static fn(string $fqcn): bool => AstUtils::classOrInterfaceExistsNoAutoload($fqcn)
        );

        foreach (\HenkPoley\DocBlockDoctor\GlobalCache::$throwOrigins[$funcKey] as $ex => $origins) {
            $origins = array_values(array_unique($origins));
            if (count($origins) > \HenkPoley\DocBlockDoctor\GlobalCache::MAX_ORIGIN_CHAINS) {
                $origins = array_slice($origins, 0, \HenkPoley\DocBlockDoctor\GlobalCache::MAX_ORIGIN_CHAINS);
            }
            \HenkPoley\DocBlockDoctor\GlobalCache::$throwOrigins[$funcKey][$ex] = $origins;
        }

        return array_values(array_unique($filtered));
    }

    /**
     * @param Node[] $stmts
     *
     * @throws \LogicException
     */
    private function getInstanceofTypesBeforeThrow(array $stmts, Node\Expr\Throw_ $throwExpr, string $varName): array
    {
        $types = [];
        foreach ($stmts as $stmt) {
            $types = array_merge($types, $this->findInstanceofTypes($stmt, $varName));
            if ($this->nodeFinder->findFirst($stmt, static fn(Node $n): bool => $n === $throwExpr) instanceof \PhpParser\Node) {
                break;
            }
        }
        return array_values(array_unique(array_filter($types)));
    }

    /**
     * @throws \LogicException
     */
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
                if ($this->instanceofHasInterveningThrow($ins, $varName)) {
                    continue;
                }
                $types[] = $this->astUtils->resolveNameNodeToFqcn($ins->class, $this->currentNamespace, $this->useMap, false);
            }
        }
        return $types;
    }

    private function instanceofHasInterveningThrow(Node\Expr\Instanceof_ $ins, string $varName): bool
    {
        $parent = $ins->getAttribute('parent');
        while ($parent && !$parent instanceof Node\Stmt\If_) {
            $parent = $parent->getAttribute('parent');
        }
        if ($parent instanceof Node\Stmt\If_) {
            $throws = $this->nodeFinder->findInstanceOf($parent->stmts, Node\Expr\Throw_::class);
            if ($throws !== []) {
                foreach ($throws as $t) {
                    if ($t->expr instanceof Node\Expr\Variable && $t->expr->name === $varName) {
                        return false;
                    }
                }
                return true;
            }
        }
        return false;
    }

    /**
     * Find the class-string assignment for a variable prior to the given node.
     *
     * @param Node[] $stmts
     */
    private function findClassStringAssignment(array $stmts, Node $reference, string $varName): ?string
    {
        $assigns = $this->nodeFinder->findInstanceOf($stmts, Node\Expr\Assign::class);
        $bestPos = -1;
        $bestFqcn = null;
        foreach ($assigns as $assign) {
            if ($assign->var instanceof Node\Expr\Variable && $assign->var->name === $varName) {
                $pos = $assign->getStartFilePos() ?? -1;
                $refPos = $reference->getStartFilePos() ?? 0;
                if ($pos >= $refPos) {
                    continue;
                }
                if ($pos > $bestPos) {
                    if ($assign->expr instanceof Node\Expr\ClassConstFetch
                        && $assign->expr->class instanceof Node\Name
                        && $assign->expr->name instanceof Node\Identifier
                        && $assign->expr->name->toLowerString() === 'class') {
                        $bestFqcn = $this->astUtils->resolveNameNodeToFqcn(
                            $assign->expr->class,
                            $this->currentNamespace,
                            $this->useMap,
                            false
                        );
                        $bestPos = $pos;
                    } elseif ($assign->expr instanceof Node\Scalar\String_) {
                        $bestFqcn = $this->astUtils->resolveStringToFqcn(
                            $assign->expr->value,
                            $this->currentNamespace,
                            $this->useMap
                        );
                        $bestPos = $pos;
                    }
                }
            }
        }
        return $bestFqcn;
    }
}
