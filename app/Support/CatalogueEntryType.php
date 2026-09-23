<?php

namespace App\Support;

use App\Models\AiModel;
use App\Models\Script;
use Illuminate\Database\Eloquent\Model;

/**
 * The two catalogue entry types, as one definition.
 *
 * `script` and `ai_model` are the morph aliases enforced by
 * `Relation::enforceMorphMap()` in AppServiceProvider, so they are what the
 * `revisable_type` column holds, what `config('marketplace.allowed_extensions')`
 * is keyed on, and what the API and the admin pages echo back. Before this class
 * the same `match` from alias to model class was written out in half a dozen
 * places; it lives here once instead.
 */
final class CatalogueEntryType
{
    public const SCRIPT = 'script';

    public const AI_MODEL = 'ai_model';

    /** @var array<int, string> */
    public const ALL = [self::SCRIPT, self::AI_MODEL];

    /**
     * The model class behind a morph alias, or null for anything else — a
     * `revisable_type` this application does not know, such as a row left by a
     * type that has since been removed.
     *
     * The fully qualified class name is accepted too, because a row written
     * before the morph map was enforced may still carry one.
     *
     * @return class-string<Model>|null
     */
    public static function modelClass(string $alias): ?string
    {
        return match ($alias) {
            self::SCRIPT, Script::class => Script::class,
            self::AI_MODEL, AiModel::class => AiModel::class,
            default => null,
        };
    }

    /** The human label for an alias, as the UI and the CSV exports spell it. */
    public static function label(string $alias): string
    {
        return match ($alias) {
            self::SCRIPT, Script::class => 'Script',
            self::AI_MODEL, AiModel::class => 'AI Model',
            default => $alias,
        };
    }

    /**
     * The alias a model instance is stored under. This is `getMorphClass()`, named
     * so that call sites read as the catalogue concept rather than the Eloquent one.
     */
    public static function fromModel(Model $model): string
    {
        return $model->getMorphClass();
    }
}
