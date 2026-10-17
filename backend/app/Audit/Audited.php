<?php

namespace App\Audit;

/**
 * Put on an Eloquent model and every create, update, delete and restore lands in the audit trail
 * without the code that saves it having to remember (decision 22.14).
 *
 * A model changes what is recorded by overriding auditAction() (e.g. 'publish' when a post's status
 * becomes published), auditChanges() or auditSubject().
 */
trait Audited
{
    public static function bootAudited(): void
    {
        static::created(fn ($model) => $model->audit('create'));
        static::updated(fn ($model) => $model->audit('update'));
        static::deleted(fn ($model) => $model->audit('delete'));

        if (method_exists(static::class, 'restored')) {
            static::restored(fn ($model) => $model->audit('restore'));
        }
    }

    /** Fields that are bookkeeping, not changes anybody made. */
    protected function auditIgnored(): array
    {
        return ['updated_at', 'created_at', 'deleted_at'];
    }

    protected function auditAction(string $event): string
    {
        return $event;
    }

    /** @return array{0: string, 1: int}|null  table and id of the subject; null when the key is not a number */
    protected function auditSubject(): ?array
    {
        $key = $this->getKey();

        return is_int($key) ? [$this->getTable(), $key] : null;
    }

    /** @return array<string, array{0: mixed, 1: mixed}> */
    protected function auditChanges(string $event): array
    {
        $ignored = $this->auditIgnored();

        if ($event === 'create') {
            return collect($this->getAttributes())->except($ignored)->map(fn ($v) => [null, $v])->all();
        }

        if ($event === 'update') {
            return collect($this->getChanges())->except($ignored)
                ->mapWithKeys(fn ($new, $field) => [$field => [$this->getOriginal($field), $new]])->all();
        }

        return [];
    }

    public function audit(string $event): void
    {
        $changes = $this->auditChanges($event);

        // Saving a model that nothing really changed leaves no trace worth keeping.
        if ($event === 'update' && $changes === []) {
            return;
        }

        [$table, $id] = $this->auditSubject() ?? [null, null];

        AuditLogger::record($this->auditAction($event), $table, $id, $changes);
    }
}
