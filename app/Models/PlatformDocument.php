<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PlatformDocumentType;
use Database\Factories\PlatformDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One version of one of the platform's legal texts (SPEC §12.2).
 *
 * Not tenant-scoped — these are ours, and a venue can only read them.
 */
#[Fillable([
    'type', 'version', 'content', 'change_summary', 'needs_reacceptance',
])]
class PlatformDocument extends Model
{
    /** @use HasFactory<PlatformDocumentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => PlatformDocumentType::class,
            'needs_reacceptance' => 'boolean',
            'is_current' => 'boolean',
            'published_at' => 'immutable_datetime',
        ];
    }

    /**
     * The version being served right now, or null if nothing is published.
     */
    public static function current(PlatformDocumentType $type): ?self
    {
        return self::query()
            ->where('type', $type->value)
            ->where('is_current', true)
            ->whereNotNull('published_at')
            ->first();
    }

    /**
     * @param  Builder<PlatformDocument>  $query
     * @return Builder<PlatformDocument>
     */
    public function scopeNewestFirst(Builder $query): Builder
    {
        return $query->orderByDesc('published_at')->orderByDesc('id');
    }

    public function isDraft(): bool
    {
        return $this->published_at === null;
    }

    /**
     * Is this still the placeholder?
     *
     * The seeded text carries a marker rather than being recognised by its
     * wording, so the check cannot be broken by somebody tidying up a
     * sentence. The public page uses this to say plainly that the text is not
     * final — a half-finished legal page that looks finished is worse than an
     * obviously unfinished one.
     */
    public function isPlaceholder(): bool
    {
        return str_contains($this->content, '<!--placeholder-->');
    }

    /**
     * The document as HTML.
     *
     * Raw HTML is stripped rather than passed through. Only a platform admin
     * writes these, so this is not an injection defence — it is a defence
     * against a Word document pasted straight out of Outlook, which arrives
     * carrying half a stylesheet and `<o:p>` tags.
     */
    public function html(): string
    {
        return Str::markdown($this->content, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }
}
