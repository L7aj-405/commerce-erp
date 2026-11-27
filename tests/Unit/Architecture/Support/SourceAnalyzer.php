<?php

namespace Tests\Unit\Architecture\Support;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\ParserFactory;

/**
 * Static (AST) source analysis shared by the architecture tests. It parses PHP
 * files with nikic/php-parser (a hard dependency of PHPUnit 12, so it is always
 * available wherever the test suite runs) and never executes application code
 * or touches a database.
 */
final class SourceAnalyzer
{
    /** @var array<string, array<int, Node>> */
    private static array $cache = [];

    public static function root(): string
    {
        return dirname(__DIR__, 4);
    }

    /** @return list<string> repo-relative, forward-slash paths of every PHP file under the given directories */
    public static function phpFiles(string ...$directories): array
    {
        $files = [];
        foreach ($directories as $directory) {
            $base = self::root().'/'.$directory;
            if (! is_dir($base)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = str_replace('\\', '/', substr($file->getPathname(), strlen(self::root()) + 1));
                }
            }
        }
        sort($files);

        return $files;
    }

    /** @return array<int, Node> parsed, name-resolved AST with parent links */
    public static function ast(string $relativePath): array
    {
        if (isset(self::$cache[$relativePath])) {
            return self::$cache[$relativePath];
        }
        $code = file_get_contents(self::root().'/'.$relativePath);
        $parser = (new ParserFactory)->createForNewestSupportedVersion();
        $traverser = new NodeTraverser(new NameResolver(null, ['replaceNodes' => true, 'preserveOriginalNames' => false]), new ParentConnectingVisitor);

        return self::$cache[$relativePath] = $traverser->traverse($parser->parse($code) ?? []);
    }

    /**
     * Every outermost class-method / function body of a file, with its display name.
     *
     * @return list<array{name: string, node: Node, line: int}>
     */
    public static function units(string $relativePath): array
    {
        $finder = new NodeFinder;
        $units = [];
        foreach ($finder->findInstanceOf(self::ast($relativePath), Stmt\ClassLike::class) as $class) {
            $className = $class->namespacedName?->toString() ?? ($class->name?->toString() ?? 'anonymous');
            foreach ($class->getMethods() as $method) {
                $units[] = ['name' => $className.'::'.$method->name->toString(), 'node' => $method, 'line' => $method->getStartLine()];
            }
        }
        foreach ($finder->findInstanceOf(self::ast($relativePath), Stmt\Function_::class) as $function) {
            $units[] = ['name' => $function->name->toString(), 'node' => $function, 'line' => $function->getStartLine()];
        }

        return $units;
    }

    /** True when any string literal inside the node contains the needle (concatenations are covered because their parts are literals). */
    public static function containsString(Node $node, string $needle): bool
    {
        foreach ((new NodeFinder)->findInstanceOf($node, Node\Scalar\String_::class) as $string) {
            if (str_contains($string->value, $needle)) {
                return true;
            }
        }
        foreach ((new NodeFinder)->findInstanceOf($node, Node\InterpolatedStringPart::class) as $part) {
            if (str_contains($part->value, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** Top of a call chain ( `A::query()->where()->first()` → the outermost MethodCall ). */
    public static function chainTop(Node $node): Node
    {
        while (($parent = $node->getAttribute('parent')) !== null
            && (($parent instanceof Expr\MethodCall && $parent->var === $node)
                || ($parent instanceof Expr\NullsafeMethodCall && $parent->var === $node)
                || ($parent instanceof Expr\PropertyFetch && $parent->var === $node))) {
            $node = $parent;
        }

        return $node;
    }
}
