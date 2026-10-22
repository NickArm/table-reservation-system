<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\VenueDocumentType;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\LegalDocumentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * One version of one of a venue's own legal pages (SPEC §12.1).
 *
 * Tenant-scoped, unlike `PlatformDocument`: this belongs to the venue, and
 * nobody else's query should ever reach it.
 */
class LegalDocument extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<LegalDocumentFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => VenueDocumentType::class,
            'is_current' => 'boolean',
            'published_at' => 'immutable_datetime',
        ];
    }

    /**
     * The version a guest would be shown right now.
     *
     * Scoped, so it answers for whichever venue is in context — which is the
     * whole point of the trait and the reason this needs no tenant argument.
     */
    public static function current(VenueDocumentType $type): ?self
    {
        return self::query()
            ->where('type', $type->value)
            ->where('is_current', true)
            ->first();
    }

    /**
     * The document as HTML.
     *
     * Raw HTML stripped, for a reason that matters more here than on the
     * platform side: this text is typed by a venue owner, and a venue owner
     * is not a trusted author. Without stripping, one of them pasting
     * something off another website would put whatever came with it onto a
     * page we serve.
     */
    public function html(): string
    {
        return Str::markdown($this->content, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }

    /**
     * The first line or so, for the one-line summary at checkout.
     *
     * Plain text: it sits inside a label next to a checkbox, where markup
     * would be noise and a heading would be absurd.
     */
    public function summary(int $limit = 180): string
    {
        $plain = trim(preg_replace('/\s+/', ' ', strip_tags($this->html())) ?? '');

        return Str::limit($plain, $limit);
    }
}
