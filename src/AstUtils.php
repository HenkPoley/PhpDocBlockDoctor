<?php

declare(strict_types=1);

namespace HenkPoley\DocBlockDoctor;

use PhpParser\Node;
use PhpParser\Modifiers;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Expr\Assign;
use PhpParser\NodeFinder;
use PhpParser\Node\Stmt\Return_;
use Composer\Autoload\ClassLoader;

/**
 * Utility Class for AST operations and Name Resolution
 */
class AstUtils
{
    /**
     * Cache of variable assignments for each function/method node.
     *
     * @var array<string,array<string,array<int,array{pos:int,expr:Node\Expr}>>>
     */
    private array $assignmentCache = [];

    /**
     * Build or retrieve cached assignments for the given function/method.
     *
     * @param Node\FunctionLike $func
     * @return array<string,array<int,array{pos:int,expr:Node\Expr}>>
     *
     * @throws \InvalidArgumentException
     * @throws \LogicException
     */
    private function getAssignmentsForFunction(Node\FunctionLike $func): array
    {
        $key = spl_object_hash($func);
        if (!isset($this->assignmentCache[$key])) {
            $map = [];
            $stmts = $func->getStmts();
            if ($stmts !== null) {
                $finder = new NodeFinder();
                $assigns = $finder->findInstanceOf($stmts, Assign::class);
                foreach ($assigns as $assign) {
                    if ($assign->var instanceof Variable && is_string($assign->var->name)) {
                        $map[$assign->var->name][] = [
                            'pos'  => $assign->getStartFilePos(),
                            'expr' => $assign->expr,
                        ];
                    }
                }
            }
            $this->assignmentCache[$key] = $map;
        }

        return $this->assignmentCache[$key];
    }

    /**
     * Find the expression assigned to $varName before the given call position.
     *
     * @throws \InvalidArgumentException
     * @throws \LogicException
     */
    private function findPriorAssignment(string $varName, Node $callNode, Node\FunctionLike $func): ?Node\Expr
    {
        $assignments = $this->getAssignmentsForFunction($func)[$varName] ?? [];
        $callPos = $callNode->getStartFilePos();
        $bestPos = -1;
        $bestExpr = null;
        foreach ($assignments as $info) {
            if ($info['expr'] === $callNode) {
                continue;
            }
            if ($info['pos'] < $callPos && $info['pos'] > $bestPos) {
                $bestPos = $info['pos'];
                $bestExpr = $info['expr'];
            }
        }

        return $bestExpr;
    }

    public function getNodeKey(Node $node, ?string $currentNamespace): ?string
    {
        if ($node instanceof Node\Stmt\ClassMethod) {
            $className = $this->getContextClassName($node, $currentNamespace);

            if ($className !== null && $className !== '') {
                return $className . '::' . $node->name->toString();
            }

            return null;
        }

        if ($node instanceof Node\Stmt\Function_) {
            $fnName = $node->name->toString();

            $prefix = '';
            if ($currentNamespace !== null
                && $currentNamespace !== ''
                && strncmp($fnName, '\\', strlen('\\')) !== 0) {
                $prefix = $currentNamespace . '\\';
            }

            return $prefix . $fnName;
        }

        return null;
    }

    public function getContextClassName(Node $nodeContext, ?string $currentNamespace): ?string
    {
        $current = $nodeContext;
        while (true) {
            $parentNode = $current->getAttribute('parent');
            if (!$parentNode instanceof Node) {
                break;
            }
            $current = $parentNode;
            if (
                $current instanceof Node\Stmt\Class_
                || $current instanceof Node\Stmt\Interface_
                || $current instanceof Node\Stmt\Trait_
            ) {
                if ($current->hasAttribute('namespacedName')) {
                    /** @var mixed $attr */
                    $attr = $current->getAttribute('namespacedName');
                    if ($attr instanceof Node\Name) {
                        return $attr->toString();
                    }
                }

                if (isset($current->name)) {
                    $prefix = ($currentNamespace !== null && $currentNamespace !== '')
                        ? $currentNamespace . '\\'
                        : '';

                    return $prefix . $current->name->toString();
                }

                return null;
            }
        }

        return null;
    }

    /**
     * Determine the namespace for a given node by walking up the parent chain.
     */
    private function getNamespaceForNode(Node $node): string
    {
        $current = $node;
        while (true) {
            $parentNode = $current->getAttribute('parent');
            if (!$parentNode instanceof Node) {
                break;
            }
            $current = $parentNode;
            if ($current instanceof Node\Stmt\Namespace_ && $current->name instanceof Name) {
                return $current->name->toString();
            }
        }

        return '';
    }

    /**
     * @param array<string, string> $useMap
     */
    public function resolveNameNodeToFqcn(Name $nameNode, ?string $currentNamespace, array $useMap, bool $isFunctionContext): string
    {
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

        if ($currentNamespace !== null && $currentNamespace !== '') {
            return $currentNamespace . '\\' . $name;
        }

        return $name;
    }

    /**
     * @param array<string, string> $useMap
     */
    public function resolveStringToFqcn(string $name, ?string $currentNamespace, array $useMap): string
    {
        if ($name === '' || $name === '0') {
            return '';
        }
        if (strpos($name, '|') !== false) {
            $name = explode('|', $name, 2)[0];
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

        if ($currentNamespace !== null && $currentNamespace !== '') {
            return $currentNamespace . '\\' . $name;
        }

        return $name;
    }

    /**
     * @param array<string, string> $callerUseMap
     * @param array<string, bool>   $visited
     *
     * @throws \InvalidArgumentException
     * @throws \LogicException
     */
    public function getCalleeKey(
        Node\Expr $callNode,
        string $callerNamespace,
        array $callerUseMap,
        Node\FunctionLike $callerFuncOrMethodNode,
        array &$visited = []
    ): ?string
    {
        $hash = spl_object_hash($callNode);
        if (isset($visited[$hash])) {
            return null;
        }
        $visited[$hash] = true;
        // If this is a MethodCall whose “var” is itself another MethodCall or a StaticCall,
        // try to follow the returned object from that inner call.
        if (
            $callNode instanceof Node\Expr\MethodCall
            && ($callNode->var instanceof Node\Expr\MethodCall || $callNode->var instanceof Node\Expr\StaticCall)
        ) {
            $resolved = $this->resolveInnerMethodCall(
                $callNode->var,
                $callNode,
                $callerNamespace,
                $callerUseMap,
                $callerFuncOrMethodNode,
                $visited
            );
            if ($resolved !== null) {
                return $resolved;
            }
            // If we can’t follow it, we just fall through to the existing logic below.
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

            $resolved = $this->resolveThisPropertyCall(
                $propertyName,
                $methodName,
                $callerFuncOrMethodNode,
                $callerNamespace,
                $callerUseMap
            );
            if ($resolved !== null) {
                return $resolved;
            }
            // If we didn’t find a matching @var or couldn’t resolve it, fall through:
        }

        // StaticCall on $this->property → translate "$this->prop::foo()" → "ClassName::foo"
        if (
            $callNode instanceof Node\Expr\StaticCall
            && $callNode->class instanceof PropertyFetch
            && $callNode->class->var instanceof Variable
            && $callNode->class->var->name === 'this'
            && $callNode->class->name instanceof Node\Identifier
            && $callNode->name instanceof Node\Identifier
        ) {
            $propertyName = $callNode->class->name->toString();
            $methodName   = $callNode->name->toString();

            $resolved = $this->resolveThisPropertyCall(
                $propertyName,
                $methodName,
                $callerFuncOrMethodNode,
                $callerNamespace,
                $callerUseMap
            );
            if ($resolved !== null) {
                return $resolved;
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
            foreach ($callerFuncOrMethodNode->getParams() as $param) {
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
                        $decl = $this->findDeclaringClassForMethod(
                            ltrim($paramFqcn, '\\'),
                            $methodName
                        );
                        $target = $decl ?? ltrim($paramFqcn, '\\');

                        return $target . '::' . $methodName;
                    }
                }
            }
            // If no matching typed parameter, fall through to other logic:
        }

        // === METHOD CALL ON NEW OBJECT: (new Foo())->bar() ===
        if (
            $callNode instanceof Node\Expr\MethodCall
            && $callNode->var instanceof Node\Expr\New_
            && $callNode->var->class instanceof Name
            && $callNode->name instanceof Identifier
        ) {
            $classFqcn = $this->resolveNameNodeToFqcn(
                $callNode->var->class,
                $callerNamespace,
                $callerUseMap,
                false
            );
            if ($classFqcn !== '' && $classFqcn !== '0') {
                $decl = $this->findDeclaringClassForMethod(
                    ltrim($classFqcn, '\\'),
                    $callNode->name->toString()
                );
                $target = $decl ?? ltrim($classFqcn, '\\');

                return $target . '::' . $callNode->name->toString();
            }
        }

        // === ANONYMOUS CLASS METHOD CALL: (new class(...) extends Foo {})->bar() ===
        if (
            $callNode instanceof Node\Expr\MethodCall
            && $callNode->var instanceof Node\Expr\New_
            && $callNode->var->class instanceof Class_
            && $callNode->name instanceof Identifier
        ) {
            $anonClass = $callNode->var->class;
            if ($anonClass->extends instanceof Name) {
                $parentFqcn = $this->resolveNameNodeToFqcn(
                    $anonClass->extends,
                    $callerNamespace,
                    $callerUseMap,
                    false
                );
                if ($parentFqcn !== '' && $parentFqcn !== '0') {
                    $decl = $this->findDeclaringClassForMethod(
                        ltrim($parentFqcn, '\\'),
                        $callNode->name->toString()
                    );
                    $target = $decl ?? ltrim($parentFqcn, '\\');

                    return $target . '::' . $callNode->name->toString();
                }
            }
        }

        // === LOCAL ASSIGNMENT: variable initialized from "new" or another call ===
        if (
            $callNode instanceof Node\Expr\MethodCall
            && $callNode->var instanceof Variable
            && is_string($callNode->var->name)
            && $callNode->name instanceof Identifier
        ) {
            $varName    = $callNode->var->name;
            $methodName = $callNode->name->toString();

            $assignedExpr = $this->findPriorAssignment($varName, $callNode, $callerFuncOrMethodNode);

            if ($assignedExpr instanceof Node\Expr\New_ && $assignedExpr->class instanceof Node\Name) {
                $classFqcn = $this->resolveNameNodeToFqcn(
                    $assignedExpr->class,
                    $callerNamespace,
                    $callerUseMap,
                    false
                );
                if ($classFqcn !== '' && $classFqcn !== '0') {
                    $decl  = $this->findDeclaringClassForMethod(ltrim($classFqcn, '\\'), $methodName);
                    $target = $decl ?? ltrim($classFqcn, '\\');

                    return $target . '::' . $methodName;
                }
            } elseif ($assignedExpr instanceof Node\Expr\FuncCall || $assignedExpr instanceof Node\Expr\MethodCall || $assignedExpr instanceof Node\Expr\StaticCall) {
                $innerKey = $this->getCalleeKey(
                    $assignedExpr,
                    $callerNamespace,
                    $callerUseMap,
                    $callerFuncOrMethodNode,
                    $visited
                );
                if ($innerKey !== null && $innerKey !== '') {
                    $innerNode     = GlobalCache::$astNodeMap[$innerKey] ?? null;
                    $innerFilePath = GlobalCache::getFilePathForKey($innerKey);

                    if ($innerNode instanceof Node\FunctionLike && is_string($innerFilePath) && $innerFilePath !== '') {
                        $innerNamespace = GlobalCache::getFileNamespace($innerFilePath);
                        if ($innerNamespace === '') {
                            $innerNamespace = $this->getNamespaceForNode($innerNode);
                        }
                        $innerUseMap    = GlobalCache::getFileUseMap($innerFilePath);

                        $returnType = $innerNode->getReturnType();
                        if ($returnType instanceof Name) {
                            $returnedFqcn = $this->resolveNameNodeToFqcn(
                                $returnType,
                                $innerNamespace,
                                $innerUseMap,
                                false
                            );
                            if ($returnedFqcn !== '') {
                                $decl  = $this->findDeclaringClassForMethod(ltrim($returnedFqcn, '\\'), $methodName);
                                $target = $decl ?? ltrim($returnedFqcn, '\\');

                                return $target . '::' . $methodName;
                            }
                        } elseif ($returnType instanceof NullableType && $returnType->type instanceof Name) {
                            $returnedFqcn = $this->resolveNameNodeToFqcn(
                                $returnType->type,
                                $innerNamespace,
                                $innerUseMap,
                                false
                            );
                            if ($returnedFqcn !== '') {
                                $decl  = $this->findDeclaringClassForMethod(ltrim($returnedFqcn, '\\'), $methodName);
                                $target = $decl ?? ltrim($returnedFqcn, '\\');

                                return $target . '::' . $methodName;
                            }
                        }

                        $finder2 = new NodeFinder();
                        $returns = $finder2->findInstanceOf($innerNode->getStmts() ?? [], Return_::class);
                        foreach ($returns as $returnStmt) {
                            if (
                                $returnStmt->expr instanceof Node\Expr\New_
                                && $returnStmt->expr->class instanceof Name
                            ) {
                                $returnedFqcn = $this->resolveNameNodeToFqcn(
                                    $returnStmt->expr->class,
                                    $innerNamespace,
                                    $innerUseMap,
                                    false
                                );
                                if ($returnedFqcn !== '' && $returnedFqcn !== '0') {
                                    $decl  = $this->findDeclaringClassForMethod(ltrim($returnedFqcn, '\\'), $methodName);
                                    $target = $decl ?? ltrim($returnedFqcn, '\\');

                                    return $target . '::' . $methodName;
                                }
                            }
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
            if ($callerClass !== null && $callerClass !== '') {
                $methodName  = $callNode->name->toString();
                $declaring   = $this->findDeclaringClassForMethod($callerClass, $methodName);
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
                if ($callerClass !== null && $callerClass !== '') {
                    return $callerClass . '::' . $callNode->name->toString();
                }

                return null;
            }

            // 2b) "parent::method()" → use the parent class via the AST
            if ($lower === 'parent') {
                // Attempt to fetch the parent from the AST:
                /** @var Node|null $classNode */
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
            $methodName = $callNode->name->toString();
            $key        = ltrim($classFqcn, '\\') . '::' . $methodName;

            $exists = isset(GlobalCache::$astNodeMap[$key]);
            if (!$exists) {
                $lowerKey = strtolower($key);
                foreach (array_keys(GlobalCache::$astNodeMap) as $k) {
                    if (strtolower($k) === $lowerKey) {
                        $key = $k;
                        $pos = strrpos($k, '::');
                        $methodName = (string) substr($k, ($pos === false ? 0 : $pos + 2));
                        $exists = true;
                        break;
                    }
                }
            }
            if (!$exists && class_exists($classFqcn, false)) {
                try {
                    $ref = new \ReflectionClass($classFqcn);
                    if ($ref->hasMethod($methodName)) {
                        $methodName = $ref->getMethod($methodName)->getName();
                        $key = ltrim($classFqcn, '\\') . '::' . $methodName;
                        $exists = true;
                    } else {
                        $exists = false;
                    }
                } catch (\ReflectionException $e) {
                    $exists = false;
                }
            }
            if (!$exists) {
                $decl = $this->findDeclaringClassForMethod(
                    ltrim($classFqcn, '\\'),
                    $methodName
                );
                if ($decl !== null) {
                    if (class_exists($decl, false)) {
                        try {
                            $methodName = (new \ReflectionClass($decl))->getMethod($methodName)->getName();
                        } catch (\ReflectionException $e) {
                            // ignore
                        }
                    }

                    return $decl . '::' . $methodName;
                }
            }

            if (!$exists) {
                $magicKey = ltrim($classFqcn, '\\') . '::__callStatic';
                $magicExists = isset(GlobalCache::$astNodeMap[$magicKey]);
                if (!$magicExists && class_exists($classFqcn, false)) {
                    try {
                        $ref = new \ReflectionClass($classFqcn);
                        if ($ref->hasMethod('__callStatic')) {
                            return ltrim($ref->getMethod('__callStatic')->getDeclaringClass()->getName(), '\\') . '::__callStatic';
                        }
                    } catch (\ReflectionException $e) {
                        // ignore
                    }
                }
                if ($magicExists) {
                    return $magicKey;
                }
            }

            return $key;
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
                if ($callerClass !== null && $callerClass !== '') {
                    return $callerClass . '::__construct';
                }

                return null;
            }

            // 4b) "new parent()" → parent class of current class, if it exists
            if ($lower === 'parent') {
                // Attempt to find the AST Class_ node that contains this method/function:
                /** @var mixed $maybeParent */
                $maybeParent = $callerFuncOrMethodNode->getAttribute('parent');
                if ($maybeParent instanceof Node\Stmt\Class_ && $maybeParent->extends instanceof Node\Name) {
                    // Resolve parent’s FQCN using useMap + namespace:
                    $parentFqcn = $this->resolveNameNodeToFqcn(
                        $maybeParent->extends,
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
     * Determine if an exception of the given type would be caught before
     * propagating outside the provided boundary when thrown from the
     * given AST node.
     *
     * @param Node   $node             Starting point for upward traversal
     * @param string $thrownFqcn       Fully-qualified class name of the thrown exception
     * @param Node   $boundaryNode     Typically the enclosing function or method node
     * @param string|null $currentNamespace Namespace of the starting node
     * @param array<string, string> $useMap Use statements in effect for the file
     */
    public function isExceptionCaught(
        Node    $node,
        string  $thrownFqcn,
        Node    $boundaryNode,
        ?string $currentNamespace,
        array   $useMap
    ): bool
    {
        /** @var Node|null $parent */
        $parent = $node->getAttribute('parent');
        $currentCatch = null;
        while ($parent instanceof Node && $parent !== $boundaryNode->getAttribute('parent')) {
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
                        if ($thrownFqcn === $caughtTypeFqcn) {
                            return true;
                        }
                        if (class_exists($thrownFqcn, false) && class_exists($caughtTypeFqcn, false)) {
                            if (is_subclass_of($thrownFqcn, $caughtTypeFqcn)) {
                                return true;
                            }
                        } elseif ($this->isSubclassUsingCache($thrownFqcn, $caughtTypeFqcn)) {
                            return true;
                        } elseif (
                            self::classOrInterfaceExistsNoAutoload($thrownFqcn) &&
                            self::classOrInterfaceExistsNoAutoload($caughtTypeFqcn) &&
                            !self::classFileIsInVendor($thrownFqcn) &&
                            !self::classFileIsInVendor($caughtTypeFqcn) &&
                            (class_exists($caughtTypeFqcn) || interface_exists($caughtTypeFqcn)) && is_a($thrownFqcn, $caughtTypeFqcn, true)
                        ) {
                            // Use autoload to inspect class hierarchy for non-vendor classes
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

            $nextParent = $parent->getAttribute('parent');
            if (!$nextParent instanceof Node) {
                break;
            }
            $parent = $nextParent;
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
    public function isNodeAfterExecutionEndingStmt(Node $node, Node $boundary): bool
    {
        $current = $node;
        while ($current instanceof Node && $current !== $boundary) {
            /** @var Node|null $parent */
            $parent = $current->getAttribute('parent');
            if ($parent instanceof Node && property_exists($parent, 'stmts')) {
                /** @var object{stmts:mixed} $parentObj */
                $parentObj = $parent;
                /** @var mixed $stmtsProp */
                $stmtsProp = $parentObj->stmts;
                if (is_array($stmtsProp)) {
                    /** @var list<Node> $stmts */
                    $stmts = $stmtsProp;
                } else {
                    $stmts = [];
                }
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
     * Check if a class or interface exists without triggering autoload.
     */
    public static function classOrInterfaceExistsNoAutoload(string $fqcn): bool
    {
        if (class_exists($fqcn, false) || interface_exists($fqcn, false)) {
            return true;
        }
        if (isset(\HenkPoley\DocBlockDoctor\GlobalCache::$classParents[$fqcn])) {
            return true;
        }
        $autoloaders = spl_autoload_functions();
        if ($autoloaders === false) {
            $autoloaders = [];
        }
        foreach ($autoloaders as $fn) {
            if (is_array($fn) && $fn[0] instanceof ClassLoader) {
                $loader = $fn[0];
                $file   = $loader->findFile($fqcn);
                if ($file !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Determine if a class would be loaded from a vendor directory.
     */
    public static function classFileIsInVendor(string $fqcn): bool
    {
        $autoloaders = spl_autoload_functions();
        if ($autoloaders === false) {
            $autoloaders = [];
        }
        foreach ($autoloaders as $fn) {
            if (is_array($fn) && $fn[0] instanceof ClassLoader) {
                $loader = $fn[0];
                $file   = $loader->findFile($fqcn);
                if ($file !== false) {
                    $normalized = str_replace(['\\', '/'], '/', $file);
                    if (strpos($normalized, '/vendor/') !== false) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Find the class in the inheritance chain that declares the given method.
     */
    private function findDeclaringClassForMethod(
        string $classFqcn,
        string $method
    ): ?string
    {
        $current = $classFqcn;
        $visited = [];
        while ($current !== null && $current !== '' && !in_array($current, $visited, true)) {
            $visited[] = $current;
            $candidateKey = ltrim($current, '\\') . '::' . $method;
            if (isset(GlobalCache::$astNodeMap[$candidateKey])) {
                return ltrim($current, '\\');
            }
            foreach (GlobalCache::$classTraits[$current] ?? [] as $traitFqcn) {
                $traitKey = ltrim($traitFqcn, '\\') . '::' . $method;
                if (isset(GlobalCache::$astNodeMap[$traitKey])) {
                    return ltrim($traitFqcn, '\\');
                }
            }
            if (class_exists($current, false)) {
                try {
                    $ref = new \ReflectionClass($current);
                    if ($ref->hasMethod($method)) {
                        return ltrim($ref->getMethod($method)->getDeclaringClass()->getName(), '\\');
                    }
                    $parent = $ref->getParentClass();
                    $current = $parent ? $parent->getName() : null;

                    continue;
                } catch (\ReflectionException $e) {
                    break;
                }
            }
            $current = GlobalCache::$classParents[$current] ?? null;
        }

        return null;
    }

    /**
     * Resolve a call on a property of `$this` to a fully qualified "Class::method" string.
     *
     * @param string              $propertyName
     * @param string              $methodName
     * @param Node\FunctionLike   $callerFuncOrMethodNode
     * @param string              $callerNamespace
     * @param array<string,string> $callerUseMap
     */
    private function resolveThisPropertyCall(
        string $propertyName,
        string $methodName,
        Node\FunctionLike $callerFuncOrMethodNode,
        string $callerNamespace,
        array $callerUseMap
    ): ?string {
        $parent = $callerFuncOrMethodNode;
        while ($parent instanceof Node && !$parent instanceof Class_ && !$parent instanceof Node\Stmt\Trait_) {
            $nextParent = $parent->getAttribute('parent');
            if (!$nextParent instanceof Node) {
                break;
            }
            $parent = $nextParent;
        }

        if ($parent instanceof Class_ || $parent instanceof Node\Stmt\Trait_) {
            $classNode = $parent;

            foreach ($classNode->stmts as $stmt) {
                if (!($stmt instanceof Node\Stmt\Property)) {
                    continue;
                }
                foreach ($stmt->props as $propElem) {
                    if ($propElem->name->toString() !== $propertyName) {
                        continue;
                    }
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
                                $decl = $this->findDeclaringClassForMethod(ltrim($fqcn, '\\'), $methodName);
                                $target = $decl ?? ltrim($fqcn, '\\');

                                return $target . '::' . $methodName;
                            }
                        }
                    }
                    if ($stmt->type instanceof Name) {
                        $fqcn = $this->resolveNameNodeToFqcn($stmt->type, $callerNamespace, $callerUseMap, false);
                        if ($fqcn !== '') {
                            $decl = $this->findDeclaringClassForMethod(ltrim($fqcn, '\\'), $methodName);
                            $target = $decl ?? ltrim($fqcn, '\\');

                            return $target . '::' . $methodName;
                        }
                    } elseif ($stmt->type instanceof NullableType && $stmt->type->type instanceof Name) {
                        $fqcn = $this->resolveNameNodeToFqcn($stmt->type->type, $callerNamespace, $callerUseMap, false);
                        if ($fqcn !== '') {
                            $decl = $this->findDeclaringClassForMethod(ltrim($fqcn, '\\'), $methodName);
                            $target = $decl ?? ltrim($fqcn, '\\');

                            return $target . '::' . $methodName;
                        }
                    }
                }
            }

            foreach ($classNode->stmts as $stmt) {
                if ($stmt instanceof Node\Stmt\ClassMethod && $stmt->name->toString() === '__construct') {
                    foreach ($stmt->params as $param) {
                        if (
                            ($param->flags & (Modifiers::PUBLIC | Modifiers::PROTECTED | Modifiers::PRIVATE)) !== 0
                            && $param->var instanceof Variable
                            && is_string($param->var->name)
                            && $param->var->name === $propertyName
                            && $param->type !== null
                        ) {
                            $typeNode = $param->type;
                            if ($typeNode instanceof Name) {
                                $fqcn = $this->resolveNameNodeToFqcn($typeNode, $callerNamespace, $callerUseMap, false);
                            } elseif ($typeNode instanceof NullableType && $typeNode->type instanceof Name) {
                                $fqcn = $this->resolveNameNodeToFqcn($typeNode->type, $callerNamespace, $callerUseMap, false);
                            } else {
                                $fqcn = '';
                            }
                            if ($fqcn !== '') {
                                $decl = $this->findDeclaringClassForMethod(ltrim($fqcn, '\\'), $methodName);
                                $target = $decl ?? ltrim($fqcn, '\\');

                                return $target . '::' . $methodName;
                            }
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * Follow a chained inner call (MethodCall or StaticCall) and try to resolve
     * the returned object for the outer MethodCall.
     *
     * @param Node\Expr            $innerCall
     * @param Node\Expr\MethodCall $outerCall
     * @param string               $callerNamespace
     * @param array<string,string> $callerUseMap
     * @param Node\FunctionLike    $callerFuncOrMethodNode
     * @param array<string,bool>   $visited
     *
     * @throws \InvalidArgumentException
     * @throws \LogicException
     */
    private function resolveInnerMethodCall(
        Node\Expr $innerCall,
        Node\Expr\MethodCall $outerCall,
        string $callerNamespace,
        array $callerUseMap,
        Node\FunctionLike $callerFuncOrMethodNode,
        array &$visited
    ): ?string {
        $innerKey = $this->getCalleeKey(
            $innerCall,
            $callerNamespace,
            $callerUseMap,
            $callerFuncOrMethodNode,
            $visited
        );

        if ($innerKey === null || $innerKey === '') {
            return null;
        }

        $innerNode     = GlobalCache::$astNodeMap[$innerKey] ?? null;
        $innerFilePath = GlobalCache::getFilePathForKey($innerKey);

        if (!$innerNode instanceof Node\Stmt\ClassMethod || !is_string($innerFilePath) || $innerFilePath === '') {
            return null;
        }

        $innerNamespace = GlobalCache::getFileNamespace($innerFilePath);
        if ($innerNamespace === '') {
            $innerNamespace = $this->getNamespaceForNode($innerNode);
        }
        $innerUseMap = GlobalCache::getFileUseMap($innerFilePath);

        $returnType = $innerNode->getReturnType();
        if ($returnType instanceof Node\Name) {
            $returnedFqcn = $this->resolveNameNodeToFqcn(
                $returnType,
                $innerNamespace,
                $innerUseMap,
                false
            );
            if ($returnedFqcn !== '') {
                $methodName = $outerCall->name instanceof Node\Identifier ? $outerCall->name->toString() : '';
                $decl       = $this->findDeclaringClassForMethod(ltrim($returnedFqcn, '\\'), $methodName);
                $target     = $decl ?? ltrim($returnedFqcn, '\\');

                return $target . '::' . $methodName;
            }
        } elseif ($returnType instanceof Node\NullableType && $returnType->type instanceof Node\Name) {
            $returnedFqcn = $this->resolveNameNodeToFqcn(
                $returnType->type,
                $innerNamespace,
                $innerUseMap,
                false
            );
            if ($returnedFqcn !== '') {
                $methodName = $outerCall->name instanceof Node\Identifier ? $outerCall->name->toString() : '';
                $decl       = $this->findDeclaringClassForMethod(ltrim($returnedFqcn, '\\'), $methodName);
                $target     = $decl ?? ltrim($returnedFqcn, '\\');

                return $target . '::' . $methodName;
            }
        }

        $finder  = new NodeFinder();
        $returns = $finder->findInstanceOf($innerNode->getStmts() ?? [], Return_::class);

        foreach ($returns as $returnStmt) {
            if (
                $returnStmt->expr instanceof Node\Expr\New_
                && $returnStmt->expr->class instanceof Node\Name
            ) {
                $returnedFqcn = $this->resolveNameNodeToFqcn(
                    $returnStmt->expr->class,
                    $innerNamespace,
                    $innerUseMap,
                    false
                );

                if ($returnedFqcn !== '' && $returnedFqcn !== '0') {
                    $methodName = $outerCall->name instanceof Node\Identifier ? $outerCall->name->toString() : '';
                    $decl       = $this->findDeclaringClassForMethod(ltrim($returnedFqcn, '\\'), $methodName);
                    $target     = $decl ?? ltrim($returnedFqcn, '\\');

                    return $target . '::' . $methodName;
                }
            }
        }

        return null;
    }

    /**
     * Determine class inheritance using the cached parent map when classes are
     * not loaded.
     */
    private function isSubclassUsingCache(string $child, string $parent): bool
    {
        $current = $child;
        $visited = [];
        while ($current !== null && $current !== '' && !in_array($current, $visited, true)) {
            if ($current === $parent) {
                return true;
            }
            $visited[] = $current;
            $current = GlobalCache::$classParents[$current] ?? null;
        }

        return false;
    }
}
