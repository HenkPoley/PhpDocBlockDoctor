<?php

namespace HenkPoley\DocBlockDoctor;

use PhpParser\Node;
use PhpParser\Node\Identifier;
use PhpParser\NodeVisitorAbstract;
use PhpParser\PrettyPrinter;

class UseStatementSimplifierSurgical extends NodeVisitorAbstract
{
    /** @var array<int, array{startPos: int, length: int, replacementText: string}> */
    public $pendingPatches = [];
    private \PhpParser\PrettyPrinter\Standard $printer;

    public function __construct()
    {
        $this->printer = new PrettyPrinter\Standard();
    }

    public function beforeTraverse(array $nodes)
    {
        $this->pendingPatches = [];
        return null;
    }

    /**
     * @param \PhpParser\Node $node
     *
     * @throws \InvalidArgumentException
     */
    public function leaveNode($node): ?int // Return type indicates no AST modification by traverser
    {
        if ($node instanceof Node\Stmt\GroupUse && count($node->uses) === 1) {
            $originalUse = $node->uses[0];

            // Construct the new single UseUse node
            $newNameParts = array_merge($node->prefix->getParts(), $originalUse->name->getParts());
            $newUseName = new Node\Name($newNameParts, $originalUse->name->getAttributes());
            $aliasNode = $originalUse->alias instanceof Identifier
                ? clone $originalUse->alias
                : null;
            $newUseUse = new Node\Stmt\UseUse(
                $newUseName,
                $aliasNode,
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
