<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One changed field of an audit row, with its value before and after. Read only, like its parent. */
class AuditLogChange extends Model
{
    protected $table = 'audit_log_changes';

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = null;
}
