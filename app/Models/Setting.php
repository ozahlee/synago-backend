<?php

namespace App\Models;

use App\Models\Concerns\BelongsToChurch;
use App\Support\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Key/value settings for the current church; unset keys fall back to config('church.defaults').
 * Name, denomination, diocese and address live on the church record itself.
 */
class Setting extends Model
{
    use BelongsToChurch;

    protected $table = 'church_settings';

    protected $fillable = ['key', 'value'];

    /** Settings stored on the `churches` row rather than as key/value pairs. */
    private const ON_CHURCH = ['churchName' => 'name', 'denomination' => 'denomination', 'diocese' => 'diocese', 'address' => 'address'];

    protected function casts(): array
    {
        return ['value' => 'json'];
    }

    /** All settings for the current church merged over the defaults. */
    public static function values(): array
    {
        $values = array_merge(config('church.defaults'), static::query()->pluck('value', 'key')->all());
        if ($church = Tenant::church()) {
            foreach (self::ON_CHURCH as $key => $column) {
                $values[$key] = $church->{$column} ?? $values[$key] ?? null;
            }
        }

        return $values;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return static::values()[$key] ?? $default;
    }

    public static function put(array $values): void
    {
        $church = Tenant::church();
        $onChurch = [];
        foreach ($values as $key => $value) {
            if ($church && isset(self::ON_CHURCH[$key])) {
                $onChurch[self::ON_CHURCH[$key]] = $value;

                continue;
            }
            static::updateOrCreate(['key' => $key], ['value' => $value]);
        }
        if ($onChurch) {
            $church->update($onChurch);
        }
    }
}
