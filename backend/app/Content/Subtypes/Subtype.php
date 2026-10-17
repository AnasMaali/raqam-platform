<?php

namespace App\Content\Subtypes;

use App\Content\PostRuleViolation;
use Filament\Schemas\Components\Component;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The facts that belong to one kind of content only (decision 5): an episode's season and number, a success
 * story's company and figures, and so on. Each kind that has such facts has its own table, keyed by the post.
 * A subtype says what its table holds, what is checked before it is written, and what a form shows for it.
 *
 * Built when the third kind arrived, as the experiment agreed: one place per kind, one saver for all.
 */
abstract class Subtype
{
    /** The kind this belongs to, e.g. "episode". Also the key of its part of a form. */
    abstract public function kind(): string;

    abstract public function table(): string;

    /** @return array<string, list<string>> column => validation rules, for the values that may be in the table */
    abstract public function rules(): array;

    /** @return list<string> columns that must be present once the row exists */
    abstract public function required(): array;

    /** @return array<string, string> column => message for a rule, in Arabic */
    public function messages(): array
    {
        return [];
    }

    /** @return list<Component> the form fields, under the state path of the kind */
    abstract public function fields(): array;

    /** @return array<string, mixed> */
    public function load(int $postId): array
    {
        $row = DB::table($this->table())->where('post_id', $postId)->first();

        return $row === null ? [] : array_intersect_key((array) $row, $this->rules());
    }

    /**
     * Checks and writes this kind's row for a post. All empty means "none yet" (a draft may wait for it; the
     * database refuses to publish without it). Some filled but not all required ones is refused.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, array{0: mixed, 1: mixed}> the changes, for the audit trail
     *
     * @throws PostRuleViolation
     */
    public function save(int $postId, array $values): array
    {
        $values = array_intersect_key($values, $this->rules());
        $values = array_map(fn ($v) => is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v, $values);
        $present = array_filter($values, fn ($v) => $v !== null && $v !== '');

        if ($present === []) {
            // Nothing to write. But a row that is already there cannot be emptied: the kind's facts are not optional once they exist.
            if (DB::table($this->table())->where('post_id', $postId)->exists()) {
                throw new PostRuleViolation('بيانات هذا النوع مطلوبة ولا يمكن تفريغها.', $this->kind().'.'.$this->required()[0]);
            }

            return $this->saveChildren($postId, $values, []);
        }

        $missing = array_values(array_diff($this->required(), array_keys($present)));
        if ($missing !== []) {
            throw new PostRuleViolation('بيانات هذا النوع ناقصة، أكملها أو اتركها كلها فارغة ما دامت المادة مسودة.', $this->kind().'.'.$missing[0]);
        }

        $this->validate($present, $postId);
        $before = $this->load($postId);

        // Not updateOrCreate(): its fallback swallows a unique violation and could overwrite another post's row.
        if (DB::table($this->table())->where('post_id', $postId)->exists()) {
            DB::table($this->table())->where('post_id', $postId)->update($values);
        } else {
            DB::table($this->table())->insert(['post_id' => $postId] + $values);
        }

        $changes = [];
        foreach ($values as $column => $value) {
            if ((string) ($before[$column] ?? '') !== (string) ($value ?? '')) {
                $changes[$this->kind().'.'.$column] = [$before[$column] ?? null, $value];
            }
        }

        return $this->saveChildren($postId, $values, $changes);
    }

    /** Rows that hang off this kind's row (the figures of a story). Nothing by default. */
    protected function saveChildren(int $postId, array $values, array $changes): array
    {
        return $changes;
    }

    /** @param  array<string, mixed>  $values */
    protected function validate(array $values, int $postId): void
    {
        try {
            Validator::make($values, array_intersect_key($this->rules(), $values), $this->messages())->validate();
        } catch (ValidationException $e) {
            $field = array_key_first($e->errors());

            throw new PostRuleViolation($e->errors()[$field][0], $this->kind().'.'.$field);
        }
    }
}
