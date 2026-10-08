<?php

namespace Tey\LaravelDDD\Commands\Concerns;

trait InteractsWithStubs
{
    protected function fillPlaceholder($stub, $placeholder, $value)
    {
        return str_replace(['{{'.$placeholder.'}}', '{{ '.$placeholder.' }}'], $value, $stub);
    }

    protected function preparePlaceholders(): array
    {
        return [];
    }

    protected function applyPlaceholders($stub)
    {
        $placeholders = $this->preparePlaceholders();

        foreach ($placeholders as $placeholder => $value) {
            $stub = $this->fillPlaceholder($stub, $placeholder, $value ?? '');
        }

        return $stub;
    }

    protected function buildClass($name)
    {
        return $this->applyPlaceholders(parent::buildClass($name));
    }
}
