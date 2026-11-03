<?php

namespace App\Services\OrganizationDataReset\Selective;

use Illuminate\Validation\ValidationException;

/**
 * What the owner asked to clean. Three combinable modes:
 *  - categories: whole categories (every root row of the category);
 *  - date range: only root rows whose BUSINESS date falls in [from, to];
 *  - records: for a root table, only the listed ids (individual selection),
 *    or — "select all filtered" — every row matching `filters`.
 * Everything not selected is preserved.
 */
final readonly class SelectiveResetSelection
{
    /**
     * @param list<string> $categories
     * @param array<string, list<int>> $records root table => ids
     * @param array<string, array{search?:?string,status?:?string,warehouse_id?:?int}> $filters root table => filters
     */
    public function __construct(
        public array $categories,
        public ?string $dateFrom = null,
        public ?string $dateTo = null,
        public array $records = [],
        public array $filters = [],
    ) {}

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data, SelectiveResetCategoryRegistry $registry): self
    {
        $categories = array_values(array_unique(array_map('strval', (array) ($data['categories'] ?? []))));
        if ($categories === []) {
            throw ValidationException::withMessages(['categories' => 'Choisissez au moins une catégorie à nettoyer.']);
        }
        foreach ($categories as $category) {
            if ($registry->find($category) === null) {
                throw ValidationException::withMessages(['categories' => "Catégorie inconnue : {$category}."]);
            }
        }
        $allowedRoots = [];
        foreach ($categories as $category) {
            foreach ($registry->find($category)['roots'] as $root) {
                $allowedRoots[$root['table']] = true;
            }
        }

        $records = [];
        foreach ((array) ($data['records'] ?? []) as $table => $ids) {
            if (! isset($allowedRoots[$table])) {
                throw ValidationException::withMessages(['records' => "Les enregistrements « {$table} » n’appartiennent à aucune catégorie choisie."]);
            }
            $ids = array_values(array_unique(array_filter(array_map('intval', (array) $ids), fn (int $id) => $id > 0)));
            if (count($ids) > 5000) {
                throw ValidationException::withMessages(['records' => 'Sélection trop large : utilisez « tout sélectionner (filtré) ».']);
            }
            if ($ids !== []) {
                sort($ids);
                $records[$table] = $ids;
            }
        }

        $filters = [];
        foreach ((array) ($data['filters'] ?? []) as $table => $filter) {
            if (! isset($allowedRoots[$table]) || ! is_array($filter)) {
                continue;
            }
            $clean = array_filter([
                'search' => isset($filter['search']) && trim((string) $filter['search']) !== '' ? mb_substr(trim((string) $filter['search']), 0, 100) : null,
                'status' => isset($filter['status']) && $filter['status'] !== '' ? mb_substr((string) $filter['status'], 0, 40) : null,
                'warehouse_id' => isset($filter['warehouse_id']) && (int) $filter['warehouse_id'] > 0 ? (int) $filter['warehouse_id'] : null,
            ], fn ($v) => $v !== null);
            if ($clean !== []) {
                ksort($clean);
                $filters[$table] = $clean;
            }
        }

        $from = self::date($data['date_from'] ?? null, 'date_from');
        $to = self::date($data['date_to'] ?? null, 'date_to');
        if ($from !== null && $to !== null && $from > $to) {
            throw ValidationException::withMessages(['date_to' => 'La date de fin doit suivre la date de début.']);
        }
        sort($categories);
        ksort($records);
        ksort($filters);

        return new self($categories, $from, $to, $records, $filters);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'categories' => $this->categories,
            'date_from' => $this->dateFrom,
            'date_to' => $this->dateTo,
            'records' => $this->records,
            'filters' => $this->filters,
        ];
    }

    private static function date(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = (string) $value;
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (! $parsed || $parsed->format('Y-m-d') !== $value) {
            throw ValidationException::withMessages([$field => 'Date invalide (AAAA-MM-JJ).']);
        }

        return $value;
    }
}
