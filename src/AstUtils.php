<?php

namespace HenkPoley\DocBlockDoctor;

use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\NodeFinder;

/**
 * Utility Class for AST operations and Name Resolution
 */
class AstUtils
{
    /**
     * @param \PhpParser\Node $node
     * @param string $currentNamespace
     */
    public function getNodeKey($node, $currentNamespace): ?string
    {
        if ($node instanceof Node\Stmt\ClassMethod) {
            $className = $this->getContextClassName($node, $currentNamespace);
            return $className ? $className . '::' . $node->name->toString() : null;
        }

        if ($node instanceof Node\Stmt\Function_) {
            if ($node->name->hasAttribute('resolvedName') && $node->name->getAttribute('resolvedName') instanceof Node\Name) {
                return $node->name->getAttribute('resolvedName')->toString();
            }
            $fnName = $node->name->toString();
            return ($currentNamespace && strncmp($fnName, '\\', strlen('\\')) !== 0 ? $currentNamespace . '\\' : '') . $fnName;
        }
        return null;
    }

    /**
     * @param \PhpParser\Node $nodeContext
     * @param string $currentNamespace
     */
    public function getContextClassName($nodeContext, $currentNamespace): ?string
    {
        $current = $nodeContext;
        while ($current = $current->getAttribute('parent')) {
            if ($current instanceof Node\Stmt\Class_ || $current instanceof Node\Stmt\Interface_ || $current instanceof Node\Stmt\Trait_) {
                if ($current->hasAttribute('namespacedName') && $current->getAttribute('namespacedName') instanceof Node\Name) {
                    return $current->getAttribute('namespacedName')->toString();
                }

                if (isset($current->name) && $current->name instanceof Node\Identifier) {
                    return ($currentNamespace ? $currentNamespace . '\\' : '') . $current->name->toString();
                }
                return null;
            }
        }
        return null;
    }

    /**
     * @param \PhpParser\Node\Name $nameNode
     * @param string $currentNamespace
     * @param mixed[] $useMap
     * @param bool $isFunctionContext
     */
    public function resolveNameNodeToFqcn($nameNode, $currentNamespace, $useMap, $isFunctionContext): string
    {
        if ($nameNode->hasAttribute('resolvedName') && $nameNode->getAttribute('resolvedName') instanceof Node\Name\FullyQualified) {
            return $nameNode->getAttribute('resolvedName')->toString();
        }
        $name = $nameNode->toString();
        if ($nameNode->isFullyQualified()) {
            return ltrim($name, '\\');
        }
        $parts = $nameNode->getParts();
        if (!$isFunctionContext && isset($useMap[$parts[0]])) { // Check first part against use map for classes/namespaces
            $baseFqcnFromUse = $useMap[$parts[0]];
            array_shift($parts);
            return $baseFqcnFromUse . (count($parts) > 0 ? '\\' . implode('\\', $parts) : '');
        }
        if ($currentNamespace) {
            return $currentNamespace . '\\' . $name;
        }
        return $name;
    }

    /**
     * @param string $name
     * @param string $currentNamespace
     * @param mixed[] $useMap
     */
    public function resolveStringToFqcn($name, $currentNamespace, $useMap): string
    {
        if (empty($name)) {
            return '';
        }
        if (strncmp($name, '\\', strlen('\\')) === 0) {
            return ltrim($name, '\\');
        }
        $parts = explode('\\', $name);
        if (isset($useMap[$parts[0]])) {
            $baseFqcnFromUse = $useMap[$parts[0]];
            array_shift($parts);
            return $baseFqcnFromUse . (count($parts) > 0 ? '\\' . implode('\\', $parts) : '');
        }
        if ($currentNamespace) {
            return $currentNamespace . '\\' . $name;
        }
        return $name;
    }

    /**
     * @param \PhpParser\Node\Expr $callNode
     * @param string $callerNamespace
     * @param mixed[] $callerUseMap
     * @param \PhpParser\Node $callerFuncOrMethodNode
     */
    public function getCalleeKey($callNode, $callerNamespace, $callerUseMap, $callerFuncOrMethodNode): ?string
    {
        $calleeKey = null;
        $callerContextClassName = $this->getContextClassName($callerFuncOrMethodNode, $callerNamespace);


        if ($callNode instanceof Node\Expr\MethodCall && $callNode->name instanceof Node\Identifier) {
            if ($callNode->var instanceof Node\Expr\Variable) {
                $varName = $callNode->var->name;
                if ($varName === 'this' && $callerContextClassName) {
                    // existing this->method() logic
                    $calleeKey = $callerContextClassName . '::' . $callNode->name->toString();
                } else {
                    // try to infer $var’s class from a “new” assignment in this method
                    $finder = new NodeFinder();
                    $stmts = $callerFuncOrMethodNode->stmts ?? [];
                    $assigns = $finder->findInstanceOf($stmts, Assign::class);
                    foreach ($assigns as $assign) {
                        if ($assign->var instanceof Node\Expr\Variable
                            && $assign->var->name === $varName
                            && $assign->expr instanceof Node\Expr\New_
                            && $assign->expr->class instanceof Node\Name) {
                            $resolvedClass = $this->resolveNameNodeToFqcn(
                                $assign->expr->class,
                                $callerNamespace,
                                $callerUseMap,
                                false
                            );
                            $calleeKey = $resolvedClass . '::' . $callNode->name->toString();
                            break;
                        }
                    }
                }
            }
        } elseif ($callNode instanceof Node\Expr\StaticCall && $callNode->class instanceof Node\Name && $callNode->name instanceof Node\Identifier) {
            $classNameNode = $callNode->class;
            $classNameString = $classNameNode->toString();
            $resolvedClassName = null;

            if (strtolower($classNameString) === 'self' || strtolower($classNameString) === 'static') {
                $resolvedClassName = $callerContextClassName;
            } elseif (strtolower($classNameString) === 'parent') {
                $classNode = $callerFuncOrMethodNode->getAttribute('parent');
                if ($classNode instanceof Node\Stmt\Class_ && $classNode->extends) {
                    if ($classNode->extends->hasAttribute('resolvedName') && $classNode->extends->getAttribute('resolvedName') instanceof Node\Name\FullyQualified) {
                        $resolvedClassName = $classNode->extends->getAttribute('resolvedName')->toString();
                    } else {
                        $resolvedClassName = $this->resolveNameNodeToFqcn($classNode->extends, $callerNamespace, $callerUseMap, false);
                    }
                }
            } elseif ($classNameNode->hasAttribute('resolvedName') && $classNameNode->getAttribute('resolvedName') instanceof Node\Name\FullyQualified) {
                $resolvedClassName = $classNameNode->getAttribute('resolvedName')->toString();
            } else {
                $resolvedClassName = $this->resolveNameNodeToFqcn($classNameNode, $callerNamespace, $callerUseMap, false);
            }

            if ($resolvedClassName) {
                $calleeKey = $resolvedClassName . '::' . $callNode->name->toString();
            }
        } elseif ($callNode instanceof Node\Expr\FuncCall && $callNode->name instanceof Node\Name) {
            $funcNameNode = $callNode->name;
            if ($funcNameNode->hasAttribute('resolvedName') && $funcNameNode->getAttribute('resolvedName') instanceof Node\Name) {
                $calleeKey = $funcNameNode->getAttribute('resolvedName')->toString();
            } else {
                $calleeKey = $this->resolveNameNodeToFqcn($funcNameNode, $callerNamespace, $callerUseMap, true);
            }
        } // --- handle constructor calls as calls to ClassName::__construct ---
        elseif ($callNode instanceof \PhpParser\Node\Expr\New_
            && $callNode->class instanceof \PhpParser\Node\Name
        ) {
            $classFqcn = $this->resolveNameNodeToFqcn(
                $callNode->class,
                $callerNamespace,
                $callerUseMap,
                false
            );
            $calleeKey = $classFqcn . '::__construct';
            return ltrim($calleeKey, '\\');
        }

        return null;
    }

    /**
     * @param \PhpParser\Node\Expr\Throw_ $throwNode
     * @param string $thrownFqcn
     * @param \PhpParser\Node $boundaryNode
     * @param string $currentNamespace
     * @param mixed[] $useMap
     */
    public function isExceptionCaught($throwNode, $thrownFqcn, $boundaryNode, $currentNamespace, $useMap): bool
    {
        $parent = $throwNode->getAttribute('parent');
        while ($parent && $parent !== $boundaryNode->getAttribute('parent')) {
            if ($parent instanceof Node\Stmt\TryCatch) {
                foreach ($parent->catches as $catchClause) {
                    foreach ($catchClause->types as $typeNode) {
                        $caughtTypeFqcn = $this->resolveNameNodeToFqcn($typeNode, $currentNamespace, $useMap, false);
                        if (class_exists($thrownFqcn, true) && class_exists($caughtTypeFqcn, true)) {
                            if ($thrownFqcn === $caughtTypeFqcn || is_subclass_of($thrownFqcn, $caughtTypeFqcn)) {
                                return true;
                            }
                        } elseif ($thrownFqcn === $caughtTypeFqcn) { // Fallback if classes not autoloadable during analysis
                            return true;
                        }
                    }
                }
            }
            if (($parent instanceof Node\Stmt\Function_ || $parent instanceof Node\Stmt\ClassMethod || $parent instanceof Node\Expr\Closure) && $parent !== $boundaryNode) {
                break; // Do not cross into other function/method/closure boundaries
            }
            $parent = $parent->getAttribute('parent');
        }
        return false;
    }
}