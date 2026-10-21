<?php

namespace App\Database;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\Grammars\MySqlGrammar;
use Illuminate\Support\Str;

/**
 * A translated column falls back to the original when there is no translation.
 *
 * Every query that serves another language left-joins the matching `*_langs`
 * table and selects the translated column straight over the original:
 *
 *     Category::leftJoin('category_langs as cl', ...)->select('categories.*', 'cl.title')
 *
 * Any row without a translation came back with a NULL title, and a filter on
 * `cl.title` matched nothing. Choosing Turkish blanked category names in the
 * menu, product names on the tiles and the product page h1 (the Arabic product
 * page crashed on it), and search found nothing. There are 200+ such joins, so
 * the rule lives here rather than in each of them.
 *
 * Within a select whose joins include `<thing>_langs as x`, a translated column
 * `x.col` reads as `COALESCE(x.col, <things>.col)`:
 *   - selected:            `x.title`        -> COALESCE(...) as `title`
 *   - selected with alias: `x.title as foo` -> COALESCE(...) as `foo`
 *   - where / order / group by `x.title`   -> COALESCE(...)
 *
 * Every column of a `*_langs` table other than id, lang, timestamps and the
 * `*_id` keys also exists on its base table, so the fallback is always valid.
 * A join whose base table is not in the same query is left alone, as are raw
 * expressions and everything outside a select (inserts, updates, deletes).
 */
class TranslationFallbackGrammar extends MySqlGrammar
{
    /**
     * One entry per select being compiled (subqueries nest): lang alias => base qualifier.
     *
     * @var array<int, array<string, string>>
     */
    protected $fallbackStack = [];

    public function compileSelect(Builder $query)
    {
        $this->fallbackStack[] = $this->translationFallbacks($query);

        try {
            return parent::compileSelect($query);
        } finally {
            array_pop($this->fallbackStack);
        }
    }

    protected function compileColumns(Builder $query, $columns)
    {
        if ($this->currentFallbacks()) {
            $columns = array_map(function ($column) {
                return $this->fallbackSelectColumn($column) ?? $column;
            }, $columns);
        }

        return parent::compileColumns($query, $columns);
    }

    public function wrap($value, $prefixAlias = false)
    {
        if (! $this->isExpression($value) && $this->currentFallbacks()
            && preg_match('/^(\w+)\.(\w+)$/', trim($value), $m)
            && ($sql = $this->fallbackSql($m[1], $m[2])) !== null) {
            return $sql;
        }

        return parent::wrap($value, $prefixAlias);
    }

    protected function fallbackSelectColumn($column)
    {
        if ($this->isExpression($column)
            || ! preg_match('/^\s*(\w+)\.(\w+)(?:\s+as\s+(\w+))?\s*$/i', $column, $m)) {
            return null;
        }

        $sql = $this->fallbackSql($m[1], $m[2]);

        return $sql === null ? null : new Expression($sql.' as '.$this->wrapValue($m[3] ?? $m[2]));
    }

    protected function fallbackSql($alias, $column)
    {
        $fallbacks = $this->currentFallbacks();

        if (! isset($fallbacks[$alias]) || ! $this->isTranslatedColumn($column)) {
            return null;
        }

        return 'coalesce('.parent::wrap($alias.'.'.$column).', '
            .parent::wrap($fallbacks[$alias].'.'.$column).')';
    }

    protected function isTranslatedColumn($column)
    {
        return ! in_array($column, ['id', 'lang', 'created_at', 'updated_at'], true)
            && ! Str::endsWith($column, '_id');
    }

    protected function currentFallbacks()
    {
        return $this->fallbackStack ? end($this->fallbackStack) : [];
    }

    /**
     * Map each joined `*_langs` alias to the qualifier of its base table.
     */
    protected function translationFallbacks(Builder $query)
    {
        $joins = $this->flattenJoins($query->joins ?: []);

        if (! $joins) {
            return [];
        }

        // qualifier => real table name, for the FROM and every join
        $tables = [];
        $sources = array_merge([$query->from], array_map(function ($join) {
            return $join->table;
        }, $joins));

        foreach ($sources as $table) {
            if (is_string($table)) {
                [$name, $qualifier] = $this->splitTable($table);
                $tables[$qualifier] = $name;
            }
        }

        $fallbacks = [];

        foreach ($joins as $join) {
            if (! is_string($join->table)) {
                continue;
            }

            [$langTable, $langAlias] = $this->splitTable($join->table);

            if (! Str::endsWith($langTable, '_langs')) {
                continue;
            }

            $baseTable = Str::plural(Str::beforeLast($langTable, '_langs'));
            $qualifier = null;

            // Prefer the base table the join is attached to: a self-joined table
            // (categories as parent) can appear twice.
            foreach ($join->wheres as $where) {
                if (($where['type'] ?? null) !== 'Column') {
                    continue;
                }
                foreach ([$where['first'], $where['second']] as $side) {
                    if (is_string($side) && Str::contains($side, '.')) {
                        $q = Str::before($side, '.');
                        if ($q !== $langAlias && ($tables[$q] ?? null) === $baseTable) {
                            $qualifier = $q;
                        }
                    }
                }
            }

            if ($qualifier === null) {
                $qualifier = array_search($baseTable, $tables, true) ?: null;
            }

            if ($qualifier !== null) {
                $fallbacks[$langAlias] = $qualifier;
            }
        }

        return $fallbacks;
    }

    protected function flattenJoins(array $joins)
    {
        $all = [];
        foreach ($joins as $join) {
            $all[] = $join;
            if (! empty($join->joins)) {
                $all = array_merge($all, $this->flattenJoins($join->joins));
            }
        }

        return $all;
    }

    /**
     * "products as p" => ['products', 'p']; "products" => ['products', 'products'].
     */
    protected function splitTable($table)
    {
        $parts = preg_split('/\s+as\s+/i', trim($table));

        return [$parts[0], $parts[1] ?? $parts[0]];
    }
}
