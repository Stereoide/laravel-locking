<?php

namespace Stereoide\Locking;

use Stereoide\Locking\Locking;
use Auth;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

class Lock extends Model
{
    /* Prevent Laravel from trying to update created_at and updated_at columns */

    public $timestamps = false;

    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'locks';

    /**
     * Attributes that should be mass-assignable.
     *
     * @var array
     */
    protected $fillable = [
        'user_id',
        'entity_id',
        'lock_type',
        'expires_at',
    ];

    /**
     * The attributes excluded from the model's JSON form.
     *
     * @var array
     */
    protected $hidden = [];

    /**
     * The attributes that should be casted to native types.
     *
     * @var array
     */
    protected $casts = [];

    /**
     * The attributes that should be mutated to dates.
     *
     * @var array
     */
    protected $dates = ['expires_at'];

    public function scopeIsReadLock($query)
    {
        return $query->where('lock_type', Locking::READ_LOCK);
    }

    public function scopeIsWriteLock($query)
    {
        return $query->where('lock_type', Locking::WRITE_LOCK);
    }

    public function scopeIsExpired($query)
    {
        return $query->where('expires_at', '<', Carbon::now());
    }

    public function scopeIsActive($query)
    {
        return $query->where('expires_at', '>=', Carbon::now());
    }

    public function scopeFromCurrentUser($query)
    {
        if (is_null(Auth::user())) {
            return $query->where('id', -1);
        }

        return $query->where('user_id', Auth::user()->id);
    }
}
