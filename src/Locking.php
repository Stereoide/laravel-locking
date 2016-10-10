<?php

namespace Stereoide\Locking;

use Stereoide\Locking\Exceptions\LockTimeoutException;
    use Stereoide\Locking\Lock;
    use Auth;
    use Carbon\Carbon;

    class Locking
    {
        /* Constants */

        const READ_LOCK = 1;
        const WRITE_LOCK = 2;

        /**
         * @param int $entityId
         * @param int $lockType
         */
        public function isLocked($entityId, $lockType = null)
        {
            $query = Lock::isActive()->where('entity_id', $entityId);
            
            if (!is_null($lockType)) {
                $query->where('lock_type', $lockType);
            }
            
            return 0 != $query->count();
        }

        /**
         * @param int $entityId
         *
         * @return bool
         */
        public function isReadLocked($entityId)
        {
            return $this->isLocked($entity, $this::READ_LOCK);
        }

        /**
         * @param int $entityId
         *
         * @return bool
         */
        public function isWriteLocked($entityId)
        {
            return $this->isLocked($entityId, $this::WRITE_LOCK);
        }

        /**
         * @param int $entityId
         * @param int $userId
         * @param int $lockType
         */
        public function getLocks($entityId = null, $userId = null, $lockType = null)
        {
            /* Fetch active locks that match the requirements */

            $query = Lock::isActive();

            if (!is_null($entityId)) {
                $query->where('entity_id', $entityId);
            }

            if (!is_null($userId)) {
                $query->where('user_id', $userId);
            }

            if (!is_null($lockType)) {
                $query->where('lock_type', $lockType);
            }

            $locks = $query->get();

            /* Return */

            return $locks;
        }

        /**
         * @param int $entityId
         * @param int $lockType
         * @param int $expirationSeconds
         * @param int $retryTimeoutSeconds
         *
         * @throws LockTimeoutException
         */
        public function acquireLock($entityId, $lockType, $expirationSeconds = null, $retryTimeoutSeconds = null)
        {
            /* Sanitize */
            
            if (is_null($expirationSeconds)) {
                $expirationSeconds = (int)config('locking.expiration_seconds', 30);
            }
            
            if (is_null($retryTimeoutSeconds)) {
                $retryTimeoutSeconds = (int)config('locking.retry_timeout_seconds', 10);
            }
            
            /* Initialize */

            $lock = null;

            if (is_null(Auth::user())) {
                throw new LockTimeoutException();
            }
            $userId = Auth::user()->id;

            /* Attempt to acquire the desired lock */

            $timestampEnd = time() + $retryTimeoutSeconds;
            while (is_null($lock) && time() <= $timestampEnd) {
                /* Determine whether a more important lock is set on the requested entity */

                $needToSleep = false;

                switch ($lockType) {
                    case $this::READ_LOCK:
                        if ($this->isWriteLocked($entityId)) {
                            $needToSleep = true;
                        }

                        break;

                    case $this::WRITE_LOCK:
                        /* We need to check all active locks for this entity */

                        $locks = $this->getLocks($entityId);
                        $activeLockCount = $this->getLocks($entityId)->filter(function ($lock, $key) use ($userId) {
                            // (new Dumper())->dump($lock);
                            return Locking::WRITE_LOCK == $lock->lock_type || $lock->user_id != $userId;
                        })->count();

                        $needToSleep = (0 != $activeLockCount);

                        break;
                }

                if ($needToSleep) {
                    usleep(500000);
                    continue;
                }

                $lock = Lock::create([
                    'user_id'    => Auth::user()->id,
                    'entity_id'  => $entityId,
                    'lock_type'  => $lockType,
                    'expires_at' => Carbon::now()->addSeconds($expirationSeconds),
                ]);
            }

            if (is_null($lock)) {
                throw new LockTimeoutException();
            }

            /* Return lock */

            return $lock->id;
        }

        /**
         * @param int $entityId
         * @param int $expirationSeconds
         * @param int $retryTimeoutSeconds
         */
        public function acquireReadLock($entityId, $expirationSeconds = null, $retryTimeoutSeconds = null)
        {
            /* Sanitize */
            
            if (is_null($expirationSeconds)) {
                $expirationSeconds = (int)config('locking.expiration_seconds', 30);
            }
            
            if (is_null($retryTimeoutSeconds)) {
                $retryTimeoutSeconds = (int)config('locking.retry_timeout_seconds', 10);
            }
            
            return $this->acquireLock($entityId, self::READ_LOCK, $expirationSeconds, $retryTimeoutSeconds);
        }

        /**
         * @param int $entityId
         * @param int $expirationSeconds
         * @param int $retryTimeoutSeconds
         */
        public function acquireWriteLock($entityId, $expirationSeconds = null, $retryTimeoutSeconds = null)
        {
            /* Sanitize */
            
            if (is_null($expirationSeconds)) {
                $expirationSeconds = (int)config('locking.expiration_seconds', 30);
            }
            
            if (is_null($retryTimeoutSeconds)) {
                $retryTimeoutSeconds = (int)config('locking.retry_timeout_seconds', 10);
            }
            
            return $this->acquireLock($entityId, self::WRITE_LOCK, $expirationSeconds, $retryTimeoutSeconds);
        }

        /**
         * @param int|array(int) $lockIds
         */
        public function releaseLocks($lockIds)
        {
            /* Authenticate */

            if (is_null(Auth::user())) {
                return;
            }
            $userId = Auth::user()->id;

            /* Sanitize parameters */

            $lockIds = collect($lockIds);

            /* Remove specified locks */

            Lock::fromCurrentUser()->whereIn('id', $lockIds->all())->delete();
        }

        public function releaseLocksByEntityId($entityId, $lockType = null)
        {
            /* Release all matching locks */

            $query = Lock::isActive()->fromCurrentUser()->where('entity_id', $entityId);

            if (!is_null($lockType)) {
                $query->where('lock_type', $lockType);
            }

            $query->delete();
        }


        public function releaseAllLocks()
        {
            Lock::fromCurrentUser()->delete();
        }

        /**
         * @param int $entityId
         */
        public function readLockParentSequence($entityId)
        {
            /* Initialize */

            $lockIds = [];

            /* Fetch entity in question */

            $entity = Api::loadEntitiesById($entityId)->first();

            /* Fetch parent sequence */

            Api::getParentSequence($entity)->each(function ($parent, $key) use (&$lockIds) {
                $lockIds[] = $this->acquireReadLock($parent->id);
            });

            /* Return */

            return $lockIds;
        }

        /**
         * @param int $expirationSeconds
         */
        public function refreshLocks($expirationSeconds = null)
        {
            /* Sanitize */
            
            if (is_null($expirationSeconds)) {
                $expirationSeconds = (int)config('locking.expiration_seconds', 30);
            }
            
            /* Update expiration timestamp in all our active locks */

            Lock::isActive()->fromCurrentUser()->update(['expires_at', Carbon::now()->addSeconds($expirationSeconds)]);
        }
    }
