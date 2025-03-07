<?php

namespace Astrotomic\Fileable\Models;

use Astrotomic\Fileable\Concerns\Fileable;
use Astrotomic\Fileable\Contracts\File as FileContract;
use Astrotomic\Fileable\Contracts\Fileable as FileableContract;
use Astrotomic\LaravelEloquentUuid\Eloquent\Concerns\UsesUUID;
use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Ramsey\Uuid\UuidInterface;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @property int $id
 * @property string $uuid
 * @property string $fileable_type
 * @property int $fileable_id
 * @property string|null $disk
 * @property string|null $mimetype
 * @property int|null $size
 * @property string|null $display_name
 * @property string|null $filepath
 * @property string|null $filename
 * @property array|null $meta
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read string|null $url
 * @property-read Carbon|null $modified_at
 * @property-read Model|Fileable $fileable
 *
 * @method static Builder|File newModelQuery()
 * @method static Builder|File newQuery()
 * @method static Builder|File query()
 * @method static Builder|MorphMany|File whereCreatedAt($value)
 * @method static Builder|MorphMany|File whereDisk($value)
 * @method static Builder|MorphMany|File whereFileable(FileableContract $fileable)
 * @method static Builder|MorphMany|File whereFileableId($value)
 * @method static Builder|MorphMany|File whereFileableType($value)
 * @method static Builder|MorphMany|File whereFilename($value)
 * @method static Builder|MorphMany|File whereFilepath($value)
 * @method static Builder|MorphMany|File whereId($value)
 * @method static Builder|MorphMany|File whereMeta($value)
 * @method static Builder|MorphMany|File whereMimetype($value)
 * @method static Builder|MorphMany|File whereDisplayName($value)
 * @method static Builder|MorphMany|File whereSize($value)
 * @method static Builder|MorphMany|File whereUpdatedAt($value)
 * @method static Builder|MorphMany|File whereUuid(string|string[]|UuidInterface|UuidInterface[] $uuid)
 *
 * @mixin Builder
 */
class File extends Model implements Responsable, FileContract
{
    use UsesUUID;

    protected $guarded = [];

    protected $casts = [
        'size' => 'int',
        'meta' => 'json',
    ];

    protected $observables = [
        'storing',
        'stored',
    ];

    public static function booted(): void
    {
        static::deleting(static function (self $file): bool {
            if (! $file->exists()) {
                return true;
            }

            return $file->storage()->delete($file->filepath);
        });
    }

    public function __construct(array $attributes = [])
    {
        if (! isset($this->connection)) {
            $this->setConnection(config('fileable.database_connection'));
        }

        if (! isset($this->table)) {
            $this->setTable(config('fileable.table_name'));
        }

        parent::__construct($attributes);
    }

    public function fileable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param Builder $query
     * @param FileableContract|Model $fileable
     *
     * @return Builder
     */
    public function scopeWhereFileable(Builder $query, FileableContract $fileable): Builder
    {
        return $query->where(
            fn (Builder $q) => $q
                ->where('fileable_type', $fileable->getMorphClass())
                ->where('fileable_id', $fileable->getKey())
        );
    }

    public function exists(): bool
    {
        return $this->exists && $this->storage()->exists($this->filepath);
    }

    public function storage(): Filesystem
    {
        return Storage::disk($this->disk);
    }

    public function getDisplayNameAttribute(?string $value): string
    {
        return $value ?? Str::slug(pathinfo($this->filename, PATHINFO_FILENAME));
    }

    public function getUrlAttribute(): ?string
    {
        return url($this->storage()->url($this->filepath));
    }

    public function getModifiedAtAttribute(): ?Carbon
    {
        $timestamp = $this->storage()->lastModified($this->filepath);

        if ($timestamp === null) {
            return null;
        }

        return Carbon::createFromTimestamp($timestamp);
    }

    public function toResponse($request): Response
    {
        if ($request->expectsJson()) {
            return response()->json($this);
        }

        foreach ($request->getAcceptableContentTypes() as $acceptableContentType) {
            if ($this->isOfMimeType($acceptableContentType)) {
                return $this->response();
            }
        }

        return $this->download();
    }

    public function response(array $headers = []): StreamedResponse
    {
        return response()->stream(function (): void {
            $stream = $this->stream();

            fpassthru($stream);

            if (is_resource($stream)) {
                fclose($stream);
            }
        }, 200, array_merge([
            'Cache-Control' => 'must-revalidate, post-check=0, pre-check=0',
            'Content-Type' => $this->mimetype,
            'Content-Length' => $this->size,
        ], $headers));
    }

    public function download(): StreamedResponse
    {
        return $this->response([
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $this->filename,
                /** @see \Illuminate\Routing\ResponseFactory::fallbackName() */
                str_replace('%', '', Str::ascii($this->filename))
            ),
        ]);
    }

    /**
     * @return resource|null
     *
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    public function stream()
    {
        return $this->storage()->readStream($this->filepath);
    }

    public function isOfMimeType(string $pattern): bool
    {
        return Str::is($pattern, $this->mimetype);
    }

    /**
     * @param string|resource $contents
     * @param array $options
     *
     * @return bool
     */
    public function store($contents, array $options = []): bool
    {
        if ($this->fireModelEvent('storing') === false) {
            return false;
        }

        $stored = $this->storage()->put($this->filepath, $contents, $options);

        if ($stored) {
            $this->fireModelEvent('stored', false);
        }

        return $stored;
    }

    public static function storing(Closure $callback): void
    {
        static::registerModelEvent('storing', $callback);
    }

    public static function stored(Closure $callback): void
    {
        static::registerModelEvent('stored', $callback);
    }
}
