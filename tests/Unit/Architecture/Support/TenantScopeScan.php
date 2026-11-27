<?php

namespace Tests\Unit\Architecture\Support;

use Illuminate\Support\Str;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\NodeFinder;

/**
 * Finds queries on tenant-owned data that carry no organization scoping signal.
 *
 * Why this is not a grep: the application has no global Eloquent scope (isolation is explicit
 * query scoping plus composite foreign keys), so the safe pattern is "every query on a tenant
 * model/table names `organization_id` in the same call chain". The scan therefore
 *
 *  1. derives the tenant-owned models from app/Models (a model whose source mentions
 *     `organization_id`) and their tables (explicit `$table` or Laravel's convention);
 *  2. parses each file with a real PHP parser (no regex on source text);
 *  3. finds QUERY ROOTS only — a static call on a tenant model that starts a query
 *     (`Payment::query()`, `Payment::where()`, `Payment::find()` …) or `DB::table('<tenant table>')`;
 *  4. accepts a root when its own call chain names `organization_id` (any string literal, so
 *     `where('organization_id', …)`, `where($t.'.organization_id', …)` and join conditions count)
 *     or uses a known scoping helper; a builder stored in a variable is accepted when a later
 *     chain on that same variable names `organization_id`;
 *  5. reports everything else as a violation, to be fixed or listed (with a reason) in
 *     tests/Unit/Architecture/tenant-scope-exceptions.php.
 *
 * Relation traversal from an already scoped parent (`$order->lines()`) is deliberately NOT a
 * root: the relation constrains the child by the parent's key, and the parent is scoped.
 */
final class TenantScopeScan
{
    /** Static methods that begin (or directly execute) a query on a model. */
    private const QUERY_METHODS = [
        'query', 'newQuery', 'where', 'whereKey', 'whereKeyNot', 'whereIn', 'whereNotIn', 'whereNull', 'whereNotNull',
        'whereHas', 'whereDoesntHave', 'whereDate', 'whereBetween', 'whereRaw', 'orWhere', 'with', 'withCount',
        'withSum', 'has', 'doesntHave', 'find', 'findOrFail', 'findMany', 'first', 'firstOrFail', 'firstWhere',
        'all', 'count', 'exists', 'doesntExist', 'sum', 'max', 'min', 'avg', 'pluck', 'select', 'selectRaw', 'join',
        'leftJoin', 'orderBy', 'latest', 'oldest', 'lockForUpdate', 'sharedLock', 'withoutGlobalScopes',
        'withoutGlobalScope', 'withTrashed', 'onlyTrashed', 'destroy', 'truncate', 'updateOrCreate',
        'firstOrCreate', 'firstOrNew', 'upsert', 'insert', 'insertOrIgnore', 'chunkById', 'cursor', 'lazy',
        'each', 'paginate', 'get', 'limit', 'take', 'eachById',
    ];

    /** Chain methods that scope by organization through a known, tenant-aware helper. */
    private const SCOPING_METHODS = ['forOrganization', 'whereOrganization', 'ofOrganization', 'scopedToOrganization', 'visible'];

    /**
     * Platform-level models that are NOT owned by one organization although their source mentions
     * `organization_id` (pivot columns / the user's active context). Reading them without an
     * organization filter is correct by design.
     */
    private const GLOBAL_MODELS = ['User', 'Permission'];

    /** @var array<string, string>|null model FQCN => table */
    private ?array $tenantModels = null;

    /** @return array<string, string> model FQCN => table */
    public function tenantModels(): array
    {
        if ($this->tenantModels !== null) {
            return $this->tenantModels;
        }
        $organizationTables = $this->tablesWithOrganizationColumn();
        $models = [];
        foreach (SourceAnalyzer::phpFiles('app/Models') as $path) {
            if (substr_count($path, '/') !== 2) { // app/Models/X.php only (Builders/, Concerns/ are not models)
                continue;
            }
            $source = (string) file_get_contents(SourceAnalyzer::root().'/'.$path);
            if (! preg_match('/class\s+(\w+)\s+extends\s+(Model|Authenticatable|Pivot)/', $source, $m) || in_array($m[1], self::GLOBAL_MODELS, true)) {
                continue;
            }
            $table = preg_match('/protected\s+\$table\s*=\s*[\'"]([\w]+)[\'"]/', $source, $t)
                ? $t[1]
                : Str::snake(Str::pluralStudly($m[1]));
            // Tenant-owned = its table has an organization_id column (migrations are the source of truth),
            // or the model declares the tenant route-binding trait.
            if (isset($organizationTables[$table]) || str_contains($source, 'ScopesToActiveOrganization')) {
                $models['App\\Models\\'.$m[1]] = $table;
            }
        }

        return $this->tenantModels = $models;
    }

    /**
     * Tables that carry an organization_id column, read from the migrations (create and table blocks).
     *
     * @return array<string, true>
     */
    private function tablesWithOrganizationColumn(): array
    {
        $tables = [];
        foreach (SourceAnalyzer::phpFiles('database/migrations') as $path) {
            $source = (string) file_get_contents(SourceAnalyzer::root().'/'.$path);
            $parts = preg_split('/Schema::(?:create|table)\(\s*[\'"](\w+)[\'"]/', $source, -1, PREG_SPLIT_DELIM_CAPTURE);
            for ($i = 1; $i < count($parts); $i += 2) {
                if (str_contains($parts[$i + 1] ?? '', 'organization_id')) {
                    $tables[$parts[$i]] = true;
                }
            }
        }

        return $tables;
    }

    /** @return array<string, string> table => model FQCN */
    public function tenantTables(): array
    {
        return array_flip($this->tenantModels());
    }

    /**
     * @param  list<string>  $files
     * @return list<array{key: string, file: string, unit: string, model: string, line: int}>
     */
    public function violations(array $files): array
    {
        $violations = [];
        $needles = array_merge(array_map(fn (string $fqcn) => Str::afterLast($fqcn, '\\'), array_keys($this->tenantModels())), ['DB::table']);
        foreach ($files as $file) {
            // Cheap pre-filter: a file that names no tenant model and no DB::table() cannot hold a root.
            $source = (string) file_get_contents(SourceAnalyzer::root().'/'.$file);
            if (! Str::contains($source, $needles)) {
                continue;
            }
            foreach (SourceAnalyzer::units($file) as $unit) {
                foreach ($this->roots($unit['node']) as [$root, $model]) {
                    if ($this->isScoped($root, $unit['node'])) {
                        continue;
                    }
                    $short = Str::afterLast($model, '\\');
                    $violations[] = [
                        'key' => $file.'::'.$unit['name'].'::'.$short,
                        'file' => $file, 'unit' => $unit['name'], 'model' => $short, 'line' => $root->getStartLine(),
                    ];
                }
            }
        }

        return $violations;
    }

    /** @return list<array{0: Node, 1: string}> query roots (node, tenant model FQCN) in a unit */
    private function roots(Node $unit): array
    {
        $roots = [];
        foreach ((new NodeFinder)->findInstanceOf($unit, Expr\StaticCall::class) as $call) {
            if (! $call->class instanceof Node\Name || ! $call->name instanceof Node\Identifier) {
                continue;
            }
            $class = $call->class->toString();
            $method = $call->name->toString();
            if (isset($this->tenantModels()[$class]) && in_array($method, self::QUERY_METHODS, true)) {
                $roots[] = [$call, $class];
            } elseif ($class === 'Illuminate\\Support\\Facades\\DB' && $method === 'table' && ($model = $this->tableModel($call->args[0]->value ?? null)) !== null) {
                $roots[] = [$call, $model];
            }
        }

        return $roots;
    }

    /** The tenant model behind a DB::table() argument (literal table, alias, or Model::environmentTable()). */
    private function tableModel(?Node $argument): ?string
    {
        if ($argument instanceof Node\Scalar\String_) {
            $table = strtolower(preg_split('/\s+as\s+/i', trim($argument->value))[0]);

            return $this->tenantTables()[$table] ?? null;
        }
        if ($argument instanceof Expr\StaticCall && $argument->class instanceof Node\Name && isset($this->tenantModels()[$argument->class->toString()])) {
            return $argument->class->toString();
        }

        return null;
    }

    private function isScoped(Node $root, Node $unit): bool
    {
        $top = SourceAnalyzer::chainTop($root);
        if ($this->chainIsScoped($top, $unit) || $this->keyIsTheOrganization($top)) {
            return true;
        }
        // `$query = Model::query(); … $query->where('organization_id', …)`, also through a ternary / `??` / parentheses.
        $parent = $top->getAttribute('parent');
        while ($parent instanceof Expr\Ternary || $parent instanceof Expr\BinaryOp\Coalesce) {
            $parent = $parent->getAttribute('parent');
        }
        if ($parent instanceof Expr\Assign && $parent->var instanceof Expr\Variable && is_string($parent->var->name)) {
            foreach ((new NodeFinder)->findInstanceOf($unit, Expr\Variable::class) as $variable) {
                if ($variable->name === $parent->var->name && $variable !== $parent->var
                    && $this->chainIsScoped(SourceAnalyzer::chainTop($variable), $unit)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Per-organization singleton rows (document-number sequences) have the organization id AS their primary
     * key: `Sequence::query()->whereKey($organization->getKey())` is scoped by construction.
     */
    private function keyIsTheOrganization(Node $top): bool
    {
        foreach ((new NodeFinder)->findInstanceOf($top, Expr\MethodCall::class) as $call) {
            if ($call->name instanceof Node\Identifier && in_array($call->name->toString(), ['whereKey', 'find', 'findOrFail'], true)
                && ($argument = $call->args[0]->value ?? null) instanceof Expr\MethodCall
                && $argument->var instanceof Expr\Variable && in_array($argument->var->name, ['organization', 'org'], true)
                && $argument->name instanceof Node\Identifier && in_array($argument->name->toString(), ['getKey', 'getAttribute'], true)) {
                return true;
            }
        }

        return false;
    }

    private function chainIsScoped(Node $top, Node $unit): bool
    {
        if (SourceAnalyzer::containsString($top, 'organization_id')) {
            return true;
        }
        // `->where($identity)` / `->insert($records)` where `$identity = ['organization_id' => …]` or
        // `$records[] = ['organization_id' => …, …]` was built earlier in the same method.
        foreach ((new NodeFinder)->findInstanceOf($top, Expr\MethodCall::class) as $call) {
            foreach ($call->args as $argument) {
                if (! $argument instanceof Node\Arg || ! $argument->value instanceof Expr\Variable || ! is_string($argument->value->name)) {
                    continue;
                }
                foreach ((new NodeFinder)->findInstanceOf($unit, Expr\Assign::class) as $assign) {
                    $target = $assign->var instanceof Expr\ArrayDimFetch ? $assign->var->var : $assign->var;
                    if ($target instanceof Expr\Variable && $target->name === $argument->value->name
                        && SourceAnalyzer::containsString($assign->expr, 'organization_id')) {
                        return true;
                    }
                }
            }
        }
        foreach ((new NodeFinder)->findInstanceOf($top, Expr\MethodCall::class) as $call) {
            if ($call->name instanceof Node\Identifier && in_array($call->name->toString(), self::SCOPING_METHODS, true)) {
                return true;
            }
        }

        return false;
    }
}
