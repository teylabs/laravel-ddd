<?php

namespace Tey\LaravelDDD\Commands\Concerns;

use Tey\LaravelDDD\Support\Layout\DddLayout;
use Tey\Mod\Exceptions\GenerationRefused;
use Tey\Mod\Relation\RelationMode;
use Tey\Mod\Relation\RelationResolution;

/**
 * The mod adapters name related kinds by role ('model', 'factory', ...);
 * the ddd layout names them per layer ('domain.model'). Translate the role
 * before the adapter looks the kind up.
 */
trait MapsRelatedKinds
{
    /**
     * Generate a related class through its type's ddd:* command (also when
     * its kind declares no command: a custom layer, or a type outside its
     * home root), with the arguments in v3's order: the name, the options
     * the relation passes, then --domain.
     *
     * @param  array<string, mixed>  $arguments
     */
    protected function followRelation(RelationResolution $resolution, array $arguments = []): void
    {
        $target = $resolution->target;

        if ($target === null || $resolution->mode() !== RelationMode::Generate) {
            parent::followRelation($resolution, $arguments);

            return;
        }

        $command = $target->fileType->command ?? $this->commandForKind($target->fileType->id, $target->describe());
        $child = $this->argumentsFor($target);
        $domain = $child['--domain'] ?? null;
        unset($child['--domain']);

        $exitCode = $this->call($command, [
            ...$child,
            ...array_filter($arguments, static fn (mixed $value): bool => $value !== null && $value !== false && $value !== ''),
            ...($domain !== null ? ['--domain' => $domain] : []),
        ]);

        if ($exitCode !== 0) {
            throw GenerationRefused::because("Generating related {$target->fileType->id} [{$target->describe()}] failed.");
        }
    }

    protected function generateOwnedClass(string $fqcn, string $fileType): void
    {
        $artifact = $this->layout()->locate($fqcn);

        if ($artifact === null || $artifact->fileType->id !== $fileType || $artifact->fileType->command !== null) {
            parent::generateOwnedClass($fqcn, $fileType);

            return;
        }

        if ($this->call($this->commandForKind($fileType, $fqcn), $this->argumentsFor($artifact)) !== 0) {
            throw GenerationRefused::because("Generating {$fileType} [{$fqcn}] failed.");
        }
    }

    protected function commandForKind(string $fileType, string $subject): string
    {
        $layout = DddLayout::current();
        $type = $layout->typeOf($fileType);

        return ($type !== null ? $layout->commandFor($type) : null)
            ?? throw GenerationRefused::because("Kind [{$fileType}] declares no command to generate [{$subject}].");
    }
}
