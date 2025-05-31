<?php
namespace HenkPoley\DocBlockDoctor;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\PropertyProperty;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;

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
            return ($currentNamespace && strncmp($fnName, '\\', strlen('\\')) !== 0
                    ? $currentNamespace . '\\'
                    : '') . $fnName;
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
            if (
                $current instanceof Node\Stmt\Class_
                || $current instanceof Node\Stmt\Interface_
                || $current instanceof Node\Stmt\Trait_
            ) {
                if ($current->hasAttribute('namespacedName') && $current->getAttribute('namespacedName') instanceof Node\Name) {
                    return $current->getAttribute('namespacedName')->toString();
                }

                if (isset($current->name)) {
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
        if (!$isFunctionContext && isset($useMap[$parts[0]])) {
            // First‐part resolution via `use`‐map (for classes/namespaces)
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
        if ($name === null || $name === '' || $name === '0') {
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
        // First, check: is this a MethodCall on $this->someProperty? If so, read the @var of that property.
        if (
            $callNode instanceof Node\Expr\MethodCall
            // Make sure the var is a PropertyFetch on $this:
            && $callNode->var instanceof PropertyFetch
            && $callNode->var->var instanceof Variable
            && $callNode->var->var->name === 'this'
            // And that the “->name” is actually an Identifier (not an ArrayDimFetch, etc.):
            && $callNode->var->name instanceof Node\Identifier
            && $callNode->name instanceof Node\Identifier
        ) {
            // The property name, e.g. "translator"
            $propertyName = $callNode->var->name->toString();
            // The method name, e.g. "getLanguage"
            $methodName = $callNode->name->toString();

            // Walk up from the current method/function node to find the enclosing Class_ node.
            $parent = $callerFuncOrMethodNode;
            while ($parent !== null && !($parent instanceof Class_)) {
                $parent = $parent->getAttribute('parent');
            }

            if ($parent instanceof Class_) {
                /** @var Class_ $classNode */
                $classNode = $parent;

                // Look through all properties of this class to find one named `$propertyName`
                foreach ($classNode->stmts as $stmt) {
                    if (!($stmt instanceof Property)) {
                        continue;
                    }

                    /** @var PropertyProperty $propElem */
                    foreach ($stmt->props as $propElem) {
                        if ($propElem->name->toString() === $propertyName) {
                            // Found something like “private Translate $translator;”
                            $docComment = $stmt->getDocComment();
                            if ($docComment instanceof \PhpParser\Comment\Doc) {
                                $text = $docComment->getText();
                                // Look for “@var Some\Fqcn” in the doc block.
                                if (preg_match('/@var\s+([^\s]+)/', $text, $m)) {
                                    $annotatedType = $m[1];
                                    // e.g. “\SimpleSAML\Locale\Translate” or “Translate”

                                    // Resolve to a fully qualified class name, using use‐map + namespace
                                    $fqcn = $this->resolveStringToFqcn(
                                        $annotatedType,
                                        $callerNamespace,
                                        $callerUseMap
                                    );

                                    if ($fqcn !== '') {
                                        // Return “TranslateClassName::methodName”
                                        return ltrim($fqcn, '\\') . '::' . $methodName;
                                    }
                                }
                            }
                        }
                    }
                }
            }
            // If we didn’t find a matching @var or couldn’t resolve it, fall through:
        }

        // === 1) Instance method on $this: $this->foo() ===> ClassName::foo ===
        if (
            $callNode instanceof Node\Expr\MethodCall
            && $callNode->var instanceof Node\Expr\Variable
            && $callNode->var->name === 'this'
            && $callNode->name instanceof Node\Identifier
        ) {
            $callerClass = $this->getContextClassName($callerFuncOrMethodNode, $callerNamespace);
            if ($callerClass) {
                return $callerClass . '::' . $callNode->name->toString();
            }
        }

        // === 2) StaticCall: handle self::, static::, parent::, and fully qualified names ===
        if (
            $callNode instanceof Node\Expr\StaticCall
            && $callNode->class instanceof Node\Name
            && $callNode->name instanceof Node\Identifier
        ) {
            $classNameNode = $callNode->class;
            $lower = strtolower($classNameNode->toString());

            // 2a) "self::method()" or "static::method()" → use the current class
            if ($lower === 'self' || $lower === 'static') {
                $callerClass = $this->getContextClassName($callerFuncOrMethodNode, $callerNamespace);
                if ($callerClass) {
                    return $callerClass . '::' . $callNode->name->toString();
                }
                return null;
            }

            // 2b) "parent::method()" → use the parent class (NameResolver should have attached a resolvedName attribute already)
            if ($lower === 'parent') {
                // We rely on NameResolver having already resolved "parent" to a fully qualified name in $callNode->class
                $resolvedParent = $classNameNode->getAttribute('resolvedName');
                if ($resolvedParent instanceof Node\Name\FullyQualified) {
                    $parentFqcn = $resolvedParent->toString();
                    return ltrim($parentFqcn, '\\') . '::' . $callNode->name->toString();
                }
                // As a fallback, attempt to fetch the parent from the AST:
                $classNode = $callerFuncOrMethodNode->getAttribute('parent');
                if (
                    $classNode instanceof Node\Stmt\Class_
                    && $classNode->extends instanceof Node\Name
                ) {
                    $parentFqcn = $this->resolveNameNodeToFqcn(
                        $classNode->extends,
                        $callerNamespace,
                        $callerUseMap,
                        false
                    );
                    return ltrim($parentFqcn, '\\') . '::' . $callNode->name->toString();
                }
                return null;
            }

            // 2c) "SomeClass::method()" (generic static call) → resolve via useMap / namespace
            $classFqcn = $this->resolveNameNodeToFqcn(
                $classNameNode,
                $callerNamespace,
                $callerUseMap,
                false
            );
            return ltrim($classFqcn, '\\') . '::' . $callNode->name->toString();
        }

        // === 3) Free (global) function call: foo() ===> resolves to a namespaced or fully qualified function name ===
        if (
            $callNode instanceof Node\Expr\FuncCall
            && $callNode->name instanceof Node\Name
        ) {
            $functionFqcn = $this->resolveNameNodeToFqcn(
                $callNode->name,
                $callerNamespace,
                $callerUseMap,
                true
            );
            return ltrim($functionFqcn, '\\');
        }

        // === 4) "new ClassName()" → treat as ClassName::__construct ===
        if (
            $callNode instanceof Node\Expr\New_
            && $callNode->class instanceof Node\Name
        ) {
            $classFqcn = $this->resolveNameNodeToFqcn(
                $callNode->class,
                $callerNamespace,
                $callerUseMap,
                false
            );
            return ltrim($classFqcn, '\\') . '::__construct';
        }

        // No recognized callee form → return null
        return null;
    }

    /**
     * @param \PhpParser\Node\Expr\Throw_ $throwNode
     * @param string $thrownFqcn
     * @param \PhpParser\Node $boundaryNode
     * @param string $currentNamespace
     * @param mixed[] $useMap
     */
    public function isExceptionCaught(
        $throwNode,
        $thrownFqcn,
        $boundaryNode,
        $currentNamespace,
        $useMap
    ): bool {
        $parent = $throwNode->getAttribute('parent');
        while ($parent && $parent !== $boundaryNode->getAttribute('parent')) {
            if ($parent instanceof Node\Stmt\TryCatch) {
                foreach ($parent->catches as $catchClause) {
                    foreach ($catchClause->types as $typeNode) {
                        $caughtTypeFqcn = $this->resolveNameNodeToFqcn(
                            $typeNode,
                            $currentNamespace,
                            $useMap,
                            false
                        );
                        if (class_exists($thrownFqcn, true) && class_exists($caughtTypeFqcn, true)) {
                            if ($thrownFqcn === $caughtTypeFqcn
                                || is_subclass_of($thrownFqcn, $caughtTypeFqcn)
                            ) {
                                return true;
                            }
                        } elseif ($thrownFqcn === $caughtTypeFqcn) {
                            // Fallback if classes not autoloadable during analysis
                            return true;
                        }
                    }
                }
            }

            if (
                ($parent instanceof Node\Stmt\Function_
                    || $parent instanceof Node\Stmt\ClassMethod
                    || $parent instanceof Node\Expr\Closure)
                && $parent !== $boundaryNode
            ) {
                // Do not cross into another function/method/closure boundary
                break;
            }

            $parent = $parent->getAttribute('parent');
        }

        return false;
    }
}