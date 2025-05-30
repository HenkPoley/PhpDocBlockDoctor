<?php
// doc-block-doctor.php

require 'vendor/autoload.php';

use PhpParser\Error;
use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;
use PhpParser\PrettyPrinter;

// --- Utility Class for AST operations and Name Resolution ---
class AstUtils
{
    public function __construct()
    {
    }

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

class UseStatementSimplifierSurgical extends NodeVisitorAbstract
{
    /** @var array<int, array{startPos: int, length: int, replacementText: string}> */
    public $pendingPatches = [];
    /**
     * @var \PhpParser\PrettyPrinter\Standard
     */
    private $printer;

    public function __construct()
    {
        $this->printer = new PrettyPrinter\Standard();
    }

    /** @param Node[] $nodes */
    public function beforeTraverse($nodes): ?array
    {
        $this->pendingPatches = [];
        return null;
    }

    /**
     * @param \PhpParser\Node $node
     */
    public function leaveNode($node): ?int // Return type indicates no AST modification by traverser
    {
        if ($node instanceof Node\Stmt\GroupUse && count($node->uses) === 1) {
            $originalUse = $node->uses[0];

            // Construct the new single UseUse node
            $newNameParts = array_merge($node->prefix->getParts(), $originalUse->name->getParts());
            $newUseName = new Node\Name($newNameParts, $originalUse->name->getAttributes());
            $newUseUse = new Node\Stmt\UseUse(
                $newUseName,
                $originalUse->alias->name ?? null, // alias is Identifier or null
                $originalUse->type,
                $originalUse->getAttributes() // Carry over attributes from UseUse
            );

            // Construct the new Use_ statement node
            $newUseStmtNode = new Node\Stmt\Use_(
                [$newUseUse],
                $node->type, // Carry over type (TYPE_NORMAL, TYPE_FUNCTION, TYPE_CONSTANT)
                $node->getAttributes() // Carry over attributes from GroupUse
            );

            $rawPrinted = $this->printer->prettyPrint([$newUseStmtNode]);
            $replacementCode = $rawPrinted;

            // Strip "\<\?php " or "\<\?php\n" prefix if present
            // The PrettyPrinter wraps a single statement array in \<\?php ... \?\>
            $phpPrefixNewline = "<?php\n";
            $phpPrefixSpace = "<?php "; // Note: nikic/php-parser often uses "\<\?php \n" (with space)
            $phpPrefixSpaceNewline = "<?php \n";

            if (strncmp($replacementCode, $phpPrefixSpaceNewline, strlen($phpPrefixSpaceNewline)) === 0) {
                $replacementCode = substr($replacementCode, strlen($phpPrefixSpaceNewline));
            } elseif (strncmp($replacementCode, $phpPrefixNewline, strlen($phpPrefixNewline)) === 0) {
                $replacementCode = substr($replacementCode, strlen($phpPrefixNewline));
            } elseif (strncmp($replacementCode, $phpPrefixSpace, strlen($phpPrefixSpace)) === 0) {
                $replacementCode = substr($replacementCode, strlen($phpPrefixSpace));
            }

            // Ensure it ends with a newline, as use statements are typically on their own line.
            // prettyPrint() on an array of statements usually adds this.
            if (substr_compare($replacementCode, "\n", -strlen("\n")) !== 0) {
                $replacementCode .= "\n";
            }


            $this->pendingPatches[] = [
                'startPos' => $node->getStartFilePos(),
                'length' => $node->getEndFilePos() - $node->getStartFilePos() + 1,
                'replacementText' => $replacementCode,
            ];
            // Return null to signify that the traverser should not replace this node in the AST.
            // The change will be applied textually.
            return null;
        }
        return null;
    }
}

class GlobalCache
{
    /**
     * @var mixed[]
     */
    public static $directThrows = [];
    /**
     * @var mixed[]
     */
    public static $annotatedThrows = [];
    /**
     * @var mixed[]
     */
    public static $originalDescriptions = [];
    /**
     * @var mixed[]
     */
    public static $fileNamespaces = [];
    /**
     * @var mixed[]
     */
    public static $fileUseMaps = [];
    /**
     * @var mixed[]
     */
    public static $astNodeMap = [];
    /**
     * @var mixed[]
     */
    public static $nodeKeyToFilePath = [];
    /**
     * @var mixed[]
     */
    public static $resolvedThrows = [];

    public static function clear(): void
    {
        self::$directThrows = [];
        self::$annotatedThrows = [];
        self::$originalDescriptions = [];
        self::$fileNamespaces = [];
        self::$fileUseMaps = [];
        self::$astNodeMap = [];
        self::$nodeKeyToFilePath = [];
        self::$resolvedThrows = [];
    }
}

class ThrowsGatherer extends NodeVisitorAbstract
{
    /**
     * @var \PhpParser\NodeFinder
     */
    private $nodeFinder;
    /**
     * @var \AstUtils
     */
    private $astUtils;
    /**
     * @var string
     */
    private $filePath;
    /**
     * @var string
     */
    private $currentNamespace = '';
    /**
     * @var mixed[]
     */
    private $useMap = [];

    public function __construct(NodeFinder $nodeFinder, AstUtils $astUtils, string $filePath)
    {
        $this->nodeFinder = $nodeFinder;
        $this->astUtils = $astUtils;
        $this->filePath = $filePath;
    }

    /**
     * @param mixed[] $nodes
     */
    public function beforeTraverse($nodes)
    {
        $this->currentNamespace = '';
        $this->useMap = [];
        $nsNode = $this->nodeFinder->findFirstInstanceOf($nodes, Node\Stmt\Namespace_::class);
        if ($nsNode && $nsNode->name) {
            $this->currentNamespace = $nsNode->name->toString();
        }
        GlobalCache::$fileNamespaces[$this->filePath] = $this->currentNamespace;

        foreach ($this->nodeFinder->find($nodes, function (Node $n): bool {
            return $n instanceof Node\Stmt\Use_ || $n instanceof Node\Stmt\GroupUse;
        }) as $useNode) {
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
        GlobalCache::$fileUseMaps[$this->filePath] = $this->useMap;
        return null;
    }

    /**
     * @param \PhpParser\Node $node
     */
    public function enterNode($node)
    {
        if (!($node instanceof Node\Stmt\Function_ || $node instanceof Node\Stmt\ClassMethod)) {
            return null;
        }
        $key = $this->astUtils->getNodeKey($node, $this->currentNamespace);
        if (!$key) {
            return null;
        }
        GlobalCache::$astNodeMap[$key] = $node;
        GlobalCache::$nodeKeyToFilePath[$key] = $this->filePath;
        GlobalCache::$directThrows[$key] = $this->calculateDirectThrowsForNode($node);
        GlobalCache::$originalDescriptions[$key] = [];
        $currentAnnotatedThrowsFqcns = [];

        $docComment = $node->getDocComment();
        if ($docComment) {
            $docText = $docComment->getText();
            $docLines = preg_split('/\R/u', $docText);
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
                    if ($currentThrowsFqcnForDesc && !isset(GlobalCache::$originalDescriptions[$key][$currentThrowsFqcnForDesc]) && !empty(trim($accumulatedDescription))) {
                        GlobalCache::$originalDescriptions[$key][$currentThrowsFqcnForDesc] = trim($accumulatedDescription);
                    }
                    $exceptionNameInAnnotation = trim($matches[1]);
                    $resolvedFqcnForAnnotation = $this->astUtils->resolveStringToFqcn($exceptionNameInAnnotation, $this->currentNamespace, $this->useMap);
                    $currentThrowsFqcnForDesc = $resolvedFqcnForAnnotation;
                    $accumulatedDescription = trim($matches[2]);
                    if ($resolvedFqcnForAnnotation) {
                        $currentAnnotatedThrowsFqcns[] = $resolvedFqcnForAnnotation;
                    }

                } elseif ($currentThrowsFqcnForDesc && !$isFirstLine && !$isLastLine && !preg_match('/^@\w+/', $contentLine)) {
                    $accumulatedDescription .= (empty(trim($accumulatedDescription)) && $contentLine === '' ? '' : "\n") . $contentLine;
                } elseif ($isLastLine || preg_match('/^@\w+/', $contentLine)) {
                    if ($currentThrowsFqcnForDesc && !empty(trim($accumulatedDescription)) && !isset(GlobalCache::$originalDescriptions[$key][$currentThrowsFqcnForDesc])) {
                        GlobalCache::$originalDescriptions[$key][$currentThrowsFqcnForDesc] = trim($accumulatedDescription);
                    }
                    $currentThrowsFqcnForDesc = null;
                    $accumulatedDescription = "";
                }
            }
            if ($currentThrowsFqcnForDesc && !empty(trim($accumulatedDescription)) && !isset(GlobalCache::$originalDescriptions[$key][$currentThrowsFqcnForDesc])) {
                GlobalCache::$originalDescriptions[$key][$currentThrowsFqcnForDesc] = trim($accumulatedDescription);
            }
        }
        GlobalCache::$annotatedThrows[$key] = array_values(array_unique($currentAnnotatedThrowsFqcns));
        return null;
    }

    private function calculateDirectThrowsForNode(Node $funcOrMethodNode): array
    {
        $fqcns = [];
        if (!isset($funcOrMethodNode->stmts) || !is_array($funcOrMethodNode->stmts)) {
            return [];
        }
        $throwNodes = $this->nodeFinder->findInstanceOf($funcOrMethodNode->stmts, Node\Expr\Throw_::class);
        foreach ($throwNodes as $throwExpr) {
            if ($throwExpr->expr instanceof Node\Expr\New_) {
                $newExpr = $throwExpr->expr;
                if ($newExpr->class instanceof Node\Name) {
                    $thrownFqcn = $this->astUtils->resolveNameNodeToFqcn($newExpr->class, $this->currentNamespace, $this->useMap, false);
                    if (!$this->astUtils->isExceptionCaught($throwExpr, $thrownFqcn, $funcOrMethodNode, $this->currentNamespace, $this->useMap)) {
                        $fqcns[] = $thrownFqcn;
                    }
                }
            }
        }
        return array_values(array_filter($fqcns, function ($fqcn): bool {
            return class_exists($fqcn) || interface_exists($fqcn);
        }));
    }
}

class DocBlockUpdater extends NodeVisitorAbstract
{
    /**
     * @var \AstUtils
     */
    private $astUtils;
    /**
     * @var string
     */
    private $currentFilePath;
    /**
     * @var mixed[]
     */
    public $pendingPatches = [];
    /**
     * @var string
     */
    private $currentNamespace = '';

    public function __construct(AstUtils $astUtils, string $currentFilePath)
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
        $this->currentNamespace = GlobalCache::$fileNamespaces[$this->currentFilePath] ?? '';
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
        if (!$hasMeaningfulContent && empty(array_filter($actualContent, function ($l): bool {
                return trim($l) !== "";
            }))) {
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

        if (empty($actualContent)) {
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
        if (!($node instanceof Node\Stmt\Function_ || $node instanceof Node\Stmt\ClassMethod)) {
            return null;
        }
        $nodeKey = $this->astUtils->getNodeKey($node, $this->currentNamespace);
        if (!$nodeKey) {
            return null;
        }

        $analyzedThrowsFqcns = GlobalCache::$resolvedThrows[$nodeKey] ?? [];
        // Filter out any classes or interfaces that don’t actually exist
        $analyzedThrowsFqcns = array_filter($analyzedThrowsFqcns, function ($fqcn): bool {
            return class_exists($fqcn) || interface_exists($fqcn);
        });
        $analyzedThrowsFqcns = array_values($analyzedThrowsFqcns);
        sort($analyzedThrowsFqcns);
        $docCommentNode = $node->getDocComment();
        $originalNodeDescriptions = GlobalCache::$originalDescriptions[$nodeKey] ?? [];

        $newDocBlockContentLines = [];
        $hasAnyContentForNewDocBlock = false;

        if ($docCommentNode) {
            $originalLines = preg_split('/\R/u', $docCommentNode->getText());
            $currentGenericTagLines = [];
            $isInsideGenericTag = false;

            for ($lineIdx = 1; $lineIdx < count($originalLines) - 1; $lineIdx++) {
                $currentDocLine = $originalLines[$lineIdx];
                $lineContent = preg_replace('/^\s*\*?\s?/', '', $currentDocLine);
                $trimmedLineContent = trim((string)$lineContent);

                if (preg_match('/^@throws\s/i', $trimmedLineContent)) {
                    if (!empty($currentGenericTagLines)) {
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
                    if (!empty($currentGenericTagLines)) {
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
                    if (!empty($currentGenericTagLines)) {
                        // TODO: [EA] 'array_merge(...)' is used in a loop and is a resources greedy construction.
                        $newDocBlockContentLines = array_merge($newDocBlockContentLines, $currentGenericTagLines);
                        $currentGenericTagLines = [];
                    }
                    $newDocBlockContentLines[] = $lineContent;
                    $hasAnyContentForNewDocBlock = true;
                    $isInsideGenericTag = false;
                } elseif (!empty($newDocBlockContentLines) && trim((string)end($newDocBlockContentLines)) !== "") {
                    $newDocBlockContentLines[] = "";
                }
            }
            if (!empty($currentGenericTagLines)) {
                $newDocBlockContentLines = array_merge($newDocBlockContentLines, $currentGenericTagLines);
            }
        }

        if (!empty($analyzedThrowsFqcns)) {
            $hasAnyContentForNewDocBlock = true;
            if (!empty($newDocBlockContentLines) && trim((string)end($newDocBlockContentLines)) !== "") {
                $newDocBlockContentLines[] = "";
            }
            foreach ($analyzedThrowsFqcns as $fqcn) {
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
        if ($hasAnyContentForNewDocBlock || !empty($newDocBlockContentLines)) {
            while (count($newDocBlockContentLines) > 0 && trim((string)$newDocBlockContentLines[0]) === "") {
                array_shift($newDocBlockContentLines);
            }
            while (count($newDocBlockContentLines) > 0 && trim((string)end($newDocBlockContentLines)) === "") {
                array_pop($newDocBlockContentLines);
            }

            if (!empty($newDocBlockContentLines)) {
                $textToNormalize = implode("\n", $newDocBlockContentLines);
                $finalNormalizedNewDocText = $this->normalizeDocBlockString($textToNormalize);
            }
        }

        $originalNormalizedDocText = null;
        if ($docCommentNode) {
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
            if ($patchType) {
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

// --- Main Script ---
$parser = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 1));
// $prettyPrinter is no longer needed globally, UseStatementSimplifierSurgical creates its own.
$nodeFinder = new NodeFinder();
$astUtils = new AstUtils();
$rootDir = $argv[1] ?? getcwd();
$phpFilePaths = [];
$rii = new \RecursiveIteratorIterator(
    new \RecursiveCallbackFilterIterator(
        new \RecursiveDirectoryIterator($rootDir, \RecursiveDirectoryIterator::SKIP_DOTS | \RecursiveDirectoryIterator::FOLLOW_SYMLINKS),
        function ($file, $key, $iterator): bool {
            $filename = $file->getFilename();
            if ($iterator->hasChildren()) {
                return !in_array($filename, ['vendor', '.git', 'node_modules', '.history', 'tests', 'cache']);
            }
            return $file->isFile() && $file->getExtension() === 'php';
        }
    ), \RecursiveIteratorIterator::LEAVES_ONLY
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
        if ($newlyCalculatedThrowsForFunc != $previouslyStoredResolvedThrows) {
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

        // TODO: [EA] \DocBlockUpdater needs to implement __isset to properly work here.
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