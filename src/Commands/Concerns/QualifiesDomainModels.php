<?php

namespace Tey\LaravelDDD\Commands\Concerns;

trait QualifiesDomainModels
{
    protected function qualifyModel(string $model)
    {
        if ($this->blueprint->domain) {
            $domainModel = $this->blueprint->getModelFor($model);

            return $domainModel->fullyQualifiedName;
        }

        return parent::qualifyModel($model);
    }
}
