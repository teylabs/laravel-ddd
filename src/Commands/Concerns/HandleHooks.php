<?php

namespace Tey\LaravelDDD\Commands\Concerns;

use Tey\Mod\Commands\MigrationCommand;

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
     * Preparation and planning stay inside Laravel's handler dispatch and isolation gate.
     *
     * @return int|bool|null
     */
    public function handle()
    {
        $this->beforeHandle();

        // Built on a mod class adapter: place the class (and the relations its
        // options follow) once the domain is known, before the native write.
        // The migration adapter plans in its own execute() and honours --path.
        if (isset($this->blueprint) && ! $this instanceof MigrationCommand) {
            $this->resolvePlan();
        }

        $result = $this->handleNatively();

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

    /**
     * The parent generator's handler.
     *
     * @return int|bool|null
     */
    protected function handleNatively()
    {
        /** @phpstan-ignore-next-line staticMethod.void */
        return parent::handle();
    }
}
