<?php

namespace Tey\LaravelDDD\Commands\Concerns;

trait HandleHooks
{
    protected bool $handlePrepared = false;

    protected function prepareHandle(): void
    {
        if (! $this->handlePrepared) {
            $this->beforeHandle();
        }
    }

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
     * Domain input is prepared separately by the command execution lifecycle.
     *
     * @return int|bool|null
     */
    public function handle()
    {
        $this->prepareHandle();

        /** @phpstan-ignore-next-line staticMethod.void */
        $result = parent::handle();

        $this->afterHandle();

        // Handle various return types from parent commands
        /** @phpstan-ignore-next-line identical.alwaysFalse */
        if ($result === false) {
            return self::FAILURE;
        }

        /** @phpstan-ignore-next-line function.impossibleType */
        if (is_int($result)) {
            return $result;
        }

        // void/null defaults to SUCCESS
        return self::SUCCESS;
    }
}
