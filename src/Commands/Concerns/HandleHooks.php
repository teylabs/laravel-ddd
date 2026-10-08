<?php

namespace Tey\LaravelDDD\Commands\Concerns;

trait HandleHooks
{
    protected function beforeHandle()
    {
        //
    }

    protected function afterHandle()
    {
        //
    }

    /**
     * Retain the legacy handler hooks and generator return-value normalization.
     * Preparation stays inside Laravel's handler dispatch and isolation gate.
     *
     * @return int|bool|null
     */
    public function handle()
    {
        $this->beforeHandle();

        /** @phpstan-ignore-next-line staticMethod.void */
        $result = parent::handle();

        $this->afterHandle();

        // Handle various return types from parent commands. A native generator
        // returns false when the class already exists or the name is reserved;
        // Artisan turns that into exit 0, so ddd:* exits like its make:* parent.
        /** @phpstan-ignore-next-line identical.alwaysFalse */
        if ($result === false) {
            return false;
        }

        /** @phpstan-ignore-next-line function.impossibleType */
        if (is_int($result)) {
            return $result;
        }

        // void/null defaults to SUCCESS
        return self::SUCCESS;
    }
}
