<?php
namespace HenkPoley\DocBlockDoctor;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Expr\Assign;
use PhpParser\NodeFinder;
use PhpParser\Node\Stmt\Return_;

/**
 * Utility Class for AST operations and Name Resolution
 */
class AstUtils
{
    /**
     * @param \PhpParser\Node $node
     * @param string $currentNamespace
     */
    public function getNodeKey($node, ?string $currentNamespace): ?string
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
    public function getContextClassName($nodeContext, ?string $currentNamespace): ?string
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
    public function resolveNameNodeToFqcn($nameNode, ?string $currentNamespace, $useMap, $isFunctionContext): string
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
    public function resolveStringToFqcn($name, ?string $currentNamespace, $useMap): string
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
     *
     * @throws \LogicException
     */
    public function getCalleeKey($callNode, $callerNamespace, $callerUseMap, $callerFuncOrMethodNode): ?string
    {
        // If this is a MethodCall whose “var” is itself another MethodCall, try to follow the returned object.
        if (
            $callNode instanceof Node\Expr\MethodCall
            && $callNode->var instanceof Node\Expr\MethodCall
        ) {
            // 1) First, resolve the “inner” call (e.g. OneMoreClass::nonStaticFunction)
            $innerKey = $this->getCalleeKey(
                $callNode->var,
                $callerNamespace,
                $callerUseMap,
                $callerFuncOrMethodNode
            );

            if ($innerKey) {
                // 2) Look up that inner method's AST node from the GlobalCache
                $innerNode     = GlobalCache::$astNodeMap[$innerKey] ?? null;
                $innerFilePath = GlobalCache::$nodeKeyToFilePath[$innerKey] ?? null;

                if ($innerNode instanceof Node\Stmt\ClassMethod && $innerFilePath) {
                    $innerNamespace = GlobalCache::$fileNamespaces[$innerFilePath] ?? '';
                    $innerUseMap    = GlobalCache::$fileUseMaps[$innerFilePath] ?? [];

                    // 3a) If the inner method has a return type hint, use that
                    $returnType = $innerNode->returnType;
                    if ($returnType instanceof Node\Name) {
                        $returnedFqcn = $this->resolveNameNodeToFqcn(
                            $returnType,
                            $innerNamespace,
                            $innerUseMap,
                            false
                        );
                        if ($returnedFqcn !== '') {
                            $methodName = $callNode->name instanceof Node\Identifier ? $callNode->name->toString() : '';
                            return ltrim($returnedFqcn, '\\') . '::' . $methodName;
                        }
                    } elseif ($returnType instanceof Node\NullableType && $returnType->type instanceof Node\Name) {
                        $returnedFqcn = $this->resolveNameNodeToFqcn(
                            $returnType->type,
                            $innerNamespace,
                            $innerUseMap,
                            false
                        );
                        if ($returnedFqcn !== '') {
                            $methodName = $callNode->name instanceof Node\Identifier ? $callNode->name->toString() : '';
                            return ltrim($returnedFqcn, '\\') . '::' . $methodName;
                        }
                    }

                    // 3b) Find any “return new SomeClass();” inside that method
                    $finder = new NodeFinder();
                    $returns = $finder->findInstanceOf(
                        $innerNode->stmts ?? [],
                        Return_::class
                    );

                    foreach ($returns as $returnStmt) {
                        if (
                            $returnStmt->expr instanceof Node\Expr\New_
                            && $returnStmt->expr->class instanceof Node\Name
                        ) {
                            // Resolve the FQCN of the returned class:
                            $returnedFqcn = $this->resolveNameNodeToFqcn(
                                $returnStmt->expr->class,
                                $innerNamespace,
                                $innerUseMap,
                                false
                            );

                            if ($returnedFqcn !== '' && $returnedFqcn !== '0') {
                                // 4) Synthesize “ReturnedClass::outerMethod”
                                $methodName = $callNode->name instanceof Node\Identifier ? $callNode->name->toString() : '';
                                return ltrim($returnedFqcn, '\\') . '::' . $methodName;
                            }
                        }
                    }
                }
            }
            // If we can’t follow it, we just “fall through” to the existing logic below.
        }

        // MethodCall on $this->property → translate “$this->prop->foo()” → “ClassName::foo”
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

            // Walk up from the current method/function node to find the enclosing Class_ or Trait_ node.
            $parent = $callerFuncOrMethodNode;
            while ($parent !== null && (!$parent instanceof Class_ && !$parent instanceof Node\Stmt\Trait_)) {
                $parent = $parent->getAttribute('parent');
            }

            if ($parent instanceof Class_ || $parent instanceof Node\Stmt\Trait_) {
                $classNode = $parent;

                // Look through all properties of this class/trait to find one named `$propertyName`
                foreach ($classNode->stmts as $stmt) {
                    if (!($stmt instanceof Node\Stmt\Property)) {
                        continue;
                    }

                    /** @var Node\Stmt\PropertyProperty $propElem */
                    foreach ($stmt->props as $propElem) {
                        if ($propElem->name->toString() === $propertyName) {
                            // Found something like “private Translate $translator;”
                            $docComment = $stmt->getDocComment();
                            if ($docComment instanceof \PhpParser\Comment\Doc) {
                                $text = $docComment->getText();
                                if (preg_match('/@var\s+([^\s]+)/', $text, $m)) {
                                    $annotatedType = $m[1];
                                    $fqcn = $this->resolveStringToFqcn(
                                        $annotatedType,
                                        $callerNamespace,
                                        $callerUseMap
                                    );
                                    if ($fqcn !== '') {
                                        return ltrim($fqcn, '\\') . '::' . $methodName;
                                    }
                                }
                            }
                            if ($stmt->type instanceof Name) {
                                $fqcn = $this->resolveNameNodeToFqcn(
                                    $stmt->type,
                                    $callerNamespace,
                                    $callerUseMap,
                                    false
                                );
                                if ($fqcn !== '') {
                                    return ltrim($fqcn, '\\') . '::' . $methodName;
                                }
                            } elseif ($stmt->type instanceof NullableType && $stmt->type->type instanceof Name) {
                                $fqcn = $this->resolveNameNodeToFqcn(
                                    $stmt->type->type,
                                    $callerNamespace,
                                    $callerUseMap,
                                    false
                                );
                                if ($fqcn !== '') {
                                    return ltrim($fqcn, '\\') . '::' . $methodName;
                                }
                            }
                        }
                    }
                }

                // Handle constructor property promotion
                foreach ($classNode->stmts as $stmt) {
                    if ($stmt instanceof Node\Stmt\ClassMethod && $stmt->name->toString() === '__construct') {
                        foreach ($stmt->params as $param) {
                            if (($param->flags & (Class_::MODIFIER_PUBLIC | Class_::MODIFIER_PROTECTED | Class_::MODIFIER_PRIVATE)) !== 0
                                && $param->var instanceof Variable
                                && is_string($param->var->name)
                                && $param->var->name === $propertyName
                                && $param->type !== null
                            ) {
                                $typeNode = $param->type;
                                if ($typeNode instanceof Name) {
                                    $fqcn = $this->resolveNameNodeToFqcn(
                                        $typeNode,
                                        $callerNamespace,
                                        $callerUseMap,
                                        false
                                    );
                                } elseif ($typeNode instanceof NullableType && $typeNode->type instanceof Name) {
                                    $fqcn = $this->resolveNameNodeToFqcn(
                                        $typeNode->type,
                                        $callerNamespace,
                                        $callerUseMap,
                                        false
                                    );
                                } else {
                                    $fqcn = '';
                                }
                                if ($fqcn !== '') {
                                    return ltrim($fqcn, '\\') . '::' . $methodName;
                                }
                            }
                        }
                    }
                }
            }
            // If we didn’t find a matching @var or couldn’t resolve it, fall through:
        }

        // === PARAMETER‐TYPE: $foo->bar() ===
        // New block: if $callNode->var is a simple variable, see if that variable
        // was defined as a typed parameter in the enclosing method/function.
        if (
            $callNode instanceof Node\Expr\MethodCall
            && $callNode->var instanceof Variable
            && is_string($callNode->var->name)
            && $callNode->name instanceof Identifier
        ) {
            $varName = $callNode->var->name; // e.g. "oneMoreClass"
            $methodName = $callNode->name->toString();

            // Search the enclosing function/method for a parameter with the same name:
            /** @psalm-suppress NoInterfaceProperties */
            foreach ($callerFuncOrMethodNode->params as $param) {
                if ($param->var instanceof Variable
                    && is_string($param->var->name)
                    && $param->var->name === $varName
                    && $param->type !== null
                ) {
                    // We only handle "Name" or "NullableType(Name)" specifically:
                    $typeNode = $param->type;
                    if ($typeNode instanceof Name) {
                        // Resolve a direct Name (e.g. OneMoreClass or \Foo\Bar)
                        $paramFqcn = $this->resolveNameNodeToFqcn(
                            $typeNode,
                            $callerNamespace,
                            $callerUseMap,
                            false
                        );
                    } elseif ($typeNode instanceof NullableType && $typeNode->type instanceof Name) {
                        // Resolve “?OneMoreClass” → OneMoreClass
                        $paramFqcn = $this->resolveNameNodeToFqcn(
                            $typeNode->type,
                            $callerNamespace,
                            $callerUseMap,
                            false
                        );
                    } else {
                        // Other types (scalar types, etc.) can be ignored.
                        continue;
                    }

                    if ($paramFqcn !== '' && $paramFqcn !== '0') {
                        // Successfully mapped $oneMoreClass → FQCN
                        return ltrim($paramFqcn, '\\') . '::' . $methodName;
                    }
                }
            }
            // If no matching typed parameter, fall through to other logic:
        }

        // === LOCAL NEW ASSIGNMENT:  $foo = new SomeClass();  then  $foo->bar()  ===
        if (
            $callNode instanceof Node\Expr\MethodCall
            && $callNode->var instanceof Variable
            && is_string($callNode->var->name)
            && $callNode->name instanceof Identifier
        ) {
            $varName    = $callNode->var->name;     // e.g. "foo"
            $methodName = $callNode->name->toString();

            // Ensure we have statements to scan:
            /** @psalm-suppress NoInterfaceProperties */
            if (property_exists($callerFuncOrMethodNode, 'stmts') && is_array($callerFuncOrMethodNode->stmts)) {
                $finder     = new NodeFinder();
                $allAssigns = $finder->findInstanceOf(
                    $callerFuncOrMethodNode->stmts,
                    Assign::class
                );

                $bestAssign   = null;
                $bestPosition = -1;
                foreach ($allAssigns as $assignNode) {
                    // match "$foo = new Something();"
                    if (
                        $assignNode->var instanceof Variable
                        && is_string($assignNode->var->name)
                        && $assignNode->var->name === $varName
                        && $assignNode->expr instanceof Node\Expr\New_
                        && $assignNode->expr->class instanceof Node\Name
                    ) {
                        $pos = $assignNode->getStartFilePos();
                        $callPos = $callNode->getStartFilePos();
                        // Only consider assignments that happen earlier in the file than the call:
                        if ($pos < $callPos && $pos > $bestPosition) {
                            $bestPosition = $pos;
                            $bestAssign   = $assignNode;
                        }
                    }
                }

                if ($bestAssign instanceof Assign) {
                    // Resolve "new Something()" → FQCN
                    /** @var Node\Expr\New_ $newExpr */
                    $newExpr = $bestAssign->expr;
                    if ($newExpr->class instanceof Node\Name) {
                        $newClassNode = $newExpr->class;
                        $classFqcn    = $this->resolveNameNodeToFqcn(
                            $newClassNode,
                            $callerNamespace,
                            $callerUseMap,
                            false
                        );
                        if ($classFqcn !== '' && $classFqcn !== '0') {
                            return ltrim($classFqcn, '\\') . '::' . $methodName;
                        }
                    }
                }
            }
            // If nothing matched, fall through to "$this->", "static::", etc.
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
                $methodName  = $callNode->name->toString();
                $declaring   = $this->findDeclaringClassForMethod($callerClass, $methodName, $callerFuncOrMethodNode, $callerNamespace, $callerUseMap);
                $targetClass = $declaring ?? $callerClass;
                return $targetClass . '::' . $methodName;
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

        // === 4) "new ClassName()" or "new self()/static()/parent()" → treat as ClassName::__construct ===
        if (
            $callNode instanceof Node\Expr\New_
            && $callNode->class instanceof Node\Name
        ) {
            $classNameNode = $callNode->class;
            $lower         = strtolower($classNameNode->toString());

            // 4a) "new self()" or "new static()" → current class
            if ($lower === 'self' || $lower === 'static') {
                $callerClass = $this->getContextClassName($callerFuncOrMethodNode, $callerNamespace);
                if ($callerClass) {
                    return $callerClass . '::__construct';
                }
                return null;
            }

            // 4b) "new parent()" → parent class of current class, if it exists
            if ($lower === 'parent') {
                // Attempt to find the AST Class_ node that contains this method/function:
                $classNode = $callerFuncOrMethodNode->getAttribute('parent');
                if ($classNode instanceof Node\Stmt\Class_ && $classNode->extends instanceof Node\Name) {
                    // Resolve parent’s FQCN using useMap + namespace:
                    $parentFqcn = $this->resolveNameNodeToFqcn(
                        $classNode->extends,
                        $callerNamespace,
                        $callerUseMap,
                        false
                    );
                    if ($parentFqcn !== '' && $parentFqcn !== '0') {
                        return ltrim($parentFqcn, '\\') . '::__construct';
                    }
                }
                return null;
            }

            // 4c) Otherwise a normal "new SomeClass()" → resolve via useMap / namespace:
            $classFqcn = $this->resolveNameNodeToFqcn(
                $classNameNode,
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
        ?string $currentNamespace,
        $useMap
    ): bool {
        $parent = $throwNode->getAttribute('parent');
        $currentCatch = null;
        while ($parent && $parent !== $boundaryNode->getAttribute('parent')) {
            if ($parent instanceof Node\Stmt\Catch_) {
                $currentCatch = $parent;
            }
            if ($parent instanceof Node\Stmt\TryCatch) {
                foreach ($parent->catches as $catchClause) {
                    if ($currentCatch === $catchClause) {
                        continue; // Skip catch block that contains the throw
                    }
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
                $currentCatch = null;
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

    /**
     * Determine if a node appears after a throw/return statement that would
     * terminate execution of the current statement list. Used to avoid
     * considering unreachable calls when propagating exceptions.
     *
     * @param \PhpParser\Node $node        The node to check reachability for
     * @param \PhpParser\Node $boundary    Typically a function or method node
     */
    public function isNodeAfterExecutionEndingStmt($node, $boundary): bool
    {
        $current = $node;
        while ($current && $current !== $boundary) {
            $parent = $current->getAttribute('parent');
            if ($parent && property_exists($parent, 'stmts') && is_array($parent->stmts)) {
                $stmts = $parent->stmts;
                $idx = array_search($current, $stmts, true);
                if ($idx !== false) {
                    for ($i = 0; $i < $idx; $i++) {
                        $s = $stmts[$i];
                        if (
                            $s instanceof Node\Stmt\Return_
                            || $s instanceof Node\Expr\Throw_
                            || ($s instanceof Node\Stmt\Expression && $s->expr instanceof Node\Expr\Throw_)
                        ) {
                            return true;
                        }
                    }
                }
            }
            $current = $parent;
        }

        return false;
    }

    /**
     * Find the class in the inheritance chain that declares the given method.
     *
     * @param string $classFqcn
     * @param string $method
     */
    private function findDeclaringClassForMethod(
        string $classFqcn,
        string $method,
        Node $callerFuncOrMethodNode,
        ?string $callerNamespace,
        array $callerUseMap
    ): ?string {
        if (class_exists($classFqcn)) {
            try {
                $ref = new \ReflectionClass($classFqcn);
                while ($ref) {
                    if ($ref->hasMethod($method)) {
                        $decl = $ref->getMethod($method)->getDeclaringClass()->getName();
                        return ltrim($decl, '\\');
                    }
                    $ref = $ref->getParentClass();
                }
            } catch (\ReflectionException $e) {
                // ignore and fall back to AST
            }
        }

        $classNode = $callerFuncOrMethodNode->getAttribute('parent');
        while ($classNode && !$classNode instanceof Node\Stmt\Class_) {
            $classNode = $classNode->getAttribute('parent');
        }

        while ($classNode instanceof Node\Stmt\Class_ && $classNode->extends instanceof Node\Name) {
            $parentFqcn = $this->resolveNameNodeToFqcn($classNode->extends, $callerNamespace, $callerUseMap, false);
            $candidateKey = ltrim($parentFqcn, '\\') . '::' . $method;
            if (isset(GlobalCache::$astNodeMap[$candidateKey])) {
                return ltrim($parentFqcn, '\\');
            }
            if (class_exists($parentFqcn)) {
                try {
                    $ref = new \ReflectionClass($parentFqcn);
                    if ($ref->hasMethod($method)) {
                        return ltrim($ref->getMethod($method)->getDeclaringClass()->getName(), '\\');
                    }
                    $classNode = null;
                    if ($ref->getParentClass()) {
                        $parentFqcn = $ref->getParentClass()->getName();
                        $candidateKey = ltrim($parentFqcn, '\\') . '::' . $method;
                        if (isset(GlobalCache::$astNodeMap[$candidateKey])) {
                            return ltrim($parentFqcn, '\\');
                        }
                    }
                } catch (\ReflectionException $e) {
                    break;
                }
            } else {
                break;
            }
        }

        return null;
    }
}