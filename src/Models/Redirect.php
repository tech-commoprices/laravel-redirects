<?php

namespace Neurony\Redirects\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Neurony\Redirects\Contracts\RedirectModelContract;
use Neurony\Redirects\Exceptions\RedirectException;

class Redirect extends Model implements RedirectModelContract
{
    /**
     * The database table.
     *
     * @var string
     */
    protected $table = 'redirects';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'old_url',
        'new_url',
        'new_url_external',
        'status',
    ];

    /**
     * Boot the model.
     *
     * @return void
     */
    public static function boot()
    {
        parent::boot();

        static::saving(function (self $model) {
            if (trim($model->old_url, '/') == trim($model->new_url, '/')) {
                throw RedirectException::sameUrls();
            }

            static::whereRaw('BINARY `old_url` = ?', [$model->new_url])
                ->whereRaw('BINARY `new_url` = ?', [$model->old_url])
                ->delete();

            $model->syncOldRedirects($model, $model->new_url);
        });
    }

    /**
     * The mutator to set the "old_url" attribute.
     *
     * @param string $value
     */
    public function setOldUrlAttribute($value)
    {
        $this->attributes['old_url'] = trim(parse_url($value)['path'], '/');
    }

    /**
     * The mutator to set the "new_url" attribute.
     *
     * @param string $value
     */
    public function setNewUrlAttribute($value)
    {
        $this->attributes['new_url'] = trim(parse_url($value)['path'], '/');
    }

    /**
     * The mutator to set the "new_url" attribute if the new url is external.
     *
     * @param string $value
     */
    public function setNewUrlExternalAttribute($value)
    {
        $this->attributes['new_url'] = trim($value, '/');
    }

    /**
     * Filter the query by an old url.
     *
     * @param Builder $query
     * @param string $url
     *
     * @return mixed
     */
    public function scopeWhereOldUrl($query, string $url)
    {
        return $query->whereRaw('BINARY `old_url` = ?', [$url]);
    }

    /**
     * Filter the query by a new url.
     *
     * @param Builder $query
     * @param string $url
     *
     * @return mixed
     */
    public function scopeWhereNewUrl($query, string $url)
    {
        return $query->whereRaw('BINARY `new_url` = ?', [$url]);
    }

    /**
     * Get all redirect statuses defined inside the "config/redirects.php" file.
     *
     * @return array
     */
    public static function getStatuses(): array
    {
        return (array) config('redirects.statuses', []);
    }

    /**
     * Sync old redirects to point to the new (final) url.
     *
     * @param RedirectModelContract $model
     * @param string $finalUrl
     * @return void
     */
    public function syncOldRedirects(RedirectModelContract $model, string $finalUrl): void
    {
        $items = static::whereNewUrl($model->old_url)->get();

        foreach ($items as $item) {
            $item->update(['new_url' => $finalUrl]);
            $item->syncOldRedirects($model, $finalUrl);
        }
    }

    /**
     * Return a valid redirect entity for a given path (old url).
     * A redirect is valid if:
     * - it has an url to redirect to (new url)
     * - it's status code is one of the statuses defined on this model.
     *
     * @param string $path
     * @return Redirect|null
     */
    public static function findValidOrNull($path): ?RedirectModelContract
    {
        $req = static::whereRaw('BINARY `old_url` = ?', [$path === '/' ? $path : trim($path, '/')])
            ->whereNotNull('new_url')
            ->whereIn('status', array_keys(self::getStatuses()))
            ->latest();

        return $req->first();
    }
}
